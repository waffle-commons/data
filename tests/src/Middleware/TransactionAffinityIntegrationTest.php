<?php

declare(strict_types=1);

namespace WaffleTests\Commons\Data\Middleware;

use PDO;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use RuntimeException;
use Waffle\Commons\Data\Compiler\SQLCompiler;
use Waffle\Commons\Data\Compiler\SQLDialect;
use Waffle\Commons\Data\Compiler\SQLWriteCompiler;
use Waffle\Commons\Data\Connection\PDOConnectionPool;
use Waffle\Commons\Data\Middleware\TransactionIsolationMiddleware;
use Waffle\Commons\Data\Repository\SQLRepository;
use WaffleTests\Commons\Data\AbstractTestCase;
use WaffleTests\Commons\Data\Fixture\AutoIdPersonMapper;
use WaffleTests\Commons\Data\Fixture\PersonRow;

use function bin2hex;
use function random_bytes;

/**
 * End-to-end proof of the DBAL-01 connection affinity (DBAL-05).
 *
 * Everything load-bearing is REAL: a {@see PDOConnectionPool} whose factory mints
 * distinct PDO handles over one shared-cache in-memory SQLite database, a real
 * {@see SQLRepository} write, a real {@see RequestHandlerInterface}, and the real
 * {@see TransactionIsolationMiddleware}. Only the inert PSR-7 message envelopes
 * are doubled — they carry no logic the fix depends on.
 *
 * The proof: because the middleware pins ONE connection for the request and the
 * repository's `acquire()` reuses that same pinned connection, the repository's
 * INSERT runs INSIDE the middleware's transaction — so a thrown error rolls the
 * write BACK and a successful request COMMITS it. Without the affinity fix the
 * repository would open its own connection and auto-commit independently of the
 * middleware, and the rollback assertion would fail.
 */
#[CoversClass(TransactionIsolationMiddleware::class)]
#[CoversClass(PDOConnectionPool::class)]
#[AllowMockObjectsWithoutExpectations]
final class TransactionAffinityIntegrationTest extends AbstractTestCase
{
    private PDOConnectionPool $pool;

    /** Holds the shared-cache in-memory DB open for the test's lifetime. */
    private PDO $keepAlive;

    /** @var SQLRepository<PersonRow> */
    private SQLRepository $repository;

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        // The factory mints a DISTINCT PDO handle on every call, all pointing at
        // the same shared-cache in-memory database. This is what isolates the
        // DBAL-01 affinity from a single-handle artifact: WITHOUT pinning, the
        // repository would acquire a different connection than the middleware and
        // auto-commit its own transaction independently — so the rollback proof
        // below would FAIL. WITH the affinity fix the repo reuses the pinned
        // handle and its write rolls back with the request.
        $dsn = 'sqlite:file:affinity_' . bin2hex(random_bytes(8)) . '?mode=memory&cache=shared';
        $this->pool = new PDOConnectionPool(factory: static fn(): PDO => new PDO($dsn), maxConnections: 16);

        // Hold one open connection for the test lifetime so the shared-cache DB
        // is never torn down between pool dispenses.
        $this->keepAlive = new PDO($dsn);
        $this->keepAlive->exec(
            'CREATE TABLE people (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL, score REAL)',
        );

        $this->repository = new SQLRepository(
            $this->pool,
            PersonRow::class,
            new SQLCompiler(SQLDialect::SQLite),
            new AutoIdPersonMapper(),
            new SQLWriteCompiler(SQLDialect::SQLite),
        );
    }

    public function testSuccessfulWriteRequestCommitsTheRepositoryInsert(): void
    {
        $handler = $this->handlerThatInserts('alice', throwAfterWrite: false);
        $middleware = new TransactionIsolationMiddleware($this->pool);

        $middleware->process($this->writeRequest(), $handler);

        // The scope is closed; the row must be durably committed.
        self::assertSame(1, $this->countRows());
    }

    public function testFailingWriteRequestRollsBackTheRepositoryInsert(): void
    {
        $handler = $this->handlerThatInserts('bob', throwAfterWrite: true);
        $middleware = new TransactionIsolationMiddleware($this->pool);

        try {
            $middleware->process($this->writeRequest(), $handler);
            self::fail('The handler was expected to throw.');
        } catch (RuntimeException $caught) {
            self::assertSame('boom', $caught->getMessage());
        }

        // The repository INSERT ran inside the middleware's transaction, so the
        // throw rolled it back: the table is empty. (Without affinity the repo
        // would have auto-committed on its own connection and this would be 1.)
        self::assertSame(0, $this->countRows());
    }

    public function testEachWriteIsScopedSoOneRequestCannotSeeAnother(): void
    {
        $middleware = new TransactionIsolationMiddleware($this->pool);

        // A committed request, then a rolled-back one: only the first survives.
        $middleware->process($this->writeRequest(), $this->handlerThatInserts('alice', throwAfterWrite: false));
        try {
            $middleware->process($this->writeRequest(), $this->handlerThatInserts('bob', throwAfterWrite: true));
        } catch (RuntimeException) {
            // expected
        }

        self::assertSame(1, $this->countRows());
        // The first committed INSERT took the auto-assigned id 1; the rolled-back
        // second request left nothing behind.
        $survivor = $this->repository->findById(1);
        self::assertInstanceOf(PersonRow::class, $survivor);
        self::assertSame('alice', $survivor->name);
    }

    /**
     * A real request handler that performs a genuine repository INSERT, then
     * optionally throws — emulating a controller that writes then fails.
     */
    private function handlerThatInserts(string $name, bool $throwAfterWrite): RequestHandlerInterface
    {
        $repository = $this->repository;
        $response = $this->createMock(ResponseInterface::class);

        return new class($repository, $response, $name, $throwAfterWrite) implements RequestHandlerInterface {
            /**
             * @param SQLRepository<PersonRow> $repository
             */
            public function __construct(
                private readonly SQLRepository $repository,
                private readonly ResponseInterface $response,
                private readonly string $name,
                private readonly bool $throwAfterWrite,
            ) {}

            #[\Override]
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                // AutoIdPersonMapper always INSERTs with a backend-assigned id.
                $this->repository->save(new PersonRow(0, $this->name));

                if ($this->throwAfterWrite) {
                    throw new RuntimeException('boom');
                }

                return $this->response;
            }
        };
    }

    private function writeRequest(): ServerRequestInterface
    {
        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getMethod')->willReturn('POST');

        return $request;
    }

    private function countRows(): int
    {
        $lease = $this->pool->acquire();
        try {
            $statement = $lease->pdo()->query('SELECT COUNT(*) FROM people');
            self::assertNotFalse($statement);

            return (int) $statement->fetchColumn();
        } finally {
            $this->pool->release($lease);
        }
    }
}
