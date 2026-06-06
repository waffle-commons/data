<?php

declare(strict_types=1);

namespace WaffleTests\Commons\Data\Fixture;

use Waffle\Commons\Data\Compiler\CompiledMongoQuery;
use Waffle\Commons\Data\Driver\Mongo\MongoSessionInterface;

/**
 * Recording Mongo transport: returns canned rows and exposes the last compiled
 * query so tests can assert exactly what reached the driver boundary.
 */
final class FakeMongoSession implements MongoSessionInterface
{
    public ?CompiledMongoQuery $lastQuery = null;

    /**
     * @param list<array<string, int|float|string|bool|null>> $rows
     */
    public function __construct(
        private readonly array $rows = [],
    ) {}

    #[\Override]
    public function find(CompiledMongoQuery $query): array
    {
        $this->lastQuery = $query;

        return $this->rows;
    }
}
