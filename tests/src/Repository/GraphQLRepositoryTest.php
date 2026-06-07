<?php

declare(strict_types=1);

namespace WaffleTests\Commons\Data\Repository;

use PHPUnit\Framework\Attributes\CoversClass;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\StreamInterface;
use Waffle\Commons\Contracts\Exception\Validation\ValidationExceptionInterface;
use Waffle\Commons\Data\Driver\Graph\GraphQLExecutor;
use Waffle\Commons\Data\Query\Criteria;
use Waffle\Commons\Data\Query\Query;
use Waffle\Commons\Data\Repository\GraphQLRepository;
use WaffleTests\Commons\Data\AbstractTestCase;
use WaffleTests\Commons\Data\Fixture\PersonRow;

use function array_map;
use function iterator_to_array;

#[CoversClass(GraphQLRepository::class)]
final class GraphQLRepositoryTest extends AbstractTestCase
{
    /**
     * @return GraphQLRepository<PersonRow>
     */
    private function repository(string $responseBody): GraphQLRepository
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

        $executor = new GraphQLExecutor($client, $requestFactory, $streamFactory, 'https://api.example.test/graphql');

        return new GraphQLRepository($executor, PersonRow::class);
    }

    public function testFindHydratesTheRootRows(): void
    {
        $repository = $this->repository(
            '{"data": {"people": [{"id": 1, "name": "alice", "score": 9.5}, {"id": 2, "name": "bob", "score": null}]}}',
        );

        $people = $repository->find(Query::select('id', 'name', 'score')->from('people')->where(Criteria::gt('id', 0)));

        self::assertContainsOnlyInstancesOf(PersonRow::class, $people);
        self::assertSame(['alice', 'bob'], array_map(static fn(PersonRow $person): string => $person->name, $people));
    }

    public function testFindOneReturnsFirstRowOrNull(): void
    {
        $repository = $this->repository('{"data": {"people": [{"id": 1, "name": "alice", "score": null}]}}');

        $alice = $repository->findOne(Query::select('id', 'name', 'score')->from('people'));
        self::assertSame('alice', $alice?->name);

        $empty = $this->repository('{"data": {"people": []}}');
        self::assertNull($empty->findOne(Query::select('id', 'name', 'score')->from('people')));
    }

    public function testStreamYieldsHydratedRows(): void
    {
        $repository = $this->repository('{"data": {"people": [{"id": 3, "name": "carol", "score": 7.25}]}}');

        $people = iterator_to_array($repository->stream(Query::select('id', 'name', 'score')->from('people')));

        self::assertSame([3], array_map(static fn(PersonRow $person): int => $person->id, $people));
    }

    public function testPoisonedRowIsRejectedDuringHydration(): void
    {
        $repository = $this->repository('{"data": {"people": [{"id": 1, "name": 5, "score": null}]}}');

        $this->expectException(ValidationExceptionInterface::class);

        $repository->find(Query::select('id', 'name', 'score')->from('people'));
    }
}
