<?php

declare(strict_types=1);

namespace Waffle\Commons\Data\Repository;

use Generator;
use InvalidArgumentException;
use PDO;
use PDOException;
use PDOStatement;
use Throwable;
use Waffle\Commons\Contracts\Data\Connection\RelationalConnectionPoolInterface;
use Waffle\Commons\Contracts\Data\Mapper\DataMapperInterface;
use Waffle\Commons\Contracts\Data\Query\QueryInterface;
use Waffle\Commons\Contracts\Data\Repository\WritableRepositoryInterface;
use Waffle\Commons\Contracts\Telemetry\NullTracer;
use Waffle\Commons\Contracts\Telemetry\TracerInterface;
use Waffle\Commons\Data\Compiler\CompiledQuery;
use Waffle\Commons\Data\Compiler\CompiledWrite;
use Waffle\Commons\Data\Compiler\SQLCompiler;
use Waffle\Commons\Data\Compiler\SQLWriteCompiler;
use Waffle\Commons\Data\Exception\DatabaseException;
use Waffle\Commons\Data\Hydrator\PropertyHookHydrator;
use Waffle\Commons\Data\Hydrator\RowNormaliser;
use Waffle\Commons\Data\Query\Criteria;
use Waffle\Commons\Data\Query\Query;
use Waffle\Commons\Data\Telemetry\QueryTracer;

use function is_bool;
use function is_int;

/**
 * Stateless relational repository (RFC-022 §3): SQR in, immutable DTOs out.
 *
 * Each call borrows a pooled connection (ping-before-dispense), compiles the
 * SQR into a parameterised statement, hydrates every row through PHP 8.5
 * Property Hook validation, and returns the connection — no Identity Map, no
 * change tracking, no per-request state of any kind survives the call, so one
 * instance is safe to share across FrankenPHP resident-worker requests.
 *
 * {@see self::stream()} honours the buffer-streaming mandate (§4.1): rows are
 * fetched one at a time from the driver cursor and yielded as hydrated DTOs, so
 * a large result set never materialises in memory at once.
 *
 * @template T of object
 *
 * @implements WritableRepositoryInterface<T>
 */
final class SQLRepository implements WritableRepositoryInterface
{
    /** @var PropertyHookHydrator<T> */
    private readonly PropertyHookHydrator $hydrator;

    private readonly RowNormaliser $normaliser;

    private readonly SQLWriteCompiler $writeCompiler;

    private QueryTracer $queryTracer;

    /**
     * @param RelationalConnectionPoolInterface $pool          Worker-safe relational pool.
     * @param class-string<T>             $target        `readonly` DTO each row hydrates into.
     * @param SQLCompiler                 $compiler      Dialect-aware read (SELECT) compiler.
     * @param DataMapperInterface<T>|null $mapper        Write/identity mapper; omit for a read-only
     *                                                   repository (save / delete / findById then
     *                                                   throw, the read methods keep working).
     * @param SQLWriteCompiler|null       $writeCompiler Write compiler; SHOULD share the read
     *                                                   compiler's dialect (defaults to MySQL).
     */
    public function __construct(
        private readonly RelationalConnectionPoolInterface $pool,
        string $target,
        private readonly SQLCompiler $compiler = new SQLCompiler(),
        private readonly ?DataMapperInterface $mapper = null,
        ?SQLWriteCompiler $writeCompiler = null,
    ) {
        $this->hydrator = new PropertyHookHydrator($target);
        $this->normaliser = new RowNormaliser();
        $this->writeCompiler = $writeCompiler ?? new SQLWriteCompiler();
        $this->queryTracer = new QueryTracer(new NullTracer(), 'sql');
    }

    /**
     * Return a copy that emits `waffle.db.query` spans through the given tracer
     * (OBS-01). Tracing is opt-in at wiring time; the no-op default keeps the
     * hot path free and the returned instance stays immutable across worker
     * requests.
     *
     * @return self<T>
     */
    public function withTracer(TracerInterface $tracer): self
    {
        $clone = clone $this;
        $clone->queryTracer = new QueryTracer($tracer, 'sql');

        return $clone;
    }

    /**
     * @return list<T>
     *
     * @throws Throwable When the backend call fails or a row cannot be hydrated.
     */
    #[\Override]
    public function find(QueryInterface $query): array
    {
        $span = $this->queryTracer->open('find');

        try {
            return $this->runFind($query);
        } catch (Throwable $error) {
            $this->queryTracer->fail($span, $error);
        } finally {
            $span->end();
        }
    }

