<?php

declare(strict_types=1);

namespace WaffleTests\Commons\Data\Compiler;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Waffle\Commons\Contracts\Data\Enum\Direction;
use Waffle\Commons\Contracts\Data\Enum\Operator;
use Waffle\Commons\Data\Compiler\CompiledQuery;
use Waffle\Commons\Data\Compiler\SQLCompiler;
use Waffle\Commons\Data\Compiler\SQLDialect;
use Waffle\Commons\Data\Query\Comparison;
use Waffle\Commons\Data\Query\Criteria;
use Waffle\Commons\Data\Query\Query;
use WaffleTests\Commons\Data\AbstractTestCase;

use function str_replace;

#[CoversClass(SQLCompiler::class)]
#[CoversClass(SQLDialect::class)]
#[CoversClass(CompiledQuery::class)]
final class SQLCompilerTest extends AbstractTestCase
{
    public function testCompilesProjectionAndEqualityFilter(): void
    {
        $query = Query::select('id', 'email')->from('users')->where(Criteria::eq('status', 'active'));

        $compiled = new SQLCompiler()->compile($query);

        self::assertSame('SELECT `id`, `email` FROM `users` WHERE `status` = ?', $compiled->sql);
        self::assertSame(['active'], $compiled->parameters);
    }

    public function testEmptyProjectionSelectsStar(): void
    {
        $compiled = new SQLCompiler()->compile(Query::select()->from('users'));

        self::assertSame('SELECT * FROM `users`', $compiled->sql);
        self::assertSame([], $compiled->parameters);
    }

    public function testMultiplePredicatesAreAndCombined(): void
    {
        $query = Query::select()
            ->from('users')
            ->where(Criteria::eq('a', 1), Criteria::gt('b', 2))
            ->where(Criteria::in('role', ['x', 'y']));

        $compiled = new SQLCompiler()->compile($query);

        self::assertSame('SELECT * FROM `users` WHERE `a` = ? AND `b` > ? AND `role` IN (?, ?)', $compiled->sql);
        self::assertSame([1, 2, 'x', 'y'], $compiled->parameters);
    }

    public function testOrderingIsRendered(): void
    {
        $query = Query::select()->from('users')->orderBy('created_at', Direction::Descending)->orderBy('id');

        $compiled = new SQLCompiler()->compile($query);

        self::assertSame('SELECT * FROM `users` ORDER BY `created_at` DESC, `id` ASC', $compiled->sql);
    }

    public function testDottedIdentifierIsQuotedPerSegment(): void
    {
        $compiled = new SQLCompiler()->compile(Query::select('u.id')->from('app.users'));

        self::assertSame('SELECT `u`.`id` FROM `app`.`users`', $compiled->sql);
    }

