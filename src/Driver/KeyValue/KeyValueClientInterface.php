<?php

declare(strict_types=1);

namespace Waffle\Commons\Data\Driver\KeyValue;

/**
 * Transport port for the key-value driver family (RFC-022 §4.2): the minimal
 * key-access surface a {@see \Waffle\Commons\Data\Repository\KeyValueRepository}
 * needs, kept implementation-free so the repository logic stays fully testable
 * without a live server.
 *
 * {@see RedisKeyValueClient} is the bundled live adapter (phpredis); a DynamoDB
 * `GetItem`/`BatchGetItem` adapter satisfies the same shape.
 */
interface KeyValueClientInterface
{
    /**
     * Fetch a single value, or null when the key does not exist.
     *
     * @throws \Waffle\Commons\Contracts\Data\Exception\DatabaseExceptionInterface
     *         When the backend call fails.
     */
    public function get(string $key): ?string;

    /**
     * Fetch a batch of values keyed by the requested key; a missing key maps to
     * null rather than being dropped, so the caller can tell a miss from a hit.
     *
     * @param non-empty-list<string> $keys
     *
     * @return array<string, string|null>
     *
     * @throws \Waffle\Commons\Contracts\Data\Exception\DatabaseExceptionInterface
     *         When the backend call fails.
     */
    public function getMany(array $keys): array;
}
