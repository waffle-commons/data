<?php

declare(strict_types=1);

namespace WaffleTests\Commons\Data\Query;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Waffle\Commons\Data\Query\Operator;
use WaffleTests\Commons\Data\AbstractTestCase;

#[CoversClass(Operator::class)]
final class OperatorTest extends AbstractTestCase
{
    public function testEqualMapsToSqlToken(): void
    {
        self::assertSame('=', Operator::Equal->value);
        self::assertSame('NOT IN', Operator::NotIn->value);
    }

    /**
     * @return iterable<string, array{Operator, bool}>
     */
    public static function operatorProvider(): iterable
    {
        yield 'IN is a set operator' => [Operator::In, true];
        yield 'NOT IN is a set operator' => [Operator::NotIn, true];
        yield 'equal is scalar' => [Operator::Equal, false];
        yield 'greater-than is scalar' => [Operator::GreaterThan, false];
        yield 'like is scalar' => [Operator::Like, false];
    }

    #[DataProvider('operatorProvider')]
    public function testIsSetOperator(Operator $operator, bool $expected): void
    {
        self::assertSame($expected, $operator->isSetOperator());
    }
}
