<?php

declare(strict_types=1);

namespace WaffleTests\Commons\Data\Crud;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use Waffle\Commons\Data\Repository\FirestoreRepository;
use WaffleTests\Commons\Data\AbstractTestCase;
use WaffleTests\Commons\Data\Fixture\FakeFirestoreClient;
use WaffleTests\Commons\Data\Fixture\FakeSecurityContext;
use WaffleTests\Commons\Data\Fixture\PersonMapper;
use WaffleTests\Commons\Data\Fixture\PersonRow;

#[CoversClass(FirestoreRepository::class)]
final class FirestoreCrudTest extends AbstractTestCase
{
    private function repository(FakeFirestoreClient $client): FirestoreRepository
    {
        return FirestoreRepository::forPublic(
            $client,
            PersonRow::class,
            FakeSecurityContext::authenticatedAs('user-1'),
            new PersonMapper(),
            'app-1',
        );
    }

    public function testSaveThenFindByIdRoundTrips(): void
    {
        $client = new FakeFirestoreClient();
        $repository = $this->repository($client);

        $repository->save(new PersonRow(1, 'ada', 9.5));

        $found = $repository->findById(1);
        self::assertInstanceOf(PersonRow::class, $found);
        self::assertSame('ada', $found->name);
        self::assertSame(9.5, $found->score);
    }

    public function testSaveOverwritesAnExistingDocument(): void
    {
        $client = new FakeFirestoreClient();
        $repository = $this->repository($client);

        $repository->save(new PersonRow(1, 'ada', 9.5));
        $repository->save(new PersonRow(1, 'ada-renamed', 1.0));

        $found = $repository->findById(1);
        self::assertInstanceOf(PersonRow::class, $found);
        self::assertSame('ada-renamed', $found->name);
    }

    public function testDeleteRemovesTheDocument(): void
    {
        $client = new FakeFirestoreClient();
        $repository = $this->repository($client);
        $repository->save(new PersonRow(1, 'ada', 9.5));

        $repository->delete(new PersonRow(1, 'ada', 9.5));

        self::assertNull($repository->findById(1));
    }

    public function testFindByIdReturnsNullOnMiss(): void
    {
        self::assertNull($this->repository(new FakeFirestoreClient())->findById(999));
    }

    public function testFindOneReturnsTheFirstMatch(): void
    {
        $client = new FakeFirestoreClient();
        $repository = $this->repository($client);
        $repository->save(new PersonRow(1, 'ada', 9.5));

        self::assertInstanceOf(
            PersonRow::class,
            $repository->findOne(\Waffle\Commons\Data\Query\Query::select()->from('people')),
        );
    }

    public function testStreamYieldsHydratedDocuments(): void
    {
        $client = new FakeFirestoreClient();
        $repository = $this->repository($client);
        $repository->save(new PersonRow(1, 'ada', 9.5));
        $repository->save(new PersonRow(2, 'bob', 7.0));

        $names = [];
        foreach ($repository->stream(\Waffle\Commons\Data\Query\Query::select()->from('people')) as $person) {
            self::assertInstanceOf(PersonRow::class, $person);
            $names[] = $person->name;
        }

        self::assertEqualsCanonicalizing(['ada', 'bob'], $names);
    }

    public function testDeleteWithoutIdentityIsRejected(): void
    {
        $repository = $this->repository(new FakeFirestoreClient());

        $this->expectException(InvalidArgumentException::class);
        $repository->delete(new PersonRow(0, 'unsaved'));
    }

    public function testNullIdentitySaveAutoAssignsAnId(): void
    {
        $client = new FakeFirestoreClient();
        $repository = $this->repository($client);

        // id 0 ⇒ insert with a backend-assigned id.
        $repository->save(new PersonRow(0, 'auto', 1.0));

        self::assertNotNull($client->getDocument('artifacts/app-1/public/data/people', 'auto-1'));
    }
}
