<?php

declare(strict_types=1);

namespace Waffle\Commons\Data\Compiler;

use InvalidArgumentException;
use Waffle\Commons\Contracts\Data\Enum\Operator;
use Waffle\Commons\Contracts\Data\Query\ComparisonInterface;
use Waffle\Commons\Contracts\Data\Query\QueryInterface;

use function array_map;
use function implode;
use function is_bool;
use function is_float;
use function is_int;
use function preg_match;
use function sprintf;

/**
 * Compiles an SQR {@see QueryInterface} into a {@see CompiledGraphQLQuery}.
 *
 * Following RFC-022 §4.3, an external GraphQL service is elevated to a virtual
 * database engine: the query's source becomes the root field, its projection the
 * selection set, its predicates a `where` argument, and its pagination the
 * `limit`/`offset` arguments. Predicates compile to the conventional nested
 * operator form (`field: {_gt: $v0}`) and every operand is emitted as a *declared
 * variable*, never inlined — closing the injection vector. Field and root names
 * are validated against the GraphQL name grammar so a crafted identifier cannot
 * break out of the document structure either.
 */
final class GraphQLCompiler
{
    private const string NAME_PATTERN = '/^[_A-Za-z][_0-9A-Za-z]*$/';

    /**
     * @throws InvalidArgumentException When the query has no root field, no
     *         projection (GraphQL has no `SELECT *`), or carries an invalid name.
     */
    public function compile(QueryInterface $query): CompiledGraphQLQuery
    {
        $root = $query->from;
        if ($root === null) {
            throw new InvalidArgumentException('A GraphQL query requires a root field; call Query::from().');
        }

        if ($query->fields === []) {
            throw new InvalidArgumentException(
                'A GraphQL query requires an explicit projection (GraphQL has no SELECT *); call Query::select(...).',
            );
        }

        $variables = [];
        $declarations = [];
        $conditions = [];
        $index = 0;
        foreach ($query->criteria as $comparison) {
            $name = 'v' . $index;
            ++$index;

            $variables[$name] = $this->operand($comparison);
            $declarations[] = sprintf('$%s: %s', $name, $this->variableType($comparison));
            $conditions[] = sprintf(
                '%s: {%s: $%s}',
                $this->name($comparison->field),
                $this->operator($comparison->operator),
                $name,
            );
        }

        $document = sprintf(
            'query%s { %s%s { %s } }',
            $this->declarationList($declarations),
            $this->name($root),
            $this->argumentList($query, $conditions),
            implode(' ', array_map($this->name(...), $query->fields)),
        );

        return new CompiledGraphQLQuery($document, $variables, $root);
    }

    /**
     * @param list<string> $declarations
     */
    private function declarationList(array $declarations): string
    {
        return $declarations === [] ? '' : ' (' . implode(', ', $declarations) . ')';
    }

    /**
     * @param list<string> $conditions
     */
    private function argumentList(QueryInterface $query, array $conditions): string
    {
        $arguments = [];
        if ($conditions !== []) {
            $arguments[] = 'where: {' . implode(', ', $conditions) . '}';
        }

        if ($query->limit !== null) {
            $arguments[] = 'limit: ' . $query->limit;
        }

        if ($query->offset !== null) {
            $arguments[] = 'offset: ' . $query->offset;
        }

        return $arguments === [] ? '' : '(' . implode(', ', $arguments) . ')';
    }

    /**
     * @return int|float|string|bool|null|list<int|float|string|bool|null>
     */
    private function operand(ComparisonInterface $comparison): int|float|string|bool|array|null
    {
        return $comparison->operator->isSetOperator() ? $comparison->values : $comparison->values[0] ?? null;
    }

    private function variableType(ComparisonInterface $comparison): string
    {
        if ($comparison->operator->isSetOperator()) {
            return '[' . $this->scalarType($comparison->values[0] ?? null) . '!]';
        }

        $value = $comparison->values[0] ?? null;

        return $this->scalarType($value) . ($value === null ? '' : '!');
    }

    private function scalarType(int|float|string|bool|null $value): string
    {
        return match (true) {
            is_int($value) => 'Int',
            is_float($value) => 'Float',
            is_bool($value) => 'Boolean',
            default => 'String',
        };
    }

    private function operator(Operator $operator): string
    {
        return match ($operator) {
            Operator::Equal => '_eq',
            Operator::NotEqual => '_neq',
            Operator::GreaterThan => '_gt',
            Operator::GreaterThanOrEqual => '_gte',
            Operator::LessThan => '_lt',
            Operator::LessThanOrEqual => '_lte',
            Operator::In => '_in',
            Operator::NotIn => '_nin',
            Operator::Like => '_like',
        };
    }

    /**
     * @throws InvalidArgumentException When the identifier is not a valid GraphQL name.
     */
    private function name(string $name): string
    {
        if (preg_match(self::NAME_PATTERN, $name) !== 1) {
            throw new InvalidArgumentException(sprintf('"%s" is not a valid GraphQL name.', $name));
        }

        return $name;
    }
}
