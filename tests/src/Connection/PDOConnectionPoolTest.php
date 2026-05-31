<?php

declare(strict_types=1);

namespace WaffleTests\Commons\Data\Connection;

use Closure;
use PDO;
use PDOException;
use PHPUnit\Framework\Attributes\CoversClass;
use Waffle\Commons\Data\Connection\PDOConnectionPool;
use Waffle\Commons\Data\Exception\DatabaseException;
use WaffleTests\Commons\Data\AbstractTestCase;

#[CoversClass(PDOConnectionPool::class)]
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

        $connection = $pool->acquire();
        $statement = $connection->query('SELECT 1');

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

        $connection = $pool->acquire();
        $pool->release($connection);
        $pool->release($connection);

        self::assertSame(1, $pool->idleCount());
    }

    public function testHealthyIdleConnectionIsReused(): void
    {
        $pool = new PDOConnectionPool($this->sqliteFactory());

        $first = $pool->acquire();
        $pool->release($first);
        $second = $pool->acquire();

        // The warm connection passed its ping and was handed back out.
        self::assertSame($first, $second);
    }

    public function testDeadIdleConnectionIsRecycledAndReconnected(): void
    {
        // The probe targets a table no fresh connection has, so any idle
        // connection fails its ping and must be transparently replaced.
        $pool = new PDOConnectionPool($this->sqliteFactory(), 8, 'SELECT 1 FROM ping_probe');

        $dead = $pool->acquire();
        $pool->release($dead);
        $replacement = $pool->acquire();

        self::assertNotSame($dead, $replacement);
        self::assertSame(0, $pool->idleCount());
        self::assertSame(1, $pool->activeCount());
    }

    public function testResetRollsBackDanglingTransactionAndRecycles(): void
    {
        $pool = new PDOConnectionPool($this->sqliteFactory());

        $connection = $pool->acquire();
        $connection->exec('CREATE TABLE t (id INTEGER PRIMARY KEY)');
        $connection->beginTransaction();
        $connection->exec('INSERT INTO t (id) VALUES (1)');
        self::assertTrue($connection->inTransaction());

        $pool->reset();

        self::assertFalse($connection->inTransaction());
        self::assertSame(0, $pool->activeCount());
        self::assertSame(1, $pool->idleCount());

        $reused = $pool->acquire();
        $count = $reused->query('SELECT COUNT(*) FROM t');
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

        $connection = $pool->acquire();
        $connection->beginTransaction();
        // End the transaction underneath PDO so its own rollBack() will fail;
        // reset() must swallow that and still recycle the handle.
        $connection->exec('ROLLBACK');

        $pool->reset();

        self::assertSame(1, $pool->idleCount());
    }

    public function testPreparedStatementsAreCachedPerConnection(): void
    {
        $pool = new PDOConnectionPool($this->sqliteFactory());

        $connection = $pool->acquire();
        $first = $pool->prepare($connection, 'SELECT 1');
        $second = $pool->prepare($connection, 'SELECT 1');

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
        $connection = $pool->acquire();

        $this->expectException(DatabaseException::class);

        $pool->prepare($connection, 'THIS IS NOT VALID SQL');
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

    public function testWarmConnectionSurvivesAcrossSimulatedRequests(): void
    {
        $pool = new PDOConnectionPool($this->sqliteFactory());

        $first = $pool->acquire();
        $first->exec('CREATE TABLE warm (id INTEGER PRIMARY KEY)');
        // Return the warm handle to the idle set, as a worker does at end of request.
        $pool->reset();

        // Simulate 50 worker iterations: each acquires the *same* warm handle
        // (its in-memory schema must survive every reset), works, and resets.
        for ($i = 0; $i < 50; $i++) {
            $connection = $pool->acquire();
            $connection->exec('INSERT INTO warm (id) VALUES (' . ($i + 1) . ')');
            $pool->reset();
        }

        $survivor = $pool->acquire();
        $count = $survivor->query('SELECT COUNT(*) FROM warm');
        self::assertSame($first, $survivor);
        self::assertNotFalse($count);
        self::assertSame(50, (int) $count->fetchColumn());
        self::assertSame(1, $pool->idleCount() + $pool->activeCount());
    }
}
