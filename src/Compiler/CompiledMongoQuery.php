<?php

declare(strict_types=1);

namespace Waffle\Commons\Data\Compiler;

/**
 * The document-store output of compiling an SQR
 * {@see \Waffle\Commons\Data\Query\Query} for MongoDB: a collection name plus the
 * native `find($filter, $options)` arguments — a filter document and the cursor
 * {@see MongoFindOptions}.
 *
 * Unlike Firestore, MongoDB evaluates rich predicates (range, set membership,
 * pattern) server-side, so every predicate is pushed down into the filter
 * document and nothing is deferred to memory. Each field maps to an operator
 * document (`{$eq: …}`, `{$gt: …}`, `{$in: […]}`, …); the explicit `$eq` form is
 * used even for equality so multiple predicates on one field merge losslessly
 * into a single operator document.
 */
final readonly class CompiledMongoQuery
{
    /**
     * @param string $collection Source collection.
     * @param array<string, array<string, int|float|string|bool|null|list<int|float|string|bool|null>>> $filter
     *        Native filter document: field → operator → operand.
     * @param MongoFindOptions $options Projection, sort, and bounded pagination.
     */
    public function __construct(
        public string $collection,
        public array $filter,
        public MongoFindOptions $options,
    ) {}
}
