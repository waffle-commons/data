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
use Waffle\Commons\Data\Compiler\GraphQLCompiler;
use Waffle\Commons\Data\Compiler\GraphQLMutationCompiler;
use Waffle\Commons\Data\Driver\Graph\GraphQLExecutor;
use Waffle\Commons\Data\Hydrator\PropertyHookHydrator;
use Waffle\Commons\Data\Query\Criteria;
use Waffle\Commons\Data\Query\Query;
use Waffle\Commons\Data\Telemetry\QueryTracer;

/**
 * Stateless repository over a GraphQL endpoint (RFC-022 §3 + §4.3): the
 * external service is treated as a virtual database engine. The SQR compiles to
 * a parameterised query document (operands as declared variables, never
 * inlined), executes over PSR-18, and every result row hydrates into an
 * immutable DTO through PHP 8.5 Property Hook validation.
 *
 * @template T of object
 *
 * @implements WritableRepositoryInterface<T>
 */
final class GraphQLRepository implements WritableRepositoryInterface
{
    /** @var PropertyHookHydrator<T> */
    private readonly PropertyHookHydrator $hydrator;

    private readonly GraphQLMutationCompiler $mutationCompiler;

    private QueryTracer $queryTracer;

    /**
     * @param GraphQLExecutor             $executor Live PSR-18 network executor.
     * @param class-string<T>             $target   `readonly` DTO each row hydrates into.
     * @param GraphQLCompiler             $compiler SQR → query-document compiler.
     * @param DataMapperInterface<T>|null $mapper   Write/identity mapper; omit for a read-only
     *                                              repository (save / delete / findById then throw).
     */
    public function __construct(
        private readonly GraphQLExecutor $executor,
        string $target,
        private readonly GraphQLCompiler $compiler = new GraphQLCompiler(),
        private readonly ?DataMapperInterface $mapper = null,
    ) {
        $this->hydrator = new PropertyHookHydrator($target);
        $this->mutationCompiler = new GraphQLMutationCompiler();
        $this->queryTracer = new QueryTracer(new NullTracer(), 'graphql');
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
        $clone->queryTracer = new QueryTracer($tracer, 'graphql');

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
     * @throws InvalidArgumentException When the query cannot be represented as
     *         GraphQL (no root field, no projection, or an invalid name).
     * @throws \Waffle\Commons\Contracts\Data\Exception\DatabaseExceptionInterface
     * @throws \Waffle\Commons\Contracts\Exception\Validation\ValidationExceptionInterface
     */
    private function runFind(QueryInterface $query): array
    {
        $hydrated = [];
        foreach ($this->executor->execute($this->compiler->compile($query)) as $row) {
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
     * @return Generator<int, T>
     *
     * @throws Throwable When the backend call fails or a row cannot be hydrated.
     */
    #[\Override]
    public function stream(QueryInterface $query): Generator
    {
        $span = $this->queryTracer->open('stream');

        try {
            // GraphQL has no row cursor; yield from the (bounded) result page.
            yield from $this->runFind($query);
        } catch (Throwable $error) {
            $this->queryTracer->fail($span, $error);
        } finally {
            $span->end();
        }
    }

    /**
     * Insert (null identity) or update-by-pk (by identity) via a Hasura-style
     * parameterised mutation.
     *
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

            $mutation = $id === null
                ? $this->mutationCompiler->compileInsert($mapper->target(), $mapper->identityField(), $row)
                : $this->mutationCompiler->compileUpdate($mapper->target(), $mapper->identityField(), $id, $row);

            $this->executor->mutate($mutation);
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

            $this->executor->mutate($this->mutationCompiler->compileDelete(
                $mapper->target(),
                $mapper->identityField(),
                $id,
            ));
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

            // GraphQL requires an explicit projection; take it from the mapper.
            $query = Query::select(...$mapper->fields())
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
                'This GraphQLRepository was constructed without a DataMapper; write operations are unavailable.',
            );
        }

        return $this->mapper;
    }
}
