<?php

declare(strict_types=1);

namespace Waffle\Commons\Data\Query;

/**
 * Sort direction for an {@see Order} clause. The backing value is the canonical
 * SQL keyword.
 */
enum Direction: string
{
    case Ascending = 'ASC';
    case Descending = 'DESC';
}
