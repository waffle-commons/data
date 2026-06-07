<?php

declare(strict_types=1);

namespace Waffle\Commons\Data\Repository;

use Generator;
use InvalidArgumentException;
use Waffle\Commons\Contracts\Data\Mapper\DataMapperInterface;
use Waffle\Commons\Contracts\Data\Query\QueryInterface;
use Waffle\Commons\Contracts\Data\Repository\WritableRepositoryInterface;
use Waffle\Commons\Data\Compiler\GraphQLCompiler;
use Waffle\Commons\Data\Compiler\GraphQLMutationCompiler;
use Waffle\Commons\Data\Driver\Graph\GraphQLExecutor;
use Waffle\Commons\Data\Hydrator\PropertyHookHydrator;
use Waffle\Commons\Data\Query\Criteria;
use Waffle\Commons\Data\Query\Query;

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
    }

    /**
     * @return list<T>
     *
     * @throws InvalidArgumentException When the query cannot be represented as
     *         GraphQL (no root field, no projection, or an invalid name).
     * @throws \Waffle\Commons\Contracts\Data\Exception\DatabaseExceptionInterface
     * @throws \Waffle\Commons\Contracts\Exception\Validation\ValidationExceptionInterface
     */
    #[\Override]
    public function find(QueryInterface $query): array
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
     * @throws InvalidArgumentException When the query cannot be represented as
     *         GraphQL (no root field, no projection, or an invalid name).
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
     * @throws InvalidArgumentException When the query cannot be represented as
     *         GraphQL (no root field, no projection, or an invalid name).
     * @throws \Waffle\Commons\Contracts\Data\Exception\DatabaseExceptionInterface
     * @throws \Waffle\Commons\Contracts\Exception\Validation\ValidationExceptionInterface
     */
    #[\Override]
    public function stream(QueryInterface $query): Generator
    {
        // GraphQL has no row cursor; yield from the (bounded) result page.
        yield from $this->find($query);
    }

    /**
     * Insert (null identity) or update-by-pk (by identity) via a Hasura-style
     * parameterised mutation.
     *
     * @throws InvalidArgumentException When the repository has no mapper or the
     *         target/identity name is not a valid GraphQL name.
     * @throws \Waffle\Commons\Contracts\Data\Exception\DatabaseExceptionInterface
     */
    #[\Override]
    public function save(object $entity): void
    {
        $mapper = $this->requireMapper();
        $id = $mapper->identify($entity);
        $row = $mapper->toRow($entity);

        $mutation = $id === null
            ? $this->mutationCompiler->compileInsert($mapper->target(), $mapper->identityField(), $row)
            : $this->mutationCompiler->compileUpdate($mapper->target(), $mapper->identityField(), $id, $row);

        $this->executor->mutate($mutation);
    }

    /**
     * @throws InvalidArgumentException When the repository has no mapper, the
     *         entity carries no identity, or the target name is invalid.
     * @throws \Waffle\Commons\Contracts\Data\Exception\DatabaseExceptionInterface
     */
    #[\Override]
    public function delete(object $entity): void
    {
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
    }

    /**
     * @return T|null
     *
     * @throws InvalidArgumentException When the repository has no mapper.
     * @throws \Waffle\Commons\Contracts\Data\Exception\DatabaseExceptionInterface
     * @throws \Waffle\Commons\Contracts\Exception\Validation\ValidationExceptionInterface
     */
    #[\Override]
    public function findById(int|string $id): ?object
    {
        $mapper = $this->requireMapper();

        // GraphQL requires an explicit projection; take it from the mapper.
        $query = Query::select(...$mapper->fields())
            ->from($mapper->target())
            ->where(Criteria::eq($mapper->identityField(), $id))
            ->limit(1);

        return $this->find($query)[0] ?? null;
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
