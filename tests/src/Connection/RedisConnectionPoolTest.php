<?php

declare(strict_types=1);

namespace WaffleTests\Commons\Data\Connection;

use Closure;
use PHPUnit\Framework\Attributes\CoversClass;
use Waffle\Commons\Contracts\Data\Connection\ConnectionKind;
use Waffle\Commons\Contracts\Data\Connection\ConnectionTrackerInterface;
use Waffle\Commons\Data\Connection\RedisConnection;
use Waffle\Commons\Data\Connection\RedisConnectionPool;
use Waffle\Commons\Data\Exception\DatabaseException;
use WaffleTests\Commons\Data\AbstractTestCase;
use WaffleTests\Commons\Data\Fixture\FakeRedisClient;

#[CoversClass(RedisConnectionPool::class)]
#[CoversClass(RedisConnection::class)]
final class RedisConnectionPoolTest extends AbstractTestCase
{
    /**
     * @return Closure(): object
     */
    private function clientFactory(): Closure
    {
        return static fn(): object => new FakeRedisClient();
    }

    /**
     * @return Closure(object): bool
     */
    private function pingCheck(): Closure
    {
        return static fn(object $client): bool => $client instanceof FakeRedisClient && $client->alive;
    }

    public function testConstructorRejectsNonPositiveCeiling(): void
    {
        $this->expectException(DatabaseException::class);

        new RedisConnectionPool($this->clientFactory(), $this->pingCheck(), 0);
    }

    public function testAcquireDispensesFreshClient(): void
    {
        $pool = new RedisConnectionPool($this->clientFactory(), $this->pingCheck());

        $lease = $pool->acquire();

        self::assertSame(ConnectionKind::Redis, $lease->kind());
        self::assertTrue($lease->isAlive());
        self::assertInstanceOf(FakeRedisClient::class, $lease->client());
        self::assertSame(1, $pool->activeCount());
        self::assertSame(0, $pool->idleCount());
    }

    public function testReleaseReturnsClientToIdleSet(): void
    {
        $pool = new RedisConnectionPool($this->clientFactory(), $this->pingCheck());

        $pool->release($pool->acquire());

        self::assertSame(0, $pool->activeCount());
        self::assertSame(1, $pool->idleCount());
    }

    public function testHealthyIdleClientIsReused(): void
    {
        $pool = new RedisConnectionPool($this->clientFactory(), $this->pingCheck());

        $first = $pool->acquire();
        $pool->release($first);
        $second = $pool->acquire();

        self::assertSame($first->client(), $second->client());
        self::assertSame($first->id(), $second->id());
    }

    public function testDeadIdleClientIsRecycledAndReconnected(): void
    {
        $pool = new RedisConnectionPool($this->clientFactory(), $this->pingCheck());

        $dead = $pool->acquire();
        $client = $dead->client();
        $pool->release($dead);

        // Simulate a dropped socket on the idle handle.
        self::assertInstanceOf(FakeRedisClient::class, $client);
        $client->alive = false;

        $replacement = $pool->acquire();

        self::assertNotSame($dead->client(), $replacement->client());
        self::assertSame(0, $pool->idleCount());
        self::assertSame(1, $pool->activeCount());
    }

    public function testResetReturnsBorrowedHandlesAndRunsResetHook(): void
    {
        $resets = 0;
        $onReset = static function (object $client) use (&$resets): void {
            if ($client instanceof FakeRedisClient) {
                ++$client->resetCount;
                ++$resets;
            }
        };
        $pool = new RedisConnectionPool($this->clientFactory(), $this->pingCheck(), 8, $onReset);

        $lease = $pool->acquire();
        $pool->reset();

        self::assertSame(0, $pool->activeCount());
        self::assertSame(1, $pool->idleCount());
        self::assertSame(1, $resets);
        $client = $lease->client();
        self::assertInstanceOf(FakeRedisClient::class, $client);
        self::assertSame(1, $client->resetCount);
    }

    public function testResetWithoutResetHookIsSafe(): void
    {
        $pool = new RedisConnectionPool($this->clientFactory(), $this->pingCheck());

        $pool->acquire();
        $pool->reset();

        self::assertSame(0, $pool->activeCount());
        self::assertSame(1, $pool->idleCount());
    }

    public function testPoolExhaustionThrows(): void
    {
        $pool = new RedisConnectionPool($this->clientFactory(), $this->pingCheck(), 1);
        $pool->acquire();

        $this->expectException(DatabaseException::class);

        $pool->acquire();
    }

