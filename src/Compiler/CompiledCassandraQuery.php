<?php

declare(strict_types=1);

namespace Waffle\Commons\Data\Compiler;

/**
 * The wide-column output of compiling an SQR
 * {@see \Waffle\Commons\Data\Query\Query} for Cassandra: a parameterised CQL
 * string and the positional operands that fill its `?` markers, in order.
 *
 * As with the relational {@see CompiledQuery}, no operand is interpolated into
 * {@see self::$cql}; the driver binds {@see self::$parameters}, which keeps the
 * statement injection-safe. {@see self::$requiresAllowFiltering} is raised
 * whenever the query filters on columns the compiler cannot prove are part of the
 * primary key (i.e. any `WHERE`), signalling the caller to append
 * `ALLOW FILTERING` only when those columns are genuinely non-key.
 */
final readonly class CompiledCassandraQuery
{
    /** @var list<int|float|string|bool|null> */
    public array $parameters;

    /**
     * @param list<int|float|string|bool|null> $parameters
     */
    public function __construct(
        public string $cql,
        array $parameters,
        public bool $requiresAllowFiltering,
    ) {
        $this->parameters = $parameters;
    }
}
