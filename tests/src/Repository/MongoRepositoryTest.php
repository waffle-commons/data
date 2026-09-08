<?php

declare(strict_types=1);

namespace WaffleTests\Commons\Data\Repository;

use PHPUnit\Framework\Attributes\CoversClass;
use Waffle\Commons\Contracts\Exception\Validation\ValidationExceptionInterface;
use Waffle\Commons\Data\Query\Criteria;
use Waffle\Commons\Data\Query\Query;
use Waffle\Commons\Data\Repository\MongoRepository;
use WaffleTests\Commons\Data\AbstractTestCase;
use WaffleTests\Commons\Data\Fixture\FakeMongoSession;
use WaffleTests\Commons\Data\Fixture\PersonRow;

use function array_map;
use function iterator_to_array;

#[CoversClass(MongoRepository::class)]
final class MongoRepositoryTest extends AbstractTestCase
{
    public function testFindCompilesTheQueryAndHydratesRows(): void
    {
        $session = new FakeMongoSession([
            ['id' => 1, 'name' => 'alice', 'score' => 9.5],
            ['id' => 2, 'name' => 'bob', 'score' => null],
        ]);
        $repository = new MongoRepository($session, PersonRow::class);

        $people = $repository->find(Query::select()->from('people')->where(Criteria::eq('name', 'alice')));

        self::assertContainsOnlyInstancesOf(PersonRow::class, $people);
        self::assertSame([1, 2], array_map(static fn(PersonRow $person): int => $person->id, $people));
        $query = $session->lastQuery;
        self::assertNotNull($query);
        self::assertSame('people', $query->collection);
        self::assertSame(['name' => ['$eq' => 'alice']], $query->filter);
    }

    public function testFindOneBoundsTheCallServerSide(): void
    {
        $session = new FakeMongoSession([['id' => 1, 'name' => 'alice', 'score' => null]]);
        $repository = new MongoRepository($session, PersonRow::class);

        $alice = $repository->findOne(Query::select()->from('people'));

        self::assertSame('alice', $alice?->name);
        // The repository rebuilt the SQR with a server-side bound of one.
        self::assertSame(1, $session->lastQuery?->options->limit);
    }

    public function testFindOneReturnsNullOnEmptyResult(): void
    {
        $repository = new MongoRepository(new FakeMongoSession([]), PersonRow::class);

        self::assertNull($repository->findOne(Query::select()->from('people')));
    }

    public function testStreamYieldsHydratedRows(): void
    {
        $session = new FakeMongoSession([['id' => 3, 'name' => 'carol', 'score' => 7.25]]);
        $repository = new MongoRepository($session, PersonRow::class);

        $people = iterator_to_array($repository->stream(Query::select()->from('people')));

        self::assertSame(['carol'], array_map(static fn(PersonRow $person): string => $person->name, $people));
    }

    public function testPoisonedDocumentIsRejectedDuringHydration(): void
    {
        $session = new FakeMongoSession([['id' => 1, 'name' => 5, 'score' => null]]);
        $repository = new MongoRepository($session, PersonRow::class);

        $this->expectException(ValidationExceptionInterface::class);

        $repository->find(Query::select()->from('people'));
    }
}
