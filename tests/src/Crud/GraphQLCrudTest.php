<?php

declare(strict_types=1);

namespace WaffleTests\Commons\Data\Crud;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\StreamInterface;
use Waffle\Commons\Data\Driver\Graph\GraphQLExecutor;
use Waffle\Commons\Data\Exception\DatabaseException;
use Waffle\Commons\Data\Repository\GraphQLRepository;
use WaffleTests\Commons\Data\AbstractTestCase;
use WaffleTests\Commons\Data\Fixture\PersonMapper;
use WaffleTests\Commons\Data\Fixture\PersonRow;

#[CoversClass(GraphQLRepository::class)]
#[CoversClass(GraphQLExecutor::class)]
final class GraphQLCrudTest extends AbstractTestCase
{
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

        $client = $this->createStub(\Psr\Http\Client\ClientInterface::class);
        $client->method('sendRequest')->willReturn($response);

        return new GraphQLExecutor($client, $requestFactory, $streamFactory, 'https://api.example.test/graphql');
    }

    /**
     * @return GraphQLRepository<PersonRow>
     */
    private function repository(GraphQLExecutor $executor): GraphQLRepository
    {
        return new GraphQLRepository($executor, PersonRow::class, mapper: new PersonMapper());
    }

    public function testSaveInsertSucceedsOnACleanResponse(): void
    {
        $repository = $this->repository($this->executor('{"data":{"insert_people_one":{"id":1}}}'));

        $repository->save(new PersonRow(0, 'ada', 9.5));

        $this->addToAssertionCount(1); // No exception ⇒ the mutation was accepted.
    }

    public function testSaveUpdateSucceedsOnACleanResponse(): void
    {
        $repository = $this->repository($this->executor('{"data":{"update_people_by_pk":{"id":7}}}'));

        $repository->save(new PersonRow(7, 'ada', 9.5));

        $this->addToAssertionCount(1);
    }

    public function testDeleteSucceedsOnACleanResponse(): void
    {
        $repository = $this->repository($this->executor('{"data":{"delete_people_by_pk":{"id":7}}}'));

        $repository->delete(new PersonRow(7, 'ada', 9.5));

        $this->addToAssertionCount(1);
    }

    public function testMutationErrorIsSurfaced(): void
    {
        $repository = $this->repository($this->executor('{"errors":[{"message":"constraint violation"}]}'));

        $this->expectException(DatabaseException::class);
        $this->expectExceptionMessage('constraint violation');
        $repository->save(new PersonRow(7, 'ada', 9.5));
    }

    public function testMutationNonOkStatusIsSurfaced(): void
    {
        $repository = $this->repository($this->executor('{}', 500));

        $this->expectException(DatabaseException::class);
        $this->expectExceptionMessage('HTTP 500');
        $repository->delete(new PersonRow(7, 'ada', 9.5));
    }

    public function testFindByIdHydratesTheProjectedRow(): void
    {
        $executor = $this->executor('{"data":{"people":[{"id":7,"name":"ada","score":9.5}]}}');

        $found = $this->repository($executor)->findById(7);

        self::assertInstanceOf(PersonRow::class, $found);
        self::assertSame('ada', $found->name);
    }

    public function testWriteOnAReadOnlyRepositoryIsRejected(): void
    {
        $repository = new GraphQLRepository($this->executor('{"data":{}}'), PersonRow::class);

        $this->expectException(InvalidArgumentException::class);
        $repository->save(new PersonRow(1, 'ada'));
    }
}
