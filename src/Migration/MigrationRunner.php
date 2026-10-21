<?php

declare(strict_types=1);

namespace Waffle\Commons\Data\Migration;

use Closure;
use InvalidArgumentException;
use PDO;
use PDOException;
use Throwable;
use Waffle\Commons\Contracts\Config\ConfigInterface;
use Waffle\Commons\Contracts\Data\Connection\RelationalConnectionPoolInterface;
use Waffle\Commons\Contracts\Data\Exception\DatabaseExceptionInterface;
use Waffle\Commons\Contracts\Data\Migration\Enum\MigrationStatus;
use Waffle\Commons\Contracts\Data\Migration\MigrationRunnerInterface;
use Waffle\Commons\Contracts\Data\Migration\MigrationState;
use Waffle\Commons\Data\Compiler\SQLDialect;
use Waffle\Commons\Data\Exception\DatabaseException;
use Waffle\Commons\Data\Hydrator\RowNormaliser;

use function array_key_exists;
use function array_keys;
use function array_slice;
use function array_unique;
use function basename;
use function file_get_contents;
use function glob;
use function is_file;
use function rsort;
use function rtrim;
use function sort;
use function sprintf;
use function str_ends_with;
use function substr;

use const SORT_STRING;

/**
 * Lightweight SQL migration runner (RFC-022, [OPS-03]).
 *
 * The runner borrows a single connection from the {@see RelationalConnectionPoolInterface},
 * provisions a `waffle_migrations` log table on first use, then applies every
 * `*.sql` script found in the configured directory whose version is not yet
 * recorded — in lexicographic version order. Each script runs inside its own
 * transaction: on failure the transaction is rolled back, the run aborts, and a
 * {@see DatabaseException} carrying the offending version is thrown.
 *
 * A version `V` is the file `V.sql`; `V.down.sql`, when present, is its down
 * script. {@see self::rollback()} reverts the most recent versions through those
 * scripts, and {@see self::status()} reports applied, pending and missing versions.
 *
 * There is no identity map, no schema diffing and no filesystem scan during a
 * request: discovery happens only when the CLI calls the runner.
 *
 * Engine note: per-migration transactions make a run fully atomic on databases
 * with transactional DDL (SQLite, PostgreSQL, SQL Server). On MySQL, MariaDB and
 * Oracle, DDL statements (`CREATE TABLE`, `ALTER TABLE`, …) trigger an implicit
 * commit, so a half-applied DDL step cannot be undone there — keep one schema
 * change per migration file on those engines so a failure stays recoverable by
 * writing a compensating migration.
 */
final class MigrationRunner implements MigrationRunnerInterface
{
    private const string PATH_KEY = 'waffle.database.migrations_path';
    private const string DEFAULT_PATH = 'migrations';
    private const string DOWN_SUFFIX = '.down.sql';

    /**
     * @param SQLDialect|null $dialect The ledger dialect; null reads it from the
     *        leased connection's PDO driver name.
     */
    public function __construct(
        private readonly RelationalConnectionPoolInterface $pool,
        private readonly ConfigInterface $config,
        private readonly ?SQLDialect $dialect = null,
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
        $lease = $this->pool->acquire();
        $connection = $lease->pdo();

        try {
            $ledger = $this->ledgerFor($connection);
            $this->ensureLogTable($connection, $ledger);
            $applied = $this->appliedVersions($connection, $ledger);

            $performed = [];
            foreach ($this->discover() as $version => $scripts) {
                if (array_key_exists($version, $applied)) {
                    continue;
                }

                $this->execute($connection, $ledger->insert(), $version, $scripts['up'], 'Migration');
                $performed[] = $version;

                if ($onApplied !== null) {
                    $onApplied($version);
                }
            }

            return $performed;
        } finally {
            // Return the borrowed handle to the pool; the CLI command then calls
            // reset() to clear worker-scoped state before the process exits.
            $this->pool->release($lease);
        }
    }

    /**
     * @param (Closure(string): void)|null $onReverted
     *
     * @return list<string>
     *
     * @throws InvalidArgumentException
     * @throws DatabaseExceptionInterface
     */
    #[\Override]
    public function rollback(int $steps = 1, ?Closure $onReverted = null): array
    {
        if ($steps < 1) {
            throw new InvalidArgumentException(sprintf('Rollback steps must be at least 1, got %d.', $steps));
        }

        $lease = $this->pool->acquire();
        $connection = $lease->pdo();

        try {
            $ledger = $this->ledgerFor($connection);
            $this->ensureLogTable($connection, $ledger);

            $versions = array_keys($this->appliedVersions($connection, $ledger));
            rsort($versions, SORT_STRING);
            $targets = array_slice($versions, 0, $steps);

            // Check every target before reverting any, so a missing down script
            // never leaves the schema half rolled back.
            $discovered = $this->discover();
            $downScripts = [];
            foreach ($targets as $version) {
                $downScripts[$version] = $this->downScriptOf($version, $discovered);
            }

            $reverted = [];
            foreach ($downScripts as $version => $path) {
                $this->execute($connection, $ledger->delete(), $version, $path, 'Rollback of');
                $reverted[] = $version;

                if ($onReverted !== null) {
                    $onReverted($version);
                }
            }

            return $reverted;
        } finally {
            $this->pool->release($lease);
        }
    }

