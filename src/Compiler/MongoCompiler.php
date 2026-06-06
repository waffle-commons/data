<?php

declare(strict_types=1);

namespace Waffle\Commons\Data\Compiler;

use InvalidArgumentException;
use Waffle\Commons\Contracts\Data\Enum\Direction;
use Waffle\Commons\Contracts\Data\Enum\Operator;
use Waffle\Commons\Contracts\Data\Query\ComparisonInterface;
use Waffle\Commons\Contracts\Data\Query\QueryInterface;

use function preg_quote;
use function str_replace;

/**
 * Compiles an SQR {@see QueryInterface} into a {@see CompiledMongoQuery}.
 *
 * MongoDB is a document store with a capable server-side query engine, so — in
 * contrast to the Firestore compiler — every predicate is translated into a
 * native filter document and pushed down; nothing is deferred to in-memory
 * processing. Operators map onto Mongo query operators (`$eq`, `$ne`, `$gt`,
 * `$gte`, `$lt`, `$lte`, `$in`, `$nin`) and a SQL `LIKE` becomes an anchored
 * `$regex`. No value is ever concatenated into a query string: the filter is a
 * structured document the driver binds as data.
 */
final class MongoCompiler
{
    /**
     * @throws InvalidArgumentException When the query has no source collection.
     */
    public function compile(QueryInterface $query): CompiledMongoQuery
    {
        $collection = $query->from;
        if ($collection === null) {
            throw new InvalidArgumentException('A Mongo query requires a source collection; call Query::from().');
        }

        return new CompiledMongoQuery(
            $collection,
            $this->filter($query),
            new MongoFindOptions($this->projection($query), $this->sort($query), $query->limit, $query->offset),
        );
    }

    /**
     * @return array<string, array<string, int|float|string|bool|null|list<int|float|string|bool|null>>>
     */
    private function filter(QueryInterface $query): array
    {
        $filter = [];

        foreach ($query->criteria as $comparison) {
            // Merge every predicate on a field into one operator document so
            // `gt` AND `lt` on the same field coexist instead of overwriting.
            $document = $filter[$comparison->field] ?? [];
            $document[$this->operator($comparison->operator)] = $this->operand($comparison);
            $filter[$comparison->field] = $document;
        }

        return $filter;
    }

    private function operator(Operator $operator): string
    {
        return match ($operator) {
            Operator::Equal => '$eq',
            Operator::NotEqual => '$ne',
            Operator::GreaterThan => '$gt',
            Operator::GreaterThanOrEqual => '$gte',
            Operator::LessThan => '$lt',
            Operator::LessThanOrEqual => '$lte',
            Operator::In => '$in',
            Operator::NotIn => '$nin',
            Operator::Like => '$regex',
        };
    }

    /**
     * @return int|float|string|bool|null|list<int|float|string|bool|null>
     */
    private function operand(ComparisonInterface $comparison): int|float|string|bool|array|null
    {
        if ($comparison->operator === Operator::Like) {
            return $this->likeToRegex($comparison->values[0] ?? null);
        }

        if ($comparison->operator->isSetOperator()) {
            // $in / $nin take the full value list, which Mongo binds as-is.
            return $comparison->values;
        }

        // Scalar operators carry a single operand.
        return $comparison->values[0] ?? null;
    }

    /**
     * Translate a SQL `LIKE` pattern into an anchored PCRE: `%` → `.*`, `_` → `.`,
     * every other character taken literally (PCRE metacharacters escaped), so the
     * pattern can never inject regex behaviour.
     */
    private function likeToRegex(int|float|string|bool|null $pattern): string
    {
        $literal = preg_quote((string) $pattern, '/');

        return '^' . str_replace(['%', '_'], ['.*', '.'], $literal) . '$';
    }

    /**
     * @return array<string, int>
     */
    private function projection(QueryInterface $query): array
    {
        $projection = [];
        foreach ($query->fields as $field) {
            $projection[$field] = 1;
        }

        return $projection;
    }

    /**
     * @return array<string, int>
     */
    private function sort(QueryInterface $query): array
    {
        $sort = [];
        foreach ($query->orderings as $order) {
            $sort[$order->field] = $order->direction === Direction::Ascending ? 1 : -1;
        }

        return $sort;
    }
}
