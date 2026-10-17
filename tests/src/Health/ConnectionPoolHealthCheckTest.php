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
    public function testUpWhenThePoolLeasesAConnectionAndTakesItBack(): void
    {
        $connection = $this->createStub(ConnectionInterface::class);
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
