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
     * Strict allow-list applied to every identifier segment ALONGSIDE dialect
     * quote-escaping (FIX-01, hardening the earlier HARDEN-03 control): a bare SQL
     * identifier is one or more ASCII letters/digits/underscores that does not
     * start with a digit. The previous NUL/control-character-only deny-list still
     * let punctuation (quotes, backticks, brackets, semicolons, backslashes …)
     * through to {@see quoteSegment()}'s escaping — safe in the common case, but a
     * single missed dialect-quoting edge case turns into identifier injection.
     * Rejecting anything outside the allow-list closes that vector at the source
     * instead of depending solely on escaping.
     */
    private const string IDENTIFIER_PATTERN = '/^[a-zA-Z_][a-zA-Z0-9_]*$/';

    /**
     * Quote a (possibly dotted) identifier. Each segment is validated against the
     * {@see IDENTIFIER_PATTERN} allow-list — rejecting anything but ASCII
     * letters/digits/underscores — before the dialect's quote character wraps it,
     * so a crafted column name can neither smuggle punctuation/control bytes nor
     * break out of its quoting.
     *
     * @throws InvalidArgumentException When a segment is empty or holds a
     *         character outside the allow-list.
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

    /**
     * @throws InvalidArgumentException When the segment is empty or holds a
     *         character outside the ASCII letter/digit/underscore allow-list.
     */
    private function quoteSegment(string $segment): string
    {
        if (preg_match(self::IDENTIFIER_PATTERN, $segment) !== 1) {
            throw new InvalidArgumentException(
                'Invalid SQL identifier: a segment must start with an ASCII letter or underscore and '
                . 'contain only ASCII letters, digits, and underscores.',
            );
        }

        return match ($this) {
            // The allow-list above already excludes backslash and backtick, so
            // neither can reach here — the escaping stays as defence-in-depth
            // (FIX-01) should the allow-list ever be relaxed, and mirrors how
            // MySQL/MariaDB treat a backslash inside a quoted identifier.
            self::MySQL, self::MariaDB => '`' . str_replace(['\\', '`'], ['\\\\', '``'], $segment) . '`',
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
