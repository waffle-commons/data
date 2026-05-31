<?php

declare(strict_types=1);

namespace WaffleTests\Commons\Data\Fixture;

use Waffle\Commons\Data\Exception\ValidationException;

use function str_contains;

/**
 * Hooked, validating DTO. Hooked properties cannot be `readonly`, so immutability
 * is provided by asymmetric visibility (`public private(set)`) on a `final class`.
 */
final class HookedEmailDto
{
    public function __construct(
        public private(set) int $id,
        public private(set) string $email {
            set(string $value) {
                if (!str_contains($value, '@')) {
                    throw new ValidationException('Invalid email address.', 'email');
                }

                $this->email = $value;
            }
        },
        public private(set) ?float $score = null,
    ) {}
}
