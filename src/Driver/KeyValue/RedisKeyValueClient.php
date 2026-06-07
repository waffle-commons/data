<?php

declare(strict_types=1);

namespace Waffle\Commons\Data\Driver\KeyValue;

use Redis;
use RedisException;
use Waffle\Commons\Data\Exception\DatabaseException;

use function count;
use function is_array;
use function is_string;

/**
 * Live key-value adapter over the phpredis extension (`ext-redis`).
 *
 * The connected {@see Redis} handle is injected and held `readonly`: this class
 * keeps no mutable state of its own, issues only stateless `GET`/`MGET`/`SET`/
 * `DEL` commands (no transactions, no subscriptions), and rethrows every driver
 * failure as a {@see DatabaseException} per the unified exception strategy
 * (RFC-022 §7.3).
 */
final class RedisKeyValueClient implements KeyValueClientInterface
{
    public function __construct(
        private readonly Redis $redis,
    ) {}

    /**
     * @throws DatabaseException When the Redis call fails.
     */
    #[\Override]
    public function get(string $key): ?string
    {
        try {
            $value = $this->redis->get($key);
        } catch (RedisException $error) {
            throw DatabaseException::fromThrowable($error, 'Redis GET failed.');
        }

        return is_string($value) ? $value : null;
    }

    /**
     * @param non-empty-list<string> $keys
     *
     * @return array<string, string|null>
     *
     * @throws DatabaseException When the Redis call fails or answers with an
     *         unexpected shape.
     */
    #[\Override]
    public function getMany(array $keys): array
    {
        try {
            $values = $this->redis->mGet($keys);
        } catch (RedisException $error) {
            throw DatabaseException::fromThrowable($error, 'Redis MGET failed.');
        }

        if (!is_array($values) || count($values) !== count($keys)) {
            throw new DatabaseException('Redis MGET answered with an unexpected shape.');
        }

        $byKey = [];
        $index = 0;
        foreach ($keys as $key) {
            $value = $values[$index] ?? null;
            $byKey[$key] = is_string($value) ? $value : null;
            ++$index;
        }

        return $byKey;
    }

    /**
     * @throws DatabaseException When the Redis call fails.
     */
    #[\Override]
    public function set(string $key, string $value): void
    {
        try {
            $this->redis->set($key, $value);
        } catch (RedisException $error) {
            throw DatabaseException::fromThrowable($error, 'Redis SET failed.');
        }
    }

    /**
     * @throws DatabaseException When the Redis call fails.
     */
    #[\Override]
    public function delete(string $key): void
    {
        try {
            $this->redis->del($key);
        } catch (RedisException $error) {
            throw DatabaseException::fromThrowable($error, 'Redis DEL failed.');
        }
    }
}
