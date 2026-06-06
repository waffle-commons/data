<?php

declare(strict_types=1);

namespace Waffle\Commons\Data\Repository;

use Generator;
use InvalidArgumentException;
use Waffle\Commons\Contracts\Data\Mapper\DataMapperInterface;
use Waffle\Commons\Contracts\Data\Query\QueryInterface;
use Waffle\Commons\Contracts\Data\Repository\WritableRepositoryInterface;
use Waffle\Commons\Data\Compiler\MongoCompiler;
use Waffle\Commons\Data\Driver\Mongo\MongoSessionInterface;
use Waffle\Commons\Data\Hydrator\PropertyHookHydrator;
use Waffle\Commons\Data\Query\Criteria;
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
 * @implements WritableRepositoryInterface<T>
 */
final class MongoRepository implements WritableRepositoryInterface
{
    /** @var PropertyHookHydrator<T> */
    private readonly PropertyHookHydrator $hydrator;

    /**
     * @param MongoSessionInterface       $session  Transport (live: MongoDriverSession).
     * @param class-string<T>             $target   `readonly` DTO each document hydrates into.
     * @param MongoCompiler               $compiler SQR → filter-document compiler.
     * @param DataMapperInterface<T>|null $mapper   Write/identity mapper; omit for a read-only
     *                                              repository (save / delete / findById then throw).
     */
    public function __construct(
        private readonly MongoSessionInterface $session,
        string $target,
        private readonly MongoCompiler $compiler = new MongoCompiler(),
        private readonly ?DataMapperInterface $mapper = null,
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

    /**
     * Insert (null identity) or replace-upsert (by identity) the document.
     *
     * @throws InvalidArgumentException When the repository has no mapper.
     * @throws \Waffle\Commons\Contracts\Data\Exception\DatabaseExceptionInterface
     */
    #[\Override]
    public function save(object $entity): void
    {
        $mapper = $this->requireMapper();
        $id = $mapper->identify($entity);
        $row = $mapper->toRow($entity);

        if ($id === null) {
            $this->session->insert($mapper->target(), $row);

            return;
        }

        $this->session->upsert($mapper->target(), $mapper->identityField(), $id, $row);
    }

    /**
     * @throws InvalidArgumentException When the repository has no mapper or the
     *         entity carries no identity.
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

        $this->session->deleteOne($mapper->target(), $mapper->identityField(), $id);
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

        $query = Query::select()->from($mapper->target())->where(Criteria::eq($mapper->identityField(), $id))->limit(1);

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
                'This MongoRepository was constructed without a DataMapper; write operations are unavailable.',
            );
        }

        return $this->mapper;
    }
}
