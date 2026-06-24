<?php

declare(strict_types=1);

namespace WaffleTests\Commons\Data\Connection;

use Closure;
use PDO;
use PDOException;
use PHPUnit\Framework\Attributes\CoversClass;
use Waffle\Commons\Contracts\Data\Connection\ConnectionKind;
use Waffle\Commons\Contracts\Data\Connection\ConnectionTrackerInterface;
use Waffle\Commons\Data\Connection\PdoConnection;
use Waffle\Commons\Data\Connection\PDOConnectionPool;
use Waffle\Commons\Data\Connection\RedisConnection;
use Waffle\Commons\Data\Exception\DatabaseException;
use WaffleTests\Commons\Data\AbstractTestCase;

#[CoversClass(PDOConnectionPool::class)]
#[CoversClass(PdoConnection::class)]
final class PDOConnectionPoolTest extends AbstractTestCase
{
    /**
     * @return Closure(): PDO
     */
    private function sqliteFactory(): Closure
    {
        return static fn(): PDO => new PDO('sqlite::memory:');
    }

    public function testConstructorRejectsNonPositiveCeiling(): void
    {
        $this->expectException(DatabaseException::class);

        new PDOConnectionPool($this->sqliteFactory(), 0);
    }

    public function testAcquireDispensesFreshConnection(): void
    {
        $pool = new PDOConnectionPool($this->sqliteFactory());

        $lease = $pool->acquire();
        $statement = $lease->pdo()->query('SELECT 1');

        self::assertSame(ConnectionKind::Pdo, $lease->kind());
        self::assertTrue($lease->isAlive());
        self::assertSame(1, $pool->activeCount());
        self::assertSame(0, $pool->idleCount());
        self::assertNotFalse($statement);
        self::assertSame(1, (int) $statement->fetchColumn());
    }

    public function testReleaseReturnsConnectionToIdleSet(): void
    {
        $pool = new PDOConnectionPool($this->sqliteFactory());

        $pool->release($pool->acquire());

        self::assertSame(0, $pool->activeCount());
        self::assertSame(1, $pool->idleCount());
    }

    public function testReleasingTwiceDoesNotDoublePool(): void
    {
        $pool = new PDOConnectionPool($this->sqliteFactory());

        $lease = $pool->acquire();
        $pool->release($lease);
        $pool->release($lease);

        self::assertSame(1, $pool->idleCount());
    }

    public function testHealthyIdleConnectionIsReused(): void
    {
        $pool = new PDOConnectionPool($this->sqliteFactory());

        $first = $pool->acquire();
        $pool->release($first);
        $second = $pool->acquire();

        // The warm connection passed its ping and was handed back out: the lease
        // wrapper differs, but it wraps the same underlying PDO handle.
        self::assertSame($first->pdo(), $second->pdo());
        self::assertSame($first->id(), $second->id());
    }

    public function testDeadIdleConnectionIsRecycledAndReconnected(): void
    {
        // The probe targets a table no fresh connection has, so any idle
        // connection fails its ping and must be transparently replaced.
        $pool = new PDOConnectionPool($this->sqliteFactory(), 8, 'SELECT 1 FROM ping_probe');

        $dead = $pool->acquire();
        $pool->release($dead);
        $replacement = $pool->acquire();

        self::assertNotSame($dead->pdo(), $replacement->pdo());
        self::assertSame(0, $pool->idleCount());
        self::assertSame(1, $pool->activeCount());
    }

    public function testResetRollsBackDanglingTransactionAndRecycles(): void
    {
        $pool = new PDOConnectionPool($this->sqliteFactory());

        $lease = $pool->acquire();
        $pdo = $lease->pdo();
        $pdo->exec('CREATE TABLE t (id INTEGER PRIMARY KEY)');
        $pdo->beginTransaction();
        $pdo->exec('INSERT INTO t (id) VALUES (1)');
        self::assertTrue($pdo->inTransaction());

        $pool->reset();

        self::assertFalse($pdo->inTransaction());
        self::assertSame(0, $pool->activeCount());
        self::assertSame(1, $pool->idleCount());

        $reused = $pool->acquire();
        $count = $reused->pdo()->query('SELECT COUNT(*) FROM t');
        self::assertNotFalse($count);
        self::assertSame(0, (int) $count->fetchColumn());
    }

    public function testResetWithoutTransactionIsSafe(): void
    {
        $pool = new PDOConnectionPool($this->sqliteFactory());

        $pool->release($pool->acquire());
        $pool->reset();

        self::assertSame(1, $pool->idleCount());
    }

