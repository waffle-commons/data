<?php

declare(strict_types=1);

namespace Waffle\Commons\Data\Compiler;

use InvalidArgumentException;

use function array_map;
use function explode;
use function implode;
use function preg_match;
use function str_replace;

/**
 * Relational dialects supported by {@see SQLCompiler}.
 *
 * Each case owns its two points of SQL divergence: how an identifier is quoted
 * (and how the quote character itself is escaped, closing the identifier-
 * injection vector) and how bounded pagination is expressed.
 */
enum SQLDialect
{
    case MySQL;
    /** MariaDB is wire- and syntax-compatible with MySQL; it shares every rule below. */
    case MariaDB;
    case SQLite;
    case MSSQL;
    case PostgreSQL;
    case Oracle;

    /**
     * Allow-list applied to every identifier segment ALONGSIDE quote-escaping
     * (HARDEN-03): rejects NUL and control characters, which quoting cannot make
     * safe (truncation / statement-splitting / log injection). Printable quote
     * characters are still permitted and neutralised by {@see quoteSegment()}.
     */
    private const string IDENTIFIER_PATTERN = '/^[^\x00-\x1F\x7F]+$/';

    /**
     * Quote a (possibly dotted) identifier. Each segment is validated against the
     * {@see IDENTIFIER_PATTERN} allow-list, then its closing quote is escaped so a
     * crafted column name can neither smuggle control bytes nor break out.
     *
     * @throws InvalidArgumentException When a segment is empty or holds a control character.
     */
    public function quoteIdentifier(string $identifier): string
    {
        $quoted = array_map($this->quoteSegment(...), explode('.', $identifier));

        return implode('.', $quoted);
    }

    /**
     * Build the trailing pagination clause (with a leading space), or an empty
     * string when the query is unbounded.
     *
     * Note: MSSQL's `OFFSET ... FETCH` is only valid alongside an `ORDER BY`,
     * which the SQR caller is responsible for supplying. Oracle (12c+) shares the
     * very same clause; it tolerates a missing `ORDER BY` syntactically, but one
     * is still required for a deterministic page.
     */
    public function paginate(?int $limit, ?int $offset): string
    {
        if ($limit === null && $offset === null) {
            return '';
        }

        return match ($this) {
            self::MSSQL, self::Oracle => $this->paginateOffsetFetch($limit, $offset),
            self::MySQL, self::MariaDB, self::SQLite => $this->paginateLimitOffset($limit, $offset),
            self::PostgreSQL => $this->paginateLimitOffsetIndependent($limit, $offset),
        };
    }

    /** @throws InvalidArgumentException When the segment is empty or holds a NUL/control character. */
    private function quoteSegment(string $segment): string
    {
        if (preg_match(self::IDENTIFIER_PATTERN, $segment) !== 1) {
            throw new InvalidArgumentException(
                'Invalid SQL identifier: a segment must be non-empty and free of NUL/control characters.',
            );
        }

        return match ($this) {
            self::MySQL, self::MariaDB => '`' . str_replace('`', '``', $segment) . '`',
            self::SQLite, self::PostgreSQL, self::Oracle => '"' . str_replace('"', '""', $segment) . '"',
            self::MSSQL => '[' . str_replace(']', ']]', $segment) . ']',
        };
    }

    private function paginateLimitOffset(?int $limit, ?int $offset): string
    {
        // MySQL/MariaDB/SQLite require a LIMIT before an OFFSET; when only an
        // offset is given, use the dialect's "no upper bound" sentinel.
        $sentinel = $this === self::SQLite ? '-1' : '18446744073709551615';
        $clause = ' LIMIT ' . ($limit ?? $sentinel);

        if ($offset !== null) {
            $clause .= ' OFFSET ' . $offset;
        }

        return $clause;
    }

    private function paginateOffsetFetch(?int $limit, ?int $offset): string
    {
        $clause = ' OFFSET ' . ($offset ?? 0) . ' ROWS';

        if ($limit !== null) {
            $clause .= ' FETCH NEXT ' . $limit . ' ROWS ONLY';
        }

        return $clause;
    }

    private function paginateLimitOffsetIndependent(?int $limit, ?int $offset): string
    {
        // PostgreSQL accepts LIMIT and OFFSET independently: an OFFSET may stand
        // alone without a LIMIT, so neither side needs the "no upper bound"
        // sentinel MySQL/MariaDB require.
        $clause = '';

        if ($limit !== null) {
            $clause .= ' LIMIT ' . $limit;
        }

        if ($offset !== null) {
            $clause .= ' OFFSET ' . $offset;
        }

        return $clause;
    }
}
