<?php

declare(strict_types=1);

namespace Waffle\Commons\Data\Query;

use InvalidArgumentException;
use Waffle\Commons\Contracts\Data\Enum\Operator;

/**
 * Static factory for {@see Comparison} predicates.
 *
 * Keeping construction behind named factories gives repositories a fluent,
 * type-safe vocabulary (`Criteria::eq(...)`, `Criteria::in(...)`) and centralises
 * field validation. Scalar values are typed precisely (no `mixed`), honouring the
 * project's strict-typing mandate.
 */
final class Criteria
{
    /** @throws InvalidArgumentException When $field is blank. */
    public static function eq(string $field, int|float|string|bool|null $value): Comparison
    {
        return new Comparison(self::field($field), Operator::Equal, [$value]);
    }

    /** @throws InvalidArgumentException When $field is blank. */
    public static function neq(string $field, int|float|string|bool|null $value): Comparison
    {
        return new Comparison(self::field($field), Operator::NotEqual, [$value]);
    }

    /** @throws InvalidArgumentException When $field is blank. */
    public static function gt(string $field, int|float|string $value): Comparison
    {
        return new Comparison(self::field($field), Operator::GreaterThan, [$value]);
    }

    /** @throws InvalidArgumentException When $field is blank. */
    public static function gte(string $field, int|float|string $value): Comparison
    {
        return new Comparison(self::field($field), Operator::GreaterThanOrEqual, [$value]);
    }

    /** @throws InvalidArgumentException When $field is blank. */
    public static function lt(string $field, int|float|string $value): Comparison
    {
        return new Comparison(self::field($field), Operator::LessThan, [$value]);
    }

    /** @throws InvalidArgumentException When $field is blank. */
    public static function lte(string $field, int|float|string $value): Comparison
    {
        return new Comparison(self::field($field), Operator::LessThanOrEqual, [$value]);
    }

    /** @throws InvalidArgumentException When $field is blank. */
    public static function like(string $field, string $value): Comparison
    {
        return new Comparison(self::field($field), Operator::Like, [$value]);
    }

    /**
     * @param list<int|float|string|bool> $values
     *
     * @throws InvalidArgumentException When $field is blank or $values is empty.
     */
    public static function in(string $field, array $values): Comparison
    {
        return new Comparison(self::field($field), Operator::In, self::set($values));
    }

    /**
     * @param list<int|float|string|bool> $values
     *
     * @throws InvalidArgumentException When $field is blank or $values is empty.
     */
    public static function notIn(string $field, array $values): Comparison
    {
        return new Comparison(self::field($field), Operator::NotIn, self::set($values));
    }

    /** @throws InvalidArgumentException When the field name is blank. */
    private static function field(string $field): string
    {
        if (mb_trim($field) === '') {
            throw new InvalidArgumentException('A criteria field name must not be blank.');
        }

        return $field;
    }

    /**
     * @param list<int|float|string|bool> $values
     *
     * @return non-empty-list<int|float|string|bool>
     *
     * @throws InvalidArgumentException When $values is empty.
     */
    private static function set(array $values): array
    {
        if ($values === []) {
            throw new InvalidArgumentException('A set comparison (IN / NOT IN) requires at least one value.');
        }

        return array_values($values);
    }
}
