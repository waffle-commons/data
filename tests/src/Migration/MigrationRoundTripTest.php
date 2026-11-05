<?php

declare(strict_types=1);

namespace WaffleTests\Commons\Data\Migration;

use PDO;
use PDOException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Waffle\Commons\Contracts\Data\Migration\Enum\MigrationStatus;
use Waffle\Commons\Data\Connection\PDOConnectionPool;
use Waffle\Commons\Data\Migration\MigrationLedger;
use Waffle\Commons\Data\Migration\MigrationRunner;
use WaffleTests\Commons\Data\AbstractTestCase;
use WaffleTests\Commons\Data\Fixture\MigrationsPathConfig;

use function array_map;
use function file_put_contents;
use function getenv;
use function glob;
use function is_dir;
use function is_string;
use function mkdir;
use function rmdir;
use function sys_get_temp_dir;
use function uniqid;
use function unlink;

/**
 * The live db:migrate → db:status → db:rollback round trip ([OPS-03] acceptance) on the
 * engines the dev image can reach: PostgreSQL, MySQL and MariaDB (SQLite is covered by
 * MigrationRunnerTest). Each run works in a dedicated schema or database, so the workspace
 * application's own migration log is never touched. Hosts come from WAFFLE_TEST_PGSQL_HOST,
 * WAFFLE_TEST_MYSQL_HOST and WAFFLE_TEST_MARIADB_HOST, defaulting to the workspace services.
 */
#[CoversClass(MigrationRunner::class)]
#[CoversClass(MigrationLedger::class)]
final class MigrationRoundTripTest extends AbstractTestCase
{
    private const string SANDBOX = 'waffle_ops03';

    private string $migrationsDir = '';

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        $dir = sys_get_temp_dir() . '/waffle-roundtrip-' . uniqid('', true);
        mkdir($dir);
        $this->migrationsDir = $dir;

        // Two DDL steps and a data step: on MySQL and MariaDB each DDL commits implicitly.
        file_put_contents(
            $dir . '/001_create_authors.sql',
            'CREATE TABLE ops03_authors (id INT PRIMARY KEY, name VARCHAR(64) NOT NULL)',
        );
        file_put_contents($dir . '/001_create_authors.down.sql', 'DROP TABLE ops03_authors');
        file_put_contents($dir . '/002_add_email.sql', 'ALTER TABLE ops03_authors ADD email VARCHAR(255)');
        file_put_contents($dir . '/002_add_email.down.sql', 'ALTER TABLE ops03_authors DROP COLUMN email');
        file_put_contents(
            $dir . '/003_seed.sql',
            "INSERT INTO ops03_authors (id, name, email) VALUES (1, 'Ada', 'ada@example.test')",
        );
        file_put_contents($dir . '/003_seed.down.sql', 'DELETE FROM ops03_authors WHERE id = 1');
    }

    #[\Override]
    protected function tearDown(): void
    {
        $files = glob($this->migrationsDir . '/*');
        foreach ($files === false ? [] : $files as $file) {
            unlink($file);
        }
        if (is_dir($this->migrationsDir)) {
            rmdir($this->migrationsDir);
        }

        parent::tearDown();
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function engines(): iterable
    {
        yield 'PostgreSQL' => ['pgsql', 'WAFFLE_TEST_PGSQL_HOST', 'waffle-postgres'];
        yield 'MySQL' => ['mysql', 'WAFFLE_TEST_MYSQL_HOST', 'waffle-mysql'];
        yield 'MariaDB' => ['mariadb', 'WAFFLE_TEST_MARIADB_HOST', 'waffle-mariadb'];
    }

    #[DataProvider('engines')]
    public function testMigrateStatusAndRollbackRoundTripLive(
        string $engine,
        string $hostVariable,
        string $defaultHost,
    ): void {
        $host = getenv($hostVariable);
        $pdo = self::sandbox($engine, is_string($host) && $host !== '' ? $host : $defaultHost);
        $runner = new MigrationRunner(
            new PDOConnectionPool(static fn(): PDO => $pdo),
            new MigrationsPathConfig($this->migrationsDir),
        );

        self::assertSame(['001_create_authors', '002_add_email', '003_seed'], $runner->run());
        self::assertSame([], $runner->run());
        self::assertSame(1, self::scalarCount($pdo, 'SELECT COUNT(*) FROM ops03_authors'));

        $states = $runner->status();
        self::assertSame(
            [MigrationStatus::Applied, MigrationStatus::Applied, MigrationStatus::Applied],
            array_map(static fn($state) => $state->status, $states),
        );
        self::assertNotNull($states[0]->appliedAt ?? null);

        self::assertSame(['003_seed', '002_add_email'], $runner->rollback(2));
        self::assertSame(0, self::scalarCount($pdo, 'SELECT COUNT(*) FROM ops03_authors'));

        self::assertSame(['002_add_email', '003_seed'], $runner->run());
        self::assertSame(['003_seed', '002_add_email', '001_create_authors'], $runner->rollback(3));

        self::assertSame(0, self::scalarCount($pdo, 'SELECT COUNT(*) FROM waffle_migrations'));
        self::assertSame(
            [MigrationStatus::Pending, MigrationStatus::Pending, MigrationStatus::Pending],
            array_map(static fn($state) => $state->status, $runner->status()),
        );

        $failed = false;
        try {
            $pdo->query('SELECT COUNT(*) FROM ops03_authors');
        } catch (PDOException) {
            $failed = true;
        }
        self::assertTrue($failed, 'The first migration\'s table must be gone after a full rollback.');
    }

    /**
     * A connection inside a freshly emptied sandbox schema (PostgreSQL) or database (MySQL, MariaDB).
     */
    private static function sandbox(string $engine, string $host): PDO
    {
        try {
            if ($engine === 'pgsql') {
                $pdo = new PDO("pgsql:host={$host};dbname=waffle", 'waffle', 'waffle', [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                ]);
                $pdo->exec('DROP SCHEMA IF EXISTS ' . self::SANDBOX . ' CASCADE');
                $pdo->exec('CREATE SCHEMA ' . self::SANDBOX);
                $pdo->exec('SET search_path TO ' . self::SANDBOX);

                return $pdo;
            }

            $pdo = new PDO("mysql:host={$host}", 'root', 'waffle', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $pdo->exec('DROP DATABASE IF EXISTS ' . self::SANDBOX);
            $pdo->exec('CREATE DATABASE ' . self::SANDBOX);
            $pdo->exec('USE ' . self::SANDBOX);

            return $pdo;
        } catch (PDOException $failure) {
            self::markTestSkipped("No reachable {$engine} instance for the round trip: {$failure->getMessage()}");
        }
    }

    private static function scalarCount(PDO $pdo, string $sql): int
    {
        $statement = $pdo->query($sql);
        if ($statement === false) {
            self::fail("Query failed: {$sql}");
        }

        return (int) $statement->fetchColumn();
    }
}
