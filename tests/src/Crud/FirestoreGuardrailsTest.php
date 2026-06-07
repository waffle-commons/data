<?php

declare(strict_types=1);

namespace WaffleTests\Commons\Data\Crud;

use PHPUnit\Framework\Attributes\CoversClass;
use Waffle\Commons\Data\Compiler\FirestoreCompiler;
use Waffle\Commons\Data\Compiler\FirestoreScope;
use Waffle\Commons\Data\Exception\SecurityPathViolationException;
use Waffle\Commons\Data\Exception\UnauthenticatedAccessException;
use Waffle\Commons\Data\Query\Criteria;
use Waffle\Commons\Data\Query\Query;
use Waffle\Commons\Data\Repository\FirestoreRepository;
use WaffleTests\Commons\Data\AbstractTestCase;
use WaffleTests\Commons\Data\Fixture\BlankCollectionMapper;
use WaffleTests\Commons\Data\Fixture\FakeFirestoreClient;
use WaffleTests\Commons\Data\Fixture\FakeSecurityContext;
use WaffleTests\Commons\Data\Fixture\PersonMapper;
use WaffleTests\Commons\Data\Fixture\PersonRow;

/**
 * The three Firestore guardrails (RFC-022 §4.2).
 *
 * @covers \Waffle\Commons\Data\Repository\FirestoreRepository
 */
#[CoversClass(FirestoreRepository::class)]
#[CoversClass(FirestoreScope::class)]
#[CoversClass(FirestoreCompiler::class)]
#[CoversClass(SecurityPathViolationException::class)]
#[CoversClass(UnauthenticatedAccessException::class)]
final class FirestoreGuardrailsTest extends AbstractTestCase
{
    // --- Rule 1: strict path boundaries -------------------------------------

    public function testRootLevelCollectionIsRejectedAsAPathViolation(): void
    {
        // A blank collection (the mapper's target) cannot form an isolated path.
        $this->expectException(SecurityPathViolationException::class);

        FirestoreRepository::forPublic(
            new FakeFirestoreClient(),
            PersonRow::class,
            FakeSecurityContext::authenticatedAs('user-1'),
            new BlankCollectionMapper(),
            'app-1',
        );
    }

    public function testTraversalInTheAppIdIsRejectedAsAPathViolation(): void
    {
        $this->expectException(SecurityPathViolationException::class);

        FirestoreRepository::forPublic(
            new FakeFirestoreClient(),
            PersonRow::class,
            FakeSecurityContext::authenticatedAs('user-1'),
            new PersonMapper(),
            '../secret',
        );
    }

    public function testMalformedPrivateScopeIsRejectedAsAPathViolation(): void
    {
        // Authenticated, but a traversal-laden appId cannot form an isolated path.
        $this->expectException(SecurityPathViolationException::class);

        FirestoreRepository::forPrivate(
            new FakeFirestoreClient(),
            PersonRow::class,
            FakeSecurityContext::authenticatedAs('user-1'),
            new PersonMapper(),
            'bad/app',
        );
    }

    public function testPrivateScopeIsolatesToTheAuthenticatedUser(): void
    {
        $client = new FakeFirestoreClient();
        $repository = FirestoreRepository::forPrivate(
            $client,
            PersonRow::class,
            FakeSecurityContext::authenticatedAs('user-42'),
            new PersonMapper(),
            'app-1',
        );

        $repository->save(new PersonRow(1, 'ada', 9.5));

        // The document landed under the user's private path, nobody else's.
        self::assertNotNull($client->getDocument('artifacts/app-1/users/user-42/people', '1'));
    }

    // --- Rule 3: authentication gate ----------------------------------------

    public function testPrivateScopeConstructionRequiresAuthentication(): void
    {
        $this->expectException(UnauthenticatedAccessException::class);

        FirestoreRepository::forPrivate(
            new FakeFirestoreClient(),
            PersonRow::class,
            FakeSecurityContext::anonymous(),
            new PersonMapper(),
            'app-1',
        );
    }

    public function testEveryOperationIsGatedOnAuthentication(): void
    {
        $repository = FirestoreRepository::forPublic(
            new FakeFirestoreClient(),
            PersonRow::class,
            FakeSecurityContext::anonymous(),
            new PersonMapper(),
            'app-1',
        );

        $this->expectException(UnauthenticatedAccessException::class);
        $repository->findById(1);
    }

    public function testAnonymousWriteIsRejected(): void
    {
        $repository = FirestoreRepository::forPublic(
            new FakeFirestoreClient(),
            PersonRow::class,
            FakeSecurityContext::anonymous(),
            new PersonMapper(),
            'app-1',
        );

        $this->expectException(UnauthenticatedAccessException::class);
        $repository->save(new PersonRow(1, 'ada'));
    }

    // --- Rule 2: no complex server-side queries -----------------------------

    public function testComplexCriteriaAreResolvedInMemoryNotPushedToTheDriver(): void
    {
        $client = new FakeFirestoreClient();
        $repository = FirestoreRepository::forPublic(
            $client,
            PersonRow::class,
            FakeSecurityContext::authenticatedAs('user-1'),
            new PersonMapper(),
            'app-1',
        );

        $repository->save(new PersonRow(1, 'ada', 5.0));
        $repository->save(new PersonRow(2, 'bob', 9.0));
        $repository->save(new PersonRow(3, 'cleo', 7.0));

        // A range predicate + ordering: Firestore must NOT receive them.
        $results = $repository->find(
            Query::select()->from('people')->where(Criteria::gt('score', 6.0))->orderBy('score'),
        );

        self::assertSame(['cleo', 'bob'], array_map(static fn(PersonRow $p): string => $p->name, $results));

        // The last query that reached the driver carried NO filters and NO limit
        // — the range/sort were applied in memory afterwards.
        $lastQuery = $client->lastQuery();
        self::assertSame([], $lastQuery['filters'], 'No predicate may reach the Firestore driver here.');
        self::assertNull($lastQuery['limit'], 'No server limit when an in-memory filter is required.');
    }

    public function testEqualityOnlyQueryIsPushedToTheDriver(): void
    {
        $client = new FakeFirestoreClient();
        $repository = FirestoreRepository::forPublic(
            $client,
            PersonRow::class,
            FakeSecurityContext::authenticatedAs('user-1'),
            new PersonMapper(),
            'app-1',
        );
        $repository->save(new PersonRow(1, 'ada', 5.0));
        $repository->save(new PersonRow(2, 'bob', 9.0));

        $results = $repository->find(Query::select()->from('people')->where(Criteria::eq('name', 'bob')));

        self::assertCount(1, $results);
        self::assertSame([['field' => 'name', 'op' => 'EQUAL', 'value' => 'bob']], $client->lastQuery()['filters']);
    }
}
