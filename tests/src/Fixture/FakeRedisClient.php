<?php

declare(strict_types=1);

namespace WaffleTests\Commons\Data\Fixture;

/**
 * A stand-in Redis client for {@see \Waffle\Commons\Data\Connection\RedisConnectionPool}
 * tests: it carries a controllable liveness flag and counts reset hooks, so the
 * pool can be exercised without `ext-redis`.
 */
final class FakeRedisClient
{
    public bool $alive = true;

    public int $resetCount = 0;
}
