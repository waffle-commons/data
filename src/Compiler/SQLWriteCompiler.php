<?php

declare(strict_types=1);

namespace Waffle\Commons\Data\Compiler;

use InvalidArgumentException;

use function array_fill;
use function array_keys;
use function array_map;
use function array_values;
use function count;
use function implode;
use function sprintf;

/**
 * Compiles a mapper-produced row + identity into a parameterised
 * {@see CompiledWrite} (INSERT / UPDATE / DELETE).
 *
 * Every column name is quoted and escaped through the {@see SQLDialect} (closing
 * the identifier-injection vector) and every value is emitted as a `?`
 * placeholder bound separately by the driver (OWASP A03 — Injection). No caller
 * value is ever concatenated into the SQL text.
 */
final class SQLWriteCompiler
{
    public function __construct(
        private readonly SQLDialect $dialect = SQLDialect::MySQL,
    ) {}

    /**
     * @param array<string, int|float|string|bool|null> $row
     *
     * @throws InvalidArgumentException When the row is empty.
     */
    public function compileInsert(string $table, array $row): CompiledWrite
    {
        if ($row === []) {
            throw new InvalidArgumentException('Cannot INSERT an empty row.');
        }

        $columns = array_map($this->dialect->quoteIdentifier(...), array_keys($row));
        $placeholders = implode(', ', array_fill(0, count($row), '?'));

        $sql = sprintf(
            'INSERT INTO %s (%s) VALUES (%s)',
            $this->dialect->quoteIdentifier($table),
            implode(', ', $columns),
            $placeholders,
        );

        return new CompiledWrite($sql, array_values($row));
    }

    /**
     * Builds `UPDATE … SET … WHERE {idField} = ?`. The identity column is never
     * part of the SET list, so a primary key is never rewritten.
     *
     * @param array<string, int|float|string|bool|null> $row
     *
     * @throws InvalidArgumentException When the row has no updatable column.
     */
    public function compileUpdate(string $table, array $row, string $idField, int|string $id): CompiledWrite
    {
        $assignments = [];
        $parameters = [];
        foreach ($row as $column => $value) {
            if ($column === $idField) {
                continue;
            }

            $assignments[] = $this->dialect->quoteIdentifier($column) . ' = ?';
            $parameters[] = $value;
        }

        if ($assignments === []) {
            throw new InvalidArgumentException('Cannot UPDATE without at least one non-identity column.');
        }

        $parameters[] = $id;

        $sql = sprintf(
            'UPDATE %s SET %s WHERE %s = ?',
            $this->dialect->quoteIdentifier($table),
            implode(', ', $assignments),
            $this->dialect->quoteIdentifier($idField),
        );

        return new CompiledWrite($sql, $parameters);
    }

    public function compileDelete(string $table, string $idField, int|string $id): CompiledWrite
    {
        $sql = sprintf(
            'DELETE FROM %s WHERE %s = ?',
            $this->dialect->quoteIdentifier($table),
            $this->dialect->quoteIdentifier($idField),
        );

        return new CompiledWrite($sql, [$id]);
    }
}
