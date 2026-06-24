<?php

declare(strict_types=1);

namespace WaffleTests\Commons\Data\Middleware;

use PDO;
use PDOException;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use RuntimeException;
use Waffle\Commons\Data\Connection\PDOConnectionPool;
use Waffle\Commons\Data\Middleware\TransactionIsolationMiddleware;
use WaffleTests\Commons\Data\AbstractTestCase;

#[CoversClass(TransactionIsolationMiddleware::class)]
#[AllowMockObjectsWithoutExpectations]
final class TransactionIsolationMiddlewareTest extends AbstractTestCase
{
    private function poolFor(PDO $pdo): PDOConnectionPool
    {
        return new PDOConnectionPool(static fn(): PDO => $pdo);
    }

    private function request(string $method): ServerRequestInterface
    {
        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getMethod')->willReturn($method);

        return $request;
    }

    private function handlerReturning(ResponseInterface $response): RequestHandlerInterface
    {
        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->method('handle')->willReturn($response);

        return $handler;
    }

    private function handlerThrowing(\Throwable $error): RequestHandlerInterface
    {
        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->method('handle')->willThrowException($error);

        return $handler;
    }

    public function testReadRequestBypassesTransaction(): void
    {
        $pdo = $this->createMock(PDO::class);
        $pdo->expects(self::never())->method('beginTransaction');
        $response = $this->createMock(ResponseInterface::class);

        $middleware = new TransactionIsolationMiddleware($this->poolFor($pdo));
        $result = $middleware->process($this->request('GET'), $this->handlerReturning($response));

        self::assertSame($response, $result);
    }

    public function testWriteRequestCommitsOnSuccess(): void
    {
        $pdo = $this->createMock(PDO::class);
        $pdo->expects(self::once())->method('beginTransaction')->willReturn(true);
        $pdo->expects(self::once())->method('commit')->willReturn(true);
        $pdo->expects(self::never())->method('rollBack');
        $response = $this->createMock(ResponseInterface::class);

        $middleware = new TransactionIsolationMiddleware($this->poolFor($pdo));
        $result = $middleware->process($this->request('POST'), $this->handlerReturning($response));

        self::assertSame($response, $result);
    }

    public function testWriteRequestRollsBackOnThrow(): void
    {
        $pdo = $this->createMock(PDO::class);
        $pdo->method('beginTransaction')->willReturn(true);
        $pdo->method('inTransaction')->willReturn(true);
        $pdo->expects(self::once())->method('rollBack')->willReturn(true);
        $pdo->expects(self::never())->method('commit');

        $middleware = new TransactionIsolationMiddleware($this->poolFor($pdo));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('boom');
        $middleware->process($this->request('DELETE'), $this->handlerThrowing(new RuntimeException('boom')));
    }

    public function testRollbackIsSkippedWhenNoTransactionIsActive(): void
    {
        $pdo = $this->createMock(PDO::class);
        $pdo->method('beginTransaction')->willReturn(true);
        $pdo->method('inTransaction')->willReturn(false);
        $pdo->expects(self::never())->method('rollBack');

        $middleware = new TransactionIsolationMiddleware($this->poolFor($pdo));

        $this->expectException(RuntimeException::class);
        $middleware->process($this->request('PUT'), $this->handlerThrowing(new RuntimeException('boom')));
    }

    public function testRollbackFailureIsSwallowedAndOriginalErrorSurfaces(): void
    {
        $pdo = $this->createMock(PDO::class);
        $pdo->method('beginTransaction')->willReturn(true);
        $pdo->method('inTransaction')->willReturn(true);
        $pdo->method('rollBack')->willThrowException(new PDOException('socket gone'));

        $middleware = new TransactionIsolationMiddleware($this->poolFor($pdo));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('boom');
        $middleware->process($this->request('POST'), $this->handlerThrowing(new RuntimeException('boom')));
    }

    public function testCustomWriteMethodsAreHonoured(): void
    {
        $pdo = $this->createMock(PDO::class);
        $pdo->expects(self::never())->method('beginTransaction');
        $response = $this->createMock(ResponseInterface::class);

        // Only PATCH is a write here, so a POST bypasses the transaction.
        $middleware = new TransactionIsolationMiddleware($this->poolFor($pdo), ['PATCH']);
        $result = $middleware->process($this->request('POST'), $this->handlerReturning($response));

        self::assertSame($response, $result);
    }
}
