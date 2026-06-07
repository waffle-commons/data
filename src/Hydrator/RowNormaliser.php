<?php

declare(strict_types=1);

namespace Waffle\Commons\Data\Hydrator;

use JsonException;
use PDO;
use PDOException;
use PDOStatement;
use Waffle\Commons\Data\Exception\DatabaseException;

use function is_array;
use function is_scalar;
use function json_decode;

use const JSON_THROW_ON_ERROR;

/**
 * Normalises raw backend output — a decoded JSON payload, a PDO fetch, a driver
 * result set — into the flat `array<string, scalar|null>` row shape the
 * {@see PropertyHookHydrator} consumes.
 *
 * This is deliberately the ONE place in the component where untyped backend
 * data (`mixed` by signature: `json_decode()`, `PDOStatement::fetch()`) is
 * touched: every value is narrowed by an `is_array()` / `is_scalar()` guard
 * before use, and any shape violation throws a {@see DatabaseException} so a
 * poisoned record never reaches application code with a widened type.
 */
final class RowNormaliser
{
    /**
     * Decode a JSON payload that must hold a list of flat rows.
     *
     * @return list<array<string, int|float|string|bool|null>>
     *
     * @throws DatabaseException When the payload is not valid JSON or does not
     *         decode to a list of flat scalar rows.
     */
    public function fromJsonRows(string $json): array
    {
        try {
            $decoded = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $error) {
            throw DatabaseException::fromThrowable($error, 'Backend payload is not valid JSON.');
        }

        if (!is_array($decoded)) {
            throw new DatabaseException('Backend payload must decode to an array of rows.');
        }

        return $this->normaliseAll($decoded);
    }

    /**
     * Decode a JSON payload that must hold exactly one flat row (e.g. a
     * key-value store document).
     *
     * @return array<string, int|float|string|bool|null>
     *
     * @throws DatabaseException When the payload is not valid JSON or does not
     *         decode to a flat scalar row.
     */
    public function fromJsonRow(string $json): array
    {
        try {
            $decoded = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $error) {
            throw DatabaseException::fromThrowable($error, 'Backend document is not valid JSON.');
        }

        if (!is_array($decoded)) {
            throw new DatabaseException('Backend document must decode to a row.');
        }

        return $this->normalise($decoded);
    }

    /**
     * Normalise a batch of raw rows.
     *
     * @param array<array-key, mixed> $rows
     *
     * @return list<array<string, int|float|string|bool|null>>
     *
     * @throws DatabaseException When an entry is not a row or a value is neither
     *         scalar nor null.
     */
    public function normaliseAll(array $rows): array
    {
        $normalised = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                throw new DatabaseException('Backend returned a non-row entry; every row must be a map.');
            }

            $normalised[] = $this->normalise($row);
        }

        return $normalised;
    }

    /**
     * Normalise a single raw row.
     *
     * @param array<array-key, mixed> $row
     *
     * @return array<string, int|float|string|bool|null>
     *
     * @throws DatabaseException When a value is neither scalar nor null.
     */
    public function normalise(array $row): array
    {
        $normalised = [];
        foreach ($row as $key => $value) {
            if ($value !== null && !is_scalar($value)) {
                throw new DatabaseException('Backend row values must be scalar or null.');
            }

            $normalised[(string) $key] = $value;
        }

        return $normalised;
    }

    /**
     * Pull and normalise the next row from an executed statement, or null once
     * the cursor is exhausted — the row-by-row primitive behind the
     * buffer-streaming mandate (RFC-022 §4.1).
     *
     * @return array<string, int|float|string|bool|null>|null
     *
     * @throws DatabaseException When the driver yields a non-row result or a
     *         value that is neither scalar nor null.
     * @throws PDOException When the underlying cursor read fails; the calling
     *         driver wraps it into a DatabaseException.
     */
    public function fromFetch(PDOStatement $statement): ?array
    {
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }

        if (!is_array($row)) {
            throw new DatabaseException('Backend fetch yielded a non-row result.');
        }

        return $this->normalise($row);
    }
}
