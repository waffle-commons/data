<?php

declare(strict_types=1);

namespace WaffleTests\Commons\Data\Fixture;

/**
 * Minimal immutable row DTO for repository tests: an int key, a string column,
 * and a nullable float so all the common backend type mappings are exercised.
 */
final readonly class PersonRow
{
    public function __construct(
        public int $id,
        public string $name,
        public ?float $score = null,
    ) {}
}
