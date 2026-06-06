<?php

declare(strict_types=1);

namespace Waffle\Commons\Data\Repository;

use Generator;
use InvalidArgumentException;
use Waffle\Commons\Contracts\Data\Query\QueryInterface;
use Waffle\Commons\Contracts\Data\Repository\RepositoryInterface;
use Waffle\Commons\Data\Hydrator\PropertyHookHydrator;
use Waffle\Commons\Data\Query\Query;
use Waffle\Commons\Data\Storage\JsonFileStore;

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
 * @implements RepositoryInterface<T>
 */
final class JsonFileRepository implements RepositoryInterface
{
    /** @var PropertyHookHydrator<T> */
    private readonly PropertyHookHydrator $hydrator;

    /**
     * @param string          $path   Collection file the repository is bound to.
     * @param class-string<T> $target `readonly` DTO each row hydrates into.
     * @param JsonFileStore   $store  Atomic flat-file store.
     */
    public function __construct(
        private readonly string $path,
        string $target,
        private readonly JsonFileStore $store = new JsonFileStore(),
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
}
