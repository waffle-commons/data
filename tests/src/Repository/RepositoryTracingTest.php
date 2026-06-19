<?php

declare(strict_types=1);

namespace WaffleTests\Commons\Data\Repository;

use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\StreamInterface;
use Throwable;
use Waffle\Commons\Contracts\Telemetry\Enum\SpanStatus;
use Waffle\Commons\Data\Compiler\SQLCompiler;
use Waffle\Commons\Data\Compiler\SQLDialect;
use Waffle\Commons\Data\Connection\PDOConnectionPool;
use Waffle\Commons\Data\Driver\Graph\GraphQLExecutor;
use Waffle\Commons\Data\Query\Criteria;
use Waffle\Commons\Data\Query\Query;
use Waffle\Commons\Data\Repository\CassandraRepository;
use Waffle\Commons\Data\Repository\FirestoreRepository;
use Waffle\Commons\Data\Repository\GraphQLRepository;
use Waffle\Commons\Data\Repository\JsonFileRepository;
use Waffle\Commons\Data\Repository\KeyValueRepository;
use Waffle\Commons\Data\Repository\MongoRepository;
use Waffle\Commons\Data\Repository\SQLRepository;
use WaffleTests\Commons\Data\AbstractTestCase;
use WaffleTests\Commons\Data\Fixture\FakeCqlSession;
use WaffleTests\Commons\Data\Fixture\FakeFirestoreClient;
use WaffleTests\Commons\Data\Fixture\FakeMongoSession;
use WaffleTests\Commons\Data\Fixture\FakeSecurityContext;
use WaffleTests\Commons\Data\Fixture\InMemoryKeyValueClient;
use WaffleTests\Commons\Data\Fixture\PersonMapper;
use WaffleTests\Commons\Data\Fixture\PersonRow;
use WaffleTests\Commons\Data\Fixture\RecordingTracer;

use function bin2hex;
use function file_put_contents;
use function glob;
use function is_dir;
use function iterator_to_array;
use function mkdir;
use function random_bytes;
use function rmdir;
use function sys_get_temp_dir;
use function unlink;

/**
 * Proves OBS-01 is native to the data layer: every repository, once given a
 * tracer through `withTracer()`, opens a `waffle.db.query` CLIENT span tagged
 * with its backend system + operation, and records the failure on the span
 * before re-throwing.
 */
#[CoversClass(SQLRepository::class)]
#[CoversClass(MongoRepository::class)]
#[CoversClass(KeyValueRepository::class)]
#[CoversClass(CassandraRepository::class)]
#[CoversClass(GraphQLRepository::class)]
#[CoversClass(JsonFileRepository::class)]
#[CoversClass(FirestoreRepository::class)]
final class RepositoryTracingTest extends AbstractTestCase
{
    private PDOConnectionPool $pool;

    private string $directory;

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->pool = new PDOConnectionPool(factory: static fn(): PDO => new PDO('sqlite::memory:'));
        $connection = $this->pool->acquire();
        $connection->exec('CREATE TABLE people (id INTEGER PRIMARY KEY, name TEXT NOT NULL, score REAL)');
        $connection->exec("INSERT INTO people (id, name, score) VALUES (1, 'alice', 9.5)");
        $this->pool->release($connection);

