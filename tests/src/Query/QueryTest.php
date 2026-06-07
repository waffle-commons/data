<?php

declare(strict_types=1);

namespace WaffleTests\Commons\Data\Query;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use Waffle\Commons\Contracts\Data\Enum\Direction;
use Waffle\Commons\Data\Query\Criteria;
use Waffle\Commons\Data\Query\Order;
use Waffle\Commons\Data\Query\Query;
use WaffleTests\Commons\Data\AbstractTestCase;

#[CoversClass(Query::class)]
#[CoversClass(Order::class)]
final class QueryTest extends AbstractTestCase
{
    public function testSelectWithoutFieldsProjectsEverything(): void
    {
        $query = Query::select();

        self::assertSame([], $query->fields);
        self::assertNull($query->from);
        self::assertSame([], $query->criteria);
        self::assertSame([], $query->orderings);
        self::assertNull($query->limit);
        self::assertNull($query->offset);
    }

    public function testSelectCapturesProjection(): void
    {
        self::assertSame(['id', 'email'], Query::select('id', 'email')->fields);
    }

    public function testBuilderIsImmutableCopyOnWrite(): void
    {
        $base = Query::select('id');
        $derived = $base->from('users')->limit(5);

        // The original instance is never mutated by a derived builder call.
        self::assertNull($base->from);
        self::assertNull($base->limit);
        self::assertNotSame($base, $derived);
        self::assertSame('users', $derived->from);
        self::assertSame(5, $derived->limit);
    }

    public function testWhereAppendsPredicates(): void
    {
        $query = Query::select()->where(Criteria::eq('a', 1))->where(Criteria::gt('b', 2), Criteria::lt('c', 3));

        self::assertCount(3, $query->criteria);
    }

    public function testOrderByAppendsOrderNodes(): void
    {
        $query = Query::select()->orderBy('a')->orderBy('b', Direction::Descending);

        self::assertEquals(
            [new Order('a', Direction::Ascending), new Order('b', Direction::Descending)],
            $query->orderings,
        );
    }

    public function testLimitAndOffsetAreStored(): void
    {
        $query = Query::select()->limit(10)->offset(20);

        self::assertSame(10, $query->limit);
        self::assertSame(20, $query->offset);
    }

    public function testZeroLimitIsAllowed(): void
    {
        self::assertSame(0, Query::select()->limit(0)->limit);
    }

    public function testNegativeLimitIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Query::select()->limit(-1);
    }

    public function testNegativeOffsetIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Query::select()->offset(-1);
    }
}
