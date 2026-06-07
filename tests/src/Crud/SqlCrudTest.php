<?php

declare(strict_types=1);

namespace WaffleTests\Commons\Data\Crud;

use InvalidArgumentException;
use PDO;
use PDOStatement;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use Waffle\Commons\Data\Compiler\SQLCompiler;
use Waffle\Commons\Data\Compiler\SQLDialect;
use Waffle\Commons\Data\Compiler\SQLWriteCompiler;
use Waffle\Commons\Data\Connection\PDOConnectionPool;
use Waffle\Commons\Data\Repository\SQLRepository;
use WaffleTests\Commons\Data\AbstractTestCase;
use WaffleTests\Commons\Data\Fixture\PersonMapper;
use WaffleTests\Commons\Data\Fixture\PersonRow;

#[CoversClass(SQLRepository::class)]
#[AllowMockObjectsWithoutExpectations]
final class SqlCrudTest extends AbstractTestCase
{
    private PDOConnectionPool $pool;

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->pool = new PDOConnectionPool(factory: static fn(): PDO => new PDO('sqlite::memory:'));
        $connection = $this->pool->acquire();
        $connection->exec('CREATE TABLE people (id INTEGER PRIMARY KEY, name TEXT NOT NULL, score REAL)');
        $connection->exec("INSERT INTO people (id, name, score) VALUES (1, 'alice', 9.5)");
        $this->pool->release($connection);
    }

    /**
     * @return SQLRepository<PersonRow>
     */
    private function repository(): SQLRepository
    {
        return new SQLRepository(
            $this->pool,
            PersonRow::class,
            new SQLCompiler(SQLDialect::SQLite),
            new PersonMapper(),
            new SQLWriteCompiler(SQLDialect::SQLite),
        );
    }

    public function testSaveUpdatesAnExistingRow(): void
    {
        $repository = $this->repository();

        $repository->save(new PersonRow(1, 'alice-renamed', 1.0));

        $found = $repository->findById(1);
        self::assertInstanceOf(PersonRow::class, $found);
        self::assertSame('alice-renamed', $found->name);
        self::assertSame(1.0, $found->score);
    }

    public function testSaveInsertsANewRow(): void
    {
        $repository = $this->repository();

        // id 0 ⇒ INSERT (the mapper maps 0 to a null identity).
        $repository->save(new PersonRow(0, 'newcomer', 4.0));

        $found = $repository->findById(0);
        self::assertInstanceOf(PersonRow::class, $found);
        self::assertSame('newcomer', $found->name);
    }

    public function testDeleteRemovesTheRow(): void
    {
        $repository = $this->repository();

        $repository->delete(new PersonRow(1, 'alice', 9.5));

        self::assertNull($repository->findById(1));
    }

    public function testFindByIdReturnsNullOnMiss(): void
    {
        self::assertNull($this->repository()->findById(404));
    }

    public function testWriteOnAReadOnlyRepositoryIsRejected(): void
    {
        $readOnly = new SQLRepository($this->pool, PersonRow::class, new SQLCompiler(SQLDialect::SQLite));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('without a DataMapper');
        $readOnly->save(new PersonRow(1, 'x'));
    }

    public function testDeleteWithoutIdentityIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->repository()->delete(new PersonRow(0, 'unsaved'));
    }

    // --- Spec: mock PDO verifying insert-vs-update branching + transaction ---

    public function testInsertBranchPreparesInsertInsideATransaction(): void
    {
        [$pool, $statement, $pdo] = $this->mockedConnection();
        $pdo->expects(self::once())->method('beginTransaction')->willReturn(true);
        $pdo->expects(self::once())->method('commit')->willReturn(true);
        $pdo
            ->expects(self::once())
            ->method('prepare')
            ->with(self::stringContains('INSERT INTO'))
            ->willReturn($statement);

        $repository = new SQLRepository(
            $pool,
            PersonRow::class,
            new SQLCompiler(SQLDialect::SQLite),
            new PersonMapper(),
            new SQLWriteCompiler(SQLDialect::SQLite),
        );

        $repository->save(new PersonRow(0, 'inserted', 1.0));
    }

    public function testUpdateBranchPreparesUpdateInsideATransaction(): void
    {
        [$pool, $statement, $pdo] = $this->mockedConnection();
        $pdo->expects(self::once())->method('beginTransaction')->willReturn(true);
        $pdo->expects(self::once())->method('commit')->willReturn(true);
        $pdo->expects(self::once())->method('prepare')->with(self::stringContains('UPDATE'))->willReturn($statement);

        $repository = new SQLRepository(
            $pool,
            PersonRow::class,
            new SQLCompiler(SQLDialect::SQLite),
            new PersonMapper(),
            new SQLWriteCompiler(SQLDialect::SQLite),
        );

        $repository->save(new PersonRow(5, 'updated', 1.0));
    }

    public function testWriteRollsBackAndWrapsAFailedStatement(): void
    {
        [$pool, $statement, $pdo] = $this->mockedConnection();
        $pdo->method('beginTransaction')->willReturn(true);
        $pdo->method('prepare')->willReturn($statement);
        $pdo->method('inTransaction')->willReturn(true);
        $pdo->expects(self::once())->method('rollBack')->willReturn(true);
        $statement->method('execute')->willThrowException(new \PDOException('write blew up'));

        $repository = new SQLRepository(
            $pool,
            PersonRow::class,
            new SQLCompiler(SQLDialect::SQLite),
            new PersonMapper(),
            new SQLWriteCompiler(SQLDialect::SQLite),
        );

        $this->expectException(\Waffle\Commons\Contracts\Data\Exception\DatabaseExceptionInterface::class);
        $repository->save(new PersonRow(5, 'boom', 1.0));
    }

    public function testWriteWrapsAFailedPrepare(): void
    {
        $pdo = $this->createMock(PDO::class);
        $pdo->method('beginTransaction')->willReturn(true);
        $pdo->method('prepare')->willReturn(false);
        $pdo->method('inTransaction')->willReturn(false);

        $repository = new SQLRepository(
            new PDOConnectionPool(factory: static fn(): PDO => $pdo),
            PersonRow::class,
            new SQLCompiler(SQLDialect::SQLite),
            new PersonMapper(),
            new SQLWriteCompiler(SQLDialect::SQLite),
        );

        $this->expectException(\Waffle\Commons\Contracts\Data\Exception\DatabaseExceptionInterface::class);
        $this->expectExceptionMessage('prepare the compiled write');
        $repository->save(new PersonRow(5, 'x', 1.0));
    }

    public function testARollbackFailureDuringRecoveryIsSwallowed(): void
    {
        [$pool, $statement, $pdo] = $this->mockedConnection();
        $pdo->method('beginTransaction')->willReturn(true);
        $pdo->method('prepare')->willReturn($statement);
        $pdo->method('inTransaction')->willReturn(true);
        // The connection is already severed — even the rollback throws.
        $pdo->method('rollBack')->willThrowException(new \PDOException('socket gone'));
        $statement->method('execute')->willThrowException(new \PDOException('write blew up'));

        $repository = new SQLRepository(
            $pool,
            PersonRow::class,
            new SQLCompiler(SQLDialect::SQLite),
            new PersonMapper(),
            new SQLWriteCompiler(SQLDialect::SQLite),
        );

        // The original write failure surfaces; the failed rollback does not mask it.
        $this->expectException(\Waffle\Commons\Contracts\Data\Exception\DatabaseExceptionInterface::class);
        $this->expectExceptionMessage('execute the compiled write');
        $repository->delete(new PersonRow(5, 'x'));
    }

    /**
     * @return array{PDOConnectionPool, PDOStatement&\PHPUnit\Framework\MockObject\MockObject, PDO&\PHPUnit\Framework\MockObject\MockObject}
     */
    private function mockedConnection(): array
    {
        // No method presets here: re-stubbing a mock method elsewhere would not
        // override a preset (PHPUnit keeps the first matcher), so each test
        // configures execute() / inTransaction() as its scenario needs.
        $statement = $this->createMock(PDOStatement::class);
        $pdo = $this->createMock(PDO::class);

        return [new PDOConnectionPool(factory: static fn(): PDO => $pdo), $statement, $pdo];
    }
}
