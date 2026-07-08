<?php

declare(strict_types=1);

namespace Waffle\Commons\Data\Middleware;

use PDO;
use PDOException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Throwable;
use Waffle\Commons\Contracts\Data\Connection\RelationalConnectionPoolInterface;

use function in_array;
use function strtoupper;

/**
 * Failsafe transaction-isolation middleware (DBAL-02).
 *
 * Wraps every write request (POST/PUT/PATCH/DELETE by default) in a single
 * database transaction borrowed from the relational pool: the transaction
 * commits when the downstream handler returns normally and rolls back on ANY
 * uncaught throwable, so a half-applied write or a leaked lock can never bleed
 * from one worker iteration into the next.
 *
 * **Connection affinity (DBAL-01):** the transaction is opened on a pinned
 * connection via {@see RelationalConnectionPoolInterface::beginRequestScope()},
 * so every repository `acquire()` during the request returns that SAME
 * connection and its writes therefore run inside this transaction.
 * {@see RelationalConnectionPoolInterface::endRequestScope()} unpins and returns
 * the handle in the `finally`.
 *
 * **Pipeline ordering:** place this INSIDE the error handler (after Security,
 * before the Dispatcher). A thrown exception then unwinds through this
 * middleware — which rolls back and rethrows — before the error handler renders
 * it. A write controller that catches its own exception and returns a 2xx
 * response will COMMIT, by design.
 *
 * Stateless: the only transactional state lives on the pooled connection, which
 * the pool's `reset()` rolls back if this middleware's `finally` was ever
 * skipped — so `wfl igor` stays 0 KO.
 */
final readonly class TransactionIsolationMiddleware implements MiddlewareInterface
{
    /** @var list<string> */
    private const array DEFAULT_WRITE_METHODS = ['POST', 'PUT', 'PATCH', 'DELETE'];

    /** @var list<string> */
    private array $writeMethods;

    /**
     * @param RelationalConnectionPoolInterface $pool         Relational pool to borrow from.
     * @param list<string>|null                 $writeMethods HTTP methods wrapped in a
     *                                                        transaction; defaults to the
     *                                                        unsafe verbs.
     */
    public function __construct(
        private RelationalConnectionPoolInterface $pool,
        ?array $writeMethods = null,
    ) {
        $this->writeMethods = $writeMethods ?? self::DEFAULT_WRITE_METHODS;
    }

    /**
     * @throws Throwable Re-thrown after rollback when the downstream handler fails.
     */
    #[\Override]
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (!in_array(strtoupper($request->getMethod()), $this->writeMethods, true)) {
            return $handler->handle($request);
        }

        // Pin one connection for the whole write request (DBAL-01): every
        // repository acquire() during the scope reuses this same lease, so all
        // their writes run inside the single transaction begun below.
        $lease = $this->pool->beginRequestScope();
        $pdo = $lease->pdo();

        try {
            $pdo->beginTransaction();
            $response = $handler->handle($request);
            $pdo->commit();

            return $response;
        } catch (Throwable $error) {
            $this->rollBackQuietly($pdo);

            throw $error;
        } finally {
            $this->pool->endRequestScope();
        }
    }

    /**
     * Roll back an open transaction during failure recovery. A connection
     * already severed mid-flight cannot roll back — it is left for the pool's
     * reset cycle to reap, and the original failure is what surfaces.
     */
    private function rollBackQuietly(PDO $pdo): void
    {
        if (!$pdo->inTransaction()) {
            return;
        }

        try {
            $pdo->rollBack();
        } catch (PDOException) {
            // Connection already broken; the pool reaps it on next dispense/reset.
        }
    }
}
