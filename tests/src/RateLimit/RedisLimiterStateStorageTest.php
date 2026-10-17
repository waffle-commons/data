<?php

declare(strict_types=1);

namespace WaffleTests\Commons\Data\RateLimit;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use Redis;
use RedisException;
use RuntimeException;
use stdClass;
use Waffle\Commons\Contracts\Data\Connection\RedisConnectionPoolInterface;
use Waffle\Commons\Contracts\RateLimit\Exception\StateStorageExceptionInterface;
use Waffle\Commons\Contracts\RateLimit\TokenBucket;
use Waffle\Commons\Data\Connection\RedisConnectionPool;
use Waffle\Commons\Data\RateLimit\RedisLimiterStateStorage;
use Waffle\Commons\Data\RateLimit\StateStorageException;
use WaffleTests\Commons\Data\AbstractTestCase;

#[CoversClass(RedisLimiterStateStorage::class)]
#[CoversClass(StateStorageException::class)]
#[RequiresPhpExtension('redis')]
final class RedisLimiterStateStorageTest extends AbstractTestCase
{
    private string $prefix = '';

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->prefix = 'waffle:test:rl:' . bin2hex(random_bytes(6)) . ':';
    }

    #[\Override]
    protected function tearDown(): void
    {
        $redis = self::connect();
        $keys = $redis->keys($this->prefix . '*');
        if (is_array($keys) && $keys !== []) {
            $redis->del($keys);
        }

        parent::tearDown();
    }

    public function testAFreshBucketStartsFullAndCountsDown(): void
    {
        $storage = $this->storage();
        $bucket = new TokenBucket(3, 60);

        $first = $storage->consume('ip:203.0.113.7', $bucket, 1);

        static::assertTrue($first->allowed);
        static::assertSame(3, $first->limit);
        static::assertSame(2, $first->remaining);
        static::assertGreaterThanOrEqual(1, $first->resetSeconds);
        static::assertSame(0, $first->retryAfterSeconds);
        static::assertTrue($first->enforced);
    }

    public function testAnEmptyBucketDeniesWithARetryAfter(): void
    {
        $storage = $this->storage();
        $bucket = new TokenBucket(2, 60);

        $storage->consume('login:alice', $bucket, 1);
        $storage->consume('login:alice', $bucket, 1);
        $denied = $storage->consume('login:alice', $bucket, 1);

        static::assertFalse($denied->allowed);
        static::assertSame(0, $denied->remaining);
        static::assertGreaterThanOrEqual(1, $denied->retryAfterSeconds);
        static::assertLessThanOrEqual(30, $denied->retryAfterSeconds);
    }

    public function testTheBucketRefillsWithTime(): void
    {
        $storage = $this->storage();
        $bucket = new TokenBucket(1, 1);

        static::assertTrue($storage->consume('k', $bucket, 1)->allowed);
        static::assertFalse($storage->consume('k', $bucket, 1)->allowed);
        usleep(1_100_000);
        static::assertTrue($storage->consume('k', $bucket, 1)->allowed);
    }

    public function testKeysAreIsolated(): void
    {
        $storage = $this->storage();
        $bucket = new TokenBucket(1, 60);

        static::assertTrue($storage->consume('a', $bucket, 1)->allowed);
        static::assertTrue($storage->consume('b', $bucket, 1)->allowed);
    }

    public function testRefusesAnEmptyKey(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->storage()->consume('', new TokenBucket(1, 60), 1);
    }

    public function testRefusesACostLargerThanTheBucket(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->storage()->consume('k', new TokenBucket(2, 60), 3);
    }

    public function testAnUnreachablePoolIsAStorageFailure(): void
    {
        $pool = $this->createStub(RedisConnectionPoolInterface::class);
        $pool->method('acquire')->willThrowException(new RuntimeException('Connection refused'));

        $this->expectException(StateStorageExceptionInterface::class);
        $this->expectExceptionMessage('Connection refused');

        new RedisLimiterStateStorage($pool)->consume('k', new TokenBucket(1, 60), 1);
    }

    public function testAnotherClientIsAStorageFailure(): void
    {
        $pool = new RedisConnectionPool(static fn(): object => new stdClass(), static fn(): bool => true);

        $this->expectException(StateStorageExceptionInterface::class);
        $this->expectExceptionMessage('ext-redis');

        new RedisLimiterStateStorage($pool)->consume('k', new TokenBucket(1, 60), 1);
    }

    private function storage(): RedisLimiterStateStorage
    {
        self::connect();

        return new RedisLimiterStateStorage(
            new RedisConnectionPool(self::connect(...), static fn(): bool => true),
            $this->prefix,
        );
    }

    private static function connect(): Redis
    {
        $host = getenv('WAFFLE_TEST_REDIS_HOST');
        $redis = new Redis();
        try {
            $redis->connect(is_string($host) && $host !== '' ? $host : 'waffle-redis', 6379, 1.5);
        } catch (RedisException) {
            self::markTestSkipped('No reachable Redis instance for the integration test.');
        }

        return $redis;
    }
}
