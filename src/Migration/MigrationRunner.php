<?php

declare(strict_types=1);

namespace Waffle\Commons\Data\Migration;

use Closure;
use PDO;
use Throwable;
use Waffle\Commons\Contracts\Config\ConfigInterface;
use Waffle\Commons\Contracts\Data\Connection\ConnectionPoolInterface;
use Waffle\Commons\Contracts\Data\Exception\DatabaseExceptionInterface;
use Waffle\Commons\Contracts\Data\Migration\MigrationRunnerInterface;
use Waffle\Commons\Data\Exception\DatabaseException;

use function basename;
use function file_get_contents;
use function glob;
use function in_array;
use function rtrim;
use function sort;
use function sprintf;

/**
 * Lightweight, forward-only SQL migration runner (RFC-022).
 *
 * The runner borrows a single connection from the {@see ConnectionPoolInterface},
 * provisions a `waffle_migrations` log table on first use, then applies every
 * `*.sql` script found in the configured directory whose version is not yet
 * recorded — in lexicographic version order. Each script runs inside its own
 * transaction: on failure the transaction is rolled back, the run aborts, and a
 * {@see DatabaseException} carrying the offending version is thrown.
 *
 * There is no identity map, no schema diffing and no filesystem scan during a
 * request: discovery happens only when {@see self::run()} is called from the CLI.
 *
 * Engine note: per-migration transactions make a run fully atomic on databases
 * with transactional DDL (SQLite, PostgreSQL). On MySQL, DDL statements
 * (`CREATE TABLE`, `ALTER TABLE`, …) trigger an implicit commit, so a half-applied
 * DDL step cannot be undone there — keep one schema change per migration file on
 * MySQL so a failure stays recoverable by writing a compensating migration.
 */
final class MigrationRunner implements MigrationRunnerInterface
{
    private const string MIGRATIONS_TABLE = 'waffle_migrations';
    private const string PATH_KEY = 'waffle.database.migrations_path';
    private const string DEFAULT_PATH = 'migrations';

    public function __construct(
        private readonly ConnectionPoolInterface $pool,
        private readonly ConfigInterface $config,
    ) {}

    /**
     * @param (Closure(string): void)|null $onApplied
     *
     * @return list<string>
     *
     * @throws DatabaseExceptionInterface
     */
    #[\Override]
    public function run(?Closure $onApplied = null): array
    {
        $connection = $this->pool->acquire();

        try {
            $this->ensureLogTable($connection);
            $applied = $this->appliedVersions($connection);

            $performed = [];
            foreach ($this->discover() as $migration) {
                if (in_array($migration['version'], $applied, true)) {
                    continue;
                }

                $this->apply($connection, $migration['version'], $migration['path']);
                $performed[] = $migration['version'];

                if ($onApplied !== null) {
                    $onApplied($migration['version']);
                }
            }

            return $performed;
        } finally {
            // Return the borrowed handle to the pool; the CLI command then calls
            // reset() to clear worker-scoped state before the process exits.
            $this->pool->release($connection);
        }
    }

    /**
     * @throws DatabaseException When the log table cannot be provisioned.
     */
    private function ensureLogTable(PDO $connection): void
    {
        try {
            // The table name is a fixed constant, never user input — safe to inline.
            $connection->exec(sprintf(
                'CREATE TABLE IF NOT EXISTS %s '
                . '(version VARCHAR(255) PRIMARY KEY, applied_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)',
                self::MIGRATIONS_TABLE,
            ));
        } catch (Throwable $failure) {
            throw DatabaseException::fromThrowable($failure, 'Unable to ensure the migration log table exists.');
        }
    }

    /**
     * Returns the recorded version keys. They are only ever membership-tested
     * (`in_array`) against discovered versions, so the raw column list is enough.
     *
     * @throws DatabaseException When the migration log cannot be read.
     */
    private function appliedVersions(PDO $connection): array
    {
        try {
            $statement = $connection->query(sprintf('SELECT version FROM %s', self::MIGRATIONS_TABLE));

            // `false` only occurs on a non-exception driver; treat it as "none".
            return $statement === false ? [] : $statement->fetchAll(PDO::FETCH_COLUMN);
        } catch (Throwable $failure) {
            throw DatabaseException::fromThrowable($failure, 'Unable to read the migration log.');
        }
    }

    /**
     * Discover migration scripts in deterministic version order.
     *
     * @return list<array{version: string, path: string}>
     */
    private function discover(): array
    {
        $directory = rtrim($this->migrationsDirectory(), '/');
        $matches = glob($directory . '/*.sql');
        if ($matches === false) {
            return [];
        }

        sort($matches);

        $migrations = [];
        foreach ($matches as $path) {
            $migrations[] = ['version' => basename($path, '.sql'), 'path' => $path];
        }

        return $migrations;
    }

    private function migrationsDirectory(): string
    {
        return $this->config->getString(self::PATH_KEY, self::DEFAULT_PATH) ?? self::DEFAULT_PATH;
    }

    /**
     * Apply one migration inside its own transaction and record it on success.
     *
     * @throws DatabaseException When the file cannot be read or the script fails
     *         (the transaction is rolled back before this throws).
     */
    private function apply(PDO $connection, string $version, string $path): void
    {
        $sql = file_get_contents($path);
        if ($sql === false) {
            throw new DatabaseException(sprintf('Unable to read migration file "%s".', $path));
        }

        try {
            $connection->beginTransaction();
            $connection->exec($sql);

            $insert = $connection->prepare(sprintf('INSERT INTO %s (version) VALUES (?)', self::MIGRATIONS_TABLE));
            if ($insert === false) {
                throw new DatabaseException(sprintf('Unable to record migration "%s" in the log.', $version));
            }
            $insert->execute([$version]);

            $connection->commit();
        } catch (Throwable $failure) {
            $this->rollBackQuietly($connection);

            if ($failure instanceof DatabaseException) {
                throw $failure;
            }

            throw DatabaseException::fromThrowable($failure, sprintf(
                'Migration "%s" failed and was rolled back; the run was aborted.',
                $version,
            ));
        }
    }

    private function rollBackQuietly(PDO $connection): void
    {
        if (!$connection->inTransaction()) {
            return;
        }

        try {
            $connection->rollBack();
        } catch (Throwable) {
            // The transaction is already gone (e.g. a DDL statement triggered an
            // implicit commit on MySQL), so there is nothing left to undo. Swallow
            // it so the original failure is what surfaces to the caller.
        }
    }
}
