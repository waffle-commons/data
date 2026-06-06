<?php

declare(strict_types=1);

namespace Waffle\Commons\Data\Compiler;

/**
 * The cursor options of a compiled MongoDB query — the second argument of the
 * native `find($filter, $options)` call: a projection, a sort document, and the
 * bounded `limit`/`skip` markers that keep result-set memory $O(1)$.
 *
 * Kept separate from the filter (which carries the predicates) so
 * {@see CompiledMongoQuery} mirrors the driver's own two-argument shape.
 */
final readonly class MongoFindOptions
{
    /**
     * @param array<string, int> $projection Field → 1 (include); empty selects the whole document.
     * @param array<string, int> $sort       Field → 1 (ASC) | -1 (DESC).
     * @param int|null           $limit      Bounded page size, if any.
     * @param int|null           $skip       Bounded offset, if any.
     */
    public function __construct(
        public array $projection,
        public array $sort,
        public ?int $limit,
        public ?int $skip,
    ) {}
}
