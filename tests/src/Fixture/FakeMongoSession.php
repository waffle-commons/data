<?php

declare(strict_types=1);

namespace WaffleTests\Commons\Data\Fixture;

use Waffle\Commons\Data\Compiler\CompiledMongoQuery;
use Waffle\Commons\Data\Driver\Mongo\MongoSessionInterface;

/**
 * Recording Mongo transport: returns canned rows on reads and captures every
 * write so tests can assert exactly what reached the driver boundary.
 */
final class FakeMongoSession implements MongoSessionInterface
{
    public ?CompiledMongoQuery $lastQuery = null;

    /** @var list<array{collection: string, row: array<string, int|float|string|bool|null>}> */
    public array $inserts = [];

    /** @var list<array{collection: string, idField: string, id: int|string, row: array<string, int|float|string|bool|null>}> */
    public array $upserts = [];

    /** @var list<array{collection: string, idField: string, id: int|string}> */
    public array $deletes = [];

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

    #[\Override]
    public function insert(string $collection, array $row): void
    {
        $this->inserts[] = ['collection' => $collection, 'row' => $row];
    }

    #[\Override]
    public function upsert(string $collection, string $idField, int|string $id, array $row): void
    {
        $this->upserts[] = ['collection' => $collection, 'idField' => $idField, 'id' => $id, 'row' => $row];
    }

    #[\Override]
    public function deleteOne(string $collection, string $idField, int|string $id): void
    {
        $this->deletes[] = ['collection' => $collection, 'idField' => $idField, 'id' => $id];
    }
}
