<?php

declare(strict_types=1);

namespace Waffle\Commons\Data\Driver\Mongo;

use MongoDB\Driver\Exception\Exception as MongoDriverException;
use MongoDB\Driver\Manager;
use MongoDB\Driver\Query as MongoQuery;
use Waffle\Commons\Data\Compiler\CompiledMongoQuery;
use Waffle\Commons\Data\Exception\DatabaseException;
use Waffle\Commons\Data\Hydrator\RowNormaliser;

/**
 * Live document adapter over the MongoDB extension (`ext-mongodb`).
 *
 * Holds only an immutable {@see Manager} (the extension manages its own
 * connection pool across worker requests) and translates a
 * {@see CompiledMongoQuery} into a native driver query. Documents are read with
 * an array type map and validated into flat scalar rows; `_id` is excluded by
 * default because the SQR row model is flat scalars and a BSON `ObjectId` would
 * (rightly) be rejected as a poisoned value — store an explicit scalar id field
 * instead. Every driver failure is rethrown as a {@see DatabaseException}
 * (RFC-022 §7.3).
 */
final class MongoDriverSession implements MongoSessionInterface
{
    public function __construct(
        private readonly Manager $manager,
        private readonly string $database,
        private readonly RowNormaliser $normaliser = new RowNormaliser(),
    ) {}

    /**
     * @return list<array<string, int|float|string|bool|null>>
     *
     * @throws DatabaseException When the query fails or a document is not a flat
     *         scalar row.
     */
    #[\Override]
    public function find(CompiledMongoQuery $query): array
    {
        $options = $this->options($query);

        try {
            $cursor = $this->manager->executeQuery(
                $this->database . '.' . $query->collection,
                new MongoQuery($query->filter, $options),
            );
            $cursor->setTypeMap(['root' => 'array', 'document' => 'array', 'array' => 'array']);
            $documents = $cursor->toArray();
        } catch (MongoDriverException $error) {
            throw DatabaseException::fromThrowable($error, 'MongoDB query failed.');
        }

        return $this->normaliser->normaliseAll($documents);
    }

    /**
     * Build the native find options, always excluding the BSON `_id` (allowed
     * alongside field inclusions) so the result is a flat scalar row.
     *
     * @return array<string, array<string, int>|int>
     */
    private function options(CompiledMongoQuery $query): array
    {
        $options = ['projection' => [...$query->options->projection, '_id' => 0]];

        if ($query->options->sort !== []) {
            $options['sort'] = $query->options->sort;
        }

        if ($query->options->limit !== null) {
            $options['limit'] = $query->options->limit;
        }

        if ($query->options->skip !== null) {
            $options['skip'] = $query->options->skip;
        }

        return $options;
    }
}
