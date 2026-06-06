<?php

declare(strict_types=1);

namespace Waffle\Commons\Data\Repository;

use Generator;
use InvalidArgumentException;
use Waffle\Commons\Contracts\Auth\SecurityContextInterface;
use Waffle\Commons\Contracts\Data\Mapper\DataMapperInterface;
use Waffle\Commons\Contracts\Data\Query\QueryInterface;
use Waffle\Commons\Contracts\Data\Repository\WritableRepositoryInterface;
use Waffle\Commons\Data\Compiler\FirestoreCompiler;
use Waffle\Commons\Data\Compiler\FirestoreScope;
use Waffle\Commons\Data\Driver\Firestore\FirestoreClientInterface;
use Waffle\Commons\Data\Evaluation\InMemoryEvaluator;
use Waffle\Commons\Data\Exception\SecurityPathViolationException;
use Waffle\Commons\Data\Exception\UnauthenticatedAccessException;
use Waffle\Commons\Data\Hydrator\PropertyHookHydrator;

/**
 * Stateless Firestore repository (RFC-022 §3 + §4.2) enforcing the three
 * document-store guardrails by construction:
 *
 *  - **Rule 1 (strict paths):** every operation runs against a {@see FirestoreScope}
 *    — a path no factory can point at a root collection. The {@see self::forPublic()}
 *    / {@see self::forPrivate()} constructors translate a malformed scope into a
 *    {@see SecurityPathViolationException}; a raw path is never accepted.
 *  - **Rule 2 (no complex server queries):** only equality filters reach the driver
 *    (via {@see FirestoreCompiler}); any range / set / pattern / ordering / offset
 *    is resolved in memory by {@see InMemoryEvaluator} after a simple fetch.
 *  - **Rule 3 (authentication gate):** every read and write asserts an authenticated
 *    {@see SecurityContextInterface} first, throwing {@see UnauthenticatedAccessException}
 *    on an anonymous caller — never an anonymous fall-through.
 *
 * @template T of object
 *
 * @implements WritableRepositoryInterface<T>
 */
final class FirestoreRepository implements WritableRepositoryInterface
{
    /** @var PropertyHookHydrator<T> */
    private readonly PropertyHookHydrator $hydrator;

    private readonly FirestoreCompiler $compiler;

    private readonly InMemoryEvaluator $evaluator;

    /**
     * @param FirestoreClientInterface $client   Transport (live: FirestoreRestClient).
     * @param class-string<T>          $target   `readonly` DTO each document hydrates into.
     * @param FirestoreScope           $scope    Isolated collection path (Rule 1).
     * @param SecurityContextInterface $security Identity gate (Rule 3).
     * @param DataMapperInterface<T>   $mapper   Write/identity mapper.
     */
    public function __construct(
        private readonly FirestoreClientInterface $client,
        string $target,
        private readonly FirestoreScope $scope,
        private readonly SecurityContextInterface $security,
        private readonly DataMapperInterface $mapper,
    ) {
        $this->hydrator = new PropertyHookHydrator($target);
        $this->compiler = new FirestoreCompiler();
        $this->evaluator = new InMemoryEvaluator();
    }

    /**
     * Build a repository over the shared public collection
     * `artifacts/{appId}/public/data/{collection}`, where `{collection}` is the
     * mapper's target.
     *
     * @template TEntity of object
     *
     * @param FirestoreClientInterface     $client
     * @param class-string<TEntity>        $target
     * @param SecurityContextInterface     $security
     * @param DataMapperInterface<TEntity> $mapper
     *
     * @return self<TEntity>
     *
     * @throws \Waffle\Commons\Contracts\Data\Exception\SecurityPathViolationExceptionInterface
     *         When `{appId}` or the mapper's collection is blank or malformed (Rule 1).
     */
    public static function forPublic(
        FirestoreClientInterface $client,
        string $target,
        SecurityContextInterface $security,
        DataMapperInterface $mapper,
        string $appId,
    ): self {
        try {
            $scope = FirestoreScope::public($appId, $mapper->target());
        } catch (InvalidArgumentException $error) {
            throw new SecurityPathViolationException($error->getMessage(), null, $error);
        }

        return new self($client, $target, $scope, $security, $mapper);
    }

