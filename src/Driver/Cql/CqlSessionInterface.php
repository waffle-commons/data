<?php

declare(strict_types=1);

namespace Waffle\Commons\Data\Driver\Cql;

use Waffle\Commons\Data\Compiler\CompiledCassandraQuery;

/**
 * Transport port for the wide-column (Cassandra/CQL) driver family
 * (RFC-022 §4.2): executes a parameterised {@see CompiledCassandraQuery} and
 * returns flat scalar rows.
 *
 * The transport is deliberately injectable: as of PHP 8.5 no maintained native
 * CQL binary-protocol client exists (the DataStax `ext-cassandra` is
 * abandoned), so live access goes through an environment-provided adapter —
 * typically a Stargate gateway (whose GraphQL face is already served by the
 * {@see \Waffle\Commons\Data\Driver\Graph\GraphQLExecutor} family) or a future
 * extension. Repository logic stays fully testable against a fake session
 * either way.
 */
interface CqlSessionInterface
{
    /**
     * Execute the compiled CQL and return every matching row.
     *
     * Implementations decide whether to honour
     * {@see CompiledCassandraQuery::$requiresAllowFiltering} by appending
     * `ALLOW FILTERING`, since only the deployment knows its primary keys.
     *
     * @return list<array<string, int|float|string|bool|null>>
     *
     * @throws \Waffle\Commons\Contracts\Data\Exception\DatabaseExceptionInterface
     *         When the backend call fails or a row is not a flat scalar map.
     */
    public function execute(CompiledCassandraQuery $query): array;
}
