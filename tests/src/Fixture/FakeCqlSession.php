<?php

declare(strict_types=1);

namespace WaffleTests\Commons\Data\Fixture;

use Waffle\Commons\Data\Compiler\CompiledCassandraQuery;
use Waffle\Commons\Data\Driver\Cql\CqlSessionInterface;

/**
 * Recording CQL transport: returns canned rows on reads and captures the last
 * write statement so tests can assert exactly what reached the driver boundary.
 */
final class FakeCqlSession implements CqlSessionInterface
{
    public ?CompiledCassandraQuery $lastQuery = null;

    public ?string $lastWriteCql = null;

    /** @var list<int|float|string|bool|null> */
    public array $lastWriteParameters = [];

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

    #[\Override]
    public function executeWrite(string $cql, array $parameters): void
    {
        $this->lastWriteCql = $cql;
        $this->lastWriteParameters = $parameters;
    }
}
