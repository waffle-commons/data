<?php

declare(strict_types=1);

namespace Waffle\Commons\Data\Compiler;

use InvalidArgumentException;
use Waffle\Commons\Contracts\Data\Query\ComparisonInterface;
use Waffle\Commons\Contracts\Data\Query\QueryInterface;

use function array_fill;
use function array_map;
use function count;
use function implode;
use function sprintf;

/**
 * Compiles an SQR {@see QueryInterface} into a parameterised {@see CompiledQuery}.
 *
 * Walking the AST node by node, the compiler emits a `?`-placeholder for every
 * literal and collects the literals separately, so no caller value is ever
 * concatenated into the SQL text (OWASP A03 — Injection). Identifiers are quoted
 * and escaped through the {@see SQLDialect}, closing the identifier vector too.
 */
final class SQLCompiler
{
    public function __construct(
        private readonly SQLDialect $dialect = SQLDialect::MySQL,
    ) {}

    /**
     * @throws InvalidArgumentException When the query has no source table or a
     *                                  set predicate carries no values.
     */
    public function compile(QueryInterface $query): CompiledQuery
    {
        [$where, $parameters] = $this->whereClause($query);

        $sql = sprintf(
            'SELECT %s FROM %s%s%s%s',
            $this->projection($query),
            $this->source($query),
            $where,
            $this->orderClause($query),
            $this->dialect->paginate($query->limit, $query->offset),
        );

        return new CompiledQuery($sql, $parameters);
    }

    private function projection(QueryInterface $query): string
    {
        if ($query->fields === []) {
            return '*';
        }

        $columns = array_map($this->dialect->quoteIdentifier(...), $query->fields);

        return implode(', ', $columns);
    }

    /** @throws InvalidArgumentException When the query has no source table. */
    private function source(QueryInterface $query): string
    {
        if ($query->from === null) {
            throw new InvalidArgumentException('A SQL query requires a source table; call Query::from().');
        }

        return $this->dialect->quoteIdentifier($query->from);
    }

    /**
     * @return array{string, list<int|float|string|bool|null>}
     *
     * @throws InvalidArgumentException When a set predicate carries no values.
     */
    private function whereClause(QueryInterface $query): array
    {
        $fragments = [];
        $parameters = [];

        foreach ($query->criteria as $comparison) {
            [$fragment, $values] = $this->predicate($comparison);
            $fragments[] = $fragment;
            $parameters = [...$parameters, ...$values];
        }

        if ($fragments === []) {
            return ['', $parameters];
        }

        return [' WHERE ' . implode(' AND ', $fragments), $parameters];
    }

    private function orderClause(QueryInterface $query): string
    {
        if ($query->orderings === []) {
            return '';
        }

        $parts = [];
        foreach ($query->orderings as $order) {
            $parts[] = $this->dialect->quoteIdentifier($order->field) . ' ' . $order->direction->value;
        }

        return ' ORDER BY ' . implode(', ', $parts);
    }

    /**
     * @return array{string, list<int|float|string|bool|null>}
     *
     * @throws InvalidArgumentException When a set predicate carries no values.
     */
    private function predicate(ComparisonInterface $comparison): array
    {
        $column = $this->dialect->quoteIdentifier($comparison->field);
        $operator = $comparison->operator;

        if ($operator->isSetOperator()) {
            if ($comparison->values === []) {
                throw new InvalidArgumentException('A set predicate (IN / NOT IN) requires at least one value.');
            }

            $placeholders = implode(', ', array_fill(0, count($comparison->values), '?'));

            return [sprintf('%s %s (%s)', $column, $operator->value, $placeholders), $comparison->values];
        }

        return [sprintf('%s %s ?', $column, $operator->value), $comparison->values];
    }
}
