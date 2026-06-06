<?php

declare(strict_types=1);

namespace Waffle\Commons\Data\Storage;

use JsonException;
use Waffle\Commons\Contracts\Data\Query\QueryInterface;
use Waffle\Commons\Data\Evaluation\InMemoryEvaluator;
use Waffle\Commons\Data\Exception\DatabaseException;
use Waffle\Commons\Data\Hydrator\RowNormaliser;

use function dirname;
use function file_get_contents;
use function file_put_contents;
use function is_dir;
use function is_file;
use function json_encode;
use function rename;
use function sprintf;
use function trim;
use function uniqid;
use function unlink;

use const JSON_PRETTY_PRINT;
use const JSON_THROW_ON_ERROR;
use const LOCK_EX;

/**
 * Flat-file JSON document store (RFC-022 §4.3).
 *
 * Each collection is a single JSON file holding a list of flat rows. Writes are
 * crash- and concurrency-safe by construction: the payload is written to a
 * uniquely-named temporary file *in the same directory* under `LOCK_EX`, then
 * `rename()`d over the target — an atomic operation on a POSIX filesystem, so a
 * reader ever sees either the whole previous file or the whole new one, never a
 * half-written document. The temp file lives beside its target (never in a shared
 * `sys_get_temp_dir()`), honouring the statelessness mandate and keeping the
 * rename atomic (same filesystem).
 *
 * Decoded data is validated on the way in by the shared {@see RowNormaliser} —
 * a corrupt store (not a JSON array, or a row that is not a flat scalar map) is
 * rejected as a {@see DatabaseException} rather than silently widening the type,
 * exactly as the hydrator rejects a poisoned database row.
 *
 * Type note: JSON carries a single number type, so a whole-number float (`7.0`)
 * is encoded as `7` and reads back as an int. Callers needing float identity
 * should hydrate through a DTO whose Property Hooks normalise the value.
 */
final class JsonFileStore
{
    public function __construct(
        private readonly InMemoryEvaluator $evaluator = new InMemoryEvaluator(),
        private readonly RowNormaliser $normaliser = new RowNormaliser(),
    ) {}

    /**
     * Read and validate every row in the store, or an empty list when the file
     * does not yet exist.
     *
     * @return list<array<string, int|float|string|bool|null>>
     *
     * @throws DatabaseException When the file cannot be read or holds corrupt data.
     */
    public function read(string $path): array
    {
        if (!is_file($path)) {
            return [];
        }

        $contents = file_get_contents($path);
        if ($contents === false) {
            throw new DatabaseException(sprintf('Failed to read the JSON store "%s".', $path));
        }

        if (trim($contents) === '') {
            return [];
        }

        return $this->normaliser->fromJsonRows($contents);
    }

    /**
     * Filter, sort, and paginate the store with the given SQR, evaluated in
     * memory after a simple full read.
     *
     * @return list<array<string, int|float|string|bool|null>>
     *
     * @throws DatabaseException When the file cannot be read or holds corrupt data.
     */
    public function query(string $path, QueryInterface $query): array
    {
        return $this->evaluator->evaluate($query, $this->read($path));
    }

    /**
     * Atomically replace the store with the given rows (temp file + rename).
     *
     * @param list<array<string, int|float|string|bool|null>> $rows
     *
     * @throws DatabaseException When the rows cannot be encoded, the target
     *         directory is missing, or the file cannot be written or atomically
     *         swapped into place.
     */
    public function write(string $path, array $rows): void
    {
        try {
            $json = json_encode($rows, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT);
        } catch (JsonException $error) {
            throw DatabaseException::fromThrowable($error, 'Failed to encode rows for the JSON store.');
        }

        $directory = dirname($path);
        if (!is_dir($directory)) {
            throw new DatabaseException(sprintf('JSON store directory "%s" does not exist.', $directory));
        }

        // Unique-per-writer temp name: uniqueness is what matters here (the name
        // is not a secret); it lives beside its target so the rename stays atomic.
        $temp = $path . '.tmp.' . uniqid('', true);
        if (file_put_contents($temp, $json, LOCK_EX) === false) {
            throw new DatabaseException(sprintf('Failed to write the JSON store temp file "%s".', $temp));
        }

        if (!rename($temp, $path)) {
            // Best-effort cleanup so a failed swap does not litter orphan temps.
            if (is_file($temp)) {
                unlink($temp);
            }

            throw new DatabaseException(sprintf('Failed to atomically replace the JSON store "%s".', $path));
        }
    }
}
