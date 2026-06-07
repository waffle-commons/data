<?php

declare(strict_types=1);

namespace Waffle\Commons\Data\Repository;

use Generator;
use InvalidArgumentException;
use Waffle\Commons\Contracts\Data\Mapper\DataMapperInterface;
use Waffle\Commons\Contracts\Data\Query\QueryInterface;
use Waffle\Commons\Contracts\Data\Repository\WritableRepositoryInterface;
use Waffle\Commons\Data\Compiler\CassandraCompiler;
use Waffle\Commons\Data\Driver\Cql\CqlSessionInterface;
use Waffle\Commons\Data\Hydrator\PropertyHookHydrator;
use Waffle\Commons\Data\Query\Criteria;
use Waffle\Commons\Data\Query\Query;

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

    /**
     * Upsert the entity with a single parameterised CQL INSERT (operands bound,
     * never inlined).
     *
     * @throws InvalidArgumentException When the repository has no mapper or the
     *         mapped row is empty.
     * @throws \Waffle\Commons\Contracts\Data\Exception\DatabaseExceptionInterface
     */
    #[\Override]
    public function save(object $entity): void
    {
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

        $cql = sprintf('DELETE FROM %s WHERE %s = ?', $mapper->target(), $mapper->identityField());

        $this->session->executeWrite($cql, [$id]);
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
                'This CassandraRepository was constructed without a DataMapper; write operations are unavailable.',
            );
        }

        return $this->mapper;
    }
}
