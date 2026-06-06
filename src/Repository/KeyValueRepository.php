<?php

declare(strict_types=1);

namespace Waffle\Commons\Data\Repository;

use Generator;
use InvalidArgumentException;
use Waffle\Commons\Contracts\Data\Query\QueryInterface;
use Waffle\Commons\Contracts\Data\Repository\RepositoryInterface;
use Waffle\Commons\Data\Compiler\KeyValueCompiler;
use Waffle\Commons\Data\Compiler\KeyValueOperation;
use Waffle\Commons\Data\Driver\KeyValue\KeyValueClientInterface;
use Waffle\Commons\Data\Hydrator\PropertyHookHydrator;
use Waffle\Commons\Data\Hydrator\RowNormaliser;

use function array_values;

/**
 * Stateless repository over a key-value backend (RFC-022 §3 + §4.2).
 *
 * Each stored value is one JSON document holding one flat row. The compiler
 * accepts only the degenerate SQR a key-value store can honour (key equality →
 * `GET`, key membership → `MGET`); a missing key simply contributes no row.
 * Every hit is validated by the {@see RowNormaliser} and hydrated into an
 * immutable DTO through PHP 8.5 Property Hook validation.
 *
 * `findOne` never rebuilds the query with a bound: the key-value compiler
 * rejects pagination outright, and a key lookup is already bounded by its keys.
 *
 * @template T of object
 *
 * @implements RepositoryInterface<T>
 */
final class KeyValueRepository implements RepositoryInterface
{
    /** @var PropertyHookHydrator<T> */
    private readonly PropertyHookHydrator $hydrator;

    private readonly RowNormaliser $normaliser;

    /**
     * @param KeyValueClientInterface $client   Live transport (e.g. RedisKeyValueClient).
     * @param class-string<T>         $target   `readonly` DTO each document hydrates into.
     * @param KeyValueCompiler        $compiler SQR → key-command compiler.
     */
    public function __construct(
        private readonly KeyValueClientInterface $client,
        string $target,
        private readonly KeyValueCompiler $compiler = new KeyValueCompiler(),
    ) {
        $this->hydrator = new PropertyHookHydrator($target);
        $this->normaliser = new RowNormaliser();
    }

    /**
     * @return list<T>
     *
     * @throws InvalidArgumentException When the query expresses anything a
     *         key-value store cannot honour.
     * @throws \Waffle\Commons\Contracts\Data\Exception\DatabaseExceptionInterface
     * @throws \Waffle\Commons\Contracts\Exception\Validation\ValidationExceptionInterface
     */
    #[\Override]
    public function find(QueryInterface $query): array
    {
        $command = $this->compiler->compile($query);

        $values = match ($command->operation) {
            KeyValueOperation::Get => [$this->client->get($command->keys[0])],
            KeyValueOperation::MGet => array_values($this->client->getMany($command->keys)),
        };

        $hydrated = [];
        foreach ($values as $value) {
            if ($value === null) {
                continue;
            }

            $hydrated[] = $this->hydrator->hydrate($this->normaliser->fromJsonRow($value));
        }

        return $hydrated;
    }

    /**
     * @return T|null
     *
     * @throws InvalidArgumentException When the query expresses anything a
     *         key-value store cannot honour.
     * @throws \Waffle\Commons\Contracts\Data\Exception\DatabaseExceptionInterface
     * @throws \Waffle\Commons\Contracts\Exception\Validation\ValidationExceptionInterface
     */
    #[\Override]
    public function findOne(QueryInterface $query): ?object
    {
        return $this->find($query)[0] ?? null;
    }

    /**
     * @return Generator<int, T>
     *
     * @throws InvalidArgumentException When the query expresses anything a
     *         key-value store cannot honour.
     * @throws \Waffle\Commons\Contracts\Data\Exception\DatabaseExceptionInterface
     * @throws \Waffle\Commons\Contracts\Exception\Validation\ValidationExceptionInterface
     */
    #[\Override]
    public function stream(QueryInterface $query): Generator
    {
        // A key lookup is already bounded by its key set; yield from that page.
        yield from $this->find($query);
    }
}
