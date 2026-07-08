<?php

declare(strict_types=1);

namespace Waffle\Commons\Data\Connection;

use Closure;
use PDO;
use PDOException;
use PDOStatement;
use Waffle\Commons\Contracts\Data\Connection\ConnectionInterface;
use Waffle\Commons\Contracts\Data\Connection\ConnectionKind;
use Waffle\Commons\Contracts\Data\Connection\ConnectionTrackerInterface;
use Waffle\Commons\Contracts\Data\Connection\PdoConnectionInterface;
use Waffle\Commons\Contracts\Data\Connection\RelationalConnectionPoolInterface;
use Waffle\Commons\Contracts\Service\ResettableInterface;
use Waffle\Commons\Data\Exception\DatabaseException;

use function array_fill_keys;
use function array_keys;
use function count;
use function spl_object_id;
use function sprintf;

/**
 * Stateless, worker-safe pool of reusable PDO connections.
 *
 * Built for FrankenPHP resident-worker mode: a bounded set of connections is
 * kept warm across requests rather than opened and torn down per request. Two
 * guarantees make that safe:
 *
 *  - **Ping-before-dispense** — every connection is probed with a lightweight
 *    `SELECT 1` before it leaves the pool; a connection whose socket has dropped
 *    is discarded and transparently replaced, so a stale handle never reaches
 *    the caller.
 *  - **Reset between requests** — {@see self::reset()} rolls back any dangling
 *    transaction, returns borrowed handles to the idle set, clears the
 *    prepared-statement cache, and drops any request-scoped affinity, leaving no
 *    per-request state to leak into the next iteration.
 *
 * For the failsafe transaction middleware (DBAL-01) the pool also supports a
 * request-scoped affinity ({@see self::beginRequestScope()} / {@see self::endRequestScope()}):
 * while a scope is open every {@see self::acquire()} returns the SAME pinned lease
 * and {@see self::release()} of it is a no-op, so the middleware's single
 * transaction contains every downstream repository write.
 *
 * All internal maps are keyed by `spl_object_id()` so a connection is tracked by
 * identity and can never be double-counted or double-pooled.
 */
final class PDOConnectionPool implements RelationalConnectionPoolInterface, ResettableInterface
{
    /**
     * Idle, ready-to-dispense connections.
     *
     * @var array<int, PDO>
     */
    private array $idle = [];

    /**
     * Connections currently borrowed by the caller.
     *
     * @var array<int, PDO>
     */
    private array $inUse = [];

    /**
     * Per-connection prepared-statement cache: connection id → (SQL → handle).
     *
     * @var array<int, array<string, PDOStatement>>
     */
    private array $statements = [];

    /**
     * Underlying-handle ids this pool has issued and not yet discarded (DBAL-03):
     * a set used by {@see self::release()} to reject a lease that did not
     * originate here, so a foreign handle is never pooled.
     *
     * @var array<int, true>
     */
    private array $issued = [];

    /**
     * Request-scoped pinned lease (DBAL-01). While set, {@see self::acquire()}
     * returns this same lease and {@see self::release()} of it is a no-op, so the
     * failsafe transaction middleware can open ONE transaction that every
     * downstream repository write runs inside.
     */
    private ?PdoConnection $pinnedLease = null;

    /**
     * @param Closure(): PDO $factory        Produces a freshly connected PDO. The pool
     *                                        re-enforces exception error-mode on every
     *                                        connection it creates.
     * @param int            $maxConnections Hard ceiling on simultaneously borrowed
     *                                        connections; protects the worker from
     *                                        unbounded socket growth.
     * @param non-empty-string $pingQuery     Liveness probe executed before dispensing.
     * @param ?ConnectionTrackerInterface $tracker DIAG-03 orphaned-connection tracer.
     *                                        Null (the default) disables tracing entirely —
     *                                        zero overhead in production; a dev wiring injects
     *                                        a tracker so a borrowed-but-never-released handle
     *                                        is surfaced at request end.
     *
     * @throws DatabaseException When $maxConnections is below 1.
     */
    public function __construct(
        private readonly Closure $factory,
        private readonly int $maxConnections = 8,
        private readonly string $pingQuery = 'SELECT 1',
        private readonly ?ConnectionTrackerInterface $tracker = null,
    ) {
        if ($maxConnections < 1) {
            throw new DatabaseException('A connection pool must allow at least one connection.');
        }
    }

