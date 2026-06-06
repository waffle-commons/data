<?php

declare(strict_types=1);

namespace WaffleTests\Commons\Data\Compiler;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Waffle\Commons\Contracts\Data\Enum\Direction;
use Waffle\Commons\Contracts\Data\Enum\Operator;
use Waffle\Commons\Data\Compiler\CassandraCompiler;
use Waffle\Commons\Data\Compiler\CompiledCassandraQuery;
use Waffle\Commons\Data\Query\Comparison;
use Waffle\Commons\Data\Query\Criteria;
use Waffle\Commons\Data\Query\Query;
use WaffleTests\Commons\Data\AbstractTestCase;

#[CoversClass(CassandraCompiler::class)]
#[CoversClass(CompiledCassandraQuery::class)]
final class CassandraCompilerTest extends AbstractTestCase
{
    public function testCompilesProjectionEqualityAndFlagsFiltering(): void
    {
        $query = Query::select('id', 'email')->from('users')->where(Criteria::eq('status', 'active'));

        $compiled = new CassandraCompiler()->compile($query);

        self::assertSame('SELECT "id", "email" FROM "users" WHERE "status" = ?', $compiled->cql);
        self::assertSame(['active'], $compiled->parameters);
        self::assertTrue($compiled->requiresAllowFiltering);
    }

    public function testEmptyProjectionSelectsStarAndNoWhereClearsFilteringFlag(): void
    {
        $compiled = new CassandraCompiler()->compile(Query::select()->from('users'));

        self::assertSame('SELECT * FROM "users"', $compiled->cql);
        self::assertSame([], $compiled->parameters);
        self::assertFalse($compiled->requiresAllowFiltering);
    }

    /**
     * @return iterable<string, array{Comparison, string, list<int|float|string|bool|null>}>
     */
    public static function operatorProvider(): iterable
    {
        yield 'equal' => [Criteria::eq('a', 1), 'SELECT * FROM "t" WHERE "a" = ?', [1]];
        yield 'greater than' => [Criteria::gt('a', 1), 'SELECT * FROM "t" WHERE "a" > ?', [1]];
        yield 'greater or equal' => [Criteria::gte('a', 1), 'SELECT * FROM "t" WHERE "a" >= ?', [1]];
        yield 'less than' => [Criteria::lt('a', 1), 'SELECT * FROM "t" WHERE "a" < ?', [1]];
        yield 'less or equal' => [Criteria::lte('a', 1), 'SELECT * FROM "t" WHERE "a" <= ?', [1]];
        yield 'in set' => [Criteria::in('a', ['x', 'y']), 'SELECT * FROM "t" WHERE "a" IN (?, ?)', ['x', 'y']];
    }

    /**
     * @param list<int|float|string|bool|null> $parameters
     */
    #[DataProvider('operatorProvider')]
    public function testSupportedOperatorsCompile(Comparison $comparison, string $expectedCql, array $parameters): void
    {
        $compiled = new CassandraCompiler()->compile(Query::select()->from('t')->where($comparison));

        self::assertSame($expectedCql, $compiled->cql);
        self::assertSame($parameters, $compiled->parameters);
    }

    public function testOrderingAndLimitAreRendered(): void
    {
        $query = Query::select()->from('events')->orderBy('ts', Direction::Descending)->orderBy('id')->limit(50);

        $compiled = new CassandraCompiler()->compile($query);

        self::assertSame('SELECT * FROM "events" ORDER BY "ts" DESC, "id" ASC LIMIT 50', $compiled->cql);
    }

    public function testDottedIdentifierIsQuotedPerSegment(): void
    {
        $compiled = new CassandraCompiler()->compile(Query::select('u.id')->from('ks.users'));

        self::assertSame('SELECT "u"."id" FROM "ks"."users"', $compiled->cql);
    }

    /**
     * @return iterable<string, array{Comparison}>
     */
    public static function unsupportedOperatorProvider(): iterable
    {
        yield 'not equal' => [Criteria::neq('a', 1)];
        yield 'not in' => [Criteria::notIn('a', ['x'])];
        yield 'like' => [Criteria::like('a', 'x%')];
    }

    #[DataProvider('unsupportedOperatorProvider')]
    public function testCqlIncompatibleOperatorsAreRejected(Comparison $comparison): void
    {
        $this->expectException(InvalidArgumentException::class);

        new CassandraCompiler()->compile(Query::select()->from('t')->where($comparison));
    }

    public function testOffsetPaginationIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new CassandraCompiler()->compile(Query::select()->from('t')->offset(10));
    }

    public function testMissingSourceTableIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new CassandraCompiler()->compile(Query::select('id'));
    }

    public function testEmptySetPredicateIsRejected(): void
    {
        $query = Query::select()
            ->from('t')
            ->where(new Comparison('role', Operator::In, []));

        $this->expectException(InvalidArgumentException::class);

        new CassandraCompiler()->compile($query);
    }

    public function testInjectionPayloadIsParameterisedNotInterpolated(): void
    {
        $payload = "x'; DROP TABLE users; --";
        $compiled = new CassandraCompiler()->compile(
            Query::select()->from('users')->where(Criteria::eq('name', $payload)),
        );

        self::assertSame('SELECT * FROM "users" WHERE "name" = ?', $compiled->cql);
        self::assertSame([$payload], $compiled->parameters);
    }

    public function testMaliciousIdentifierQuoteIsEscaped(): void
    {
        $compiled = new CassandraCompiler()->compile(Query::select('a"b')->from('t'));

        self::assertSame('SELECT "a""b" FROM "t"', $compiled->cql);
    }
}
