<?php

declare(strict_types=1);

namespace WaffleTests\Commons\Data\Fixture;

use Redis;
use Waffle\Commons\Contracts\RateLimit\TokenBucket;
use Waffle\Commons\Data\Connection\RedisConnectionPool;
use Waffle\Commons\Data\RateLimit\RedisLimiterStateStorage;

/**
 * One worker of RedisLimiterConcurrencyTest, run in its own PHP process: it connects to Redis first, waits
 * for the instant every worker shares, then takes one token at a time from the same bucket.
 */
final class LimiterWorker
{
    /**
     * @return string "<allowed calls> <1 when the worker was waiting before the shared instant, else 0>"
     */
    public static function run(string $host, string $key, int $capacity, int $attempts, float $startAt): string
    {
        $redis = new Redis();
        $redis->connect($host, 6379, 1.5);
        $storage = new RedisLimiterStateStorage(
            new RedisConnectionPool(static fn(): Redis => $redis, static fn(): bool => true),
        );
        // The whole bucket takes a day to refill, so no token comes back during the run.
        $bucket = new TokenBucket($capacity, 86_400);

        $waited = microtime(true) < $startAt;
        while (microtime(true) < $startAt) {
            usleep(100);
        }

        $allowed = 0;
        for ($call = 0; $call < $attempts; $call++) {
            $allowed += $storage->consume($key, $bucket, 1)->allowed ? 1 : 0;
        }

        return sprintf('%d %d', $allowed, $waited ? 1 : 0);
    }
}
