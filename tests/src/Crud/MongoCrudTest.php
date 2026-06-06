<?php

declare(strict_types=1);

namespace WaffleTests\Commons\Data\Crud;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use Waffle\Commons\Data\Repository\MongoRepository;
use WaffleTests\Commons\Data\AbstractTestCase;
use WaffleTests\Commons\Data\Fixture\FakeMongoSession;
use WaffleTests\Commons\Data\Fixture\PersonMapper;
use WaffleTests\Commons\Data\Fixture\PersonRow;

#[CoversClass(MongoRepository::class)]
final class MongoCrudTest extends AbstractTestCase
{
    public function testNullIdentitySaveInserts(): void
    {
        $session = new FakeMongoSession();
        $repository = new MongoRepository($session, PersonRow::class, mapper: new PersonMapper());

        $repository->save(new PersonRow(0, 'ada', 9.5));

        self::assertSame(
            [['collection' => 'people', 'row' => ['id' => 0, 'name' => 'ada', 'score' => 9.5]]],
            $session->inserts,
        );
        self::assertSame([], $session->upserts);
    }

    public function testIdentifiedSaveUpserts(): void
    {
        $session = new FakeMongoSession();
        $repository = new MongoRepository($session, PersonRow::class, mapper: new PersonMapper());

        $repository->save(new PersonRow(7, 'ada', 9.5));

        self::assertCount(1, $session->upserts);
        $upsert = $session->upserts[0] ?? null;
        self::assertNotNull($upsert);
        self::assertSame('people', $upsert['collection']);
        self::assertSame('id', $upsert['idField']);
        self::assertSame(7, $upsert['id']);
    }

    public function testDeleteTargetsTheIdentity(): void
    {
        $session = new FakeMongoSession();
        $repository = new MongoRepository($session, PersonRow::class, mapper: new PersonMapper());

        $repository->delete(new PersonRow(7, 'ada', 9.5));

        self::assertSame([['collection' => 'people', 'idField' => 'id', 'id' => 7]], $session->deletes);
    }

    public function testFindByIdHydratesTheReturnedDocument(): void
    {
        $session = new FakeMongoSession([['id' => 7, 'name' => 'ada', 'score' => 9.5]]);
        $repository = new MongoRepository($session, PersonRow::class, mapper: new PersonMapper());

        $found = $repository->findById(7);

        self::assertInstanceOf(PersonRow::class, $found);
        self::assertSame('ada', $found->name);
    }

    public function testDeleteWithoutIdentityIsRejected(): void
    {
        $repository = new MongoRepository(new FakeMongoSession(), PersonRow::class, mapper: new PersonMapper());

        $this->expectException(InvalidArgumentException::class);
        $repository->delete(new PersonRow(0, 'no-id'));
    }

    public function testWriteOnAReadOnlyRepositoryIsRejected(): void
    {
        $repository = new MongoRepository(new FakeMongoSession(), PersonRow::class);

        $this->expectException(InvalidArgumentException::class);
        $repository->save(new PersonRow(1, 'ada'));
    }
}
