<?php

declare(strict_types=1);

namespace WaffleTests\Commons\Data\Fixture;

/**
 * DTO with a union-typed field, exercising the hydrator branch that defers a
 * non-{@see \ReflectionNamedType} parameter to PHP's own type system.
 */
final readonly class UnionFieldDto
{
    public function __construct(
        public private(set) int|string $ref,
    ) {}
}
