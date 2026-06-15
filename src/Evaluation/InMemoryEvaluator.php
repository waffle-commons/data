<?php

declare(strict_types=1);

namespace Waffle\Commons\Data\Evaluation;

use Waffle\Commons\Contracts\Data\Enum\Direction;
use Waffle\Commons\Contracts\Data\Enum\Operator;
use Waffle\Commons\Contracts\Data\Query\ComparisonInterface;
use Waffle\Commons\Contracts\Data\Query\QueryInterface;

use function array_filter;
use function array_slice;
use function array_values;
use function in_array;
use function is_string;
use function preg_match;
use function preg_quote;
use function str_replace;
use function usort;

/**
 * Applies an SQR {@see QueryInterface} to an in-memory list of flat rows.
 *
 * This is the engine behind the "fetch-simple-then-filter" strategy: backends
 * that cannot evaluate a predicate server-side — the flat-file JSON store, and
 * Firestore whenever {@see \Waffle\Commons\Data\Compiler\CompiledFirestoreQuery::$requiresInMemoryFilter}
 * is raised — hand their raw rows here to be filtered, sorted, and paginated with
 * the exact same SQR semantics the SQL/Mongo compilers honour server-side.
 *
 * It holds no state: every call is pure over its arguments, so it is safe to
 * share across a resident worker.
 */
final class InMemoryEvaluator
{
    /**
     * @param list<array<string, int|float|string|bool|null>> $rows
     *
     * @return list<array<string, int|float|string|bool|null>>
     */
    public function evaluate(QueryInterface $query, array $rows): array
    {
        $filtered = array_values(array_filter($rows, fn(array $row): bool => $this->matches($query, $row)));

        return $this->paginate($query, $this->sort($query, $filtered));
    }

    /**
     * @param array<string, int|float|string|bool|null> $row
     */
    private function matches(QueryInterface $query, array $row): bool
    {
        foreach ($query->criteria as $comparison) {
            if (!$this->satisfies($row[$comparison->field] ?? null, $comparison)) {
                return false;
            }
        }

        return true;
    }

    private function satisfies(int|float|string|bool|null $value, ComparisonInterface $comparison): bool
    {
        $operand = $comparison->values[0] ?? null;

        return match ($comparison->operator) {
            Operator::Equal => $value === $operand,
            Operator::NotEqual => $value !== $operand,
            Operator::GreaterThan,
            Operator::GreaterThanOrEqual,
            Operator::LessThan,
            Operator::LessThanOrEqual,
                => $this->satisfiesRange($value, $operand, $comparison->operator),
            Operator::In => in_array($value, $comparison->values, true),
            Operator::NotIn => !in_array($value, $comparison->values, true),
            Operator::Like => $this->matchesLike($value, $operand),
        };
    }

    /**
     * SQL semantics for range predicates: a NULL on either side never matches,
     * mirroring how a relational engine treats `NULL > x` as unknown.
     */
    private function satisfiesRange(
        int|float|string|bool|null $value,
        int|float|string|bool|null $operand,
        Operator $operator,
    ): bool {
        if ($value === null || $operand === null) {
            return false;
        }

        $order = $value <=> $operand;

        return match ($operator) {
            Operator::GreaterThan => $order > 0,
            Operator::GreaterThanOrEqual => $order >= 0,
            Operator::LessThan => $order < 0,
            Operator::LessThanOrEqual => $order <= 0,
            default => false,
        };
    }

    private function matchesLike(int|float|string|bool|null $value, int|float|string|bool|null $pattern): bool
    {
        // LIKE is a string operation; a non-string column never matches a pattern.
        if (!is_string($value) || !is_string($pattern)) {
            return false;
        }

        $literal = preg_quote($pattern, '/');
        // HARDEN-03: collapse runs of the multi-char wildcard so a pattern like
        // "%%%%…" cannot expand to ".*.*.*…" and trigger catastrophic backtracking.
        $literal = preg_replace('/%+/', '%', $literal) ?? $literal;

        return preg_match('/^' . str_replace(['%', '_'], ['.*', '.'], $literal) . '$/', $value) === 1;
    }

    /**
     * @param list<array<string, int|float|string|bool|null>> $rows
     *
     * @return list<array<string, int|float|string|bool|null>>
     */
    private function sort(QueryInterface $query, array $rows): array
    {
        if ($query->orderings === []) {
            return $rows;
        }

        usort(
            $rows,
            /**
             * @param array<string, int|float|string|bool|null> $a
             * @param array<string, int|float|string|bool|null> $b
             */
            function (array $a, array $b) use ($query): int {
                foreach ($query->orderings as $order) {
                    $comparison = $this->rank($a[$order->field] ?? null, $b[$order->field] ?? null);
                    if ($comparison !== 0) {
                        return $order->direction === Direction::Ascending ? $comparison : -$comparison;
                    }
                }

                return 0;
            },
        );

        return $rows;
    }

    /**
     * Total ordering over scalars-or-null: nulls sort first (deterministically),
     * non-null values by PHP's native scalar ordering.
     */
    private function rank(int|float|string|bool|null $left, int|float|string|bool|null $right): int
    {
        if ($left === null || $right === null) {
            return ($left === null ? 0 : 1) <=> ($right === null ? 0 : 1);
        }

        return $left <=> $right;
    }

    /**
     * @param list<array<string, int|float|string|bool|null>> $rows
     *
     * @return list<array<string, int|float|string|bool|null>>
     */
    private function paginate(QueryInterface $query, array $rows): array
    {
        if ($query->offset === null && $query->limit === null) {
            return $rows;
        }

        return array_slice($rows, $query->offset ?? 0, $query->limit);
    }
}
