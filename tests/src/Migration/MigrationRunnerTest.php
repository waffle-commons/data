<?php

declare(strict_types=1);

namespace WaffleTests\Commons\Data\Migration;

use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use Waffle\Commons\Contracts\Config\ConfigInterface;
use Waffle\Commons\Data\Connection\PDOConnectionPool;
use Waffle\Commons\Data\Exception\DatabaseException;
use Waffle\Commons\Data\Migration\MigrationRunner;
use WaffleTests\Commons\Data\AbstractTestCase;

use function file_put_contents;
use function glob;
use function is_dir;
use function mkdir;
use function rmdir;
use function sys_get_temp_dir;
use function uniqid;
use function unlink;

#[CoversClass(MigrationRunner::class)]
final class MigrationRunnerTest extends AbstractTestCase
{
    private string $migrationsDir = '';

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        $dir = sys_get_temp_dir() . '/waffle-migrations-' . uniqid('', true);
        mkdir($dir);
        $this->migrationsDir = $dir;
    }

    #[\Override]
    protected function tearDown(): void
    {
        $files = glob($this->migrationsDir . '/*');
        if ($files !== false) {
            foreach ($files as $file) {
                unlink($file);
            }
        }
        if (is_dir($this->migrationsDir)) {
            rmdir($this->migrationsDir);
        }

        parent::tearDown();
    }

    public function testRunCreatesLogAndDemoTablesAndRecordsVersion(): void
    {
        $this->writeMigration(
            'Version2026053101_CreateUsersTable.sql',
            'CREATE TABLE users (id VARCHAR(36) PRIMARY KEY, email VARCHAR(255) NOT NULL UNIQUE);',
        );

        $pdo = $this->sqlite();
        $runner = new MigrationRunner($this->poolFor($pdo), $this->configFor($this->migrationsDir));

        $reported = [];
        $applied = $runner->run(static function (string $version) use (&$reported): void {
            $reported[] = $version;
        });

        self::assertSame(['Version2026053101_CreateUsersTable'], $applied);
        self::assertSame(['Version2026053101_CreateUsersTable'], $reported);
        self::assertTrue($this->tableExists($pdo, 'waffle_migrations'));
        self::assertTrue($this->tableExists($pdo, 'users'));
        self::assertSame(['Version2026053101_CreateUsersTable'], $this->loggedVersions($pdo));
    }

    public function testSecondRunSkipsAlreadyAppliedMigrations(): void
    {
        $this->writeMigration(
            'Version2026053101_CreateUsersTable.sql',
            'CREATE TABLE users (id VARCHAR(36) PRIMARY KEY);',
        );

        $pdo = $this->sqlite();
        $runner = new MigrationRunner($this->poolFor($pdo), $this->configFor($this->migrationsDir));

        $first = $runner->run();
        self::assertCount(1, $first);

        $second = $runner->run();
        self::assertSame([], $second);
        self::assertSame(['Version2026053101_CreateUsersTable'], $this->loggedVersions($pdo));
    }

    public function testMigrationsApplyInVersionOrder(): void
    {
        $this->writeMigration('Version2026053102_CreatePostsTable.sql', 'CREATE TABLE posts (id INTEGER PRIMARY KEY);');
        $this->writeMigration('Version2026053101_CreateUsersTable.sql', 'CREATE TABLE users (id INTEGER PRIMARY KEY);');

        $pdo = $this->sqlite();
        $runner = new MigrationRunner($this->poolFor($pdo), $this->configFor($this->migrationsDir));

        self::assertSame(['Version2026053101_CreateUsersTable', 'Version2026053102_CreatePostsTable'], $runner->run());
    }

    public function testFailingMigrationIsRolledBackAndAborts(): void
    {
        $this->writeMigration('Version2026053101_Valid.sql', 'CREATE TABLE ok (id INTEGER PRIMARY KEY);');
        $this->writeMigration('Version2026053102_Broken.sql', 'THIS IS NOT VALID SQL;');

        $pdo = $this->sqlite();
        $runner = new MigrationRunner($this->poolFor($pdo), $this->configFor($this->migrationsDir));

        try {
            $runner->run();
            self::fail('Expected a DatabaseException for the broken migration.');
        } catch (DatabaseException $exception) {
            self::assertStringContainsString('Version2026053102_Broken', $exception->getMessage());
        }

        // The first (valid) migration committed; the broken one neither committed
        // its DDL nor left an entry in the migration log.
        self::assertSame(['Version2026053101_Valid'], $this->loggedVersions($pdo));
        self::assertTrue($this->tableExists($pdo, 'ok'));
    }

    public function testEmptyDirectoryProvisionsLogButAppliesNothing(): void
    {
        $pdo = $this->sqlite();
        $runner = new MigrationRunner($this->poolFor($pdo), $this->configFor($this->migrationsDir));

        self::assertSame([], $runner->run());
        self::assertTrue($this->tableExists($pdo, 'waffle_migrations'));
    }

    public function testMissingDirectoryIsTreatedAsNoMigrations(): void
    {
        $pdo = $this->sqlite();
        $runner = new MigrationRunner($this->poolFor($pdo), $this->configFor($this->migrationsDir . '/nope'));

        self::assertSame([], $runner->run());
    }

    private function writeMigration(string $name, string $sql): void
    {
        file_put_contents($this->migrationsDir . '/' . $name, $sql);
    }

    private function sqlite(): PDO
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        return $pdo;
    }

    private function poolFor(PDO $pdo): PDOConnectionPool
    {
        // A single shared in-memory handle so every acquire() hits the same DB.
        return new PDOConnectionPool(static fn(): PDO => $pdo);
    }

    private function configFor(string $migrationsPath): ConfigInterface
    {
        return new class($migrationsPath) implements ConfigInterface {
            public function __construct(
                private readonly string $migrationsPath,
            ) {}

            #[\Override]
            public function getInt(string $key, ?int $default = null): ?int
            {
                return $default;
            }

            #[\Override]
            public function getString(string $key, ?string $default = null): ?string
            {
                if ($key === 'waffle.database.migrations_path') {
                    return $this->migrationsPath;
                }

                return $default;
            }

            /**
             * @param array<array-key, mixed>|null $default
             *
             * @return array<array-key, mixed>|null
             */
            #[\Override]
            public function getArray(string $key, ?array $default = null): ?array
            {
                return $default;
            }

            #[\Override]
            public function getBool(string $key, ?bool $default = null): ?bool
            {
                return $default;
            }
        };
    }

    private function tableExists(PDO $pdo, string $table): bool
    {
        $statement = $pdo->prepare("SELECT name FROM sqlite_master WHERE type = 'table' AND name = ?");
        if ($statement === false) {
            return false;
        }
        $statement->execute([$table]);

        return $statement->fetchColumn() !== false;
    }

    private function loggedVersions(PDO $pdo): array
    {
        $statement = $pdo->query('SELECT version FROM waffle_migrations ORDER BY version');

        return $statement === false ? [] : $statement->fetchAll(PDO::FETCH_COLUMN);
    }
}
