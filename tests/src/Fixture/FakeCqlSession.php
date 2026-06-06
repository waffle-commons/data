<?php

declare(strict_types=1);

namespace WaffleTests\Commons\Data\Fixture;

use Waffle\Commons\Data\Compiler\CompiledCassandraQuery;
use Waffle\Commons\Data\Driver\Cql\CqlSessionInterface;

/**
 * Recording CQL transport: returns canned rows and exposes the last compiled
 * query so tests can assert exactly what reached the driver boundary.
 */
final class FakeCqlSession implements CqlSessionInterface
{
    public ?CompiledCassandraQuery $lastQuery = null;

    /**
     * @param list<array<string, int|float|string|bool|null>> $rows
     */
    public function __construct(
        private readonly array $rows = [],
    ) {}

    #[\Override]
    public function execute(CompiledCassandraQuery $query): array
    {
        $this->lastQuery = $query;

        return $this->rows;
    }
}
