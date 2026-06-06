<?php

declare(strict_types=1);

namespace Waffle\Commons\Data\Repository;

use Generator;
use InvalidArgumentException;
use Waffle\Commons\Contracts\Data\Query\QueryInterface;
use Waffle\Commons\Contracts\Data\Repository\RepositoryInterface;
use Waffle\Commons\Data\Compiler\MongoCompiler;
use Waffle\Commons\Data\Driver\Mongo\MongoSessionInterface;
use Waffle\Commons\Data\Hydrator\PropertyHookHydrator;
use Waffle\Commons\Data\Query\Query;

/**
 * Stateless repository over a MongoDB backend (RFC-022 §3 + §4.2).
 *
 * The SQR compiles to a native filter document — every predicate pushed down
 * server-side — executes through the injected {@see MongoSessionInterface}
 * transport, and every document hydrates into an immutable DTO through PHP 8.5
 * Property Hook validation.
 *
 * @template T of object
 *
 * @implements RepositoryInterface<T>
 */
final class MongoRepository implements RepositoryInterface
{
    /** @var PropertyHookHydrator<T> */
    private readonly PropertyHookHydrator $hydrator;

    /**
     * @param MongoSessionInterface $session  Transport (live: MongoDriverSession).
     * @param class-string<T>       $target   `readonly` DTO each document hydrates into.
     * @param MongoCompiler         $compiler SQR → filter-document compiler.
     */
    public function __construct(
        private readonly MongoSessionInterface $session,
        string $target,
        private readonly MongoCompiler $compiler = new MongoCompiler(),
    ) {
        $this->hydrator = new PropertyHookHydrator($target);
    }

    /**
     * @return list<T>
     *
     * @throws InvalidArgumentException When the query has no source collection.
     * @throws \Waffle\Commons\Contracts\Data\Exception\DatabaseExceptionInterface
     * @throws \Waffle\Commons\Contracts\Exception\Validation\ValidationExceptionInterface
     */
    #[\Override]
    public function find(QueryInterface $query): array
    {
        $hydrated = [];
        foreach ($this->session->find($this->compiler->compile($query)) as $row) {
            $hydrated[] = $this->hydrator->hydrate($row);
        }

        return $hydrated;
    }

    /**
     * @return T|null
     *
     * @throws InvalidArgumentException When the query has no source collection.
     * @throws \Waffle\Commons\Contracts\Data\Exception\DatabaseExceptionInterface
     * @throws \Waffle\Commons\Contracts\Exception\Validation\ValidationExceptionInterface
     */
    #[\Override]
    public function findOne(QueryInterface $query): ?object
    {
        // Bound the call server-side when the SQR is ours to rebuild — never
        // discard documents client-side.
        $bounded = $query instanceof Query ? $query->limit(1) : $query;

        return $this->find($bounded)[0] ?? null;
    }

    /**
     * @return Generator<int, T>
     *
     * @throws InvalidArgumentException When the query has no source collection.
     * @throws \Waffle\Commons\Contracts\Data\Exception\DatabaseExceptionInterface
     * @throws \Waffle\Commons\Contracts\Exception\Validation\ValidationExceptionInterface
     */
    #[\Override]
    public function stream(QueryInterface $query): Generator
    {
        // The session port returns a bounded page; yield from it.
        yield from $this->find($query);
    }
}
