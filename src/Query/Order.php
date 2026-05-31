<?php

declare(strict_types=1);

namespace Waffle\Commons\Data\Query;

/**
 * An immutable ORDER BY clause: a field paired with a sort direction.
 */
final readonly class Order
{
    public function __construct(
        public string $field,
        public Direction $direction = Direction::Ascending,
    ) {}
}
