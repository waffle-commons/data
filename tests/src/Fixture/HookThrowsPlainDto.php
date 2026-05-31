<?php

declare(strict_types=1);

namespace WaffleTests\Commons\Data\Fixture;

use InvalidArgumentException;

/**
 * Hooked DTO whose hook throws a *non-validation* exception, so the hydrator's
 * generic catch must normalise it into a ValidationException.
 */
final class HookThrowsPlainDto
{
    public function __construct(
        public private(set) int $value {
            set(int $candidate) {
                if ($candidate < 0) {
                    throw new InvalidArgumentException('must be non-negative');
                }

                $this->value = $candidate;
            }
        },
    ) {}
}
