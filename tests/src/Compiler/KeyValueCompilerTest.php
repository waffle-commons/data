<?php

declare(strict_types=1);

namespace WaffleTests\Commons\Data\Compiler;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Waffle\Commons\Contracts\Data\Enum\Direction;
use Waffle\Commons\Contracts\Data\Enum\Operator;
use Waffle\Commons\Data\Compiler\CompiledKeyValueCommand;
use Waffle\Commons\Data\Compiler\KeyValueCompiler;
use Waffle\Commons\Data\Compiler\KeyValueOperation;
use Waffle\Commons\Data\Query\Comparison;
use Waffle\Commons\Data\Query\Criteria;
use Waffle\Commons\Data\Query\Query;
use WaffleTests\Commons\Data\AbstractTestCase;

#[CoversClass(KeyValueCompiler::class)]
#[CoversClass(CompiledKeyValueCommand::class)]
#[CoversClass(KeyValueOperation::class)]
final class KeyValueCompilerTest extends AbstractTestCase
{
    public function testEqualityCompilesToNamespacedGet(): void
    {
        $command = new KeyValueCompiler()->compile(Query::select()->from('session')->where(Criteria::eq('id', 'abc')));

        self::assertSame(KeyValueOperation::Get, $command->operation);
        self::assertSame('session', $command->namespace);
        self::assertSame(['session:abc'], $command->keys);
    }

    public function testMembershipCompilesToNamespacedMget(): void
    {
        $query = Query::select()
            ->from('session')
            ->where(Criteria::in('id', ['a', 'b']));

        $command = new KeyValueCompiler()->compile($query);

        self::assertSame(KeyValueOperation::MGet, $command->operation);
        self::assertSame(['session:a', 'session:b'], $command->keys);
    }

    /**
     * @return iterable<string, array{int|float|bool|string, string}>
     */
    public static function scalarKeyProvider(): iterable
    {
        yield 'string' => ['abc', 'cache:abc'];
        yield 'integer' => [42, 'cache:42'];
        yield 'boolean true' => [true, 'cache:1'];
        yield 'boolean false' => [false, 'cache:0'];
    }

    #[DataProvider('scalarKeyProvider')]
    public function testScalarKeysAreStringified(int|float|bool|string $value, string $expected): void
    {
        $command = new KeyValueCompiler()->compile(Query::select()->from('cache')->where(Criteria::eq('id', $value)));

        self::assertSame([$expected], $command->keys);
    }

    public function testEnumExposesCanonicalVerbs(): void
    {
        self::assertSame('GET', KeyValueOperation::Get->value);
        self::assertSame('MGET', KeyValueOperation::MGet->value);
    }

    public function testMissingNamespaceIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new KeyValueCompiler()->compile(Query::select()->where(Criteria::eq('id', 'x')));
    }

    public function testProjectionIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new KeyValueCompiler()->compile(Query::select('value')->from('cache')->where(Criteria::eq('id', 'x')));
    }

    public function testOrderingIsRejected(): void
    {
        $query = Query::select()->from('cache')->where(Criteria::eq('id', 'x'))->orderBy('id', Direction::Descending);

        $this->expectException(InvalidArgumentException::class);

        new KeyValueCompiler()->compile($query);
    }

    public function testLimitIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new KeyValueCompiler()->compile(Query::select()->from('cache')->where(Criteria::eq('id', 'x'))->limit(1));
    }

    public function testOffsetIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new KeyValueCompiler()->compile(Query::select()->from('cache')->where(Criteria::eq('id', 'x'))->offset(1));
    }

    public function testZeroPredicatesIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new KeyValueCompiler()->compile(Query::select()->from('cache'));
    }

    public function testMultiplePredicatesAreRejected(): void
    {
        $query = Query::select()->from('cache')->where(Criteria::eq('id', 'x'), Criteria::eq('tenant', 'y'));

        $this->expectException(InvalidArgumentException::class);

        new KeyValueCompiler()->compile($query);
    }

    public function testUnsupportedOperatorIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new KeyValueCompiler()->compile(Query::select()->from('cache')->where(Criteria::gt('id', 5)));
    }

    public function testNullEqualityKeyIsRejected(): void
    {
        $query = Query::select()
            ->from('cache')
            ->where(new Comparison('id', Operator::Equal, [null]));

        $this->expectException(InvalidArgumentException::class);

        new KeyValueCompiler()->compile($query);
    }

    public function testNullMembershipKeyIsRejected(): void
    {
        $query = Query::select()
            ->from('cache')
            ->where(new Comparison('id', Operator::In, ['a', null]));

        $this->expectException(InvalidArgumentException::class);

        new KeyValueCompiler()->compile($query);
    }

    public function testEmptyMembershipSetIsRejected(): void
    {
        $query = Query::select()
            ->from('cache')
            ->where(new Comparison('id', Operator::In, []));

        $this->expectException(InvalidArgumentException::class);

        new KeyValueCompiler()->compile($query);
    }
}