    /**
     * @return list<T>
     *
     * @throws InvalidArgumentException When the query cannot be represented on
     *         this backend (e.g. it has no source table).
     * @throws \Waffle\Commons\Contracts\Data\Exception\DatabaseExceptionInterface
     * @throws \Waffle\Commons\Contracts\Exception\Validation\ValidationExceptionInterface
     */
    private function runFind(QueryInterface $query): array
    {
        $compiled = $this->compiler->compile($query);
        $lease = $this->pool->acquire();
        $connection = $lease->pdo();

        try {
            $statement = $this->execute($connection, $compiled);
            $rows = $this->normaliser->normaliseAll($statement->fetchAll(PDO::FETCH_ASSOC));
            $statement->closeCursor();
        } catch (PDOException $error) {
            throw DatabaseException::fromThrowable($error, 'Failed to execute the find query.');
        } finally {
            $this->pool->release($lease);
        }

        $hydrated = [];
        foreach ($rows as $row) {
            $hydrated[] = $this->hydrator->hydrate($row);
        }

        return $hydrated;
    }

    /**
     * @return T|null
     *
     * @throws Throwable When the backend call fails or the row cannot be hydrated.
     */
    #[\Override]
    public function findOne(QueryInterface $query): ?object
    {
        // Bound the call server-side when the SQR is ours to rebuild — never
        // discard rows client-side.
        $bounded = $query instanceof Query ? $query->limit(1) : $query;

        $span = $this->queryTracer->open('findOne');

        try {
            return $this->runFind($bounded)[0] ?? null;
        } catch (Throwable $error) {
            $this->queryTracer->fail($span, $error);
        } finally {
            $span->end();
        }
    }

    /**
     * @throws Throwable When the repository has no mapper or the backend write fails.
     */
    #[\Override]
    public function save(object $entity): void
    {
        $span = $this->queryTracer->open('save');

        try {
            $mapper = $this->requireMapper();
            $id = $mapper->identify($entity);
            $row = $mapper->toRow($entity);

            $compiled = $id === null
                ? $this->writeCompiler->compileInsert($mapper->target(), $row)
                : $this->writeCompiler->compileUpdate($mapper->target(), $row, $mapper->identityField(), $id);

            $this->executeWrite($compiled);
        } catch (Throwable $error) {
            $this->queryTracer->fail($span, $error);
        } finally {
            $span->end();
        }
    }

    /**
     * @throws Throwable When the repository has no mapper, the entity carries no
     *         identity, or the backend write fails.
     */
    #[\Override]
    public function delete(object $entity): void
    {
        $span = $this->queryTracer->open('delete');

        try {
            $mapper = $this->requireMapper();
            $id = $mapper->identify($entity);
            if ($id === null) {
                throw new InvalidArgumentException('Cannot delete an entity that carries no identity.');
            }

            $this->executeWrite($this->writeCompiler->compileDelete($mapper->target(), $mapper->identityField(), $id));
        } catch (Throwable $error) {
            $this->queryTracer->fail($span, $error);
        } finally {
            $span->end();
        }
    }

    /**
     * @return T|null
     *
     * @throws Throwable When the repository has no mapper, the backend call
     *         fails, or the row cannot be hydrated.
     */
    #[\Override]
    public function findById(int|string $id): ?object
    {
        $span = $this->queryTracer->open('findById');

        try {
            $mapper = $this->requireMapper();

            $query = Query::select()
                ->from($mapper->target())
                ->where(Criteria::eq($mapper->identityField(), $id))
                ->limit(1);

            return $this->runFind($query)[0] ?? null;
        } catch (Throwable $error) {
            $this->queryTracer->fail($span, $error);
        } finally {
            $span->end();
        }
    }

    /**
     * @return Generator<int, T>
     *
     * @throws Throwable When the backend call fails or a row cannot be hydrated.
     */
    #[\Override]
    public function stream(QueryInterface $query): Generator
    {
        $span = $this->queryTracer->open('stream');

        try {
            yield from $this->runStream($query);
        } catch (Throwable $error) {
            $this->queryTracer->fail($span, $error);
        } finally {
            $span->end();
        }
    }

    /**
     * @return Generator<int, T>
     *
     * @throws InvalidArgumentException When the query cannot be represented on
     *         this backend (e.g. it has no source table).
     * @throws \Waffle\Commons\Contracts\Data\Exception\DatabaseExceptionInterface
     * @throws \Waffle\Commons\Contracts\Exception\Validation\ValidationExceptionInterface
     */
    private function runStream(QueryInterface $query): Generator
    {
        $compiled = $this->compiler->compile($query);
        $lease = $this->pool->acquire();
        $connection = $lease->pdo();

        try {
            $statement = $this->execute($connection, $compiled);

            while (($row = $this->fetchNext($statement)) !== null) {
                yield $this->hydrator->hydrate($row);
            }

            $this->close($statement);
        } finally {
            // Runs on completion, on failure, and when the consumer abandons
            // the generator early — the handle always returns to the pool.
            $this->pool->release($lease);
        }
    }