    /**
     * Build a repository over the current user's private collection
     * `artifacts/{appId}/users/{userId}/{collection}`. The `{userId}` is taken
     * from the authenticated identity, so a caller can never reach another
     * user's data.
     *
     * @template TEntity of object
     *
     * @param FirestoreClientInterface     $client
     * @param class-string<TEntity>        $target
     * @param SecurityContextInterface     $security
     * @param DataMapperInterface<TEntity> $mapper
     *
     * @return self<TEntity>
     *
     * @throws \Waffle\Commons\Contracts\Data\Exception\UnauthenticatedAccessExceptionInterface
     *         When no identity is authenticated (Rule 3).
     * @throws \Waffle\Commons\Contracts\Data\Exception\SecurityPathViolationExceptionInterface
     *         When `{appId}`, the user id, or the mapper's collection is malformed (Rule 1).
     */
    public static function forPrivate(
        FirestoreClientInterface $client,
        string $target,
        SecurityContextInterface $security,
        DataMapperInterface $mapper,
        string $appId,
    ): self {
        $identity = $security->getIdentity();
        if (!$security->isAuthenticated() || $identity === null) {
            throw new UnauthenticatedAccessException(
                'A private Firestore scope requires an authenticated identity (RFC-022 §4.2, Rule 3).',
            );
        }

        try {
            $scope = FirestoreScope::private($appId, $identity->subject, $mapper->target());
        } catch (InvalidArgumentException $error) {
            throw new SecurityPathViolationException($error->getMessage(), null, $error);
        }

        return new self($client, $target, $scope, $security, $mapper);
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
        $this->assertAuthenticated();

        $compiled = $this->compiler->compile($query, $this->scope);

        // Dead-simple fetch: equality filters only. When the SQR needs more than
        // equality (range / set / sort / offset), the server limit is dropped so
        // the in-memory evaluator can filter the full set before paginating it
        // (Rule 2).
        $rows = $this->client->queryCollection(
            $this->scope->path,
            $compiled->filters,
            $compiled->requiresInMemoryFilter ? null : $compiled->limit,
        );

        if ($compiled->requiresInMemoryFilter) {
            $rows = $this->evaluator->evaluate($query, $rows);
        }

        $hydrated = [];
        foreach ($rows as $row) {
            $hydrated[] = $this->hydrator->hydrate($row);
        }

        return $hydrated;
    }

    /**
     * @return T|null
     *
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
     * @throws \Waffle\Commons\Contracts\Data\Exception\DatabaseExceptionInterface
     * @throws \Waffle\Commons\Contracts\Exception\Validation\ValidationExceptionInterface
     */
    #[\Override]
    public function stream(QueryInterface $query): Generator
    {
        yield from $this->find($query);
    }

    /**
     * @return T|null
     *
     * @throws \Waffle\Commons\Contracts\Data\Exception\DatabaseExceptionInterface
     * @throws \Waffle\Commons\Contracts\Exception\Validation\ValidationExceptionInterface
     */
    #[\Override]
    public function findById(int|string $id): ?object
    {
        $this->assertAuthenticated();

        $row = $this->client->getDocument($this->scope->path, (string) $id);

        return $row === null ? null : $this->hydrator->hydrate($row);
    }

    /**
     * @throws \Waffle\Commons\Contracts\Data\Exception\DatabaseExceptionInterface
     */
    #[\Override]
    public function save(object $entity): void
    {
        $this->assertAuthenticated();

        $id = $this->mapper->identify($entity);
        $this->client->setDocument(
            $this->scope->path,
            $id === null ? null : (string) $id,
            $this->mapper->toRow($entity),
        );
    }

    /**
     * @throws InvalidArgumentException When the entity carries no identity.
     * @throws \Waffle\Commons\Contracts\Data\Exception\DatabaseExceptionInterface
     */
    #[\Override]
    public function delete(object $entity): void
    {
        $this->assertAuthenticated();

        $id = $this->mapper->identify($entity);
        if ($id === null) {
            throw new InvalidArgumentException('Cannot delete an entity that carries no identity.');
        }

        $this->client->deleteDocument($this->scope->path, (string) $id);
    }

    /**
     * Rule 3 — every read and write is gated on an authenticated identity.
     *
     * @throws UnauthenticatedAccessException When the caller is anonymous.
     */
    private function assertAuthenticated(): void
    {
        if (!$this->security->isAuthenticated()) {
            throw new UnauthenticatedAccessException(
                'Firestore access requires an authenticated identity (RFC-022 §4.2, Rule 3).',
            );
        }
    }
}
