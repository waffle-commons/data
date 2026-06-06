<?php

declare(strict_types=1);

namespace WaffleTests\Commons\Data\Driver\Graph;

use Exception;
use PHPUnit\Framework\Attributes\CoversClass;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\StreamInterface;
use Waffle\Commons\Data\Compiler\CompiledGraphQLQuery;
use Waffle\Commons\Data\Driver\Graph\GraphQLExecutor;
use Waffle\Commons\Data\Exception\DatabaseException;
use WaffleTests\Commons\Data\AbstractTestCase;

#[CoversClass(GraphQLExecutor::class)]
final class GraphQLExecutorTest extends AbstractTestCase
{
    private function compiled(): CompiledGraphQLQuery
    {
        return new CompiledGraphQLQuery('query { people { id name } }', [], 'people');
    }

    private function executor(string $responseBody, int $status = 200): GraphQLExecutor
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
        $response->method('getStatusCode')->willReturn($status);
        $response->method('getBody')->willReturn($body);

        $client = $this->createStub(ClientInterface::class);
        $client->method('sendRequest')->willReturn($response);

        return new GraphQLExecutor($client, $requestFactory, $streamFactory, 'https://api.example.test/graphql');
    }

    public function testExecuteUnwrapsTheRowsUnderTheRootField(): void
    {
        $executor = $this->executor('{"data": {"people": [{"id": 1, "name": "alice"}, {"id": 2, "name": "bob"}]}}');

        $rows = $executor->execute($this->compiled());

        self::assertSame(
            [
                ['id' => 1, 'name' => 'alice'],
                ['id' => 2, 'name' => 'bob'],
            ],
            $rows,
        );
    }

    public function testNonOkStatusIsRejected(): void
    {
        $executor = $this->executor('{}', 503);

        $this->expectException(DatabaseException::class);
        $this->expectExceptionMessage('HTTP 503');

        $executor->execute($this->compiled());
    }

    public function testGraphQLErrorsFailTheCall(): void
    {
        $executor = $this->executor('{"errors": [{"message": "Cannot query field"}], "data": null}');

        $this->expectException(DatabaseException::class);
        $this->expectExceptionMessage('Cannot query field');

        $executor->execute($this->compiled());
    }

    public function testUnshapedErrorsFallBackToUnknown(): void
    {
        $executor = $this->executor('{"errors": ["weird"]}');

        $this->expectException(DatabaseException::class);
        $this->expectExceptionMessage('unknown error');

        $executor->execute($this->compiled());
    }

    public function testInvalidJsonResponseIsRejected(): void
    {
        $executor = $this->executor('{not json');

        $this->expectException(DatabaseException::class);

        $executor->execute($this->compiled());
    }

    public function testNonObjectResponseIsRejected(): void
    {
        $executor = $this->executor('42');

        $this->expectException(DatabaseException::class);

        $executor->execute($this->compiled());
    }

    public function testMissingDataObjectIsRejected(): void
    {
        $executor = $this->executor('{"data": null}');

        $this->expectException(DatabaseException::class);
        $this->expectExceptionMessage('no data object');

        $executor->execute($this->compiled());
    }

    public function testMissingRootListIsRejected(): void
    {
        $executor = $this->executor('{"data": {"other": []}}');

        $this->expectException(DatabaseException::class);
        $this->expectExceptionMessage('"people"');

        $executor->execute($this->compiled());
    }

    public function testTransportFailureIsWrapped(): void
    {
        $request = $this->createStub(RequestInterface::class);
        $request->method('withHeader')->willReturnSelf();
        $request->method('withBody')->willReturnSelf();

        $requestFactory = $this->createStub(RequestFactoryInterface::class);
        $requestFactory->method('createRequest')->willReturn($request);

        $streamFactory = $this->createStub(StreamFactoryInterface::class);
        $streamFactory->method('createStream')->willReturn($this->createStub(StreamInterface::class));

        $client = $this->createStub(ClientInterface::class);
        $client
            ->method('sendRequest')
            ->willThrowException(new class('refused') extends Exception implements ClientExceptionInterface {});

        $executor = new GraphQLExecutor($client, $requestFactory, $streamFactory, 'https://api.example.test/graphql');

        $this->expectException(DatabaseException::class);
        $this->expectExceptionMessage('transport');

        $executor->execute($this->compiled());
    }
}