    public function testResetToleratesUnrollbackableTransaction(): void
    {
        $pool = new PDOConnectionPool($this->sqliteFactory());

        $lease = $pool->acquire();
        $pdo = $lease->pdo();
        $pdo->beginTransaction();
        // End the transaction underneath PDO so its own rollBack() will fail;
        // reset() must swallow that and still recycle the handle.
        $pdo->exec('ROLLBACK');

        $pool->reset();

        self::assertSame(1, $pool->idleCount());
    }

    public function testPreparedStatementsAreCachedPerConnection(): void
    {
        $pool = new PDOConnectionPool($this->sqliteFactory());

        $lease = $pool->acquire();
        $first = $pool->prepare($lease, 'SELECT 1');
        $second = $pool->prepare($lease, 'SELECT 1');

        self::assertSame($first, $second);
    }

    public function testResetClearsStatementCache(): void
    {
        $pool = new PDOConnectionPool($this->sqliteFactory());

        $first = $pool->prepare($pool->acquire(), 'SELECT 1');
        $pool->reset();
        $second = $pool->prepare($pool->acquire(), 'SELECT 1');

        self::assertNotSame($first, $second);
    }

    public function testPreparingInvalidStatementThrows(): void
    {
        $pool = new PDOConnectionPool($this->sqliteFactory());
        $lease = $pool->acquire();

        $this->expectException(DatabaseException::class);

        $pool->prepare($lease, 'THIS IS NOT VALID SQL');
    }

    public function testPoolExhaustionThrows(): void
    {
        $pool = new PDOConnectionPool($this->sqliteFactory(), 1);
        $pool->acquire();

        $this->expectException(DatabaseException::class);

        $pool->acquire();
    }

    public function testFactoryFailureSurfacesAsDatabaseException(): void
    {
        $pool = new PDOConnectionPool(static function (): PDO {
            throw new PDOException('cannot connect');
        });

        $this->expectException(DatabaseException::class);

        $pool->acquire();
    }

    public function testDeadConnectionReportsNotAlive(): void
    {
        $pool = new PDOConnectionPool($this->sqliteFactory(), 8, 'SELECT 1 FROM ping_probe');

        $lease = $pool->acquire();

        // The lease wraps a fresh handle that cannot answer the (invalid) probe.
        self::assertFalse($lease->isAlive());
    }

    public function testWarmConnectionSurvivesAcrossSimulatedRequests(): void
    {
        $pool = new PDOConnectionPool($this->sqliteFactory());

        $first = $pool->acquire();
        $first->pdo()->exec('CREATE TABLE warm (id INTEGER PRIMARY KEY)');
        // Return the warm handle to the idle set, as a worker does at end of request.
        $pool->reset();

        // Simulate 50 worker iterations: each acquires the *same* warm handle
        // (its in-memory schema must survive every reset), works, and resets.
        for ($i = 0; $i < 50; $i++) {
            $lease = $pool->acquire();
            $lease->pdo()->exec('INSERT INTO warm (id) VALUES (' . ($i + 1) . ')');
            $pool->reset();
        }

        $survivor = $pool->acquire();
        $count = $survivor->pdo()->query('SELECT COUNT(*) FROM warm');
        self::assertSame($first->pdo(), $survivor->pdo());
        self::assertNotFalse($count);
        self::assertSame(50, (int) $count->fetchColumn());
        self::assertSame(1, $pool->idleCount() + $pool->activeCount());
    }

    public function testRequestScopePinsTheSameLeaseAcrossAcquires(): void
    {
        $pool = new PDOConnectionPool($this->sqliteFactory());

        $pinned = $pool->beginRequestScope();
        $reused = $pool->acquire();
        // A nested beginRequestScope is idempotent and returns the same lease.
        $nested = $pool->beginRequestScope();

        self::assertSame($pinned, $reused);
        self::assertSame($pinned, $nested);
        self::assertSame(1, $pool->activeCount());
    }

    public function testReleaseDuringScopeIsANoOpButEndRequestScopeRecycles(): void
    {
        $pool = new PDOConnectionPool($this->sqliteFactory());

        $pinned = $pool->beginRequestScope();
        // A downstream repository releasing the pinned lease must NOT return it
        // to the idle set while the scope is open.
        $pool->release($pinned);
        self::assertSame(1, $pool->activeCount());
        self::assertSame(0, $pool->idleCount());

        $pool->endRequestScope();
        self::assertSame(0, $pool->activeCount());
        self::assertSame(1, $pool->idleCount());
    }

