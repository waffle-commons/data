<?php

declare(strict_types=1);

namespace Waffle\Commons\Data\Query;

/**
 * A single, immutable filter predicate of the SQR: a field, an operator, and the
 * value(s) the field is compared against.
 *
 * Values are always stored as a list so set-operators (IN / NOT IN) and scalar
 * operators share one representation. A compiler maps every entry to a bound
 * parameter — the values are never interpolated into the query text, which is
 * what keeps the SQR injection-safe by construction.
 */
final readonly class Comparison
{
    /** @var list<int|float|string|bool|null> */
    public array $values;

    /**
     * @param list<int|float|string|bool|null> $values
     */
    public function __construct(
        public string $field,
        public Operator $operator,
        array $values,
    ) {
        $this->values = $values;
    }
}
