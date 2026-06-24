<?php

declare(strict_types=1);

namespace WaffleTests\Commons\Data\Crud;

use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use ReflectionObject;
use Waffle\Commons\Data\Compiler\SQLCompiler;
use Waffle\Commons\Data\Compiler\SQLDialect;
use Waffle\Commons\Data\Compiler\SQLWriteCompiler;
use Waffle\Commons\Data\Connection\PDOConnectionPool;
use Waffle\Commons\Data\Repository\SQLRepository;
use WaffleTests\Commons\Data\AbstractTestCase;
use WaffleTests\Commons\Data\Fixture\PersonMapper;
use WaffleTests\Commons\Data\Fixture\PersonRow;

use function count;

/**
 * FrankenPHP worker-mode safety (RFC-022 §6): after many save/find loops, the
 * pool's reset() must leave no growing internal arrays and no entity references
 * — a flat memory curve, not the Identity-Map bloat of a stateful ORM.
 */
#[CoversClass(SQLRepository::class)]
#[CoversClass(PDOConnectionPool::class)]
final class WorkerResetTest extends AbstractTestCase
{
    public function testThousandCyclesLeaveNoResidualState(): void
    {
        $pool = new PDOConnectionPool(factory: static fn(): PDO => new PDO('sqlite::memory:'));
        $connection = $pool->acquire();
        $connection->pdo()->exec('CREATE TABLE people (id INTEGER PRIMARY KEY, name TEXT NOT NULL, score REAL)');
        $pool->release($connection);

        $repository = new SQLRepository(
            $pool,
            PersonRow::class,
            new SQLCompiler(SQLDialect::SQLite),
            new PersonMapper(),
            new SQLWriteCompiler(SQLDialect::SQLite),
        );

        for ($i = 1; $i <= 1000; ++$i) {
            // Exercise the write + read paths each loop (delete is covered in
            // SqlCrudTest; the table is cleared out-of-band so the id-0 INSERT
            // never collides — this test is about the reset invariant, not CRUD).
            $repository->save(new PersonRow(0, 'person-' . $i, (float) $i));
            $repository->findById(0);

            $scratch = $pool->acquire();
            $scratch->pdo()->exec('DELETE FROM people');
            $pool->release($scratch);

            // The kernel calls this between every request loop.
            $pool->reset();

            // Nothing is held across the loop boundary, and the statement cache
            // is cleared — the two state buckets that would otherwise leak.
            self::assertSame(0, $this->bucketCount($pool, 'inUse'), 'reset() must release in-use connections.');
            self::assertSame(0, $this->bucketCount($pool, 'statements'), 'reset() must clear the statement cache.');

            // The warm pool reuses a single idle socket — it never grows with
            // the request count (flat memory curve, not Identity-Map bloat).
            self::assertLessThanOrEqual(1, $this->bucketCount($pool, 'idle'));
        }
    }

    private function bucketCount(PDOConnectionPool $pool, string $property): int
    {
        /** @var array<mixed> $bucket */
        $bucket = new ReflectionObject($pool)
            ->getProperty($property)
            ->getValue($pool);

        return count($bucket);
    }
}
