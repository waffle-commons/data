<?php

declare(strict_types=1);

namespace Waffle\Commons\Data\Compiler;

use InvalidArgumentException;
use Waffle\Commons\Contracts\Data\Enum\Operator;
use Waffle\Commons\Contracts\Data\Query\ComparisonInterface;
use Waffle\Commons\Contracts\Data\Query\QueryInterface;

use function count;
use function is_bool;
use function is_string;
use function sprintf;

/**
 * Compiles an SQR {@see QueryInterface} into a {@see CompiledKeyValueCommand} for a
 * key-value store (Redis, DynamoDB).
 *
 * A key-value store addresses opaque values by key alone — it has no field-level
 * filtering, ordering, projection, or pagination. This compiler therefore accepts
 * only the degenerate SQR that maps onto a key lookup: a single key-equality
 * predicate (compiled to `GET`) or a single key-membership predicate (compiled to
 * `MGET`). Anything richer is rejected with a precise message rather than being
 * silently mistranslated, so a caller can never believe a key-value backend is
 * filtering when it is not.
 */
final class KeyValueCompiler
{
    /**
     * @throws InvalidArgumentException When the query expresses anything a
     *         key-value store cannot honour (no namespace, a projection, an
     *         ordering, pagination, the wrong number of predicates, an
     *         unsupported operator, or a null key).
     */
    public function compile(QueryInterface $query): CompiledKeyValueCommand
    {
        $namespace = $query->from;
        if ($namespace === null) {
            throw new InvalidArgumentException('A key-value lookup requires a key namespace; call Query::from().');
        }

        if ($query->fields !== []) {
            throw new InvalidArgumentException(
                'A key-value store has no projection; use Query::select() with no fields for a key lookup.',
            );
        }

        if ($query->orderings !== []) {
            throw new InvalidArgumentException('A key-value store cannot order results.');
        }

        if ($query->limit !== null || $query->offset !== null) {
            throw new InvalidArgumentException('A key-value store cannot paginate; remove limit()/offset().');
        }

        $comparison = $this->singlePredicate($query);

        return match ($comparison->operator) {
            Operator::Equal => new CompiledKeyValueCommand(KeyValueOperation::Get, $namespace, [$this->key(
                $namespace,
                $this->scalarKey($comparison),
            )]),
            Operator::In => new CompiledKeyValueCommand(
                KeyValueOperation::MGet,
                $namespace,
                $this->keys($namespace, $comparison),
            ),
            default => throw new InvalidArgumentException(sprintf(
                "A key-value store supports only key-equality (=) or key-membership (IN) lookups; got '%s'.",
                $comparison->operator->value,
            )),
        };
    }

    /**
     * @throws InvalidArgumentException When the query does not carry exactly one
     *         predicate.
     */
    private function singlePredicate(QueryInterface $query): ComparisonInterface
    {
        $predicates = $query->criteria;
        $first = $predicates[0] ?? null;

        if ($first === null || count($predicates) !== 1) {
            throw new InvalidArgumentException('A key-value lookup must carry exactly one key predicate.');
        }

        return $first;
    }

    /**
     * @throws InvalidArgumentException When the equality key is null.
     */
    private function scalarKey(ComparisonInterface $comparison): int|float|string|bool
    {
        $value = $comparison->values[0] ?? null;
        if ($value === null) {
            throw new InvalidArgumentException('A key-value key must not be null.');
        }

        return $value;
    }

    /**
     * @return non-empty-list<string>
     *
     * @throws InvalidArgumentException When a membership key is null or the set is
     *         empty.
     */
    private function keys(string $namespace, ComparisonInterface $comparison): array
    {
        $keys = [];
        foreach ($comparison->values as $value) {
            if ($value === null) {
                throw new InvalidArgumentException('A key-value key must not be null.');
            }

            $keys[] = $this->key($namespace, $value);
        }

        if ($keys === []) {
            throw new InvalidArgumentException('A key-value membership lookup requires at least one key.');
        }

        return $keys;
    }

    private function key(string $namespace, int|float|string|bool $value): string
    {
        return $namespace . ':' . $this->stringify($value);
    }

    private function stringify(int|float|string|bool $value): string
    {
        return match (true) {
            is_string($value) => $value,
            is_bool($value) => $value ? '1' : '0',
            default => (string) $value,
        };
    }
}
