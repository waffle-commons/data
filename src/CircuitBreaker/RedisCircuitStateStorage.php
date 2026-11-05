<?php

declare(strict_types=1);

namespace Waffle\Commons\Data\CircuitBreaker;

use Redis;
use Throwable;
use Waffle\Commons\Contracts\CircuitBreaker\CircuitBreakerPolicy;
use Waffle\Commons\Contracts\CircuitBreaker\CircuitDecision;
use Waffle\Commons\Contracts\CircuitBreaker\CircuitStateStorageInterface;
use Waffle\Commons\Contracts\CircuitBreaker\Enum\CircuitState;
use Waffle\Commons\Contracts\Data\Connection\RedisConnectionPoolInterface;
use Waffle\Commons\Data\RateLimit\StateStorageException;

/**
 * Circuit breakers in Redis ([NET-03], RFC-024 §3.2, §5.1): each operation is one Lua script on the Redis
 * server clock, so every worker agrees on each circuit and on each transition.
 *
 * A circuit is a hash: `state`, `opened_at`, `trials` (half-open admissions) and `trial_at` (the last one),
 * and the failure window (`window_start`, `calls`, `failures`). It expires once nothing can depend on it any
 * more.
 *
 * Half-open trials that record no outcome within a cool-down give their slots back: a worker that died
 * mid-call, or a call whose outcome says nothing about the upstream, never holds the circuit half-open,
 * refusing every call, until the hash expires.
 */
final readonly class RedisCircuitStateStorage implements CircuitStateStorageInterface
{
    public const string DEFAULT_PREFIX = 'waffle:circuit:';

    private const string ACQUIRE = <<<'LUA'
        local t = redis.call('TIME')
        local now = tonumber(t[1]) + tonumber(t[2]) / 1000000
        local cool_down = tonumber(ARGV[1])
        local trials_max = tonumber(ARGV[2])
        local ttl = tonumber(ARGV[3])
        local s = redis.call('HMGET', KEYS[1], 'state', 'opened_at', 'trials', 'trial_at')
        local state = s[1] or 'closed'
        local previous = ''
        if state == 'open' then
            if now < (tonumber(s[2]) or 0) + cool_down then
                return {0, 'open', ''}
            end
            state = 'half_open'
            previous = 'open'
            redis.call('HSET', KEYS[1], 'state', 'half_open', 'trials', 0)
            s[3] = '0'
        end
        if state == 'closed' then
            return {1, 'closed', previous}
        end
        local trials = tonumber(s[3]) or 0
        if trials >= trials_max and now >= (tonumber(s[4]) or 0) + cool_down then
            trials = 0
        end
        if trials < trials_max then
            redis.call('HSET', KEYS[1], 'trials', trials + 1, 'trial_at', tostring(now))
            redis.call('EXPIRE', KEYS[1], ttl)
            return {1, 'half_open', previous}
        end
        return {0, 'half_open', previous}
        LUA;

    private const string RECORD = <<<'LUA'
        local t = redis.call('TIME')
        local now = tonumber(t[1]) + tonumber(t[2]) / 1000000
        local success = ARGV[1] == '1'
        local rate = tonumber(ARGV[2])
        local minimum = tonumber(ARGV[3])
        local window = tonumber(ARGV[4])
        local ttl = tonumber(ARGV[5])
        local s = redis.call('HMGET', KEYS[1], 'state', 'window_start', 'calls', 'failures')
        local state = s[1] or 'closed'
        redis.call('EXPIRE', KEYS[1], ttl)
        if state == 'half_open' then
            if success then
                redis.call('HSET', KEYS[1], 'state', 'closed', 'trials', 0, 'calls', 0, 'failures', 0, 'window_start', tostring(now))
                return {1, 'closed', 'half_open'}
            end
            redis.call('HSET', KEYS[1], 'state', 'open', 'opened_at', tostring(now), 'trials', 0)
            return {1, 'open', 'half_open'}
        end
        if state == 'open' then
            return {1, 'open', ''}
        end
        local window_start = tonumber(s[2]) or now
        local calls = tonumber(s[3]) or 0
        local failures = tonumber(s[4]) or 0
        if now - window_start >= window then
            window_start = now
            calls = 0
            failures = 0
        end
        calls = calls + 1
        if not success then
            failures = failures + 1
        end
        if calls >= minimum and failures * 100 >= rate * calls then
            redis.call('HSET', KEYS[1], 'state', 'open', 'opened_at', tostring(now), 'calls', 0, 'failures', 0, 'window_start', tostring(now))
            return {1, 'open', 'closed'}
        end
        redis.call('HSET', KEYS[1], 'state', 'closed', 'calls', calls, 'failures', failures, 'window_start', tostring(window_start))
        return {1, 'closed', ''}
        LUA;

    public function __construct(
        private RedisConnectionPoolInterface $pool,
        private string $prefix = self::DEFAULT_PREFIX,
    ) {}

    /** @throws StateStorageException */
    #[\Override]
    public function acquire(string $circuit, CircuitBreakerPolicy $policy): CircuitDecision
    {
        return $this->run(self::ACQUIRE, $circuit, [
            $policy->coolDownSeconds,
            $policy->halfOpenTrials,
            self::ttl($policy),
        ]);
    }

    /** @throws StateStorageException */
    #[\Override]
    public function record(string $circuit, CircuitBreakerPolicy $policy, bool $success): CircuitDecision
    {
        return $this->run(self::RECORD, $circuit, [
            $success ? '1' : '0',
            $policy->failureRatePercent,
            $policy->minimumCalls,
            $policy->windowSeconds,
            self::ttl($policy),
        ]);
    }

    private static function ttl(CircuitBreakerPolicy $policy): int
    {
        return $policy->windowSeconds + $policy->coolDownSeconds + 60;
    }

    /**
     * @param list<int|string> $arguments
     *
     * @throws StateStorageException
     */
    private function run(string $script, string $circuit, array $arguments): CircuitDecision
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
            $reply = [$client->eval($script, [$this->prefix . $circuit, ...$arguments], 1)];
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

        $state = CircuitState::tryFrom(self::stringAt($reply[0], 1));
        if ($state === null) {
            throw StateStorageException::unexpectedReply();
        }

        $previous = self::stringAt($reply[0], 2);

        return new CircuitDecision(
            self::intAt($reply[0], 0) === 1,
            $state,
            $previous === '' ? null : CircuitState::tryFrom($previous),
        );
    }

    /** @throws StateStorageException */
    private static function intAt(array $reply, int $index): int
    {
        if (!array_key_exists($index, $reply) || !is_int($reply[$index])) {
            throw StateStorageException::unexpectedReply();
        }

        return $reply[$index];
    }

    /** @throws StateStorageException */
    private static function stringAt(array $reply, int $index): string
    {
        if (!array_key_exists($index, $reply) || !is_string($reply[$index])) {
            throw StateStorageException::unexpectedReply();
        }

        return $reply[$index];
    }
}