    public function testMissingSourceTableIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new SQLCompiler()->compile(Query::select('id'));
    }

    public function testEmptySetPredicateIsRejected(): void
    {
        // Bypass Criteria's own guard to exercise the compiler's defensive check.
        $query = Query::select()
            ->from('t')
            ->where(new Comparison('role', Operator::In, []));

        $this->expectException(InvalidArgumentException::class);

        new SQLCompiler()->compile($query);
    }

    /**
     * @return iterable<string, array{SQLDialect, string}>
     */
    public static function quotingProvider(): iterable
    {
        yield 'mysql backticks' => [SQLDialect::MySQL, 'SELECT `id` FROM `users`'];
        yield 'mariadb backticks' => [SQLDialect::MariaDB, 'SELECT `id` FROM `users`'];
        yield 'sqlite double-quotes' => [SQLDialect::SQLite, 'SELECT "id" FROM "users"'];
        yield 'mssql brackets' => [SQLDialect::MSSQL, 'SELECT [id] FROM [users]'];
        yield 'postgresql double-quotes' => [SQLDialect::PostgreSQL, 'SELECT "id" FROM "users"'];
        yield 'oracle double-quotes' => [SQLDialect::Oracle, 'SELECT "id" FROM "users"'];
    }

    #[DataProvider('quotingProvider')]
    public function testDialectQuoting(SQLDialect $dialect, string $expected): void
    {
        $compiled = new SQLCompiler($dialect)->compile(Query::select('id')->from('users'));

        self::assertSame($expected, $compiled->sql);
    }

    /**
     * @return iterable<string, array{SQLDialect, int|null, int|null, string}>
     */
    public static function paginationProvider(): iterable
    {
        yield 'mysql limit+offset' => [SQLDialect::MySQL, 10, 20, ' LIMIT 10 OFFSET 20'];
        yield 'mysql limit only' => [SQLDialect::MySQL, 10, null, ' LIMIT 10'];
        yield 'mysql offset only' => [SQLDialect::MySQL, null, 5, ' LIMIT 18446744073709551615 OFFSET 5'];
        yield 'mariadb offset only' => [SQLDialect::MariaDB, null, 5, ' LIMIT 18446744073709551615 OFFSET 5'];
        yield 'sqlite offset only' => [SQLDialect::SQLite, null, 5, ' LIMIT -1 OFFSET 5'];
        yield 'mssql limit+offset' => [SQLDialect::MSSQL, 10, 20, ' OFFSET 20 ROWS FETCH NEXT 10 ROWS ONLY'];
        yield 'mssql offset only' => [SQLDialect::MSSQL, null, 5, ' OFFSET 5 ROWS'];
        yield 'mssql limit only' => [SQLDialect::MSSQL, 10, null, ' OFFSET 0 ROWS FETCH NEXT 10 ROWS ONLY'];
        yield 'postgresql limit+offset' => [SQLDialect::PostgreSQL, 10, 20, ' LIMIT 10 OFFSET 20'];
        yield 'postgresql limit only' => [SQLDialect::PostgreSQL, 10, null, ' LIMIT 10'];
        yield 'postgresql offset only' => [SQLDialect::PostgreSQL, null, 5, ' OFFSET 5'];
        yield 'oracle limit+offset' => [SQLDialect::Oracle, 10, 20, ' OFFSET 20 ROWS FETCH NEXT 10 ROWS ONLY'];
        yield 'oracle offset only' => [SQLDialect::Oracle, null, 5, ' OFFSET 5 ROWS'];
        yield 'oracle limit only' => [SQLDialect::Oracle, 10, null, ' OFFSET 0 ROWS FETCH NEXT 10 ROWS ONLY'];
    }

    #[DataProvider('paginationProvider')]
    public function testPagination(SQLDialect $dialect, ?int $limit, ?int $offset, string $expectedTail): void
    {
        $query = Query::select()->from('t');
        if ($limit !== null) {
            $query = $query->limit($limit);
        }

        if ($offset !== null) {
            $query = $query->offset($offset);
        }

        $compiled = new SQLCompiler($dialect)->compile($query);

        self::assertSame('SELECT * FROM `t`' . $expectedTail, $this->normaliseToMySqlQuoting($compiled->sql, $dialect));
    }

    public function testNoPaginationEmitsNoTail(): void
    {
        $compiled = new SQLCompiler()->compile(Query::select()->from('t'));

        self::assertSame('SELECT * FROM `t`', $compiled->sql);
    }

    public function testInjectionPayloadIsParameterisedNotInterpolated(): void
    {
        $payload = "x'; DROP TABLE users; --";
        $query = Query::select()->from('users')->where(Criteria::eq('name', $payload));

        $compiled = new SQLCompiler()->compile($query);

        self::assertSame('SELECT * FROM `users` WHERE `name` = ?', $compiled->sql);
        self::assertSame([$payload], $compiled->parameters);
        self::assertStringNotContainsString('DROP', $compiled->sql);
    }

    public function testIdentifierWithBacktickIsRejectedOutright(): void
    {
        // FIX-01: a crafted column name can no longer reach the escaper at all —
        // the strict allow-list rejects anything but letters/digits/underscores,
        // so it never gets a chance to try breaking out of its identifier quoting.
        $this->expectException(InvalidArgumentException::class);

        new SQLCompiler()->compile(Query::select('a`b')->from('t'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function hostileIdentifierProvider(): iterable
    {
        yield 'backslash' => ['col\\umn'];
        yield 'trailing newline (PCRE $ anchor hole)' => ["users\n"];
        yield 'newline then payload' => ["users\n-- "];
        yield 'semicolon (statement splitting)' => ['id; DROP TABLE users; --'];
        yield 'double quote' => ['a"b'];
        yield 'bracket' => ['a]b'];
        yield 'space' => ['a b'];
        yield 'leading digit' => ['1id'];
        yield 'NUL byte' => ["col\x00umn"];
        yield 'control character' => ["col\x1Fumn"];
        yield 'empty segment' => [''];
    }

    #[DataProvider('hostileIdentifierProvider')]
    public function testHostileIdentifierIsRejected(string $identifier): void
    {
        // FIX-01: anything outside the ASCII letter/digit/underscore allow-list is
        // rejected at the source, closing the identifier-injection vector rather
        // than relying solely on dialect quote-escaping.
        $this->expectException(InvalidArgumentException::class);

        SQLDialect::MySQL->quoteIdentifier($identifier);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function legitimateIdentifierProvider(): iterable
    {
        yield 'lowercase word' => ['users', '`users`'];
        yield 'leading underscore' => ['_private', '`_private`'];
        yield 'snake_case with digits' => ['user_id_2', '`user_id_2`'];
        yield 'all caps' => ['USERS', '`USERS`'];
        yield 'single letter' => ['a', '`a`'];
    }

    #[DataProvider('legitimateIdentifierProvider')]
    public function testLegitimateIdentifierIsAccepted(string $identifier, string $expected): void
    {
        self::assertSame($expected, SQLDialect::MySQL->quoteIdentifier($identifier));
    }

    public function testLegitimateDottedIdentifierIsAccepted(): void
    {
        self::assertSame('`schema_1`.`table_2`', SQLDialect::MySQL->quoteIdentifier('schema_1.table_2'));
    }

    /**
     * The pagination provider asserts dialect-specific tails; the table/column
     * quoting differs per dialect, so re-quote non-MySQL identifiers back to the
     * MySQL form for a single comparison string.
     */
    private function normaliseToMySqlQuoting(string $sql, SQLDialect $dialect): string
    {
        return match ($dialect) {
            SQLDialect::MySQL, SQLDialect::MariaDB => $sql,
            SQLDialect::SQLite, SQLDialect::PostgreSQL, SQLDialect::Oracle => str_replace('"', '`', $sql),
            SQLDialect::MSSQL => str_replace(['[', ']'], '`', $sql),
        };
    }
}