        $this->directory = sys_get_temp_dir() . '/waffle-trace-repo-' . bin2hex(random_bytes(6));
        mkdir($this->directory);
    }

    #[\Override]
    protected function tearDown(): void
    {
        if (is_dir($this->directory)) {
            $files = glob($this->directory . '/*');
            foreach ($files === false ? [] : $files as $file) {
                unlink($file);
            }

            rmdir($this->directory);
        }

        parent::tearDown();
    }

    public function testSqlRepositoryEmitsQuerySpans(): void
    {
        $tracer = new RecordingTracer();
        $repository = new SQLRepository($this->pool, PersonRow::class, new SQLCompiler(SQLDialect::SQLite))->withTracer(
            $tracer,
        );

        $repository->find(Query::select()->from('people'));
        $this->assertSuccessSpan($tracer, 'sql', 'find');

        $person = new PersonRow(1, 'alice');
        $this->assertErrorSpan($tracer, static fn(): mixed => $repository->find(Query::select()->from('missing')));
        $this->assertErrorSpan($tracer, static fn(): mixed => $repository->findOne(Query::select()->from('missing')));
        $this->assertErrorSpan($tracer, static fn(): array => iterator_to_array($repository->stream(Query::select()->from(
            'missing',
        ))));
        $this->assertErrorSpan($tracer, static fn(): mixed => $repository->findById(1));
        $this->assertErrorSpan($tracer, static fn() => $repository->save($person));
        $this->assertErrorSpan($tracer, static fn() => $repository->delete($person));
    }

    public function testMongoRepositoryEmitsQuerySpans(): void
    {
        $tracer = new RecordingTracer();
        $ok = new MongoRepository(new FakeMongoSession([[
            'id' => 1,
            'name' => 'alice',
            'score' => null,
        ]]), PersonRow::class)->withTracer($tracer);
        $ok->find(Query::select()->from('people'));
        $this->assertSuccessSpan($tracer, 'mongodb', 'find');

        $this->assertPoisonedReadsAndReadOnlyWrites(
            $tracer,
            new MongoRepository(new FakeMongoSession([[
                'id' => 1,
                'name' => 5,
                'score' => null,
            ]]), PersonRow::class)->withTracer($tracer),
            Query::select()->from('people'),
        );
    }

    public function testKeyValueRepositoryEmitsQuerySpans(): void
    {
        $tracer = new RecordingTracer();
        $ok = new KeyValueRepository(new InMemoryKeyValueClient([
            'people:1' => '{"id": 1, "name": "alice", "score": 9.5}',
        ]), PersonRow::class)->withTracer($tracer);
        $ok->find(Query::select()->from('people')->where(Criteria::eq('id', 1)));
        $this->assertSuccessSpan($tracer, 'keyvalue', 'find');

        $this->assertPoisonedReadsAndReadOnlyWrites(
            $tracer,
            new KeyValueRepository(new InMemoryKeyValueClient([
                'people:1' => '{"id": 1, "name": 5, "score": null}',
            ]), PersonRow::class)->withTracer($tracer),
            Query::select()->from('people')->where(Criteria::eq('id', 1)),
        );
    }

    public function testCassandraRepositoryEmitsQuerySpans(): void
    {
        $tracer = new RecordingTracer();
        $ok = new CassandraRepository(new FakeCqlSession([[
            'id' => 1,
            'name' => 'alice',
            'score' => null,
        ]]), PersonRow::class)->withTracer($tracer);
        $ok->find(Query::select()->from('people'));
        $this->assertSuccessSpan($tracer, 'cassandra', 'find');

        $this->assertPoisonedReadsAndReadOnlyWrites(
            $tracer,
            new CassandraRepository(new FakeCqlSession([[
                'id' => 1,
                'name' => 5,
                'score' => null,
            ]]), PersonRow::class)->withTracer($tracer),
            Query::select()->from('people'),
        );
    }

    public function testJsonFileRepositoryEmitsQuerySpans(): void
    {
        $tracer = new RecordingTracer();
        $okPath = $this->directory . '/ok.json';
        file_put_contents($okPath, '[]');
        $ok = new JsonFileRepository($okPath, PersonRow::class)->withTracer($tracer);
        $ok->find(Query::select()->from('people'));
        $this->assertSuccessSpan($tracer, 'jsonfile', 'find');

        $badPath = $this->directory . '/bad.json';
        file_put_contents($badPath, '[{"id": 1, "name": 5, "score": null}]');
        $this->assertPoisonedReadsAndReadOnlyWrites(
            $tracer,
            new JsonFileRepository($badPath, PersonRow::class)->withTracer($tracer),
            Query::select()->from('people'),
        );
    }

    public function testGraphQLRepositoryEmitsQuerySpans(): void
    {
        $tracer = new RecordingTracer();
        $ok = new GraphQLRepository(
            $this->graphqlExecutor('{"data": {"people": [{"id": 1, "name": "alice", "score": 9.5}]}}'),
            PersonRow::class,
        )->withTracer($tracer);
        $ok->find(Query::select('id', 'name', 'score')->from('people'));
        $this->assertSuccessSpan($tracer, 'graphql', 'find');

        // A projection-less SQR is rejected at compile time (GraphQL has no SELECT *),
        // so reads fail before the executor; writes fail at the missing mapper.
        $readOnly = new GraphQLRepository(
            $this->graphqlExecutor('{"data": {"people": []}}'),
            PersonRow::class,
        )->withTracer($tracer);
        $this->assertPoisonedReadsAndReadOnlyWrites($tracer, $readOnly, Query::select()->from('people'));
    }

    public function testFirestoreRepositoryEmitsQuerySpans(): void
    {
        $tracer = new RecordingTracer();
        $client = new FakeFirestoreClient();

        $ok = FirestoreRepository::forPublic(
            $client,
            PersonRow::class,
            FakeSecurityContext::authenticatedAs('user-1'),
            new PersonMapper(),
            'app-1',
        )->withTracer($tracer);
        $ok->find(Query::select()->from('people'));
        $this->assertSuccessSpan($tracer, 'firestore', 'find');

        // An anonymous caller trips the Rule-3 auth gate in every operation, so
        // each span records the failure.
        $anon = FirestoreRepository::forPublic(
            $client,
            PersonRow::class,
            FakeSecurityContext::anonymous(),
            new PersonMapper(),
            'app-1',
        )->withTracer($tracer);

        $person = new PersonRow(1, 'alice');
        $query = Query::select()->from('people');
        $this->assertErrorSpan($tracer, static fn(): mixed => $anon->find($query));
        $this->assertErrorSpan($tracer, static fn(): mixed => $anon->findOne($query));
        $this->assertErrorSpan($tracer, static fn(): array => iterator_to_array($anon->stream($query)));
        $this->assertErrorSpan($tracer, static fn(): mixed => $anon->findById(1));
        $this->assertErrorSpan($tracer, static fn() => $anon->save($person));
        $this->assertErrorSpan($tracer, static fn() => $anon->delete($person));
    }

    /**
     * @param SQLRepository<PersonRow>|MongoRepository<PersonRow>|KeyValueRepository<PersonRow>|CassandraRepository<PersonRow>|GraphQLRepository<PersonRow>|JsonFileRepository<PersonRow> $readOnly
     */
    private function assertPoisonedReadsAndReadOnlyWrites(
        RecordingTracer $tracer,
        SQLRepository|MongoRepository|KeyValueRepository|CassandraRepository|GraphQLRepository|JsonFileRepository $readOnly,
        Query $query,
    ): void {
        $person = new PersonRow(1, 'alice');

        $this->assertErrorSpan($tracer, static fn(): mixed => $readOnly->find($query));
        $this->assertErrorSpan($tracer, static fn(): mixed => $readOnly->findOne($query));
        $this->assertErrorSpan($tracer, static fn(): array => iterator_to_array($readOnly->stream($query)));
        $this->assertErrorSpan($tracer, static fn(): mixed => $readOnly->findById(1));
        $this->assertErrorSpan($tracer, static fn() => $readOnly->save($person));
        $this->assertErrorSpan($tracer, static fn() => $readOnly->delete($person));
    }

    private function assertSuccessSpan(RecordingTracer $tracer, string $system, string $operation): void
    {
        $span = $tracer->last();
        self::assertSame('waffle.db.query', $span->name);
        self::assertSame($system, $span->attributes['db.system'] ?? null);
        self::assertSame($operation, $span->attributes['db.operation'] ?? null);
        self::assertNull($span->status);
        self::assertSame(1, $span->ended);
    }

    private function assertErrorSpan(RecordingTracer $tracer, callable $operation): void
    {
        $threw = false;
        try {
            $operation();
        } catch (Throwable) {
            $threw = true;
        }

        self::assertTrue($threw, 'The repository operation should have thrown.');

        $span = $tracer->last();
        self::assertSame(SpanStatus::Error, $span->status);
        self::assertNotNull($span->exception);
        self::assertSame(1, $span->ended);
    }

    private function graphqlExecutor(string $responseBody): GraphQLExecutor
    {
        $request = $this->createStub(RequestInterface::class);
        $request->method('withHeader')->willReturnSelf();
        $request->method('withBody')->willReturnSelf();

        $requestFactory = $this->createStub(RequestFactoryInterface::class);
        $requestFactory->method('createRequest')->willReturn($request);

        $streamFactory = $this->createStub(StreamFactoryInterface::class);
        $streamFactory->method('createStream')->willReturn($this->createStub(StreamInterface::class));

        $body = $this->createStub(StreamInterface::class);
        $body->method('__toString')->willReturn($responseBody);

        $response = $this->createStub(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(200);
        $response->method('getBody')->willReturn($body);

        $client = $this->createStub(ClientInterface::class);
        $client->method('sendRequest')->willReturn($response);

        return new GraphQLExecutor($client, $requestFactory, $streamFactory, 'https://api.example.test/graphql');
    }
}
