<?php

declare(strict_types=1);

namespace Waffle\Commons\Data\Driver\Firestore;

/**
 * Transport port for the Firestore document family (RFC-022 §4.2): a
 * deliberately dead-simple key/value + equality surface.
 *
 * The port exists so {@see \Waffle\Commons\Data\Repository\FirestoreRepository}
 * stays fully testable against a fake, while the live REST adapter
 * ({@see FirestoreRestClient}) is the thin boundary over the Firestore HTTP API.
 *
 * Rule 2 (no complex server queries) is structural here: the only read this port
 * exposes is a single-document get or a collection fetch filtered by **equality
 * only** with an optional limit — never a compound predicate, range, or ordering.
 * Everything else is resolved in memory by the repository.
 */
interface FirestoreClientInterface
{
    /**
     * Fetch one document by id, or null when it does not exist.
     *
     * @return array<string, int|float|string|bool|null>|null
     *
     * @throws \Waffle\Commons\Contracts\Data\Exception\DatabaseExceptionInterface
     *         When the backend call fails.
     */
    public function getDocument(string $path, string $id): ?array;

    /**
     * Fetch documents in a collection matching simple equality filters, bounded
     * by an optional limit. NO range, set, pattern, or ordering is accepted —
     * those are the repository's in-memory concern (Rule 2).
     *
     * @param list<array{field: string, op: string, value: int|float|string|bool|null}> $filters
     *
     * @return list<array<string, int|float|string|bool|null>>
     *
     * @throws \Waffle\Commons\Contracts\Data\Exception\DatabaseExceptionInterface
     *         When the backend call fails.
     */
    public function queryCollection(string $path, array $filters, ?int $limit): array;

    /**
     * Create or replace a document. With a null id the backend assigns one; the
     * effective document id is returned either way.
     *
     * @param array<string, int|float|string|bool|null> $row
     *
     * @throws \Waffle\Commons\Contracts\Data\Exception\DatabaseExceptionInterface
     *         When the backend call fails.
     */
    public function setDocument(string $path, ?string $id, array $row): string;

    /**
     * Delete a document by id. Deleting a missing document is a no-op.
     *
     * @throws \Waffle\Commons\Contracts\Data\Exception\DatabaseExceptionInterface
     *         When the backend call fails.
     */
    public function deleteDocument(string $path, string $id): void;
}