    /**
     * Borrow a healthy relational connection lease (ping-before-dispense).
     *
     * While a request scope is open ({@see self::beginRequestScope()}) every call
     * returns the SAME pinned lease, so all repository work in the request shares
     * one connection.
     *
     * @throws DatabaseException When no healthy connection can be dispensed.
     */
    #[\Override]
    public function acquire(): PdoConnectionInterface
    {
        if ($this->pinnedLease !== null) {
            return $this->pinnedLease;
        }

        return new PdoConnection($this->acquireRaw(), $this->pingQuery);
    }

    /**
     * Open a request-scoped connection affinity and return the pinned lease
     * (DBAL-01). Idempotent within a scope: a nested call returns the
     * already-pinned lease.
     *
     * @throws DatabaseException When no healthy connection can be dispensed.
     */
    #[\Override]
    public function beginRequestScope(): PdoConnectionInterface
    {
        if ($this->pinnedLease !== null) {
            return $this->pinnedLease;
        }

        $lease = new PdoConnection($this->acquireRaw(), $this->pingQuery);
        $this->pinnedLease = $lease;

        return $lease;
    }

    /**
     * Close the request-scoped affinity and actually return the pinned
     * connection to the idle set (DBAL-01). No-op when no scope is open.
     */
    #[\Override]
    public function endRequestScope(): void
    {
        $lease = $this->pinnedLease;
        if ($lease === null) {
            return;
        }

        // Unpin first so the now-unprotected release reclaims the handle.
        $this->pinnedLease = null;
        $this->release($lease);
    }

    #[\Override]
    public function release(ConnectionInterface $connection): void
    {
        if (!$connection instanceof PdoConnectionInterface) {
            // Fail-soft: a lease minted by another pool (or another backend kind)
            // is not ours to reclaim — silently ignore it.
            return;
        }

        // While the scope owns the pinned lease, releasing it is a no-op so a
        // downstream repository cannot return the middleware's transaction
        // connection to the idle set mid-request.
        if ($this->pinnedLease !== null && $connection === $this->pinnedLease) {
            return;
        }

        $pdo = $connection->pdo();
        $id = spl_object_id($pdo);
        if (($this->issued[$id] ?? false) === false) {
            // DBAL-03: a lease whose handle this pool never issued is not ours.
            return;
        }

        unset($this->inUse[$id]);
        // Re-key on the object id so releasing the same handle twice is a no-op
        // rather than a duplicate idle entry.
        $this->idle[$id] = $pdo;
        $this->tracker?->trackClose($this->traceId($id));
    }

    /**
     * Find or establish a healthy raw PDO handle, registering it as in-use.
     *
     * @throws DatabaseException When no healthy connection can be dispensed.
     */
    private function acquireRaw(): PDO
    {
        foreach ($this->idle as $id => $connection) {
            unset($this->idle[$id]);
            if ($this->isAlive($connection)) {
                $this->inUse[$id] = $connection;
                $this->issued[$id] = true;
                $this->tracker?->trackOpen($this->traceId($id), ConnectionKind::Pdo);

                return $connection;
            }

            // Probe failed: drop the dead handle and any statements bound to it,
            // then fall through to try the next idle connection (or a fresh one).
            $this->discard($id);
        }

        return $this->dispenseFresh();
    }

