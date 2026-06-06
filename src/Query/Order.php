<?php

declare(strict_types=1);

namespace Waffle\Commons\Data\Query;

use Waffle\Commons\Contracts\Data\Enum\Direction;
use Waffle\Commons\Contracts\Data\Query\OrderInterface;

/**
 * An immutable ORDER BY clause: a field paired with a sort direction.
 */
final readonly class Order implements OrderInterface
{
    public function __construct(
        public string $field,
        public Direction $direction = Direction::Ascending,
    ) {}
}
