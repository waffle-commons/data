<?php

declare(strict_types=1);

namespace Waffle\Commons\Data\Repository;

use Generator;
use InvalidArgumentException;
use Waffle\Commons\Contracts\Data\Query\QueryInterface;
use Waffle\Commons\Contracts\Data\Repository\RepositoryInterface;
use Waffle\Commons\Data\Compiler\GraphQLCompiler;
use Waffle\Commons\Data\Driver\Graph\GraphQLExecutor;
use Waffle\Commons\Data\Hydrator\PropertyHookHydrator;
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
 * @implements RepositoryInterface<T>
 */
final class GraphQLRepository implements RepositoryInterface
{
    /** @var PropertyHookHydrator<T> */
    private readonly PropertyHookHydrator $hydrator;

    /**
     * @param GraphQLExecutor $executor Live PSR-18 network executor.
     * @param class-string<T> $target   `readonly` DTO each row hydrates into.
     * @param GraphQLCompiler $compiler SQR → query-document compiler.
     */
    public function __construct(
        private readonly GraphQLExecutor $executor,
        string $target,
        private readonly GraphQLCompiler $compiler = new GraphQLCompiler(),
    ) {
        $this->hydrator = new PropertyHookHydrator($target);
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
}
