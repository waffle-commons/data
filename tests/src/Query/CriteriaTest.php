<?php

declare(strict_types=1);

namespace WaffleTests\Commons\Data\Query;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use Waffle\Commons\Data\Query\Comparison;
use Waffle\Commons\Data\Query\Criteria;
use Waffle\Commons\Data\Query\Operator;
use WaffleTests\Commons\Data\AbstractTestCase;

#[CoversClass(Criteria::class)]
#[CoversClass(Comparison::class)]
final class CriteriaTest extends AbstractTestCase
{
    public function testEqualBuildsSingleValuePredicate(): void
    {
        $comparison = Criteria::eq('status', 'active');

        self::assertSame('status', $comparison->field);
        self::assertSame(Operator::Equal, $comparison->operator);
        self::assertSame(['active'], $comparison->values);
    }

    public function testScalarComparisons(): void
    {
        self::assertSame(Operator::NotEqual, Criteria::neq('a', 1)->operator);
        self::assertSame(Operator::GreaterThan, Criteria::gt('a', 1)->operator);
        self::assertSame(Operator::GreaterThanOrEqual, Criteria::gte('a', 1)->operator);
        self::assertSame(Operator::LessThan, Criteria::lt('a', 1)->operator);
        self::assertSame(Operator::LessThanOrEqual, Criteria::lte('a', 1)->operator);
        self::assertSame(Operator::Like, Criteria::like('a', '%x%')->operator);
    }

    public function testInPreservesValueList(): void
    {
        $comparison = Criteria::in('role', ['admin', 'editor']);

        self::assertSame(Operator::In, $comparison->operator);
        self::assertSame(['admin', 'editor'], $comparison->values);
    }

    public function testNotInPreservesValueList(): void
    {
        $comparison = Criteria::notIn('role', [1, 2, 3]);

        self::assertSame(Operator::NotIn, $comparison->operator);
        self::assertSame([1, 2, 3], $comparison->values);
    }

    public function testEqualAcceptsNull(): void
    {
        self::assertSame([null], Criteria::eq('deleted_at', null)->values);
    }

    public function testBlankFieldIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Criteria::eq('   ', 'x');
    }

    public function testEmptySetIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Criteria::in('role', []);
    }

    public function testEmptyNotInSetIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Criteria::notIn('role', []);
    }
}
