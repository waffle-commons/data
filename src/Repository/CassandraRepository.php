<?php

declare(strict_types=1);

namespace Waffle\Commons\Data\Repository;

use Generator;
use InvalidArgumentException;
use Waffle\Commons\Contracts\Data\Query\QueryInterface;
use Waffle\Commons\Contracts\Data\Repository\RepositoryInterface;
use Waffle\Commons\Data\Compiler\CassandraCompiler;
use Waffle\Commons\Data\Driver\Cql\CqlSessionInterface;
use Waffle\Commons\Data\Hydrator\PropertyHookHydrator;
use Waffle\Commons\Data\Query\Query;

/**
 * Stateless repository over a Cassandra/CQL backend (RFC-022 §3 + §4.2).
 *
 * The SQR compiles to parameterised CQL (operands bound, never inlined),
 * executes through the injected {@see CqlSessionInterface} transport, and every
 * row hydrates into an immutable DTO through PHP 8.5 Property Hook validation.
 * CQL-incompatible queries (inequality, `NOT IN`, `LIKE`, OFFSET pagination)
 * are rejected at compile time rather than mistranslated.
 *
 * @template T of object
 *
 * @implements RepositoryInterface<T>
 */
final class CassandraRepository implements RepositoryInterface
{
    /** @var PropertyHookHydrator<T> */
    private readonly PropertyHookHydrator $hydrator;

    /**
     * @param CqlSessionInterface $session  Transport (see the port's note on
     *                                      live CQL access from PHP 8.5).
     * @param class-string<T>     $target   `readonly` DTO each row hydrates into.
     * @param CassandraCompiler   $compiler SQR → CQL compiler.
     */
    public function __construct(
        private readonly CqlSessionInterface $session,
        string $target,
        private readonly CassandraCompiler $compiler = new CassandraCompiler(),
    ) {
        $this->hydrator = new PropertyHookHydrator($target);
    }

    /**
     * @return list<T>
     *
     * @throws InvalidArgumentException When the query is CQL-incompatible.
     * @throws \Waffle\Commons\Contracts\Data\Exception\DatabaseExceptionInterface
     * @throws \Waffle\Commons\Contracts\Exception\Validation\ValidationExceptionInterface
     */
    #[\Override]
    public function find(QueryInterface $query): array
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
     * @throws InvalidArgumentException When the query is CQL-incompatible.
     * @throws \Waffle\Commons\Contracts\Data\Exception\DatabaseExceptionInterface
     * @throws \Waffle\Commons\Contracts\Exception\Validation\ValidationExceptionInterface
     */
    #[\Override]
    public function findOne(QueryInterface $query): ?object
    {
        // CQL supports LIMIT: bound the call server-side when the SQR is ours
        // to rebuild — never discard rows client-side.
        $bounded = $query instanceof Query ? $query->limit(1) : $query;

        return $this->find($bounded)[0] ?? null;
    }

    /**
     * @return Generator<int, T>
     *
     * @throws InvalidArgumentException When the query is CQL-incompatible.
     * @throws \Waffle\Commons\Contracts\Data\Exception\DatabaseExceptionInterface
     * @throws \Waffle\Commons\Contracts\Exception\Validation\ValidationExceptionInterface
     */
    #[\Override]
    public function stream(QueryInterface $query): Generator
    {
        // Token-based paging lives in the transport; yield from the bounded page.
        yield from $this->find($query);
    }
}
