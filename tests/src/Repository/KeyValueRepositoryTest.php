<?php

declare(strict_types=1);

namespace WaffleTests\Commons\Data\Repository;

use PHPUnit\Framework\Attributes\CoversClass;
use Waffle\Commons\Contracts\Exception\Validation\ValidationExceptionInterface;
use Waffle\Commons\Data\Exception\DatabaseException;
use Waffle\Commons\Data\Hydrator\RowNormaliser;
use Waffle\Commons\Data\Query\Criteria;
use Waffle\Commons\Data\Query\Query;
use Waffle\Commons\Data\Repository\KeyValueRepository;
use WaffleTests\Commons\Data\AbstractTestCase;
use WaffleTests\Commons\Data\Fixture\InMemoryKeyValueClient;
use WaffleTests\Commons\Data\Fixture\PersonRow;

use function array_map;
use function iterator_to_array;

#[CoversClass(KeyValueRepository::class)]
#[CoversClass(RowNormaliser::class)]
final class KeyValueRepositoryTest extends AbstractTestCase
{
    /**
     * @param array<string, string> $items
     *
     * @return KeyValueRepository<PersonRow>
     */
    private function repository(array $items): KeyValueRepository
    {
        return new KeyValueRepository(new InMemoryKeyValueClient($items), PersonRow::class);
    }

    public function testKeyEqualityHydratesTheStoredDocument(): void
    {
        $repository = $this->repository([
            'people:1' => '{"id": 1, "name": "alice", "score": 9.5}',
        ]);

        $people = $repository->find(Query::select()->from('people')->where(Criteria::eq('id', 1)));

        self::assertContainsOnlyInstancesOf(PersonRow::class, $people);
        self::assertSame(['alice'], array_map(static fn(PersonRow $person): string => $person->name, $people));
    }

    public function testMissingKeyContributesNoRow(): void
    {
        $repository = $this->repository([]);

        self::assertSame([], $repository->find(Query::select()->from('people')->where(Criteria::eq('id', 404))));
    }

    public function testKeyMembershipSkipsMissesAndKeepsHits(): void
    {
        $repository = $this->repository([
            'people:1' => '{"id": 1, "name": "alice", "score": 9.5}',
            'people:3' => '{"id": 3, "name": "carol", "score": 7.25}',
        ]);

        $people = $repository->find(
            Query::select()
                ->from('people')
                ->where(Criteria::in('id', [1, 2, 3])),
        );

        self::assertSame([1, 3], array_map(static fn(PersonRow $person): int => $person->id, $people));
    }

    public function testFindOneReturnsFirstHitOrNull(): void
    {
        $repository = $this->repository([
            'people:2' => '{"id": 2, "name": "bob", "score": null}',
        ]);

        $bob = $repository->findOne(Query::select()->from('people')->where(Criteria::eq('id', 2)));
        self::assertSame('bob', $bob?->name);

        self::assertNull($repository->findOne(Query::select()->from('people')->where(Criteria::eq('id', 404))));
    }

    public function testStreamYieldsHydratedDocuments(): void
    {
        $repository = $this->repository([
            'people:1' => '{"id": 1, "name": "alice", "score": 9.5}',
            'people:2' => '{"id": 2, "name": "bob", "score": null}',
        ]);

        $people = iterator_to_array($repository->stream(
            Query::select()
                ->from('people')
                ->where(Criteria::in('id', [1, 2])),
        ));

        self::assertSame([1, 2], array_map(static fn(PersonRow $person): int => $person->id, $people));
    }

    public function testCorruptDocumentIsRejected(): void
    {
        $repository = $this->repository(['people:1' => '{not json']);

        $this->expectException(DatabaseException::class);

        $repository->find(Query::select()->from('people')->where(Criteria::eq('id', 1)));
    }

    public function testPoisonedDocumentIsRejectedDuringHydration(): void
    {
        $repository = $this->repository(['people:1' => '{"id": 1, "name": 5, "score": null}']);

        $this->expectException(ValidationExceptionInterface::class);

        $repository->find(Query::select()->from('people')->where(Criteria::eq('id', 1)));
    }
}
