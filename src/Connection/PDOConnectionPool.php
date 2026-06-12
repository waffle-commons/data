<?php

declare(strict_types=1);

namespace Waffle\Commons\Data\Connection;

use Closure;
use PDO;
use PDOException;
use PDOStatement;
use Waffle\Commons\Contracts\Data\Connection\ConnectionKind;
use Waffle\Commons\Contracts\Data\Connection\ConnectionPoolInterface;
use Waffle\Commons\Contracts\Data\Connection\ConnectionTrackerInterface;
use Waffle\Commons\Contracts\Service\ResettableInterface;
use Waffle\Commons\Data\Exception\DatabaseException;

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
 *    transaction, returns borrowed handles to the idle set, and clears the
 *    prepared-statement cache, leaving no per-request state to leak into the
 *    next iteration.
 *
 * All internal maps are keyed by `spl_object_id()` so a connection is tracked by
 * identity and can never be double-counted or double-pooled.
 */
final class PDOConnectionPool implements ConnectionPoolInterface, ResettableInterface
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
     * @throws DatabaseException When no healthy connection can be dispensed.
     */
    #[\Override]
    public function acquire(): PDO
    {
        foreach ($this->idle as $id => $connection) {
            unset($this->idle[$id]);
            if ($this->isAlive($connection)) {
                $this->inUse[$id] = $connection;
                $this->tracker?->trackOpen($this->traceId($id), ConnectionKind::Pdo);

                return $connection;
            }

            // Probe failed: drop the dead handle and any statements bound to it,
            // then fall through to try the next idle connection (or a fresh one).
            $this->discard($id);
        }

        return $this->dispenseFresh();
    }

    #[\Override]
    public function release(PDO $connection): void
    {
        $id = spl_object_id($connection);
        unset($this->inUse[$id]);
        // Re-key on the object id so releasing the same handle twice is a no-op
        // rather than a duplicate idle entry.
        $this->idle[$id] = $connection;
        $this->tracker?->trackClose($this->traceId($id));
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
    public function prepare(PDO $connection, string $sql): PDOStatement
    {
        $id = spl_object_id($connection);
        $cached = $this->statements[$id][$sql] ?? null;
        if ($cached instanceof PDOStatement) {
            return $cached;
        }

        try {
            $statement = $connection->prepare($sql);
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
        foreach ($this->inUse as $id => $connection) {
            $this->idle[$id] = $connection;
        }
        $this->inUse = [];

        foreach ($this->idle as $connection) {
            $this->rollbackIfActive($connection);
        }

        $this->statements = [];
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
        unset($this->inUse[$id], $this->statements[$id]);
    }
}
