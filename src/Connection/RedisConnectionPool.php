<?php

declare(strict_types=1);

namespace Waffle\Commons\Data\Connection;

use Closure;
use Throwable;
use Waffle\Commons\Contracts\Data\Connection\ConnectionInterface;
use Waffle\Commons\Contracts\Data\Connection\ConnectionKind;
use Waffle\Commons\Contracts\Data\Connection\ConnectionTrackerInterface;
use Waffle\Commons\Contracts\Data\Connection\RedisConnectionInterface;
use Waffle\Commons\Contracts\Data\Connection\RedisConnectionPoolInterface;
use Waffle\Commons\Contracts\Service\ResettableInterface;
use Waffle\Commons\Data\Exception\DatabaseException;

use function array_fill_keys;
use function array_keys;
use function count;
use function spl_object_id;
use function sprintf;

/**
 * Stateless, worker-safe pool of reusable Redis client handles (DBAL-01).
 *
 * The Redis counterpart to {@see PDOConnectionPool}: a bounded set of clients is
 * kept warm across requests with the same two guarantees —
 *
 *  - **Heal-on-lease** — every idle client is probed (a `PING`, supplied as the
 *    health-check closure) before it leaves the pool; a client whose socket has
 *    dropped is discarded and transparently replaced.
 *  - **Reset between requests** — {@see self::reset()} returns borrowed handles
 *    to the idle set and, when an `onReset` hook is wired, clears any per-request
 *    client state (`DISCARD`/`UNWATCH`) so nothing leaks into the next iteration.
 *
 * The client is typed as {@see object} throughout so the component never assumes
 * `ext-redis`: the concrete client, its `PING`, and its reset are all injected as
 * closures by the wiring.
 */
final class RedisConnectionPool implements RedisConnectionPoolInterface, ResettableInterface
{
    /**
     * Idle, ready-to-dispense client handles.
     *
     * @var array<int, object>
     */
    private array $idle = [];

    /**
     * Client handles currently borrowed by the caller.
     *
     * @var array<int, object>
     */
    private array $inUse = [];

    /**
     * Underlying-handle ids this pool has issued and not yet discarded (DBAL-03):
     * a set used by {@see self::release()} to reject a lease that did not
     * originate here, so a foreign client is never pooled.
     *
     * @var array<int, true>
     */
    private array $issued = [];

    /**
     * @param Closure(): object         $factory        Produces a freshly connected client.
     * @param Closure(object): bool     $healthCheck    Liveness probe (`PING`); true ⇒ live.
     * @param int                       $maxConnections Hard ceiling on borrowed clients.
     * @param (Closure(object): void)|null $onReset     Optional per-handle reset run on
     *                                                  {@see self::reset()} (e.g. `DISCARD`).
     * @param ?ConnectionTrackerInterface $tracker      DIAG-03 orphaned-connection tracer.
     *
     * @throws DatabaseException When $maxConnections is below 1.
     */
    public function __construct(
        private readonly Closure $factory,
        private readonly Closure $healthCheck,
        private readonly int $maxConnections = 8,
        private readonly ?Closure $onReset = null,
        private readonly ?ConnectionTrackerInterface $tracker = null,
    ) {
        if ($maxConnections < 1) {
            throw new DatabaseException('A connection pool must allow at least one connection.');
        }
    }

    /**
     * Borrow a healthy Redis client lease (heal-on-lease).
     *
     * @throws DatabaseException When no healthy client can be dispensed.
     */
    #[\Override]
    public function acquire(): RedisConnectionInterface
    {
        return new RedisConnection($this->acquireRaw(), $this->healthCheck);
    }

    #[\Override]
    public function release(ConnectionInterface $connection): void
    {
        if (!$connection instanceof RedisConnectionInterface) {
            // Fail-soft: a lease minted by another pool/kind is not ours.
            return;
        }

        $client = $connection->client();
        $id = spl_object_id($client);
        if (($this->issued[$id] ?? false) === false) {
            // DBAL-03: a lease whose client this pool never issued is not ours.
            return;
        }

        unset($this->inUse[$id]);
        $this->idle[$id] = $client;
        $this->tracker?->trackClose($this->traceId($id));
    }

    /**
     * Release request-scoped state at the end of a worker iteration: borrowed
     * handles return to the idle set and any per-handle reset hook runs so an
     * open `MULTI`/`WATCH` never bleeds into the next request.
     */
    #[\Override]
    public function reset(): void
    {
        foreach ($this->inUse as $id => $client) {
            $this->idle[$id] = $client;
        }
        $this->inUse = [];

        // Re-establish the issued-handle set to exactly the warm idle handles:
        // they survive the request and stay ours to reclaim (DBAL-03), while any
        // discarded handle's mark is dropped — so the set never grows unbounded.
        $this->issued = array_fill_keys(array_keys($this->idle), true);

        if ($this->onReset === null) {
            return;
        }

        foreach ($this->idle as $client) {
            try {
                ($this->onReset)($client);
            } catch (Throwable) {
                // DBAL-02: a throwing DISCARD/UNWATCH must never escape reset()
                // (it runs inside Container::reset()). Swallow it and recycle the
                // remaining handles — a client too broken to reset is reaped by
                // the next heal-on-lease probe on its way back out.
            }
        }
    }

    /** Number of warm, idle clients currently held. */
    public function idleCount(): int
    {
        return count($this->idle);
    }

    /** Number of clients currently borrowed by callers. */
    public function activeCount(): int
    {
        return count($this->inUse);
    }

    /**
     * @throws DatabaseException When no healthy client can be dispensed.
     */
    private function acquireRaw(): object
    {
        foreach ($this->idle as $id => $client) {
            unset($this->idle[$id]);
            if (($this->healthCheck)($client)) {
                $this->inUse[$id] = $client;
                $this->issued[$id] = true;
                $this->tracker?->trackOpen($this->traceId($id), ConnectionKind::Redis);

                return $client;
            }

            // Probe failed: the dead client is dropped (already removed from idle,
            // and its issued mark cleared) and we fall through to the next idle
            // handle, or a fresh one.
            unset($this->issued[$id]);
        }

        return $this->dispenseFresh();
    }

    /**
     * @throws DatabaseException When the pool is saturated or the factory fails.
     */
    private function dispenseFresh(): object
    {
        if (count($this->inUse) >= $this->maxConnections) {
            throw new DatabaseException(sprintf(
                'Connection pool exhausted: all %d connections are in use.',
                $this->maxConnections,
            ));
        }

        $client = ($this->factory)();
        $id = spl_object_id($client);
        $this->inUse[$id] = $client;
        $this->issued[$id] = true;
        $this->tracker?->trackOpen($this->traceId($id), ConnectionKind::Redis);

        return $client;
    }

    /**
     * Stable, kind-scoped trace id so the DIAG-03 ledger never confuses a
     * recycled `spl_object_id()` with a handle of another kind.
     */
    private function traceId(int $clientId): string
    {
        return 'redis:' . $clientId;
    }
}
