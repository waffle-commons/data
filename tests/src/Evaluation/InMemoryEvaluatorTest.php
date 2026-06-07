<?php

declare(strict_types=1);

namespace WaffleTests\Commons\Data\Evaluation;

use PHPUnit\Framework\Attributes\CoversClass;
use Waffle\Commons\Contracts\Data\Enum\Direction;
use Waffle\Commons\Contracts\Data\Enum\Operator;
use Waffle\Commons\Data\Evaluation\InMemoryEvaluator;
use Waffle\Commons\Data\Query\Comparison;
use Waffle\Commons\Data\Query\Criteria;
use Waffle\Commons\Data\Query\Query;
use WaffleTests\Commons\Data\AbstractTestCase;

use function array_column;
use function array_values;

#[CoversClass(InMemoryEvaluator::class)]
final class InMemoryEvaluatorTest extends AbstractTestCase
{
    /**
     * @return list<array<string, int|float|string|bool|null>>
     */
    private static function people(): array
    {
        return [
            ['id' => 1, 'name' => 'alice', 'age' => 30],
            ['id' => 2, 'name' => 'bob', 'age' => 25],
            ['id' => 3, 'name' => 'carol', 'age' => 35],
        ];
    }

    /**
     * @param list<array<string, int|float|string|bool|null>> $rows
     *
     * @return list<int|float|string|bool|null>
     */
    private static function ids(array $rows): array
    {
        return array_values(array_column($rows, 'id'));
    }

    public function testEqualityFiltersStrictly(): void
    {
        $result = new InMemoryEvaluator()->evaluate(
            Query::select()->where(Criteria::eq('name', 'bob')),
            self::people(),
        );

        self::assertSame([2], self::ids($result));
    }

    public function testStrictEqualityRejectsTypeMismatch(): void
    {
        // A string '30' must not match the integer column 30 (strict comparison).
        $result = new InMemoryEvaluator()->evaluate(Query::select()->where(Criteria::eq('age', '30')), self::people());

        self::assertSame([], self::ids($result));
    }

    public function testNotEqualFilters(): void
    {
        $result = new InMemoryEvaluator()->evaluate(
            Query::select()->where(Criteria::neq('name', 'bob')),
            self::people(),
        );

        self::assertSame([1, 3], self::ids($result));
    }

    public function testRangeOperatorsFilter(): void
    {
        $evaluator = new InMemoryEvaluator();

        self::assertSame(
            [1, 3],
            self::ids($evaluator->evaluate(Query::select()->where(Criteria::gt('age', 26)), self::people())),
        );
        self::assertSame(
            [1, 2, 3],
            self::ids($evaluator->evaluate(Query::select()->where(Criteria::gte('age', 25)), self::people())),
        );
        self::assertSame(
            [2],
            self::ids($evaluator->evaluate(Query::select()->where(Criteria::lt('age', 30)), self::people())),
        );
        self::assertSame(
            [1, 2],
            self::ids($evaluator->evaluate(Query::select()->where(Criteria::lte('age', 30)), self::people())),
        );
    }

    public function testSetMembershipFilters(): void
    {
        $evaluator = new InMemoryEvaluator();

        self::assertSame(
            [1, 3],
            self::ids($evaluator->evaluate(Query::select()->where(Criteria::in('id', [1, 3])), self::people())),
        );
        self::assertSame(
            [2, 3],
            self::ids($evaluator->evaluate(Query::select()->where(Criteria::notIn('id', [1])), self::people())),
        );
    }

    public function testLikeMatchesWildcards(): void
    {
        $evaluator = new InMemoryEvaluator();

        // % matches any run; a leading 'a' selects alice.
        self::assertSame(
            [1],
            self::ids($evaluator->evaluate(Query::select()->where(Criteria::like('name', 'a%')), self::people())),
        );
        // _ matches exactly one character; '___' selects the 3-letter name bob.
        self::assertSame(
            [2],
            self::ids($evaluator->evaluate(Query::select()->where(Criteria::like('name', '___')), self::people())),
        );
    }

