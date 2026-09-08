<?php

declare(strict_types=1);

namespace WaffleTests\Commons\Data\Repository;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use Waffle\Commons\Contracts\Exception\Validation\ValidationExceptionInterface;
use Waffle\Commons\Data\Query\Criteria;
use Waffle\Commons\Data\Query\Query;
use Waffle\Commons\Data\Repository\CassandraRepository;
use WaffleTests\Commons\Data\AbstractTestCase;
use WaffleTests\Commons\Data\Fixture\FakeCqlSession;
use WaffleTests\Commons\Data\Fixture\PersonRow;

use function array_map;
use function iterator_to_array;
use function str_contains;

#[CoversClass(CassandraRepository::class)]
final class CassandraRepositoryTest extends AbstractTestCase
{
    public function testFindCompilesParameterisedCqlAndHydratesRows(): void
    {
        $session = new FakeCqlSession([
            ['id' => 1, 'name' => 'alice', 'score' => 9.5],
        ]);
        $repository = new CassandraRepository($session, PersonRow::class);

        $people = $repository->find(Query::select()->from('people')->where(Criteria::eq('name', 'alice')));

        self::assertContainsOnlyInstancesOf(PersonRow::class, $people);
        self::assertSame(['alice'], array_map(static fn(PersonRow $person): string => $person->name, $people));
        // Capturé une fois puis asserté non nul : les accès qui suivent sont de
        // simples `->`, ce qu'acceptent aussi bien l'ancien que le nouvel
        // analyseur (l'un exige le `?->`, l'autre le déclare redondant).
        $query = $session->lastQuery;
        self::assertNotNull($query);
        self::assertSame('SELECT * FROM "people" WHERE "name" = ?', $query->cql);
        self::assertSame(['alice'], $query->parameters);
        self::assertTrue($query->requiresAllowFiltering);
    }

    public function testFindOneBoundsTheCallWithCqlLimit(): void
    {
        $session = new FakeCqlSession([['id' => 1, 'name' => 'alice', 'score' => null]]);
        $repository = new CassandraRepository($session, PersonRow::class);

        $alice = $repository->findOne(Query::select()->from('people'));

        self::assertSame('alice', $alice?->name);
        self::assertTrue(str_contains((string) $session->lastQuery?->cql, ' LIMIT 1'));
    }

    public function testFindOneReturnsNullOnEmptyResult(): void
    {
        $repository = new CassandraRepository(new FakeCqlSession([]), PersonRow::class);

        self::assertNull($repository->findOne(Query::select()->from('people')));
    }

    public function testStreamYieldsHydratedRows(): void
    {
        $session = new FakeCqlSession([['id' => 2, 'name' => 'bob', 'score' => null]]);
        $repository = new CassandraRepository($session, PersonRow::class);

        $people = iterator_to_array($repository->stream(Query::select()->from('people')));

        self::assertSame([2], array_map(static fn(PersonRow $person): int => $person->id, $people));
    }

    public function testCqlIncompatibleQueryIsRejectedAtCompileTime(): void
    {
        $repository = new CassandraRepository(new FakeCqlSession([]), PersonRow::class);

        $this->expectException(InvalidArgumentException::class);

        $repository->find(Query::select()->from('people')->where(Criteria::like('name', 'a%')));
    }

    public function testPoisonedRowIsRejectedDuringHydration(): void
    {
        $session = new FakeCqlSession([['id' => 1, 'name' => 5, 'score' => null]]);
        $repository = new CassandraRepository($session, PersonRow::class);

        $this->expectException(ValidationExceptionInterface::class);

        $repository->find(Query::select()->from('people'));
    }
}