    /**
     * Borrow-and-cache a prepared statement for the given connection.
     *
     * Repeated calls with the same SQL on the same connection return the same
     * compiled handle, sparing the round-trip; the cache is wiped on
     * {@see self::reset()} so it never crosses a request boundary.
     *
     * @throws DatabaseException When the statement cannot be prepared.
     */
    public function prepare(PdoConnectionInterface $connection, string $sql): PDOStatement
    {
        $pdo = $connection->pdo();
        $id = spl_object_id($pdo);
        $cached = $this->statements[$id][$sql] ?? null;
        if ($cached instanceof PDOStatement) {
            return $cached;
        }

        try {
            $statement = $pdo->prepare($sql);
        } catch (PDOException $error) {
            throw DatabaseException::fromThrowable($error, 'Failed to prepare statement.');
        }

        if ($statement === false) {
            throw new DatabaseException('Failed to prepare statement.');
        }

        $cache = $this->statements[$id] ?? [];
        $cache[$sql] = $statement;
        $this->statements[$id] = $cache;

        return $statement;
    }

    /**
     * Release request-scoped state at the end of a worker iteration.
     *
     * Borrowed handles return to the idle set, any open transaction is rolled
     * back (a request that crashed mid-transaction must never leak a lock into
     * the next one), and the statement cache is cleared. The underlying sockets
     * stay open so the next request reuses warm connections.
     */
    #[\Override]
    public function reset(): void
    {
        // Drop any request-scoped affinity: its underlying PDO is already in
        // $inUse and is rolled back + recycled by the idle path below.
        $this->pinnedLease = null;

        foreach ($this->inUse as $id => $connection) {
            $this->idle[$id] = $connection;
        }
        $this->inUse = [];

        foreach ($this->idle as $connection) {
            $this->rollbackIfActive($connection);
        }

        $this->statements = [];
        // Re-establish the issued-handle set to exactly the warm idle handles:
        // they survive the request and stay ours to reclaim (DBAL-03), while any
        // discarded handle's mark is dropped — so the set never grows unbounded.
        $this->issued = array_fill_keys(array_keys($this->idle), true);
    }

    /** Number of warm, idle connections currently held. */
    public function idleCount(): int
    {
        return count($this->idle);
    }

    /** Number of connections currently borrowed by callers. */
    public function activeCount(): int
    {
        return count($this->inUse);
    }

    /**
     * @throws DatabaseException When the pool is saturated or a connection fails.
     */
    private function dispenseFresh(): PDO
    {
        if (count($this->inUse) >= $this->maxConnections) {
            throw new DatabaseException(sprintf(
                'Connection pool exhausted: all %d connections are in use.',
                $this->maxConnections,
            ));
        }

        $connection = $this->create();
        $id = spl_object_id($connection);
        $this->inUse[$id] = $connection;
        $this->issued[$id] = true;
        $this->tracker?->trackOpen($this->traceId($id), ConnectionKind::Pdo);

        return $connection;
    }

    /**
     * Stable, kind-scoped trace id for a connection so the DIAG-03 ledger never
     * confuses a recycled `spl_object_id()` with a handle of another kind.
     */
    private function traceId(int $connectionId): string
    {
        return 'pdo:' . $connectionId;
    }

    /**
     * @throws DatabaseException When the factory cannot establish a connection.
     */
    private function create(): PDO
    {
        try {
            $connection = ($this->factory)();
            // Force exception error-mode so the liveness probe and every query can
            // rely on thrown PDOExceptions rather than silent boolean failure.
            $connection->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

            return $connection;
        } catch (PDOException $error) {
            throw DatabaseException::fromThrowable($error, 'Failed to establish a database connection.');
        }
    }

    /**
     * Lightweight liveness probe: a connection that cannot answer the ping query
     * is considered dead and will be recycled by the caller.
     */
    private function isAlive(PDO $connection): bool
    {
        try {
            $statement = $connection->query($this->pingQuery);
            if ($statement !== false) {
                // Free the probe's result buffer immediately.
                $statement->closeCursor();
            }

            return true;
        } catch (PDOException) {
            return false;
        }
    }

    private function rollbackIfActive(PDO $connection): void
    {
        try {
            if ($connection->inTransaction()) {
                $connection->rollBack();
            }
        } catch (PDOException) {
            // A dead connection mid-transaction cannot be rolled back; it is
            // culled by the next ping-before-dispense probe on its way out.
        }
    }

    private function discard(int $id): void
    {
        unset($this->inUse[$id], $this->statements[$id], $this->issued[$id]);
    }
}
