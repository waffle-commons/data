<?php

declare(strict_types=1);

namespace WaffleTests\Commons\Data\Repository;

use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use Waffle\Commons\Contracts\Exception\Validation\ValidationExceptionInterface;
use Waffle\Commons\Data\Compiler\SQLCompiler;
use Waffle\Commons\Data\Compiler\SQLDialect;
use Waffle\Commons\Data\Connection\PDOConnectionPool;
use Waffle\Commons\Data\Exception\DatabaseException;
use Waffle\Commons\Data\Hydrator\RowNormaliser;
use Waffle\Commons\Data\Query\Criteria;
use Waffle\Commons\Data\Query\Query;
use Waffle\Commons\Data\Repository\SQLRepository;
use WaffleTests\Commons\Data\AbstractTestCase;
use WaffleTests\Commons\Data\Fixture\PersonRow;

use function array_map;
use function iterator_to_array;

#[CoversClass(SQLRepository::class)]
#[CoversClass(RowNormaliser::class)]
final class SQLRepositoryTest extends AbstractTestCase
{
    private PDOConnectionPool $pool;

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        // A single in-memory SQLite handle: seeded once, then returned to the
        // idle set so every repository call reuses the very same connection.
        $this->pool = new PDOConnectionPool(factory: static fn(): PDO => new PDO('sqlite::memory:'));
        $connection = $this->pool->acquire();
        $connection->exec('CREATE TABLE people (id INTEGER PRIMARY KEY, name TEXT NOT NULL, score REAL)');
        $connection->exec(
            "INSERT INTO people (id, name, score) VALUES (1, 'alice', 9.5), (2, 'bob', NULL), (3, 'carol', 7.25)",
        );
        $this->pool->release($connection);
    }

    /**
     * @return SQLRepository<PersonRow>
     */
    private function repository(): SQLRepository
    {
        return new SQLRepository($this->pool, PersonRow::class, new SQLCompiler(SQLDialect::SQLite));
    }

    public function testFindHydratesEveryMatchingRow(): void
    {
        $query = Query::select()->from('people')->where(Criteria::gt('id', 1))->orderBy('id');

        $people = $this->repository()->find($query);

        self::assertContainsOnlyInstancesOf(PersonRow::class, $people);
        self::assertSame(['bob', 'carol'], array_map(static fn(PersonRow $person): string => $person->name, $people));
        self::assertSame([null, 7.25], array_map(static fn(PersonRow $person): ?float => $person->score, $people));
    }

    public function testFindOneReturnsFirstMatchOrNull(): void
    {
        $repository = $this->repository();

        $bob = $repository->findOne(Query::select()->from('people')->where(Criteria::eq('id', 2)));
        self::assertSame('bob', $bob?->name);

        self::assertNull($repository->findOne(Query::select()->from('people')->where(Criteria::eq('id', 99))));
    }

    public function testStreamYieldsHydratedRowsSequentially(): void
    {
        $people = iterator_to_array($this->repository()->stream(Query::select()->from('people')->orderBy('id')));

        self::assertContainsOnlyInstancesOf(PersonRow::class, $people);
        self::assertSame([1, 2, 3], array_map(static fn(PersonRow $person): int => $person->id, $people));

        // The borrowed handle went back to the pool once the cursor drained.
        self::assertSame(1, $this->pool->idleCount());
        self::assertSame(0, $this->pool->activeCount());
    }

    public function testAbandonedStreamStillReleasesTheConnection(): void
    {
        $stream = $this->repository()->stream(Query::select()->from('people')->orderBy('id'));
        $first = $stream->current();
        self::assertInstanceOf(PersonRow::class, $first);

        // Dropping the generator mid-iteration must still run its finally block.
        unset($stream);

        self::assertSame(1, $this->pool->idleCount());
        self::assertSame(0, $this->pool->activeCount());
    }

    public function testBackendFailureIsWrappedAndConnectionReleased(): void
    {
        $repository = $this->repository();
        $failure = null;

        try {
            $repository->find(Query::select()->from('missing_table'));
        } catch (DatabaseException $caught) {
            $failure = $caught;
        }

        self::assertInstanceOf(DatabaseException::class, $failure);
        self::assertSame(1, $this->pool->idleCount());
        self::assertSame(0, $this->pool->activeCount());
    }

    public function testOperandsAreBoundWithExplicitDriverTypes(): void
    {
        // Booleans must reach the driver as PDO::PARAM_BOOL (1/0), never as the
        // string '' a plain execute(array) would send for false; a null operand
        // travels as PDO::PARAM_NULL and, per SQL semantics, `= NULL` matches
        // nothing.
        $connection = $this->pool->acquire();
        $connection->exec('CREATE TABLE flags (id INTEGER PRIMARY KEY, name TEXT NOT NULL, score REAL)');
        $connection->exec("INSERT INTO flags (id, name, score) VALUES (1, 'on', 1.0), (0, 'off', 2.0)");
        $this->pool->release($connection);

        $repository = new SQLRepository($this->pool, PersonRow::class, new SQLCompiler(SQLDialect::SQLite));

        $on = $repository->find(Query::select()->from('flags')->where(Criteria::eq('id', true)));
        self::assertSame(['on'], array_map(static fn(PersonRow $person): string => $person->name, $on));

        self::assertSame([], $repository->find(Query::select()->from('flags')->where(Criteria::eq('score', null))));
    }

    public function testPoisonedRowIsRejectedDuringHydration(): void
    {
        // SQLite's REAL affinity stores a non-numeric string as TEXT, so the
        // float column comes back as a string; the Property-Hook hydration
        // layer must reject it, never widen it.
        $connection = $this->pool->acquire();
        $connection->exec("INSERT INTO people (id, name, score) VALUES (4, 'dave', 'poison')");
        $this->pool->release($connection);

        $this->expectException(ValidationExceptionInterface::class);

        $this->repository()->find(Query::select()->from('people')->where(Criteria::eq('id', 4)));
    }
}
