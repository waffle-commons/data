<?php

declare(strict_types=1);

namespace WaffleTests\Commons\Data\Crud;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use Waffle\Commons\Data\Repository\CassandraRepository;
use WaffleTests\Commons\Data\AbstractTestCase;
use WaffleTests\Commons\Data\Fixture\FakeCqlSession;
use WaffleTests\Commons\Data\Fixture\PersonMapper;
use WaffleTests\Commons\Data\Fixture\PersonRow;

#[CoversClass(CassandraRepository::class)]
final class CassandraCrudTest extends AbstractTestCase
{
    public function testSaveCompilesAParameterisedUpsertInsert(): void
    {
        $session = new FakeCqlSession();
        $repository = new CassandraRepository($session, PersonRow::class, mapper: new PersonMapper());

        $repository->save(new PersonRow(7, 'ada', 9.5));

        self::assertSame('INSERT INTO people (id, name, score) VALUES (?, ?, ?)', $session->lastWriteCql);
        self::assertSame([7, 'ada', 9.5], $session->lastWriteParameters);
    }

    public function testDeleteCompilesAParameterisedDelete(): void
    {
        $session = new FakeCqlSession();
        $repository = new CassandraRepository($session, PersonRow::class, mapper: new PersonMapper());

        $repository->delete(new PersonRow(7, 'ada', 9.5));

        self::assertSame('DELETE FROM people WHERE id = ?', $session->lastWriteCql);
        self::assertSame([7], $session->lastWriteParameters);
    }

    public function testFindByIdHydratesTheReturnedRow(): void
    {
        $session = new FakeCqlSession([['id' => 7, 'name' => 'ada', 'score' => 9.5]]);
        $repository = new CassandraRepository($session, PersonRow::class, mapper: new PersonMapper());

        $found = $repository->findById(7);

        self::assertInstanceOf(PersonRow::class, $found);
        self::assertSame('ada', $found->name);
    }

    public function testDeleteWithoutIdentityIsRejected(): void
    {
        $repository = new CassandraRepository(new FakeCqlSession(), PersonRow::class, mapper: new PersonMapper());

        $this->expectException(InvalidArgumentException::class);
        $repository->delete(new PersonRow(0, 'no-id'));
    }

    public function testWriteOnAReadOnlyRepositoryIsRejected(): void
    {
        $repository = new CassandraRepository(new FakeCqlSession(), PersonRow::class);

        $this->expectException(InvalidArgumentException::class);
        $repository->save(new PersonRow(1, 'ada'));
    }
}
