<?php

declare(strict_types=1);

namespace Waffle\Commons\Data\Query;

use InvalidArgumentException;

/**
 * Immutable, compiler-agnostic Abstract Syntax Tree for a read query.
 *
 * A Query holds only *representation* state — projection, source, filter
 * predicates, ordering and bounded pagination. It performs no I/O and knows
 * nothing about SQL, Firestore or any other backend; driver compilers translate
 * it into a native payload. Every builder method returns a new instance via
 * copy-on-write, so a Query is safe to reuse across a resident worker without
 * any shared mutable state.
 */
final class Query
{
    /** @var list<string> */
    public private(set) array $fields = [];

    public private(set) ?string $from = null;

    /** @var list<Comparison> */
    public private(set) array $criteria = [];

    /** @var list<Order> */
    public private(set) array $orderings = [];

    public private(set) ?int $limit = null;

    public private(set) ?int $offset = null;

    private function __construct() {}

    /**
     * Begin a query, optionally projecting a subset of fields. With no argument
     * the query selects every field the backend returns.
     */
    public static function select(string ...$fields): self
    {
        $query = new self();
        $query->fields = array_values($fields);

        return $query;
    }

    /** Set the source table (relational) or collection (document store). */
    public function from(string $source): self
    {
        $clone = clone $this;
        $clone->from = $source;

        return $clone;
    }

    /** Append one or more AND-combined filter predicates. */
    public function where(Comparison ...$criteria): self
    {
        $clone = clone $this;
        $clone->criteria = [...$clone->criteria, ...array_values($criteria)];

        return $clone;
    }

    /** Append an ordering clause. */
    public function orderBy(string $field, Direction $direction = Direction::Ascending): self
    {
        $clone = clone $this;
        $clone->orderings = [...$clone->orderings, new Order($field, $direction)];

        return $clone;
    }

    /** @throws InvalidArgumentException When $limit is negative. */
    public function limit(int $limit): self
    {
        if ($limit < 0) {
            throw new InvalidArgumentException('Query limit must not be negative.');
        }

        $clone = clone $this;
        $clone->limit = $limit;

        return $clone;
    }

    /** @throws InvalidArgumentException When $offset is negative. */
    public function offset(int $offset): self
    {
        if ($offset < 0) {
            throw new InvalidArgumentException('Query offset must not be negative.');
        }

        $clone = clone $this;
        $clone->offset = $offset;

        return $clone;
    }
}
