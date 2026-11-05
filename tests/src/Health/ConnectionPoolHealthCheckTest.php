<?php

declare(strict_types=1);

namespace WaffleTests\Commons\Data\Health;

use PHPUnit\Framework\Attributes\CoversClass;
use RuntimeException;
use Waffle\Commons\Contracts\Data\Connection\ConnectionInterface;
use Waffle\Commons\Contracts\Data\Connection\ConnectionPoolInterface;
use Waffle\Commons\Contracts\Health\Enum\HealthStatus;
use Waffle\Commons\Data\Health\ConnectionPoolHealthCheck;
use WaffleTests\Commons\Data\AbstractTestCase;

#[CoversClass(ConnectionPoolHealthCheck::class)]
final class ConnectionPoolHealthCheckTest extends AbstractTestCase
{
    public function testUpWhenThePoolLeasesALiveConnectionAndTakesItBack(): void
    {
        $connection = $this->createStub(ConnectionInterface::class);
        $connection->method('isAlive')->willReturn(true);
        $pool = $this->createMock(ConnectionPoolInterface::class);
        $pool->expects($this->once())->method('acquire')->willReturn($connection);
        $pool->expects($this->once())->method('release')->with($connection);

        $result = new ConnectionPoolHealthCheck($pool)->check();

        static::assertSame(HealthStatus::Up, $result->status);
    }

    public function testDownWithTheReasonWhenThePoolCannotLease(): void
    {
        $pool = $this->createMock(ConnectionPoolInterface::class);
        $pool
            ->expects($this->once())
            ->method('acquire')
            ->willThrowException(new RuntimeException('Connection refused'));
        $pool->expects($this->never())->method('release');

        $result = new ConnectionPoolHealthCheck($pool)->check();

        static::assertSame(HealthStatus::Down, $result->status);
        static::assertStringContainsString('Connection refused', $result->detail);
    }

    /**
     * [CARRY-01]: inside the pool's freshness window a lease skips its ping, so a lease alone no longer
     * proves the backend reachable — the probe asks the connection and still gives it back.
     */
    public function testDownWhenTheLeasedConnectionIsDead(): void
    {
        $connection = $this->createStub(ConnectionInterface::class);
        $connection->method('isAlive')->willReturn(false);
        $pool = $this->createMock(ConnectionPoolInterface::class);
        $pool->expects($this->once())->method('acquire')->willReturn($connection);
        $pool->expects($this->once())->method('release')->with($connection);

        $result = new ConnectionPoolHealthCheck($pool)->check();

        static::assertSame(HealthStatus::Down, $result->status);
        static::assertStringContainsString('liveness check', $result->detail);
    }

    public function testDownWithTheReasonWhenTheLivenessCheckThrows(): void
    {
        $connection = $this->createStub(ConnectionInterface::class);
        $connection->method('isAlive')->willThrowException(new RuntimeException('PING timed out'));
        $pool = $this->createMock(ConnectionPoolInterface::class);
        $pool->expects($this->once())->method('acquire')->willReturn($connection);
        $pool->expects($this->once())->method('release')->with($connection);

        $result = new ConnectionPoolHealthCheck($pool)->check();

        static::assertSame(HealthStatus::Down, $result->status);
        static::assertStringContainsString('PING timed out', $result->detail);
    }

    public function testNamedCriticalByDefaultAndConfigurable(): void
    {
        $pool = $this->createStub(ConnectionPoolInterface::class);

        $default = new ConnectionPoolHealthCheck($pool);
        static::assertSame('db.pool', $default->name());
        static::assertTrue($default->isCritical());

        $redis = new ConnectionPoolHealthCheck($pool, 'redis', critical: false);
        static::assertSame('redis', $redis->name());
        static::assertFalse($redis->isCritical());
    }
}