    public function testLikeOnNonStringValueNeverMatches(): void
    {
        $result = new InMemoryEvaluator()->evaluate(
            Query::select()->where(Criteria::like('age', '3%')),
            self::people(),
        );

        self::assertSame([], self::ids($result));
    }

    public function testLikeWithNonStringPatternNeverMatches(): void
    {
        // A hand-built non-string LIKE pattern cannot match any value.
        $query = Query::select()->where(new Comparison('name', Operator::Like, [42]));

        self::assertSame([], self::ids(new InMemoryEvaluator()->evaluate($query, self::people())));
    }

    public function testMissingFieldIsTreatedAsNull(): void
    {
        $result = new InMemoryEvaluator()->evaluate(
            Query::select()->where(Criteria::eq('absent', null)),
            self::people(),
        );

        // Every row lacks the field, so each reads as null and matches null.
        self::assertSame([1, 2, 3], self::ids($result));
    }

    public function testMultipleCriteriaAreAndCombined(): void
    {
        $query = Query::select()->where(Criteria::gt('age', 26), Criteria::like('name', '%a%'));

        $result = new InMemoryEvaluator()->evaluate($query, self::people());

        // alice (30, contains 'a') and carol (35, contains 'a'); bob excluded by age.
        self::assertSame([1, 3], self::ids($result));
    }

    public function testSortAscendingAndDescending(): void
    {
        $evaluator = new InMemoryEvaluator();

        self::assertSame([2, 1, 3], self::ids($evaluator->evaluate(Query::select()->orderBy('age'), self::people())));
        self::assertSame(
            [3, 1, 2],
            self::ids($evaluator->evaluate(Query::select()->orderBy('age', Direction::Descending), self::people())),
        );
    }

    public function testMultiKeySortBreaksTiesOnSecondField(): void
    {
        $rows = [
            ['id' => 1, 'team' => 'a', 'score' => 10],
            ['id' => 2, 'team' => 'a', 'score' => 20],
            ['id' => 3, 'team' => 'b', 'score' => 20],
        ];

        $query = Query::select()->orderBy('score', Direction::Descending)->orderBy('team');

        // score desc puts the two 20s first; the tie breaks on team asc (a before b).
        self::assertSame([2, 3, 1], self::ids(new InMemoryEvaluator()->evaluate($query, $rows)));
    }

    public function testPaginationAppliesOffsetThenLimit(): void
    {
        $evaluator = new InMemoryEvaluator();

        self::assertSame([1, 2], self::ids($evaluator->evaluate(Query::select()->limit(2), self::people())));
        self::assertSame([2, 3], self::ids($evaluator->evaluate(Query::select()->offset(1), self::people())));
        self::assertSame([2], self::ids($evaluator->evaluate(Query::select()->offset(1)->limit(1), self::people())));
    }

    public function testEmptyQueryReturnsEveryRowUnchanged(): void
    {
        $result = new InMemoryEvaluator()->evaluate(Query::select(), self::people());

        self::assertSame(self::people(), $result);
    }

    public function testRangePredicateNeverMatchesNull(): void
    {
        // SQL semantics: NULL > x is unknown, so the null row is excluded.
        $rows = [
            ['id' => 1, 'score' => null],
            ['id' => 2, 'score' => 5],
        ];

        $result = new InMemoryEvaluator()->evaluate(Query::select()->where(Criteria::gt('score', 1)), $rows);

        self::assertSame([2], self::ids($result));
    }

    public function testNullsSortFirstDeterministically(): void
    {
        $rows = [
            ['id' => 1, 'v' => 2],
            ['id' => 2, 'v' => null],
            ['id' => 3, 'v' => 1],
        ];

        $result = new InMemoryEvaluator()->evaluate(Query::select()->orderBy('v'), $rows);

        self::assertSame([2, 3, 1], self::ids($result));
    }
}
