<?php

declare(strict_types=1);

namespace Waffle\Commons\Data\Repository;

use Generator;
use InvalidArgumentException;
use Throwable;
use Waffle\Commons\Contracts\Data\Mapper\DataMapperInterface;
use Waffle\Commons\Contracts\Data\Query\QueryInterface;
use Waffle\Commons\Contracts\Data\Repository\WritableRepositoryInterface;
use Waffle\Commons\Contracts\Telemetry\NullTracer;
use Waffle\Commons\Contracts\Telemetry\TracerInterface;
use Waffle\Commons\Data\Compiler\CassandraCompiler;
use Waffle\Commons\Data\Driver\Cql\CqlSessionInterface;
use Waffle\Commons\Data\Hydrator\PropertyHookHydrator;
use Waffle\Commons\Data\Query\Criteria;
use Waffle\Commons\Data\Query\Query;
use Waffle\Commons\Data\Telemetry\QueryTracer;

use function array_fill;
use function array_keys;
use function array_values;
use function count;
use function implode;
use function sprintf;

/**
 * Stateless repository over a Cassandra/CQL backend (RFC-022 §3 + §4.2).
 *
 * The SQR compiles to parameterised CQL (operands bound, never inlined),
 * executes through the injected {@see CqlSessionInterface} transport, and every
 * row hydrates into an immutable DTO through PHP 8.5 Property Hook validation.
 * CQL-incompatible queries (inequality, `NOT IN`, `LIKE`, OFFSET pagination)
 * are rejected at compile time rather than mistranslated.
 *
 * CQL `INSERT` is itself an upsert, so {@see self::save()} compiles to a single
 * INSERT whether or not the entity already exists.
 *
 * @template T of object
 *
 * @implements WritableRepositoryInterface<T>
 */
final class CassandraRepository implements WritableRepositoryInterface
{
    /** @var PropertyHookHydrator<T> */
    private readonly PropertyHookHydrator $hydrator;

    private QueryTracer $queryTracer;

    /**
     * @param CqlSessionInterface         $session  Transport (see the port's note on
     *                                              live CQL access from PHP 8.5).
     * @param class-string<T>             $target   `readonly` DTO each row hydrates into.
     * @param CassandraCompiler           $compiler SQR → CQL compiler.
     * @param DataMapperInterface<T>|null $mapper   Write/identity mapper; omit for a read-only
     *                                              repository (save / delete / findById then throw).
     */
    public function __construct(
        private readonly CqlSessionInterface $session,
        string $target,
        private readonly CassandraCompiler $compiler = new CassandraCompiler(),
        private readonly ?DataMapperInterface $mapper = null,
    ) {
        $this->hydrator = new PropertyHookHydrator($target);
        $this->queryTracer = new QueryTracer(new NullTracer(), 'cassandra');
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
        $clone->queryTracer = new QueryTracer($tracer, 'cassandra');

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
     * @throws InvalidArgumentException When the query is CQL-incompatible.
     * @throws \Waffle\Commons\Contracts\Data\Exception\DatabaseExceptionInterface
     * @throws \Waffle\Commons\Contracts\Exception\Validation\ValidationExceptionInterface
     */
    private function runFind(QueryInterface $query): array
    {
        $hydrated = [];
        foreach ($this->session->execute($this->compiler->compile($query)) as $row) {
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
        // CQL supports LIMIT: bound the call server-side when the SQR is ours
        // to rebuild — never discard rows client-side.
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
     * @return Generator<int, T>
     *
     * @throws Throwable When the backend call fails or a row cannot be hydrated.
     */
    #[\Override]
    public function stream(QueryInterface $query): Generator
    {
        $span = $this->queryTracer->open('stream');

        try {
            // Token-based paging lives in the transport; yield from the bounded page.
            yield from $this->runFind($query);
        } catch (Throwable $error) {
            $this->queryTracer->fail($span, $error);
        } finally {
            $span->end();
        }
    }

    /**
     * Upsert the entity with a single parameterised CQL INSERT (operands bound,
     * never inlined).
     *
     * @throws Throwable When the repository has no mapper, the mapped row is
     *         empty, or the backend write fails.
     */
    #[\Override]
    public function save(object $entity): void
    {
        $span = $this->queryTracer->open('save');

        try {
            $mapper = $this->requireMapper();
            $row = $mapper->toRow($entity);
            if ($row === []) {
                throw new InvalidArgumentException('Cannot INSERT an empty row.');
            }

            $columns = array_keys($row);
            $cql = sprintf(
                'INSERT INTO %s (%s) VALUES (%s)',
                $mapper->target(),
                implode(', ', $columns),
                implode(', ', array_fill(0, count($columns), '?')),
            );

            $this->session->executeWrite($cql, array_values($row));
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

            $cql = sprintf('DELETE FROM %s WHERE %s = ?', $mapper->target(), $mapper->identityField());

            $this->session->executeWrite($cql, [$id]);
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
     * @return DataMapperInterface<T>
     *
     * @throws InvalidArgumentException When the repository was constructed read-only.
     */
    private function requireMapper(): DataMapperInterface
    {
        if ($this->mapper === null) {
            throw new InvalidArgumentException(
                'This CassandraRepository was constructed without a DataMapper; write operations are unavailable.',
            );
        }

        return $this->mapper;
    }
}
