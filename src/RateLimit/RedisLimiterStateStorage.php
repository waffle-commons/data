<?php

declare(strict_types=1);

namespace Waffle\Commons\Data\RateLimit;

use InvalidArgumentException;
use Redis;
use Throwable;
use Waffle\Commons\Contracts\Data\Connection\RedisConnectionPoolInterface;
use Waffle\Commons\Contracts\RateLimit\LimiterStateStorageInterface;
use Waffle\Commons\Contracts\RateLimit\RateLimitDecision;
use Waffle\Commons\Contracts\RateLimit\TokenBucket;

/**
 * Token buckets in Redis ([NET-01], RFC-024 §5.1): one Lua script refills and takes in a single atomic
 * step, so concurrent workers on the same key never admit more calls than the bucket holds.
 *
 * The script reads the Redis server clock (`TIME`), so workers on hosts whose clocks drift agree on every
 * bucket. A bucket is a hash (`tokens`, `ts`) that expires once it would be full again. It leases a
 * connection from the framework's Redis pool for each call and gives it back.
 */
final readonly class RedisLimiterStateStorage implements LimiterStateStorageInterface
{
    public const string DEFAULT_PREFIX = 'waffle:ratelimit:';

    private const string SCRIPT = <<<'LUA'
        local now_parts = redis.call('TIME')
        local now = tonumber(now_parts[1]) + tonumber(now_parts[2]) / 1000000
        local capacity = tonumber(ARGV[1])
        local period = tonumber(ARGV[2])
        local cost = tonumber(ARGV[3])
        local rate = capacity / period
        local state = redis.call('HMGET', KEYS[1], 'tokens', 'ts')
        local tokens = tonumber(state[1])
        local ts = tonumber(state[2])
        if tokens == nil or ts == nil then
            tokens = capacity
            ts = now
        end
        tokens = math.min(capacity, tokens + math.max(0, now - ts) * rate)
        local allowed = 0
        if tokens >= cost then
            tokens = tokens - cost
            allowed = 1
        end
        redis.call('HSET', KEYS[1], 'tokens', tostring(tokens), 'ts', tostring(now))
        redis.call('EXPIRE', KEYS[1], math.ceil(period) + 1)
        local retry = 0
        if allowed == 0 then
            retry = math.ceil((cost - tokens) / rate)
        end
        return {allowed, math.floor(tokens), math.ceil((capacity - tokens) / rate), retry}
        LUA;

    public function __construct(
        private RedisConnectionPoolInterface $pool,
        private string $prefix = self::DEFAULT_PREFIX,
    ) {}

    /**
     * @throws InvalidArgumentException When the key is empty or the cost does not fit the bucket.
     * @throws StateStorageException    When Redis is unreachable or its reply is unreadable.
     */
    #[\Override]
    public function consume(string $key, TokenBucket $bucket, int $cost): RateLimitDecision
    {
        if ($key === '') {
            throw new InvalidArgumentException('A rate-limit key must not be empty.');
        }
        if ($cost < 1 || $cost > $bucket->capacity) {
            throw new InvalidArgumentException('A rate-limit cost must be between 1 and the bucket capacity.');
        }

        $reply = $this->run($this->prefix . $key, $bucket, $cost);

        return new RateLimitDecision(
            $reply[0] === 1,
            $bucket->capacity,
            min($bucket->capacity, max(0, $reply[1])),
            max(0, $reply[2]),
            $reply[0] === 1 ? 0 : max(1, $reply[3]),
        );
    }

    /**
     * @return array{int, int, int, int}
     *
     * @throws StateStorageException
     */
    private function run(string $key, TokenBucket $bucket, int $cost): array
    {
        try {
            $lease = $this->pool->acquire();
        } catch (Throwable $e) {
            throw StateStorageException::transport($e);
        }

        try {
            $client = $lease->client();
            if (!$client instanceof Redis) {
                throw StateStorageException::unexpectedClient();
            }

            // The untyped reply stays inside an array and is narrowed in place, never bound to a variable.
            $reply = [$client->eval(self::SCRIPT, [$key, $bucket->capacity, $bucket->periodSeconds, $cost], 1)];
        } catch (StateStorageException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw StateStorageException::transport($e);
        } finally {
            $this->pool->release($lease);
        }

        if (!is_array($reply[0])) {
            throw StateStorageException::unexpectedReply();
        }

        return [
            self::intAt($reply[0], 0),
            self::intAt($reply[0], 1),
            self::intAt($reply[0], 2),
            self::intAt($reply[0], 3),
        ];
    }

    /**
     * @throws StateStorageException When the reply has no integer at `$index`.
     */
    private static function intAt(array $reply, int $index): int
    {
        if (!array_key_exists($index, $reply) || !is_int($reply[$index])) {
            throw StateStorageException::unexpectedReply();
        }

        return $reply[$index];
    }
}