    /**
     * @return list<MigrationState>
     *
     * @throws DatabaseExceptionInterface
     */
    #[\Override]
    public function status(): array
    {
        $lease = $this->pool->acquire();
        $connection = $lease->pdo();

        try {
            $ledger = $this->ledgerFor($connection);
            $this->ensureLogTable($connection, $ledger);
            $applied = $this->appliedVersions($connection, $ledger);
            $discovered = $this->discover();

            $versions = array_unique([...array_keys($discovered), ...array_keys($applied)]);
            sort($versions, SORT_STRING);

            $states = [];
            foreach ($versions as $version) {
                $onDisk = $discovered[$version] ?? null;
                $status = match (true) {
                    !array_key_exists($version, $applied) => MigrationStatus::Pending,
                    $onDisk === null => MigrationStatus::Missing,
                    default => MigrationStatus::Applied,
                };

                $states[] = new MigrationState(
                    version: $version,
                    status: $status,
                    reversible: $onDisk !== null && $onDisk['down'] !== null,
                    appliedAt: $applied[$version] ?? null,
                );
            }

            return $states;
        } finally {
            $this->pool->release($lease);
        }
    }

    private function ledgerFor(PDO $connection): MigrationLedger
    {
        if ($this->dialect !== null) {
            return new MigrationLedger($this->dialect);
        }

        try {
            return new MigrationLedger(MigrationLedger::dialectOf($connection));
        } catch (PDOException) {
            // A driver that cannot name itself gets the portable statements.
            return new MigrationLedger();
        }
    }

    /**
     * @throws DatabaseException When the log table cannot be provisioned.
     */
    private function ensureLogTable(PDO $connection, MigrationLedger $ledger): void
    {
        try {
            $connection->exec($ledger->createTable());
        } catch (Throwable $failure) {
            throw DatabaseException::fromThrowable($failure, 'Unable to ensure the migration log table exists.');
        }
    }

    /**
     * The recorded versions, each mapped to its `applied_at` text.
     *
     * @return array<string, string|null>
     *
     * @throws DatabaseException When the migration log cannot be read.
     */
    private function appliedVersions(PDO $connection, MigrationLedger $ledger): array
    {
        try {
            $statement = $connection->query($ledger->selectAll());
            if ($statement === false) {
                // `false` only occurs on a non-exception driver; treat it as "none".
                return [];
            }

            $rows = new RowNormaliser()->normaliseAll($statement->fetchAll(PDO::FETCH_ASSOC));
        } catch (Throwable $failure) {
            throw DatabaseException::fromThrowable($failure, 'Unable to read the migration log.');
        }

        $applied = [];
        foreach ($rows as $row) {
            // Oracle reports unquoted column names in upper case.
            $version = $row['version'] ?? $row['VERSION'] ?? null;
            $appliedAt = $row['applied_at'] ?? $row['APPLIED_AT'] ?? null;
            if ($version === null) {
                continue;
            }

            $applied[(string) $version] = $appliedAt === null ? null : (string) $appliedAt;
        }

        return $applied;
    }

    /**
     * Discover migration scripts in deterministic version order, each with its
     * down script when one exists.
     *
     * @return array<string, array{up: string, down: string|null}>
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
            if (str_ends_with($path, self::DOWN_SUFFIX)) {
                continue;
            }

            $down = substr($path, 0, -4) . self::DOWN_SUFFIX;
            $migrations[basename($path, '.sql')] = ['up' => $path, 'down' => is_file($down) ? $down : null];
        }

        return $migrations;
    }

    /**
     * @param array<string, array{up: string, down: string|null}> $discovered
     *
     * @throws DatabaseException When the version cannot be reverted.
     */
    private function downScriptOf(string $version, array $discovered): string
    {
        $scripts = $discovered[$version] ?? null;
        if ($scripts === null) {
            throw new DatabaseException(sprintf(
                'Cannot roll back "%s": it is recorded in the migration log but its script is no longer on disk.',
                $version,
            ));
        }

        if ($scripts['down'] === null) {
            throw new DatabaseException(sprintf(
                'Cannot roll back "%s": it has no down script (%s%s).',
                $version,
                $version,
                self::DOWN_SUFFIX,
            ));
        }

        return $scripts['down'];
    }

    private function migrationsDirectory(): string
    {
        return $this->config->getString(self::PATH_KEY, self::DEFAULT_PATH) ?? self::DEFAULT_PATH;
    }

    /**
     * Run one script inside its own transaction, then apply the ledger statement
     * (record or erase the version) before committing.
     *
     * @throws DatabaseException When the file cannot be read or the script fails
     *         (the transaction is rolled back before this throws).
     */
    private function execute(PDO $connection, string $ledgerSql, string $version, string $path, string $action): void
    {
        $sql = file_get_contents($path);
        if ($sql === false) {
            throw new DatabaseException(sprintf('Unable to read migration file "%s".', $path));
        }

        try {
            $connection->beginTransaction();
            $connection->exec($sql);

            $statement = $connection->prepare($ledgerSql);
            if ($statement === false) {
                throw new DatabaseException(sprintf('Unable to update the migration log for "%s".', $version));
            }
            $statement->execute([$version]);

            // On MySQL, MariaDB and Oracle a DDL statement commits implicitly and ends
            // the transaction; PHP 8's commit() would then throw although the work
            // succeeded.
            if ($connection->inTransaction()) {
                $connection->commit();
            }
        } catch (Throwable $failure) {
            $this->rollBackQuietly($connection);

            if ($failure instanceof DatabaseException) {
                throw $failure;
            }

            throw DatabaseException::fromThrowable($failure, sprintf(
                '%s "%s" failed and was rolled back; the run was aborted.',
                $action,
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
