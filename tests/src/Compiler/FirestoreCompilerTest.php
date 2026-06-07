<?php

declare(strict_types=1);

namespace WaffleTests\Commons\Data\Compiler;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Waffle\Commons\Data\Compiler\CompiledFirestoreQuery;
use Waffle\Commons\Data\Compiler\FirestoreCompiler;
use Waffle\Commons\Data\Compiler\FirestoreScope;
use Waffle\Commons\Data\Query\Criteria;
use Waffle\Commons\Data\Query\Query;
use WaffleTests\Commons\Data\AbstractTestCase;

use function json_encode;

use const JSON_THROW_ON_ERROR;

#[CoversClass(FirestoreCompiler::class)]
#[CoversClass(FirestoreScope::class)]
#[CoversClass(CompiledFirestoreQuery::class)]
final class FirestoreCompilerTest extends AbstractTestCase
{
    public function testPublicScopeBuildsIsolatedPath(): void
    {
        $scope = FirestoreScope::public('app-42', 'articles');

        self::assertSame('artifacts/app-42/public/data/articles', $scope->path);
    }

    public function testPrivateScopeBuildsUserIsolatedPath(): void
    {
        $scope = FirestoreScope::private('app-42', 'user-7', 'notes');

        self::assertSame('artifacts/app-42/users/user-7/notes', $scope->path);
    }

    public function testEqualityFilterIsPushedToServer(): void
    {
        $query = Query::select()->where(Criteria::eq('status', 'published'));

        $compiled = new FirestoreCompiler()->compile($query, FirestoreScope::public('app', 'posts'));

        self::assertSame([['field' => 'status', 'op' => 'EQUAL', 'value' => 'published']], $compiled->filters);
        self::assertFalse($compiled->requiresInMemoryFilter);
    }

    public function testRangePredicateForcesInMemoryFiltering(): void
    {
        $query = Query::select()->where(Criteria::eq('status', 'published'), Criteria::gt('views', 100));

        $compiled = new FirestoreCompiler()->compile($query, FirestoreScope::public('app', 'posts'));

        // Only the equality is server-side; the range predicate is deferred.
        self::assertSame([['field' => 'status', 'op' => 'EQUAL', 'value' => 'published']], $compiled->filters);
        self::assertTrue($compiled->requiresInMemoryFilter);
    }

    public function testOrderingForcesInMemoryFiltering(): void
    {
        $query = Query::select()->orderBy('views');

        $compiled = new FirestoreCompiler()->compile($query, FirestoreScope::public('app', 'posts'));

        self::assertSame([['field' => 'views', 'direction' => 'ASC']], $compiled->orderings);
        self::assertTrue($compiled->requiresInMemoryFilter);
    }

    public function testLimitIsCarriedAndSourceIsIgnored(): void
    {
        // The query's own from() is irrelevant: the scope fixes the collection.
        $query = Query::select()->from('ignored')->limit(5);

        $compiled = new FirestoreCompiler()->compile($query, FirestoreScope::public('app', 'posts'));

        self::assertSame(5, $compiled->limit);
        self::assertSame('artifacts/app/public/data/posts', $compiled->path);
    }

    public function testToJsonRendersStructuredPayload(): void
    {
        $query = Query::select()->where(Criteria::eq('status', 'published'))->limit(3);

        $compiled = new FirestoreCompiler()->compile($query, FirestoreScope::public('app', 'posts'));

        // Compare on the JSON string itself (both sides typed as string), avoiding
        // a mixed-typed decode while still asserting the full structured payload.
        $expected = json_encode([
            'path' => 'artifacts/app/public/data/posts',
            'filters' => [['field' => 'status', 'op' => 'EQUAL', 'value' => 'published']],
            'orderings' => [],
            'limit' => 3,
        ], JSON_THROW_ON_ERROR);

        self::assertSame($expected, $compiled->toJson());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function illegalSegmentProvider(): iterable
    {
        yield 'path traversal' => ['../etc'];
        yield 'embedded slash' => ['a/b'];
        yield 'whitespace' => ['x y'];
        yield 'blank' => [''];
        yield 'only whitespace' => ['   '];
    }

    #[DataProvider('illegalSegmentProvider')]
    public function testPublicScopeRejectsIllegalCollection(string $collection): void
    {
        $this->expectException(InvalidArgumentException::class);

        FirestoreScope::public('app', $collection);
    }

    #[DataProvider('illegalSegmentProvider')]
    public function testPrivateScopeRejectsIllegalUserId(string $userId): void
    {
        $this->expectException(InvalidArgumentException::class);

        FirestoreScope::private('app', $userId, 'notes');
    }

    public function testPublicScopeRejectsIllegalAppId(): void
    {
        $this->expectException(InvalidArgumentException::class);

        FirestoreScope::public('bad id', 'posts');
    }
}
