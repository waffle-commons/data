<?php

declare(strict_types=1);

namespace WaffleTests\Commons\Data\CircuitBreaker;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use Redis;
use RedisException;
use RuntimeException;
use stdClass;
use Waffle\Commons\Contracts\CircuitBreaker\CircuitBreakerPolicy;
use Waffle\Commons\Contracts\CircuitBreaker\Enum\CircuitState;
use Waffle\Commons\Contracts\Data\Connection\RedisConnectionPoolInterface;
use Waffle\Commons\Contracts\RateLimit\Exception\StateStorageExceptionInterface;
use Waffle\Commons\Data\CircuitBreaker\RedisCircuitStateStorage;
use Waffle\Commons\Data\Connection\RedisConnectionPool;
use WaffleTests\Commons\Data\AbstractTestCase;

#[CoversClass(RedisCircuitStateStorage::class)]
#[RequiresPhpExtension('redis')]
final class RedisCircuitStateStorageTest extends AbstractTestCase
{
    private string $prefix = '';

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->prefix = 'waffle:test:cb:' . bin2hex(random_bytes(6)) . ':';
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

    public function testOpensAtTheThresholdThenRefusesCalls(): void
    {
        $storage = $this->storage();
        $policy = new CircuitBreakerPolicy(50, 4, 60, 30, 1);

        static::assertTrue($storage->acquire('up', $policy)->allowed);
        $storage->record('up', $policy, true);
        $storage->record('up', $policy, false);
        $storage->record('up', $policy, true);
        $opening = $storage->record('up', $policy, false);

        static::assertSame(CircuitState::Open, $opening->state);
        static::assertSame(CircuitState::Closed, $opening->previous);
        static::assertTrue($opening->isTransition());
        $refused = $storage->acquire('up', $policy);
        static::assertFalse($refused->allowed);
        static::assertSame(CircuitState::Open, $refused->state);
    }

    public function testBelowTheMinimumCallsTheCircuitStaysClosed(): void
    {
        $storage = $this->storage();
        $policy = new CircuitBreakerPolicy(50, 5, 60, 30, 1);

        for ($i = 0; $i < 4; $i++) {
            $decision = $storage->record('up', $policy, false);
        }

        static::assertSame(CircuitState::Closed, $decision->state);
        static::assertFalse($decision->isTransition());
    }

    public function testHalfOpensAfterTheCoolDownAndASuccessfulTrialCloses(): void
    {
        $storage = $this->storage();
        $policy = new CircuitBreakerPolicy(100, 1, 60, 1, 1);
        $storage->record('up', $policy, false);

        usleep(1_100_000);
        $trial = $storage->acquire('up', $policy);
        static::assertTrue($trial->allowed);
        static::assertSame(CircuitState::HalfOpen, $trial->state);
        static::assertSame(CircuitState::Open, $trial->previous);
        static::assertFalse($storage->acquire('up', $policy)->allowed, 'one trial only');

        $closing = $storage->record('up', $policy, true);
        static::assertSame(CircuitState::Closed, $closing->state);
        static::assertSame(CircuitState::HalfOpen, $closing->previous);
        static::assertTrue($storage->acquire('up', $policy)->allowed);
    }

    public function testAFailedTrialOpensTheCircuitAgain(): void
    {
        $storage = $this->storage();
        $policy = new CircuitBreakerPolicy(100, 1, 60, 1, 1);
        $storage->record('up', $policy, false);
        usleep(1_100_000);
        $storage->acquire('up', $policy);

        $reopening = $storage->record('up', $policy, false);

        static::assertSame(CircuitState::Open, $reopening->state);
        static::assertSame(CircuitState::HalfOpen, $reopening->previous);
        static::assertFalse($storage->acquire('up', $policy)->allowed);
        static::assertSame(
            CircuitState::Open,
            $storage->record('up', $policy, true)->state,
            'a late result changes nothing',
        );
    }

    public function testFailuresOutsideTheWindowAreForgotten(): void
    {
        $storage = $this->storage();
        $policy = new CircuitBreakerPolicy(100, 2, 1, 30, 1);
        $storage->record('up', $policy, false);
        usleep(1_100_000);

        static::assertSame(CircuitState::Closed, $storage->record('up', $policy, false)->state);
    }

    public function testAnUnreachablePoolIsAStorageFailure(): void
    {
        $pool = $this->createStub(RedisConnectionPoolInterface::class);
        $pool->method('acquire')->willThrowException(new RuntimeException('Connection refused'));

        $this->expectException(StateStorageExceptionInterface::class);

        new RedisCircuitStateStorage($pool)->acquire('up', new CircuitBreakerPolicy());
    }

    public function testAnotherClientIsAStorageFailure(): void
    {
        $pool = new RedisConnectionPool(static fn(): object => new stdClass(), static fn(): bool => true);

        $this->expectException(StateStorageExceptionInterface::class);

        new RedisCircuitStateStorage($pool)->record('up', new CircuitBreakerPolicy(), true);
    }

    private function storage(): RedisCircuitStateStorage
    {
        self::connect();

        return new RedisCircuitStateStorage(
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
