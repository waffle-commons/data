<?php

declare(strict_types=1);

namespace Waffle\Commons\Data\Compiler;

use function json_encode;

use const JSON_THROW_ON_ERROR;

/**
 * The document-store output of compiling an SQR
 * {@see \Waffle\Commons\Data\Query\Query} for Firestore: the isolated collection
 * path plus a structured query payload.
 *
 * Per the persistence design, Firestore must not be asked to run compound or
 * sorted server-side queries; the compiler therefore emits only simple
 * equality filters for the server and sets {@see self::$requiresInMemoryFilter}
 * when any predicate or ordering must instead be applied in memory after a
 * simple fetch.
 */
final readonly class CompiledFirestoreQuery
{
    /**
     * @param string                       $path                   Isolated collection path.
     * @param list<array{field: string, op: string, value: int|float|string|bool|null}> $filters
     *        Simple equality filters safe to push to the server.
     * @param list<array{field: string, direction: string}> $orderings In-memory orderings.
     * @param int|null                     $limit                  Bounded page size, if any.
     * @param bool                         $requiresInMemoryFilter Whether the caller must
     *                                        post-filter/sort in memory.
     */
    public function __construct(
        public string $path,
        public array $filters,
        public array $orderings,
        public ?int $limit,
        public bool $requiresInMemoryFilter,
    ) {}

    /**
     * Render the structured payload as the JSON body sent to the Firestore REST
     * boundary.
     *
     * @throws \JsonException When the payload cannot be encoded.
     */
    public function toJson(): string
    {
        return json_encode([
            'path' => $this->path,
            'filters' => $this->filters,
            'orderings' => $this->orderings,
            'limit' => $this->limit,
        ], JSON_THROW_ON_ERROR);
    }
}
