<?php

declare(strict_types=1);

namespace WaffleTests\Commons\Data\Driver\Firestore;

use Exception;
use PHPUnit\Framework\Attributes\CoversClass;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\StreamInterface;
use Waffle\Commons\Data\Driver\Firestore\FirestoreRestClient;
use Waffle\Commons\Data\Exception\DatabaseException;
use WaffleTests\Commons\Data\AbstractTestCase;

#[CoversClass(FirestoreRestClient::class)]
final class FirestoreRestClientTest extends AbstractTestCase
{
    private function client(string $responseBody, int $status = 200): FirestoreRestClient
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

        return new FirestoreRestClient($client, $requestFactory, $streamFactory, 'https://fs.example.test');
    }

    public function testGetDocumentUnwrapsTheDocument(): void
    {
        $row = $this->client('{"document":{"id":1,"name":"ada"}}')->getDocument('p', '1');

        self::assertSame(['id' => 1, 'name' => 'ada'], $row);
    }

    public function testGetDocumentReturnsNullOnMiss(): void
    {
        self::assertNull($this->client('{"document":null}')->getDocument('p', '1'));
    }

    public function testQueryCollectionUnwrapsTheDocuments(): void
    {
        $rows = $this->client('{"documents":[{"id":1,"name":"ada"},{"id":2,"name":"bob"}]}')->queryCollection(
            'p',
            [],
            null,
        );

        self::assertSame([['id' => 1, 'name' => 'ada'], ['id' => 2, 'name' => 'bob']], $rows);
    }

    public function testSetDocumentReturnsTheAssignedId(): void
    {
        self::assertSame('abc', $this->client('{"id":"abc"}')->setDocument('p', null, ['name' => 'ada']));
    }

    public function testDeleteDocumentSucceedsOnOk(): void
    {
        $this->client('{}')->deleteDocument('p', '1');

        $this->addToAssertionCount(1);
    }

    public function testNonOkStatusIsRejected(): void
    {
        $this->expectException(DatabaseException::class);
        $this->expectExceptionMessage('HTTP 503');
        $this->client('{}', 503)->getDocument('p', '1');
    }

    public function testNonJsonResponseIsRejected(): void
    {
        $this->expectException(DatabaseException::class);
        $this->client('<nope>')->getDocument('p', '1');
    }

    public function testNonObjectResponseIsRejected(): void
    {
        $this->expectException(DatabaseException::class);
        $this->expectExceptionMessage('JSON object');
        $this->client('"scalar"')->getDocument('p', '1');
    }

    public function testNonObjectDocumentIsRejected(): void
    {
        $this->expectException(DatabaseException::class);
        $this->expectExceptionMessage('non-object document');
        $this->client('{"document":"scalar"}')->getDocument('p', '1');
    }

    public function testQueryWithoutDocumentsListIsRejected(): void
    {
        $this->expectException(DatabaseException::class);
        $this->expectExceptionMessage('documents');
        $this->client('{"nope":true}')->queryCollection('p', [], null);
    }

    public function testSetWithoutIdIsRejected(): void
    {
        $this->expectException(DatabaseException::class);
        $this->expectExceptionMessage('document id');
        $this->client('{}')->setDocument('p', '1', ['name' => 'ada']);
    }

    public function testTransportFailureIsWrapped(): void
    {
        $client = $this->createStub(ClientInterface::class);
        $client
            ->method('sendRequest')
            ->willThrowException(new class('refused') extends Exception implements ClientExceptionInterface {});
        $requestFactory = $this->createStub(RequestFactoryInterface::class);
        $requestFactory->method('createRequest')->willReturn($this->configuredRequest());
        $streamFactory = $this->createStub(StreamFactoryInterface::class);
        $streamFactory->method('createStream')->willReturn($this->createStub(StreamInterface::class));

        $rest = new FirestoreRestClient($client, $requestFactory, $streamFactory, 'https://fs.example.test');

        $this->expectException(DatabaseException::class);
        $this->expectExceptionMessage('transport failure');
        $rest->deleteDocument('p', '1');
    }

    private function configuredRequest(): RequestInterface
    {
        $request = $this->createStub(RequestInterface::class);
        $request->method('withHeader')->willReturnSelf();
        $request->method('withBody')->willReturnSelf();

        return $request;
    }
}
