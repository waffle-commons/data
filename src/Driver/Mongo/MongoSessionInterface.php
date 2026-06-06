<?php

declare(strict_types=1);

namespace Waffle\Commons\Data\Driver\Mongo;

use Waffle\Commons\Data\Compiler\CompiledMongoQuery;

/**
 * Transport port for the MongoDB driver family (RFC-022 §4.2): executes a
 * {@see CompiledMongoQuery} and returns flat scalar rows.
 *
 * The port exists because the extension's `MongoDB\Driver\Manager` is `final`
 * and cannot be doubled: repository logic stays fully testable against a fake
 * session, while {@see MongoDriverSession} is the thin live adapter over
 * `ext-mongodb`.
 */
interface MongoSessionInterface
{
    /**
     * Execute the compiled query and return every matching document as a flat
     * scalar row.
     *
     * @return list<array<string, int|float|string|bool|null>>
     *
     * @throws \Waffle\Commons\Contracts\Data\Exception\DatabaseExceptionInterface
     *         When the backend call fails or a document is not a flat scalar row.
     */
    public function find(CompiledMongoQuery $query): array;
}