    /**
     * @throws DatabaseException When the cursor cannot be closed.
     */
    private function close(PDOStatement $statement): void
    {
        try {
            $statement->closeCursor();
        } catch (PDOException $error) {
            throw DatabaseException::fromThrowable($error, 'Failed to close the cursor.');
        }
    }

    /**
     * @throws DatabaseException When the statement cannot be prepared or executed.
     */
    private function execute(PDO $connection, CompiledQuery $compiled): PDOStatement
    {
        try {
            $statement = $connection->prepare($compiled->sql);
            if ($statement === false) {
                throw new DatabaseException('Failed to prepare the compiled query.');
            }

            // Bind with explicit driver types: `execute(array)` would send every
            // operand as a string, which strict engines reject — on PostgreSQL a
            // boolean operand would arrive as the invalid literal ''.
            $position = 1;
            foreach ($compiled->parameters as $value) {
                $statement->bindValue($position, $value, $this->parameterType($value));
                ++$position;
            }

            $statement->execute();

            return $statement;
        } catch (PDOException $error) {
            throw DatabaseException::fromThrowable($error, 'Failed to execute the compiled query.');
        }
    }

    /**
     * @return DataMapperInterface<T>
     *
     * @throws InvalidArgumentException When the repository was constructed read-only.
     */
    private function requireMapper(): DataMapperInterface
    {
        if ($this->mapper === null) {
            throw new InvalidArgumentException(
                'This SQLRepository was constructed without a DataMapper; write operations are unavailable.',
            );
        }

        return $this->mapper;
    }

    /**
     * Run a compiled mutation inside a transaction on a pooled connection,
     * rolling back on failure and always returning the connection to the pool.
     *
     * @throws \Waffle\Commons\Contracts\Data\Exception\DatabaseExceptionInterface
     *         When the pool cannot dispense a connection, or the statement
     *         cannot be prepared or executed.
     */
    private function executeWrite(CompiledWrite $compiled): void
    {
        $lease = $this->pool->acquire();
        $connection = $lease->pdo();

        // DBAL-01: when an outer transaction is already open (the failsafe
        // TransactionIsolationMiddleware pinned this connection and began one),
        // enlist in it — never open or commit a nested one. The outer scope owns
        // the commit/rollback so this write becomes part of it and is rolled back
        // with the request on failure.
        $ownsTransaction = !$connection->inTransaction();

        try {
            if ($ownsTransaction) {
                $connection->beginTransaction();
            }

            $statement = $connection->prepare($compiled->sql);
            if ($statement === false) {
                throw new DatabaseException('Failed to prepare the compiled write.');
            }

            $position = 1;
            foreach ($compiled->parameters as $value) {
                $statement->bindValue($position, $value, $this->parameterType($value));
                ++$position;
            }

            $statement->execute();

            if ($ownsTransaction) {
                $connection->commit();
            }
        } catch (PDOException $error) {
            if ($ownsTransaction) {
                $this->rollBack($connection);
            }

            throw DatabaseException::fromThrowable($error, 'Failed to execute the compiled write.');
        } catch (DatabaseException $error) {
            if ($ownsTransaction) {
                $this->rollBack($connection);
            }

            throw $error;
        } finally {
            $this->pool->release($lease);
        }
    }

    /**
     * Roll back an open transaction during write recovery (RFC-022 §6). A
     * connection already severed mid-flight cannot roll back — it is left for
     * the pool's ping-before-dispense / reset cycle to reap.
     */
    private function rollBack(PDO $connection): void
    {
        if (!$connection->inTransaction()) {
            return;
        }

        try {
            $connection->rollBack();
        } catch (PDOException) {
            // Connection is already broken; the pool reaps it on next dispense.
        }
    }

    private function parameterType(int|float|string|bool|null $value): int
    {
        return match (true) {
            $value === null => PDO::PARAM_NULL,
            is_bool($value) => PDO::PARAM_BOOL,
            is_int($value) => PDO::PARAM_INT,
            default => PDO::PARAM_STR,
        };
    }

    /**
     * @return array<string, int|float|string|bool|null>|null
     *
     * @throws DatabaseException When the cursor read fails or yields a non-row.
     */
    private function fetchNext(PDOStatement $statement): ?array
    {
        try {
            return $this->normaliser->fromFetch($statement);
        } catch (PDOException $error) {
            throw DatabaseException::fromThrowable($error, 'Failed to fetch the next row from the cursor.');
        }
    }
}
