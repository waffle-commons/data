<?php

declare(strict_types=1);

namespace Waffle\Commons\Data\Repository;

use Generator;
use InvalidArgumentException;
use JsonException;
use Throwable;
use Waffle\Commons\Contracts\Data\Mapper\DataMapperInterface;
use Waffle\Commons\Contracts\Data\Query\QueryInterface;
use Waffle\Commons\Contracts\Data\Repository\WritableRepositoryInterface;
use Waffle\Commons\Contracts\Telemetry\NullTracer;
use Waffle\Commons\Contracts\Telemetry\TracerInterface;
use Waffle\Commons\Data\Compiler\KeyValueCompiler;
use Waffle\Commons\Data\Compiler\KeyValueOperation;
use Waffle\Commons\Data\Driver\KeyValue\KeyValueClientInterface;
use Waffle\Commons\Data\Exception\DatabaseException;
use Waffle\Commons\Data\Hydrator\PropertyHookHydrator;
use Waffle\Commons\Data\Hydrator\RowNormaliser;
use Waffle\Commons\Data\Query\Criteria;
use Waffle\Commons\Data\Query\Query;
use Waffle\Commons\Data\Telemetry\QueryTracer;

use function array_values;
use function json_encode;

use const JSON_THROW_ON_ERROR;

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
 * @implements WritableRepositoryInterface<T>
 */
final class KeyValueRepository implements WritableRepositoryInterface
{
    /** @var PropertyHookHydrator<T> */
    private readonly PropertyHookHydrator $hydrator;

    private readonly RowNormaliser $normaliser;

    private QueryTracer $queryTracer;

    /**
     * @param KeyValueClientInterface     $client   Live transport (e.g. RedisKeyValueClient).
     * @param class-string<T>             $target   `readonly` DTO each document hydrates into.
     * @param KeyValueCompiler            $compiler SQR → key-command compiler.
     * @param DataMapperInterface<T>|null $mapper   Write/identity mapper; omit for a read-only
     *                                              repository (save / delete / findById then throw).
     */
    public function __construct(
        private readonly KeyValueClientInterface $client,
        string $target,
        private readonly KeyValueCompiler $compiler = new KeyValueCompiler(),
        private readonly ?DataMapperInterface $mapper = null,
    ) {
        $this->hydrator = new PropertyHookHydrator($target);
        $this->normaliser = new RowNormaliser();
        $this->queryTracer = new QueryTracer(new NullTracer(), 'keyvalue');
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
        $clone->queryTracer = new QueryTracer($tracer, 'keyvalue');

        return $clone;
    }

    /**
     * @return list<T>
     *
     * @throws Throwable When the lookup is unrepresentable or a row cannot be hydrated.
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
     * @throws InvalidArgumentException When the query expresses anything a
     *         key-value store cannot honour.
     * @throws \Waffle\Commons\Contracts\Data\Exception\DatabaseExceptionInterface
     * @throws \Waffle\Commons\Contracts\Exception\Validation\ValidationExceptionInterface
     */
    private function runFind(QueryInterface $query): array
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
     * @throws Throwable When the lookup is unrepresentable or the row cannot be hydrated.
     */
    #[\Override]
    public function findOne(QueryInterface $query): ?object
    {
        $span = $this->queryTracer->open('findOne');

        try {
            return $this->runFind($query)[0] ?? null;
        } catch (Throwable $error) {
            $this->queryTracer->fail($span, $error);
        } finally {
            $span->end();
        }
    }

    /**
     * @return Generator<int, T>
     *
     * @throws Throwable When the lookup is unrepresentable or a row cannot be hydrated.
     */
    #[\Override]
    public function stream(QueryInterface $query): Generator
    {
        $span = $this->queryTracer->open('stream');

        try {
            // A key lookup is already bounded by its key set; yield from that page.
            yield from $this->runFind($query);
        } catch (Throwable $error) {
            $this->queryTracer->fail($span, $error);
        } finally {
            $span->end();
        }
    }

    /**
     * Store the entity as one JSON document under `{namespace}:{id}`. A
     * key-value store has no auto-id, so an identity is mandatory.
     *
     * @throws Throwable When the repository has no mapper, the entity carries no
     *         identity, or the backend write fails.
     */
    #[\Override]
    public function save(object $entity): void
    {
        $span = $this->queryTracer->open('save');

        try {
            $mapper = $this->requireMapper();
            $id = $mapper->identify($entity);
            if ($id === null) {
                throw new InvalidArgumentException('A key-value write requires an explicit identity (no auto-id).');
            }

            try {
                $value = json_encode($mapper->toRow($entity), JSON_THROW_ON_ERROR);
            } catch (JsonException $jsonError) {
                throw DatabaseException::fromThrowable($jsonError, 'Failed to encode the key-value document.');
            }

            $this->client->set($this->key($mapper, $id), $value);
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

            $this->client->delete($this->key($mapper, $id));
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

            // Route through the read path so the key is formed by the same compiler.
            $query = Query::select()->from($mapper->target())->where(Criteria::eq($mapper->identityField(), $id));

            return $this->runFind($query)[0] ?? null;
        } catch (Throwable $error) {
            $this->queryTracer->fail($span, $error);
        } finally {
            $span->end();
        }
    }

    /**
     * The fully-qualified key, matching the compiler's `{namespace}:{value}`
     * format (identities are int|string, so this equals the compiler's
     * stringification).
     *
     * @param DataMapperInterface<T> $mapper
     */
    private function key(DataMapperInterface $mapper, int|string $id): string
    {
        return $mapper->target() . ':' . $id;
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
                'This KeyValueRepository was constructed without a DataMapper; write operations are unavailable.',
            );
        }

        return $this->mapper;
    }
}
