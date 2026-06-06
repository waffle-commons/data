<?php

declare(strict_types=1);

namespace Waffle\Commons\Data\Repository;

use Generator;
use InvalidArgumentException;
use PDO;
use PDOException;
use PDOStatement;
use Waffle\Commons\Contracts\Data\Connection\ConnectionPoolInterface;
use Waffle\Commons\Contracts\Data\Query\QueryInterface;
use Waffle\Commons\Contracts\Data\Repository\RepositoryInterface;
use Waffle\Commons\Data\Compiler\CompiledQuery;
use Waffle\Commons\Data\Compiler\SQLCompiler;
use Waffle\Commons\Data\Exception\DatabaseException;
use Waffle\Commons\Data\Hydrator\PropertyHookHydrator;
use Waffle\Commons\Data\Hydrator\RowNormaliser;
use Waffle\Commons\Data\Query\Query;

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
 * @implements RepositoryInterface<T>
 */
final class SQLRepository implements RepositoryInterface
{
    /** @var PropertyHookHydrator<T> */
    private readonly PropertyHookHydrator $hydrator;

    private readonly RowNormaliser $normaliser;

    /**
     * @param ConnectionPoolInterface $pool     Worker-safe PDO pool.
     * @param class-string<T>         $target   `readonly` DTO each row hydrates into.
     * @param SQLCompiler             $compiler Dialect-aware SQR compiler.
     */
    public function __construct(
        private readonly ConnectionPoolInterface $pool,
        string $target,
        private readonly SQLCompiler $compiler = new SQLCompiler(),
    ) {
        $this->hydrator = new PropertyHookHydrator($target);
        $this->normaliser = new RowNormaliser();
    }

    /**
     * @return list<T>
     *
     * @throws InvalidArgumentException When the query cannot be represented on
     *         this backend (e.g. it has no source table).
     * @throws \Waffle\Commons\Contracts\Data\Exception\DatabaseExceptionInterface
     * @throws \Waffle\Commons\Contracts\Exception\Validation\ValidationExceptionInterface
     */
    #[\Override]
    public function find(QueryInterface $query): array
    {
        $compiled = $this->compiler->compile($query);
        $connection = $this->pool->acquire();

        try {
            $statement = $this->execute($connection, $compiled);
            $rows = $this->normaliser->normaliseAll($statement->fetchAll(PDO::FETCH_ASSOC));
            $statement->closeCursor();
        } catch (PDOException $error) {
            throw DatabaseException::fromThrowable($error, 'Failed to execute the find query.');
        } finally {
            $this->pool->release($connection);
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
     * @throws InvalidArgumentException When the query cannot be represented on
     *         this backend (e.g. it has no source table).
     * @throws \Waffle\Commons\Contracts\Data\Exception\DatabaseExceptionInterface
     * @throws \Waffle\Commons\Contracts\Exception\Validation\ValidationExceptionInterface
     */
    #[\Override]
    public function findOne(QueryInterface $query): ?object
    {
        // Bound the call server-side when the SQR is ours to rebuild — never
        // discard rows client-side.
        $bounded = $query instanceof Query ? $query->limit(1) : $query;

        return $this->find($bounded)[0] ?? null;
    }

    /**
     * @return Generator<int, T>
     *
     * @throws InvalidArgumentException When the query cannot be represented on
     *         this backend (e.g. it has no source table).
     * @throws \Waffle\Commons\Contracts\Data\Exception\DatabaseExceptionInterface
     * @throws \Waffle\Commons\Contracts\Exception\Validation\ValidationExceptionInterface
     */
    #[\Override]
    public function stream(QueryInterface $query): Generator
    {
        $compiled = $this->compiler->compile($query);
        $connection = $this->pool->acquire();

        try {
            $statement = $this->execute($connection, $compiled);

            while (($row = $this->fetchNext($statement)) !== null) {
                yield $this->hydrator->hydrate($row);
            }

            $this->close($statement);
        } finally {
            // Runs on completion, on failure, and when the consumer abandons
            // the generator early — the handle always returns to the pool.
            $this->pool->release($connection);
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
