<?php

declare(strict_types=1);

namespace Waffle\Commons\Data\Health;

use Throwable;
use Waffle\Commons\Contracts\Data\Connection\ConnectionPoolInterface;
use Waffle\Commons\Contracts\Health\HealthCheckInterface;
use Waffle\Commons\Contracts\Health\HealthResult;

/**
 * Probes a connection pool for `/readyz` ([OPS-01], RFC-023 §3.3): it leases one connection, asks it whether
 * it is alive and gives it back. The lease pings on its own unless the pool's freshness window ([CARRY-01])
 * lets a recently proven connection skip the ping, so the probe never trusts the lease alone; it never opens a
 * connection of its own and costs one or two round trips. It serves the relational pool and the Redis pool
 * alike.
 */
final readonly class ConnectionPoolHealthCheck implements HealthCheckInterface
{
    public function __construct(
        private ConnectionPoolInterface $pool,
        private string $name = 'db.pool',
        private bool $critical = true,
    ) {}

    #[\Override]
    public function name(): string
    {
        return $this->name;
    }

    #[\Override]
    public function isCritical(): bool
    {
        return $this->critical;
    }

    #[\Override]
    public function check(): HealthResult
    {
        try {
            $connection = $this->pool->acquire();
        } catch (Throwable $e) {
            // The contract forbids throwing: an exhausted pool, a refused connection or a failed ping is
            // reported as down, with the reason for the private report.
            return HealthResult::down('The pool could not lease a connection: ' . $e->getMessage());
        }

        try {
            $alive = $connection->isAlive();
        } catch (Throwable $e) {
            return HealthResult::down('The leased connection failed its liveness check: ' . $e->getMessage());
        } finally {
            $this->pool->release($connection);
        }

        return $alive ? HealthResult::up() : HealthResult::down('The leased connection failed its liveness check.');
    }
}
