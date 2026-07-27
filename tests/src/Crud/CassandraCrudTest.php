<?php

declare(strict_types=1);

namespace WaffleTests\Commons\Data\Crud;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use Waffle\Commons\Data\Compiler\CassandraCompiler;
use Waffle\Commons\Data\Repository\CassandraRepository;
use WaffleTests\Commons\Data\AbstractTestCase;
use WaffleTests\Commons\Data\Fixture\FakeCqlSession;
use WaffleTests\Commons\Data\Fixture\HostileIdentifierPersonMapper;
use WaffleTests\Commons\Data\Fixture\PersonMapper;
use WaffleTests\Commons\Data\Fixture\PersonRow;

#[CoversClass(CassandraRepository::class)]
#[CoversClass(CassandraCompiler::class)]
final class CassandraCrudTest extends AbstractTestCase
{
    public function testSaveCompilesAParameterisedUpsertInsert(): void
    {
        $session = new FakeCqlSession();
        $repository = new CassandraRepository($session, PersonRow::class, mapper: new PersonMapper());

        $repository->save(new PersonRow(7, 'ada', 9.5));

        // FIX-01: table/column names route through the same quoting the read
        // path uses (see CassandraRepositoryTest), instead of raw sprintf.
        self::assertSame('INSERT INTO "people" ("id", "name", "score") VALUES (?, ?, ?)', $session->lastWriteCql);
        self::assertSame([7, 'ada', 9.5], $session->lastWriteParameters);
    }

    public function testDeleteCompilesAParameterisedDelete(): void
    {
        $session = new FakeCqlSession();
        $repository = new CassandraRepository($session, PersonRow::class, mapper: new PersonMapper());

        $repository->delete(new PersonRow(7, 'ada', 9.5));

        self::assertSame('DELETE FROM "people" WHERE "id" = ?', $session->lastWriteCql);
        self::assertSame([7], $session->lastWriteParameters);
    }

    public function testSaveRejectsAHostileTableAndColumnNameConsistentlyWithTheReadPath(): void
    {
        // FIX-01: CassandraCompiler::quoteSegment() now allow-lists identifiers
        // (not just escape-then-embed), so the hostile payload never reaches CQL
        // on the write path either — it's rejected at the source, consistently
        // with the read path, instead of surviving as an escaped-but-present
        // identifier token.
        $session = new FakeCqlSession();
        $repository = new CassandraRepository($session, PersonRow::class, mapper: new HostileIdentifierPersonMapper());

        $this->expectException(InvalidArgumentException::class);

        $repository->save(new PersonRow(7, 'ada', 9.5));
    }

    public function testDeleteRejectsAHostileTableAndIdentityFieldConsistentlyWithTheReadPath(): void
    {
        $session = new FakeCqlSession();
        $repository = new CassandraRepository($session, PersonRow::class, mapper: new HostileIdentifierPersonMapper());

        $this->expectException(InvalidArgumentException::class);

        $repository->delete(new PersonRow(7, 'ada', 9.5));
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
