<?php

declare(strict_types=1);

namespace WaffleTests\Commons\Data\Fixture;

use DateTimeImmutable;

/**
 * DTO with an optional object-typed field. Exercises the hydrator's null-handling
 * for a nullable parameter and the "non-scalar named type" coercion arm (a scalar
 * row value handed to an object parameter, which PHP then rejects).
 */
final readonly class OptionalObjectDto
{
    public function __construct(
        public private(set) ?DateTimeImmutable $when = null,
    ) {}
}
