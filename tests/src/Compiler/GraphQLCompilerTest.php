<?php

declare(strict_types=1);

namespace WaffleTests\Commons\Data\Compiler;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Waffle\Commons\Data\Compiler\CompiledGraphQLQuery;
use Waffle\Commons\Data\Compiler\GraphQLCompiler;
use Waffle\Commons\Data\Query\Comparison;
use Waffle\Commons\Data\Query\Criteria;
use Waffle\Commons\Data\Query\Query;
use WaffleTests\Commons\Data\AbstractTestCase;

use function json_encode;

use const JSON_THROW_ON_ERROR;

#[CoversClass(GraphQLCompiler::class)]
#[CoversClass(CompiledGraphQLQuery::class)]
final class GraphQLCompilerTest extends AbstractTestCase
{
    public function testCompilesFilterProjectionAndPagination(): void
    {
        $query = Query::select('id', 'title')
            ->from('posts')
            ->where(Criteria::eq('status', 'published'))
            ->limit(10)
            ->offset(5);

        $compiled = new GraphQLCompiler()->compile($query);

        self::assertSame(
            'query ($v0: String!) { posts(where: {status: {_eq: $v0}}, limit: 10, offset: 5) { id title } }',
            $compiled->query,
        );
        self::assertSame(['v0' => 'published'], $compiled->variables);
    }

    public function testMultiplePredicatesDeclareSequentialVariables(): void
    {
        $query = Query::select('x')->from('t')->where(Criteria::gt('age', 18), Criteria::eq('active', true));

        $compiled = new GraphQLCompiler()->compile($query);

        self::assertSame(
            'query ($v0: Int!, $v1: Boolean!) { t(where: {age: {_gt: $v0}, active: {_eq: $v1}}) { x } }',
            $compiled->query,
        );
        self::assertSame(['v0' => 18, 'v1' => true], $compiled->variables);
    }

    /**
     * @return iterable<string, array{Comparison, string}>
     */
    public static function operatorProvider(): iterable
    {
        yield 'not equal' => [Criteria::neq('a', 1), 'query ($v0: Int!) { t(where: {a: {_neq: $v0}}) { x } }'];
        yield 'greater than' => [Criteria::gt('a', 1), 'query ($v0: Int!) { t(where: {a: {_gt: $v0}}) { x } }'];
        yield 'greater or equal' => [Criteria::gte('a', 1), 'query ($v0: Int!) { t(where: {a: {_gte: $v0}}) { x } }'];
        yield 'less than' => [Criteria::lt('a', 1), 'query ($v0: Int!) { t(where: {a: {_lt: $v0}}) { x } }'];
        yield 'less or equal' => [Criteria::lte('a', 1), 'query ($v0: Int!) { t(where: {a: {_lte: $v0}}) { x } }'];
        yield 'in set' => [Criteria::in('a', [1, 2]), 'query ($v0: [Int!]) { t(where: {a: {_in: $v0}}) { x } }'];
        yield 'not in set' => [
            Criteria::notIn('a', ['x']),
            'query ($v0: [String!]) { t(where: {a: {_nin: $v0}}) { x } }',
        ];
        yield 'like' => [Criteria::like('a', 'x%'), 'query ($v0: String!) { t(where: {a: {_like: $v0}}) { x } }'];
    }

    #[DataProvider('operatorProvider')]
    public function testOperatorsMapToConventionalWhereForm(Comparison $comparison, string $expected): void
    {
        $compiled = new GraphQLCompiler()->compile(Query::select('x')->from('t')->where($comparison));

        self::assertSame($expected, $compiled->query);
    }

    /**
     * @return iterable<string, array{int|float|string|bool|null, string}>
     */
    public static function variableTypeProvider(): iterable
    {
        yield 'string' => ['str', '$v0: String!'];
        yield 'integer' => [7, '$v0: Int!'];
        yield 'float' => [1.5, '$v0: Float!'];
        yield 'boolean' => [true, '$v0: Boolean!'];
        yield 'null is nullable' => [null, '$v0: String'];
    }

    #[DataProvider('variableTypeProvider')]
    public function testVariableTypesAreInferredFromOperands(
        int|float|string|bool|null $value,
        string $declaration,
    ): void {
        $compiled = new GraphQLCompiler()->compile(Query::select('x')->from('t')->where(Criteria::eq('f', $value)));

        self::assertStringContainsString($declaration, $compiled->query);
    }

    public function testPaginationArgumentsWithoutFilter(): void
    {
        $compiled = new GraphQLCompiler()->compile(Query::select('x')->from('t')->limit(5));

        self::assertSame('query { t(limit: 5) { x } }', $compiled->query);
        self::assertSame([], $compiled->variables);
    }

    public function testToJsonRendersStandardPostBody(): void
    {
        $compiled = new GraphQLCompiler()->compile(Query::select('id')->from('users')->where(Criteria::eq('age', 30)));

        $expected = json_encode([
            'query' => 'query ($v0: Int!) { users(where: {age: {_eq: $v0}}) { id } }',
            'variables' => ['v0' => 30],
        ], JSON_THROW_ON_ERROR);

        self::assertSame($expected, $compiled->toJson());
    }

    public function testToJsonEncodesEmptyVariablesAsObject(): void
    {
        $compiled = new GraphQLCompiler()->compile(Query::select('id')->from('users'));

        self::assertSame('query { users { id } }', $compiled->query);
        self::assertStringContainsString('"variables":{}', $compiled->toJson());
    }

    public function testMissingRootFieldIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new GraphQLCompiler()->compile(Query::select('id'));
    }

    public function testEmptyProjectionIsRejected(): void
    {
        // GraphQL has no SELECT *: a selection set is mandatory.
        $this->expectException(InvalidArgumentException::class);

        new GraphQLCompiler()->compile(Query::select()->from('users'));
    }

    public function testInvalidRootNameIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new GraphQLCompiler()->compile(Query::select('id')->from('bad root'));
    }

    public function testInvalidProjectionNameIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new GraphQLCompiler()->compile(Query::select('id } evil {')->from('users'));
    }

    public function testInvalidCriteriaFieldNameIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new GraphQLCompiler()->compile(Query::select('id')->from('users')->where(Criteria::eq('bad-name', 1)));
    }
}
