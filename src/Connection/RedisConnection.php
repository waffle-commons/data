<?php

declare(strict_types=1);

namespace Waffle\Commons\Data\Connection;

use Closure;
use Waffle\Commons\Contracts\Data\Connection\ConnectionKind;
use Waffle\Commons\Contracts\Data\Connection\RedisConnectionInterface;

use function spl_object_id;

/**
 * Request-scoped lease wrapping a pooled Redis client handle.
 *
 * Minted by {@see RedisConnectionPool::acquire()}. The client is typed as
 * {@see object} so the component never hard-depends on `ext-redis`: liveness is
 * delegated to an injected health-check closure (a `PING`), which the wiring
 * supplies for the concrete `\Redis`/`\Relay` client in use.
 */
final readonly class RedisConnection implements RedisConnectionInterface
{
    /**
     * @param object                $client      The pooled Redis client handle.
     * @param Closure(object): bool $healthCheck Liveness probe (e.g. `PING`).
     */
    public function __construct(
        private object $client,
        private Closure $healthCheck,
    ) {}

    #[\Override]
    public function client(): object
    {
        return $this->client;
    }

    #[\Override]
    public function kind(): ConnectionKind
    {
        return ConnectionKind::Redis;
    }

    #[\Override]
    public function id(): int
    {
        return spl_object_id($this->client);
    }

    #[\Override]
    public function isAlive(): bool
    {
        return ($this->healthCheck)($this->client);
    }
}
