<?php

declare(strict_types=1);

namespace Waffle\Commons\Data\Repository;

use Generator;
use InvalidArgumentException;
use Waffle\Commons\Contracts\Data\Mapper\DataMapperInterface;
use Waffle\Commons\Contracts\Data\Query\QueryInterface;
use Waffle\Commons\Contracts\Data\Repository\WritableRepositoryInterface;
use Waffle\Commons\Data\Hydrator\PropertyHookHydrator;
use Waffle\Commons\Data\Query\Query;
use Waffle\Commons\Data\Storage\JsonFileStore;

use function array_values;

/**
 * Stateless repository over the atomic flat-file JSON store (RFC-022 §3 + §4.3).
 *
 * The store reads the whole (already memory-bounded) collection file, the
 * in-memory evaluator applies the SQR, and every surviving row hydrates into an
 * immutable DTO through PHP 8.5 Property Hook validation. There is no cursor on
 * a local JSON document, so {@see self::stream()} yields from the evaluated,
 * bounded page rather than a driver cursor — the contract this backend can
 * honestly honour.
 *
 * @template T of object
 *
 * @implements WritableRepositoryInterface<T>
 */
final class JsonFileRepository implements WritableRepositoryInterface
{
    /** @var PropertyHookHydrator<T> */
    private readonly PropertyHookHydrator $hydrator;

    /**
     * @param string                      $path   Collection file the repository is bound to.
     * @param class-string<T>             $target `readonly` DTO each row hydrates into.
     * @param JsonFileStore               $store  Atomic flat-file store (LOCK_EX + temp→rename).
     * @param DataMapperInterface<T>|null $mapper Write/identity mapper; omit for a read-only
     *                                            repository (save / delete / findById then throw).
     */
    public function __construct(
        private readonly string $path,
        string $target,
        private readonly JsonFileStore $store = new JsonFileStore(),
        private readonly ?DataMapperInterface $mapper = null,
    ) {
        $this->hydrator = new PropertyHookHydrator($target);
    }

    /**
     * @return list<T>
     *
     * @throws \Waffle\Commons\Contracts\Data\Exception\DatabaseExceptionInterface
     * @throws \Waffle\Commons\Contracts\Exception\Validation\ValidationExceptionInterface
     */
    #[\Override]
    public function find(QueryInterface $query): array
    {
        $hydrated = [];
        foreach ($this->store->query($this->path, $query) as $row) {
            $hydrated[] = $this->hydrator->hydrate($row);
        }

        return $hydrated;
    }

    /**
     * @return T|null
     *
     * @throws InvalidArgumentException When the rebuilt bounded query is invalid
     *         (never for the fixed bound used here; kept for contract honesty).
     * @throws \Waffle\Commons\Contracts\Data\Exception\DatabaseExceptionInterface
     * @throws \Waffle\Commons\Contracts\Exception\Validation\ValidationExceptionInterface
     */
    #[\Override]
    public function findOne(QueryInterface $query): ?object
    {
        // Bound the evaluation when the SQR is ours to rebuild — the evaluator
        // then stops materialising past the first row.
        $bounded = $query instanceof Query ? $query->limit(1) : $query;

        return $this->find($bounded)[0] ?? null;
    }

    /**
     * @return Generator<int, T>
     *
     * @throws \Waffle\Commons\Contracts\Data\Exception\DatabaseExceptionInterface
     * @throws \Waffle\Commons\Contracts\Exception\Validation\ValidationExceptionInterface
     */
    #[\Override]
    public function stream(QueryInterface $query): Generator
    {
        foreach ($this->store->query($this->path, $query) as $row) {
            yield $this->hydrator->hydrate($row);
        }
    }

    /**
     * Read-modify-write the whole collection file atomically: INSERT (null id)
     * appends, otherwise the row with a matching identity is replaced (upsert).
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

        $rows = $this->store->read($this->path);

        if ($id === null) {
            $rows[] = $row;
            $this->store->write($this->path, array_values($rows));

            return;
        }

        $idField = $mapper->identityField();
        $replaced = false;
        foreach ($rows as $index => $existing) {
            if (($existing[$idField] ?? null) !== $id) {
                continue;
            }

            $rows[$index] = $row;
            $replaced = true;

            break;
        }

        if (!$replaced) {
            $rows[] = $row;
        }

        $this->store->write($this->path, array_values($rows));
    }

    /**
     * @throws InvalidArgumentException When the repository has no mapper, or the
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

        $idField = $mapper->identityField();
        $remaining = [];
        foreach ($this->store->read($this->path) as $existing) {
            if (($existing[$idField] ?? null) === $id) {
                continue;
            }

            $remaining[] = $existing;
        }

        $this->store->write($this->path, $remaining);
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
        $idField = $mapper->identityField();

        foreach ($this->store->read($this->path) as $row) {
            if (($row[$idField] ?? null) === $id) {
                return $this->hydrator->hydrate($row);
            }
        }

        return null;
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
                'This JsonFileRepository was constructed without a DataMapper; write operations are unavailable.',
            );
        }

        return $this->mapper;
    }
}
