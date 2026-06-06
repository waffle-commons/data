<?php

declare(strict_types=1);

namespace WaffleTests\Commons\Data\Compiler;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Waffle\Commons\Contracts\Data\Enum\Direction;
use Waffle\Commons\Contracts\Data\Enum\Operator;
use Waffle\Commons\Data\Compiler\CompiledMongoQuery;
use Waffle\Commons\Data\Compiler\MongoCompiler;
use Waffle\Commons\Data\Compiler\MongoFindOptions;
use Waffle\Commons\Data\Query\Comparison;
use Waffle\Commons\Data\Query\Criteria;
use Waffle\Commons\Data\Query\Query;
use WaffleTests\Commons\Data\AbstractTestCase;

#[CoversClass(MongoCompiler::class)]
#[CoversClass(CompiledMongoQuery::class)]
#[CoversClass(MongoFindOptions::class)]
final class MongoCompilerTest extends AbstractTestCase
{
    public function testEqualityCompilesToExplicitEqDocument(): void
    {
        $query = Query::select()->from('posts')->where(Criteria::eq('status', 'published'));

        $compiled = new MongoCompiler()->compile($query);

        self::assertSame('posts', $compiled->collection);
        self::assertSame(['status' => ['$eq' => 'published']], $compiled->filter);
    }

    public function testMultiplePredicatesOnSameFieldMergeIntoOneDocument(): void
    {
        $query = Query::select()->from('posts')->where(Criteria::gt('views', 100), Criteria::lt('views', 500));

        $compiled = new MongoCompiler()->compile($query);

        self::assertSame(['views' => ['$gt' => 100, '$lt' => 500]], $compiled->filter);
    }

    /**
     * @return iterable<string, array{Comparison, array<string, array<string, int|float|string|bool|null|list<int|float|string|bool|null>>>}>
     */
    public static function operatorProvider(): iterable
    {
        yield 'not equal' => [Criteria::neq('a', 1), ['a' => ['$ne' => 1]]];
        yield 'greater than' => [Criteria::gt('a', 1), ['a' => ['$gt' => 1]]];
        yield 'greater or equal' => [Criteria::gte('a', 1), ['a' => ['$gte' => 1]]];
        yield 'less than' => [Criteria::lt('a', 1), ['a' => ['$lt' => 1]]];
        yield 'less or equal' => [Criteria::lte('a', 1), ['a' => ['$lte' => 1]]];
        yield 'in set' => [Criteria::in('a', ['x', 'y']), ['a' => ['$in' => ['x', 'y']]]];
        yield 'not in set' => [Criteria::notIn('a', ['x']), ['a' => ['$nin' => ['x']]]];
    }

    /**
     * @param array<string, array<string, int|float|string|bool|null|list<int|float|string|bool|null>>> $expected
     */
    #[DataProvider('operatorProvider')]
    public function testOperatorsMapToMongoQueryOperators(Comparison $comparison, array $expected): void
    {
        $compiled = new MongoCompiler()->compile(Query::select()->from('t')->where($comparison));

        self::assertSame($expected, $compiled->filter);
    }

    public function testLikePatternCompilesToAnchoredRegex(): void
    {
        $compiled = new MongoCompiler()->compile(Query::select()->from('u')->where(Criteria::like('name', 'jo%n_')));

        self::assertSame(['name' => ['$regex' => '^jo.*n.$']], $compiled->filter);
    }

    public function testLikeRegexEscapesMetacharacters(): void
    {
        // A literal dot must be escaped so it cannot act as a regex wildcard.
        $compiled = new MongoCompiler()->compile(Query::select()->from('u')->where(Criteria::like('host', 'a.b%')));

        self::assertSame(['host' => ['$regex' => '^a\.b.*$']], $compiled->filter);
    }

    public function testProjectionSortAndPaginationAreCompiled(): void
    {
        $query = Query::select('id', 'email')
            ->from('users')
            ->orderBy('created_at', Direction::Descending)
            ->orderBy('id')
            ->limit(10)
            ->offset(5);

        $compiled = new MongoCompiler()->compile($query);

        self::assertSame(['id' => 1, 'email' => 1], $compiled->options->projection);
        self::assertSame(['created_at' => -1, 'id' => 1], $compiled->options->sort);
        self::assertSame(10, $compiled->options->limit);
        self::assertSame(5, $compiled->options->skip);
    }

    public function testEmptyQueryYieldsEmptyDocuments(): void
    {
        $compiled = new MongoCompiler()->compile(Query::select()->from('users'));

        self::assertSame([], $compiled->filter);
        self::assertSame([], $compiled->options->projection);
        self::assertSame([], $compiled->options->sort);
        self::assertNull($compiled->options->limit);
        self::assertNull($compiled->options->skip);
    }

    public function testNullEqualityOperandIsPreserved(): void
    {
        // A hand-built null equality compiles to {$eq: null} rather than being dropped.
        $query = Query::select()
            ->from('t')
            ->where(new Comparison('deleted_at', Operator::Equal, [null]));

        $compiled = new MongoCompiler()->compile($query);

        self::assertSame(['deleted_at' => ['$eq' => null]], $compiled->filter);
    }

    public function testMissingCollectionIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new MongoCompiler()->compile(Query::select()->where(Criteria::eq('a', 1)));
    }
}
