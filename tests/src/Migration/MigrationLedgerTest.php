<?php

declare(strict_types=1);

namespace WaffleTests\Commons\Data\Migration;

use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Waffle\Commons\Data\Compiler\SQLDialect;
use Waffle\Commons\Data\Migration\MigrationLedger;
use WaffleTests\Commons\Data\AbstractTestCase;

/**
 * SQL generation for every dialect ([OPS-03]). SQL Server and Oracle have no
 * driver in the image, so these assertions are their coverage (scope change
 * 2026-10-17); the other four also run live in MigrationRoundTripTest.
 */
#[CoversClass(MigrationLedger::class)]
final class MigrationLedgerTest extends AbstractTestCase
{
    /**
     * @return iterable<string, array{SQLDialect|null}>
     */
    public static function portableDialects(): iterable
    {
        yield 'MySQL' => [SQLDialect::MySQL];
        yield 'MariaDB' => [SQLDialect::MariaDB];
        yield 'SQLite' => [SQLDialect::SQLite];
        yield 'PostgreSQL' => [SQLDialect::PostgreSQL];
        yield 'unknown driver' => [null];
    }

    #[DataProvider('portableDialects')]
    public function testPortableDialectsCreateTheLedgerIfAbsent(?SQLDialect $dialect): void
    {
        self::assertSame(
            'CREATE TABLE IF NOT EXISTS waffle_migrations '
            . '(version VARCHAR(255) PRIMARY KEY, applied_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)',
            new MigrationLedger($dialect)->createTable(),
        );
    }

    public function testSqlServerGuardsTheCreateAndAvoidsItsRowVersionTimestamp(): void
    {
        self::assertSame(
            "IF OBJECT_ID(N'waffle_migrations', N'U') IS NULL CREATE TABLE waffle_migrations "
            . '(version NVARCHAR(255) NOT NULL PRIMARY KEY, applied_at DATETIME2 NOT NULL DEFAULT SYSUTCDATETIME())',
            new MigrationLedger(SQLDialect::MSSQL)->createTable(),
        );
    }

    public function testOracleIgnoresOnlyTheNameAlreadyUsedError(): void
    {
        self::assertSame(
            "BEGIN EXECUTE IMMEDIATE 'CREATE TABLE waffle_migrations (version VARCHAR2(255) PRIMARY KEY, "
            . "applied_at TIMESTAMP DEFAULT SYSTIMESTAMP NOT NULL)'; "
            . 'EXCEPTION WHEN OTHERS THEN IF SQLCODE != -955 THEN RAISE; END IF; END;',
            new MigrationLedger(SQLDialect::Oracle)->createTable(),
        );
    }

    /**
     * @return iterable<string, array{SQLDialect}>
     */
    public static function everyDialect(): iterable
    {
        foreach (SQLDialect::cases() as $dialect) {
            yield $dialect->name => [$dialect];
        }
    }

    #[DataProvider('everyDialect')]
    public function testTheLedgerReadsAndWritesArePortable(SQLDialect $dialect): void
    {
        $ledger = new MigrationLedger($dialect);

        self::assertSame('SELECT version, applied_at FROM waffle_migrations', $ledger->selectAll());
        self::assertSame('INSERT INTO waffle_migrations (version) VALUES (?)', $ledger->insert());
        self::assertSame('DELETE FROM waffle_migrations WHERE version = ?', $ledger->delete());
    }

    public function testTheDialectComesFromALiveConnection(): void
    {
        self::assertSame(SQLDialect::SQLite, MigrationLedger::dialectOf(new PDO('sqlite::memory:')));
    }

    /**
     * @return iterable<string, array{string, SQLDialect|null}>
     */
    public static function driverNames(): iterable
    {
        yield 'mysql (MySQL and MariaDB)' => ['mysql', SQLDialect::MySQL];
        yield 'pgsql' => ['pgsql', SQLDialect::PostgreSQL];
        yield 'sqlite' => ['sqlite', SQLDialect::SQLite];
        yield 'sqlsrv' => ['sqlsrv', SQLDialect::MSSQL];
        yield 'dblib' => ['dblib', SQLDialect::MSSQL];
        yield 'oci' => ['oci', SQLDialect::Oracle];
        yield 'firebird' => ['firebird', null];
    }

    #[DataProvider('driverNames')]
    public function testEachPdoDriverNameMapsToItsDialect(string $driver, ?SQLDialect $expected): void
    {
        $connection = self::createStub(PDO::class);
        $connection->method('getAttribute')->willReturn($driver);

        self::assertSame($expected, MigrationLedger::dialectOf($connection));
    }
}
