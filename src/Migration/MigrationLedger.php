<?php

declare(strict_types=1);

namespace Waffle\Commons\Data\Migration;

use PDO;
use PDOException;
use Waffle\Commons\Data\Compiler\SQLDialect;

use function sprintf;

/**
 * The SQL of the `waffle_migrations` ledger, per dialect ([OPS-03], RFC-014).
 *
 * Only provisioning diverges: SQL Server has no `CREATE TABLE IF NOT EXISTS` and
 * its `TIMESTAMP` is a row-version, and Oracle before 23ai has neither the clause
 * nor a quiet way to repeat a `CREATE TABLE`, so it runs a PL/SQL block that
 * ignores ORA-00955 ("name is already used"). The read and write statements are
 * portable. A null dialect keeps the portable statements the runner always used,
 * for drivers outside the six {@see SQLDialect} cases.
 *
 * The table name is a fixed constant, never user input, so it is inlined.
 */
final readonly class MigrationLedger
{
    public const string TABLE = 'waffle_migrations';

    public function __construct(
        private ?SQLDialect $dialect = null,
    ) {}

    public function createTable(): string
    {
        return match ($this->dialect) {
            SQLDialect::MSSQL => sprintf(
                "IF OBJECT_ID(N'%1\$s', N'U') IS NULL "
                . 'CREATE TABLE %1$s (version NVARCHAR(255) NOT NULL PRIMARY KEY, '
                . 'applied_at DATETIME2 NOT NULL DEFAULT SYSUTCDATETIME())',
                self::TABLE,
            ),
            SQLDialect::Oracle => sprintf(
                "BEGIN EXECUTE IMMEDIATE 'CREATE TABLE %s (version VARCHAR2(255) PRIMARY KEY, "
                . "applied_at TIMESTAMP DEFAULT SYSTIMESTAMP NOT NULL)'; "
                . 'EXCEPTION WHEN OTHERS THEN IF SQLCODE != -955 THEN RAISE; END IF; END;',
                self::TABLE,
            ),
            default => sprintf(
                'CREATE TABLE IF NOT EXISTS %s '
                . '(version VARCHAR(255) PRIMARY KEY, applied_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)',
                self::TABLE,
            ),
        };
    }

    public function selectAll(): string
    {
        return sprintf('SELECT version, applied_at FROM %s', self::TABLE);
    }

    public function insert(): string
    {
        return sprintf('INSERT INTO %s (version) VALUES (?)', self::TABLE);
    }

    public function delete(): string
    {
        return sprintf('DELETE FROM %s WHERE version = ?', self::TABLE);
    }

    /**
     * The dialect of a connection's PDO driver, or null for a driver outside the
     * six supported cases. MariaDB speaks through `mysql`.
     *
     * @throws PDOException When the driver cannot report its name.
     */
    public static function dialectOf(PDO $connection): ?SQLDialect
    {
        return match ($connection->getAttribute(PDO::ATTR_DRIVER_NAME)) {
            'mysql' => SQLDialect::MySQL,
            'pgsql' => SQLDialect::PostgreSQL,
            'sqlite' => SQLDialect::SQLite,
            'sqlsrv', 'dblib' => SQLDialect::MSSQL,
            'oci' => SQLDialect::Oracle,
            default => null,
        };
    }
}