    public function testReleasingForeignLeaseIsIgnored(): void
    {
        $pool = new RedisConnectionPool($this->clientFactory(), $this->pingCheck());

        // DBAL-03/04: a lease wrapping a client this pool never issued must be
        // silently ignored, NOT pooled — the idle set stays empty.
        $foreign = new RedisConnection(new FakeRedisClient(), $this->pingCheck());
        $pool->release($foreign);

        self::assertSame(0, $pool->idleCount());
        self::assertSame(0, $pool->activeCount());
    }

    public function testResetSwallowsAThrowingResetHookAndStillRecyclesAllHandles(): void
    {
        // DBAL-02: a DISCARD/UNWATCH that throws must not escape reset() (it runs
        // inside Container::reset()); every borrowed handle must still recycle.
        // The hook records that it ran on each client *before* throwing.
        $onReset = static function (object $client): void {
            if ($client instanceof FakeRedisClient) {
                ++$client->resetCount;
            }

            throw new DatabaseException('DISCARD failed on a half-broken socket.');
        };
        $pool = new RedisConnectionPool($this->clientFactory(), $this->pingCheck(), 8, $onReset);

        $first = $pool->acquire();
        $second = $pool->acquire();
        self::assertNotSame($first->client(), $second->client());

        // Must NOT throw despite every onReset call throwing.
        $pool->reset();

        // Both handles recycled, and the hook was attempted on each (not aborted
        // after the first throw).
        self::assertSame(0, $pool->activeCount());
        self::assertSame(2, $pool->idleCount());
        $firstClient = $first->client();
        $secondClient = $second->client();
        self::assertInstanceOf(FakeRedisClient::class, $firstClient);
        self::assertInstanceOf(FakeRedisClient::class, $secondClient);
        self::assertSame(1, $firstClient->resetCount);
        self::assertSame(1, $secondClient->resetCount);
    }

    public function testWarmClientStaysOursToReclaimAcrossReset(): void
    {
        $pool = new RedisConnectionPool($this->clientFactory(), $this->pingCheck());

        // Borrow, then reset (end of request): the client returns to idle and
        // must remain recognised as issued by this pool.
        $pool->acquire();
        $pool->reset();
        self::assertSame(1, $pool->idleCount());

        // Next request reuses the warm client and releasing it must re-pool it
        // (the DBAL-03 issued set was preserved across reset, not wiped).
        $reused = $pool->acquire();
        $pool->release($reused);

        self::assertSame(0, $pool->activeCount());
        self::assertSame(1, $pool->idleCount());
    }

    public function testAcquireTracksAnOpenConnection(): void
    {
        $tracker = $this->recordingTracker();
        $pool = new RedisConnectionPool($this->clientFactory(), $this->pingCheck(), tracker: $tracker);

        $pool->acquire();

        $open = $tracker->openConnections();
        self::assertCount(1, $open);
        self::assertSame(ConnectionKind::Redis, $open[0]['kind'] ?? null);
    }

    public function testReleaseTracksTheConnectionClosed(): void
    {
        $tracker = $this->recordingTracker();
        $pool = new RedisConnectionPool($this->clientFactory(), $this->pingCheck(), tracker: $tracker);

        $pool->release($pool->acquire());

        self::assertSame([], $tracker->openConnections());
    }

    public function testReusedIdleConnectionIsTrackedOnReacquire(): void
    {
        $tracker = $this->recordingTracker();
        $pool = new RedisConnectionPool($this->clientFactory(), $this->pingCheck(), tracker: $tracker);

        $pool->release($pool->acquire());
        self::assertSame([], $tracker->openConnections());

        $pool->acquire();
        self::assertCount(1, $tracker->openConnections());
    }

    private function recordingTracker(): ConnectionTrackerInterface
    {
        return new class implements ConnectionTrackerInterface {
            /** @var array<string, ConnectionKind> */
            private array $open = [];

            #[\Override]
            public function trackOpen(string $id, ConnectionKind $kind): void
            {
                $this->open[$id] = $kind;
            }

            #[\Override]
            public function trackClose(string $id): void
            {
                unset($this->open[$id]);
            }

            #[\Override]
            public function openConnections(): array
            {
                $connections = [];
                foreach ($this->open as $id => $kind) {
                    $connections[] = ['id' => $id, 'kind' => $kind];
                }

                return $connections;
            }

            #[\Override]
            public function reset(): void
            {
                $this->open = [];
            }
        };
    }
}
