<?php

declare(strict_types=1);

namespace WaffleTests\Commons\Data\Fixture;

/**
 * Plain, unhooked value DTO covering every scalar coercion arm of the hydrator.
 */
final readonly class ScalarsDto
{
    public function __construct(
        public private(set) int $i,
        public private(set) float $f,
        public private(set) string $s,
        public private(set) bool $b,
    ) {}
}
