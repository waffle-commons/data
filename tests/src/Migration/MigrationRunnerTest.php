<?php

declare(strict_types=1);

namespace WaffleTests\Commons\Data\Migration;

use InvalidArgumentException;
use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use Waffle\Commons\Contracts\Config\ConfigInterface;
use Waffle\Commons\Contracts\Data\Migration\Enum\MigrationStatus;
use Waffle\Commons\Contracts\Data\Migration\MigrationState;
use Waffle\Commons\Data\Compiler\SQLDialect;
use Waffle\Commons\Data\Connection\PDOConnectionPool;
use Waffle\Commons\Data\Exception\DatabaseException;
use Waffle\Commons\Data\Migration\MigrationRunner;
use WaffleTests\Commons\Data\AbstractTestCase;
use WaffleTests\Commons\Data\Fixture\MigrationsPathConfig;

use function array_map;
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

    public function testDownScriptsAreNeverAppliedAsVersions(): void
    {
        $this->writeMigration('001_users.sql', 'CREATE TABLE users (id INTEGER PRIMARY KEY);');
        $this->writeMigration('001_users.down.sql', 'DROP TABLE users;');

        $pdo = $this->sqlite();
        $runner = new MigrationRunner($this->poolFor($pdo), $this->configFor($this->migrationsDir));

        self::assertSame(['001_users'], $runner->run());
        self::assertSame(['001_users'], $this->loggedVersions($pdo));
    }

    public function testRollbackRevertsTheHighestVersionAndErasesItFromTheLog(): void
    {
        $pdo = $this->sqlite();
        $runner = $this->migratedPair($pdo);

        $reported = [];
        $reverted = $runner->rollback(onReverted: static function (string $version) use (&$reported): void {
            $reported[] = $version;
        });

        self::assertSame(['002_posts'], $reverted);
        self::assertSame(['002_posts'], $reported);
        self::assertFalse($this->tableExists($pdo, 'posts'));
        self::assertTrue($this->tableExists($pdo, 'users'));
        self::assertSame(['001_users'], $this->loggedVersions($pdo));
    }

    public function testRollbackOfSeveralStepsRunsInReverseVersionOrder(): void
    {
        $pdo = $this->sqlite();
        $runner = $this->migratedPair($pdo);

        self::assertSame(['002_posts', '001_users'], $runner->rollback(5));
        self::assertFalse($this->tableExists($pdo, 'users'));
        self::assertSame([], $this->loggedVersions($pdo));
        self::assertSame([], $runner->rollback());
    }

    public function testRolledBackVersionsApplyAgain(): void
    {
        $pdo = $this->sqlite();
        $runner = $this->migratedPair($pdo);
        $runner->rollback(2);

        self::assertSame(['001_users', '002_posts'], $runner->run());
        self::assertTrue($this->tableExists($pdo, 'posts'));
    }

    public function testRollbackRevertsNothingWhenOneTargetHasNoDownScript(): void
    {
        $this->writeMigration('001_users.sql', 'CREATE TABLE users (id INTEGER PRIMARY KEY);');
        $this->writeMigration('002_posts.sql', 'CREATE TABLE posts (id INTEGER PRIMARY KEY);');
        $this->writeMigration('002_posts.down.sql', 'DROP TABLE posts;');

        $pdo = $this->sqlite();
        $runner = new MigrationRunner($this->poolFor($pdo), $this->configFor($this->migrationsDir));
        $runner->run();

        try {
            $runner->rollback(2);
            self::fail('Expected a DatabaseException for the version without a down script.');
        } catch (DatabaseException $exception) {
            self::assertStringContainsString('001_users.down.sql', $exception->getMessage());
        }

        // 002 had a down script, but the check runs before anything is reverted.
        self::assertTrue($this->tableExists($pdo, 'posts'));
        self::assertSame(['001_users', '002_posts'], $this->loggedVersions($pdo));
    }

    public function testRollbackRefusesAVersionWhoseScriptIsGone(): void
    {
        $pdo = $this->sqlite();
        $runner = $this->migratedPair($pdo);
        unlink($this->migrationsDir . '/002_posts.sql');

        $this->expectException(DatabaseException::class);
        $this->expectExceptionMessage('no longer on disk');

        $runner->rollback();
    }

    public function testAFailingDownScriptIsRolledBackAndKeepsTheVersionApplied(): void
    {
        $this->writeMigration('001_users.sql', 'CREATE TABLE users (id INTEGER PRIMARY KEY);');
        $this->writeMigration('001_users.down.sql', 'DROP TABLE users; NOT VALID SQL;');

        $pdo = $this->sqlite();
        $runner = new MigrationRunner($this->poolFor($pdo), $this->configFor($this->migrationsDir));
        $runner->run();

        try {
            $runner->rollback();
            self::fail('Expected a DatabaseException for the broken down script.');
        } catch (DatabaseException $exception) {
            self::assertStringContainsString('Rollback of "001_users" failed', $exception->getMessage());
        }

        self::assertTrue($this->tableExists($pdo, 'users'));
        self::assertSame(['001_users'], $this->loggedVersions($pdo));
    }

    public function testRollbackNeedsAtLeastOneStep(): void
    {
        $runner = new MigrationRunner($this->poolFor($this->sqlite()), $this->configFor($this->migrationsDir));

        $this->expectException(InvalidArgumentException::class);

        $runner->rollback(0);
    }

    public function testStatusReportsAppliedPendingAndMissingVersions(): void
    {
        $pdo = $this->sqlite();
        $runner = $this->migratedPair($pdo);
        $this->writeMigration('003_tags.sql', 'CREATE TABLE tags (id INTEGER PRIMARY KEY);');
        unlink($this->migrationsDir . '/001_users.sql');
        unlink($this->migrationsDir . '/001_users.down.sql');

        $report = array_map(static fn(MigrationState $state): array => [
            $state->version,
            $state->status,
            $state->reversible,
            $state->appliedAt !== null,
        ], $runner->status());

        self::assertSame(
            [
                ['001_users', MigrationStatus::Missing, false, true],
                ['002_posts', MigrationStatus::Applied, true,  true],
                ['003_tags',  MigrationStatus::Pending, false, false],
            ],
            $report,
        );
    }

    public function testStatusOfAnEmptyProjectIsEmpty(): void
    {
        $runner = new MigrationRunner($this->poolFor($this->sqlite()), $this->configFor($this->migrationsDir));

        self::assertSame([], $runner->status());
    }

    public function testAnExplicitDialectOverridesTheDriverName(): void
    {
        $this->writeMigration('001_users.sql', 'CREATE TABLE users (id INTEGER PRIMARY KEY);');

        $pdo = $this->sqlite();
        $runner = new MigrationRunner($this->poolFor($pdo), $this->configFor($this->migrationsDir), SQLDialect::SQLite);

        self::assertSame(['001_users'], $runner->run());
    }

    public function testAnUnreadableLogSurfacesAsADatabaseException(): void
    {
        $pdo = $this->sqlite();
        // A log table without the applied_at column cannot answer the ledger query.
        $pdo->exec('CREATE TABLE waffle_migrations (version VARCHAR(255) PRIMARY KEY)');
        $runner = new MigrationRunner($this->poolFor($pdo), $this->configFor($this->migrationsDir));

        $this->expectException(DatabaseException::class);
        $this->expectExceptionMessage('Unable to read the migration log.');

        $runner->status();
    }

    private function migratedPair(PDO $pdo): MigrationRunner
    {
        $this->writeMigration('001_users.sql', 'CREATE TABLE users (id INTEGER PRIMARY KEY);');
        $this->writeMigration('001_users.down.sql', 'DROP TABLE users;');
        $this->writeMigration('002_posts.sql', 'CREATE TABLE posts (id INTEGER PRIMARY KEY);');
        $this->writeMigration('002_posts.down.sql', 'DROP TABLE posts;');

        $runner = new MigrationRunner($this->poolFor($pdo), $this->configFor($this->migrationsDir));
        $runner->run();

        return $runner;
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
        return new MigrationsPathConfig($migrationsPath);
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