    public function testEndRequestScopeWithoutAScopeIsANoOp(): void
    {
        $pool = new PDOConnectionPool($this->sqliteFactory());

        $pool->endRequestScope();

        self::assertSame(0, $pool->activeCount());
        self::assertSame(0, $pool->idleCount());
    }

    public function testAfterScopeEndsAcquireDispensesIndependentLeases(): void
    {
        $pool = new PDOConnectionPool($this->sqliteFactory());

        $pinned = $pool->beginRequestScope();
        $pool->endRequestScope();

        // No scope open ⇒ acquire reuses the now-idle handle, but it is no longer
        // forced to be the same lease object on every call.
        $next = $pool->acquire();
        self::assertSame($pinned->pdo(), $next->pdo());
    }

    public function testResetClearsThePinnedScope(): void
    {
        $pool = new PDOConnectionPool($this->sqliteFactory());

        $pinned = $pool->beginRequestScope();
        $pinned->pdo()->exec('CREATE TABLE t (id INTEGER PRIMARY KEY)');
        $pinned->pdo()->beginTransaction();
        $pinned->pdo()->exec('INSERT INTO t (id) VALUES (1)');

        // A request that crashed mid-scope: reset() must drop the affinity, roll
        // back the dangling transaction, and recycle the handle.
        $pool->reset();

        self::assertFalse($pinned->pdo()->inTransaction());
        self::assertSame(0, $pool->activeCount());
        self::assertSame(1, $pool->idleCount());

        // The affinity is gone: the next acquire returns a distinct lease object.
        $after = $pool->acquire();
        self::assertSame($pinned->pdo(), $after->pdo());
    }

    public function testReleasingAForeignLeaseIsIgnored(): void
    {
        $pool = new PDOConnectionPool($this->sqliteFactory());

        // A lease wrapping a handle this pool never issued must not be pooled.
        $foreign = new PdoConnection(new PDO('sqlite::memory:'));
        $pool->release($foreign);

        self::assertSame(0, $pool->idleCount());
        self::assertSame(0, $pool->activeCount());
    }

    public function testReleasingALeaseOfAnotherKindIsIgnored(): void
    {
        $pool = new PDOConnectionPool($this->sqliteFactory());

        // A non-relational lease (a Redis client) is not ours to reclaim.
        $crossKind = new RedisConnection(new \stdClass(), static fn(object $client): bool => true);
        $pool->release($crossKind);

        self::assertSame(0, $pool->idleCount());
        self::assertSame(0, $pool->activeCount());
    }

    public function testWarmHandleStaysOursToReclaimAcrossReset(): void
    {
        $pool = new PDOConnectionPool($this->sqliteFactory());

        // Borrow, then reset (end of request): the handle returns to idle and
        // must remain recognised as issued by this pool.
        $pool->acquire();
        $pool->reset();
        self::assertSame(1, $pool->idleCount());

        // Next request reuses the warm handle and releasing it must re-pool it
        // (the DBAL-03 issued set was preserved across reset, not wiped).
        $reused = $pool->acquire();
        $pool->release($reused);

        self::assertSame(0, $pool->activeCount());
        self::assertSame(1, $pool->idleCount());
    }

    public function testAcquireTracksAnOpenConnection(): void
    {
        $tracker = $this->recordingTracker();
        $pool = new PDOConnectionPool($this->sqliteFactory(), tracker: $tracker);

        $pool->acquire();

        $open = $tracker->openConnections();
        self::assertCount(1, $open);
        self::assertSame(ConnectionKind::Pdo, $open[0]['kind'] ?? null);
    }

    public function testReleaseTracksTheConnectionClosed(): void
    {
        $tracker = $this->recordingTracker();
        $pool = new PDOConnectionPool($this->sqliteFactory(), tracker: $tracker);

        $pool->release($pool->acquire());

        self::assertSame([], $tracker->openConnections());
    }

    public function testLeakedConnectionStaysOpenInTheTracker(): void
    {
        $tracker = $this->recordingTracker();
        $pool = new PDOConnectionPool($this->sqliteFactory(), tracker: $tracker);

        // Borrowed but never released ⇒ still open at request end (a DIAG-03 leak).
        $pool->acquire();

        self::assertCount(1, $tracker->openConnections());
    }

    public function testReusedIdleConnectionIsTrackedOnReacquire(): void
    {
        $tracker = $this->recordingTracker();
        $pool = new PDOConnectionPool($this->sqliteFactory(), tracker: $tracker);

        $pool->release($pool->acquire());
        self::assertSame([], $tracker->openConnections());

        // Re-acquiring the warm idle handle must re-open it in the ledger.
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
