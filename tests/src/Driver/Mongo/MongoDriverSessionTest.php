<?php

declare(strict_types=1);

namespace WaffleTests\Commons\Data\Driver\Mongo;

use MongoDB\Driver\BulkWrite;
use MongoDB\Driver\Command;
use MongoDB\Driver\Exception\Exception as MongoDriverException;
use MongoDB\Driver\Manager;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use Waffle\Commons\Data\Compiler\CompiledMongoQuery;
use Waffle\Commons\Data\Compiler\MongoFindOptions;
use Waffle\Commons\Data\Driver\Mongo\MongoDriverSession;
use Waffle\Commons\Data\Exception\DatabaseException;
use Waffle\Commons\Data\Query\Criteria;
use Waffle\Commons\Data\Query\Query;
use Waffle\Commons\Data\Repository\MongoRepository;
use WaffleTests\Commons\Data\AbstractTestCase;
use WaffleTests\Commons\Data\Fixture\PersonRow;

use function array_map;
use function getenv;
use function is_string;
use function sprintf;

/**
 * End-to-end proof against the sandbox `waffle-mongo` service: real wire
 * protocol, native filter push-down, `_id` exclusion, real hydration. Skipped
 * cleanly when no MongoDB is reachable, so the suite stays self-contained.
 */
#[CoversClass(MongoDriverSession::class)]
#[CoversClass(MongoRepository::class)]
#[RequiresPhpExtension('mongodb')]
final class MongoDriverSessionTest extends AbstractTestCase
{
    private const string DATABASE = 'waffle_it';

    private function manager(): Manager
    {
        $host = getenv('WAFFLE_TEST_MONGO_HOST');
        $uri = sprintf(
            'mongodb://%s:27017/?serverSelectionTimeoutMS=1500&connectTimeoutMS=1500',
            is_string($host) && $host !== '' ? $host : 'waffle-mongo',
        );

        $manager = new Manager($uri);

        try {
            $manager->executeCommand('admin', new Command(['ping' => 1]));
        } catch (MongoDriverException) {
            self::markTestSkipped('No reachable MongoDB instance for the integration test.');
        }

        return $manager;
    }

    /**
     * @throws MongoDriverException
     */
    private function seed(Manager $manager): void
    {
        try {
            $manager->executeCommand(self::DATABASE, new Command(['drop' => 'people']));
        } catch (MongoDriverException) {
            // First run: the collection does not exist yet — nothing to drop.
        }

        $bulk = new BulkWrite();
        $bulk->insert(['id' => 1, 'name' => 'alice', 'score' => 9.5]);
        $bulk->insert(['id' => 2, 'name' => 'bob', 'score' => null]);
        $bulk->insert(['id' => 3, 'name' => 'carol', 'score' => 7.25]);
        $manager->executeBulkWrite(self::DATABASE . '.people', $bulk);
    }

    public function testLiveRoundTripThroughTheRepository(): void
    {
        $manager = $this->manager();
        $this->seed($manager);

        $session = new MongoDriverSession($manager, self::DATABASE);
        $repository = new MongoRepository($session, PersonRow::class);

        // Server-side push-down: range predicate, sort, and bound — and the
        // BSON `_id` never leaks into the flat scalar rows.
        $people = $repository->find(
            Query::select()->from('people')->where(Criteria::gt('id', 1))->orderBy('id')->limit(2),
        );

        self::assertSame(['bob', 'carol'], array_map(static fn(PersonRow $person): string => $person->name, $people));

        $carol = $repository->findOne(Query::select()->from('people')->where(Criteria::eq('name', 'carol')));
        self::assertSame(7.25, $carol?->score);
    }

    public function testLiveWriteRoundTripThroughTheRepository(): void
    {
        $manager = $this->manager();
        $this->seed($manager);

        $session = new MongoDriverSession($manager, self::DATABASE);
        $repository = new MongoRepository(
            $session,
            PersonRow::class,
            mapper: new \WaffleTests\Commons\Data\Fixture\PersonMapper(),
        );

        // insert (null identity), upsert (replace by id), then delete.
        $repository->save(new PersonRow(0, 'dave', 1.0));
        self::assertInstanceOf(PersonRow::class, $repository->findById(1));

        $repository->save(new PersonRow(1, 'alice-renamed', 2.0));
        self::assertSame('alice-renamed', $repository->findById(1)?->name);

        $repository->delete(new PersonRow(1, 'alice-renamed', 2.0));
        self::assertNull($repository->findById(1));
    }

    public function testDriverFailureIsWrappedAsDatabaseException(): void
    {
        $manager = $this->manager();
        $session = new MongoDriverSession($manager, self::DATABASE);

        $this->expectException(DatabaseException::class);

        // An unknown query operator is rejected server-side with a driver
        // exception, which must surface as a DatabaseException (§7.3).
        $session->find(
            new CompiledMongoQuery('people', ['x' => ['$badOperator' => 1]], new MongoFindOptions([], [], null, null)),
        );
    }
}
