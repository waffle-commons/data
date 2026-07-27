<?php

declare(strict_types=1);

namespace Waffle\Commons\Data\Compiler;

use InvalidArgumentException;
use Waffle\Commons\Contracts\Data\Enum\Operator;
use Waffle\Commons\Contracts\Data\Query\ComparisonInterface;
use Waffle\Commons\Contracts\Data\Query\QueryInterface;

use function array_fill;
use function array_map;
use function count;
use function explode;
use function implode;
use function preg_match;
use function sprintf;
use function str_replace;

/**
 * Compiles an SQR {@see QueryInterface} into a parameterised {@see CompiledCassandraQuery}.
 *
 * Cassandra speaks CQL, a SQL-like dialect with a deliberately narrower predicate
 * surface: equality, the four range operators, and `IN` are valid in a `WHERE`
 * clause, but inequality (`<>`), `NOT IN`, and `LIKE` are not (the latter needs a
 * SASI index) and `OFFSET` does not exist (Cassandra pages with token state).
 * Those are rejected rather than mistranslated. Every operand is emitted as a `?`
 * marker and bound separately — no value is concatenated into the CQL (OWASP A03)
 * — and identifiers are quoted and escaped, closing the identifier vector too.
 */
final class CassandraCompiler
{
    /**
     * Strict allow-list applied to every identifier segment ALONGSIDE quote
     * escaping (FIX-01), mirroring {@see SQLDialect::IDENTIFIER_PATTERN}: a bare
     * CQL identifier is one or more ASCII letters/digits/underscores that does
     * not start with a digit. Escaping the quote character alone still let
     * control bytes and other punctuation through — safe against breaking OUT
     * of the quoted identifier, but not against statement-splitting/log-
     * injection/truncation via characters embedded WITHIN it.
     */
    private const string IDENTIFIER_PATTERN = '/^[a-zA-Z_][a-zA-Z0-9_]*$/';

    /**
     * @throws InvalidArgumentException When the query has no source table, uses
     *         OFFSET pagination, or carries a CQL-incompatible predicate.
     */
    public function compile(QueryInterface $query): CompiledCassandraQuery
    {
        $table = $query->from;
        if ($table === null) {
            throw new InvalidArgumentException('A CQL query requires a source table; call Query::from().');
        }

        if ($query->offset !== null) {
            throw new InvalidArgumentException(
                'Cassandra does not support OFFSET pagination; use token-based paging instead.',
            );
        }

        [$where, $parameters] = $this->whereClause($query);

        $cql = sprintf(
            'SELECT %s FROM %s%s%s%s',
            $this->projection($query),
            $this->quoteIdentifier($table),
            $where,
            $this->orderClause($query),
            $this->limitClause($query),
        );

        // Any WHERE may need ALLOW FILTERING unless every predicate targets the
        // primary key — which the compiler cannot know — so flag it advisorily.
        return new CompiledCassandraQuery($cql, $parameters, $query->criteria !== []);
    }

    /**
     * @throws InvalidArgumentException When a field name is empty or holds a
     *         character outside the identifier allow-list.
     */
    private function projection(QueryInterface $query): string
    {
        if ($query->fields === []) {
            return '*';
        }

        return implode(', ', array_map($this->quoteIdentifier(...), $query->fields));
    }

    /**
     * @return array{string, list<int|float|string|bool|null>}
     *
     * @throws InvalidArgumentException When a predicate is CQL-incompatible or a
     *         set predicate carries no values.
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

    /**
     * @return array{string, list<int|float|string|bool|null>}
     *
     * @throws InvalidArgumentException When the operator is CQL-incompatible, a
     *         set predicate carries no values, or the field name holds a
     *         character outside the identifier allow-list.
     */
    private function predicate(ComparisonInterface $comparison): array
    {
        $column = $this->quoteIdentifier($comparison->field);
        // Resolve the token first: an unsupported operator throws here, before any
        // set-membership handling, so only CQL-valid operators reach the branches.
        $token = $this->token($comparison->operator);

        if ($comparison->operator->isSetOperator()) {
            if ($comparison->values === []) {
                throw new InvalidArgumentException('A set predicate (IN) requires at least one value.');
            }

            $placeholders = implode(', ', array_fill(0, count($comparison->values), '?'));

            return [sprintf('%s %s (%s)', $column, $token, $placeholders), $comparison->values];
        }

        return [sprintf('%s %s ?', $column, $token), $comparison->values];
    }

    /**
     * @throws InvalidArgumentException When the operator has no CQL equivalent.
     */
    private function token(Operator $operator): string
    {
        return match ($operator) {
            Operator::Equal => '=',
            Operator::GreaterThan => '>',
            Operator::GreaterThanOrEqual => '>=',
            Operator::LessThan => '<',
            Operator::LessThanOrEqual => '<=',
            Operator::In => 'IN',
            Operator::NotEqual, Operator::NotIn, Operator::Like => throw new InvalidArgumentException(sprintf(
                "Cassandra (CQL) does not support the '%s' operator in a WHERE clause.",
                $operator->value,
            )),
        };
    }

    /**
     * @throws InvalidArgumentException When an ordering field is empty or holds
     *         a character outside the identifier allow-list.
     */
    private function orderClause(QueryInterface $query): string
    {
        if ($query->orderings === []) {
            return '';
        }

        $parts = [];
        foreach ($query->orderings as $order) {
            $parts[] = $this->quoteIdentifier($order->field) . ' ' . $order->direction->value;
        }

        return ' ORDER BY ' . implode(', ', $parts);
    }

    private function limitClause(QueryInterface $query): string
    {
        return $query->limit === null ? '' : ' LIMIT ' . $query->limit;
    }

    /**
     * Quote a (possibly dotted) CQL identifier. Public (FIX-01) so a repository's
     * own write path (`save()` / `delete()`) can route table/column/identity
     * names through the exact same validation and escaping the read path
     * already uses here, instead of splicing them into CQL unquoted.
     *
     * @throws InvalidArgumentException When a segment is empty or holds a
     *         character outside the allow-list.
     */
    public function quoteIdentifier(string $identifier): string
    {
        return implode('.', array_map($this->quoteSegment(...), explode('.', $identifier)));
    }

    /**
     * @throws InvalidArgumentException When the segment is empty or holds a
     *         character outside the ASCII letter/digit/underscore allow-list.
     */
    private function quoteSegment(string $segment): string
    {
        if (preg_match(self::IDENTIFIER_PATTERN, $segment) !== 1) {
            throw new InvalidArgumentException(
                'Invalid CQL identifier: a segment must start with an ASCII letter or underscore and '
                . 'contain only ASCII letters, digits, and underscores.',
            );
        }

        // The allow-list above already excludes `"`, so this can never fire in
        // practice — kept as defence-in-depth (FIX-01) should it ever be relaxed.
        return '"' . str_replace('"', '""', $segment) . '"';
    }
}
