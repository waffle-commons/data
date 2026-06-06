<?php

declare(strict_types=1);

namespace Waffle\Commons\Data\Driver\Firestore;

use InvalidArgumentException;
use JsonException;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Waffle\Commons\Data\Exception\DatabaseException;
use Waffle\Commons\Data\Hydrator\RowNormaliser;

use function is_array;
use function is_string;
use function json_decode;
use function json_encode;
use function sprintf;

use const JSON_THROW_ON_ERROR;

/**
 * Live transport for {@see FirestoreClientInterface} (RFC-022 §4.2): a thin REST
 * boundary that POSTs each operation as a JSON envelope to a Firestore client or
 * raw NoSQL proxy through any PSR-18 client — the framework's async `http-client`
 * in production, a stub in tests.
 *
 * The adapter holds only immutable collaborators (no per-request state) and
 * rethrows every transport or envelope failure as a {@see DatabaseException}
 * per the unified exception strategy (§7.3). It is deliberately dumb: it never
 * builds a compound or sorted query — that contract is enforced upstream by the
 * repository + compiler (Rule 2).
 */
final class FirestoreRestClient implements FirestoreClientInterface
{
    public function __construct(
        private readonly ClientInterface $client,
        private readonly RequestFactoryInterface $requestFactory,
        private readonly StreamFactoryInterface $streamFactory,
        private readonly string $endpoint,
        private readonly RowNormaliser $normaliser = new RowNormaliser(),
    ) {}

    /**
     * @return array<string, int|float|string|bool|null>|null
     *
     * @throws DatabaseException When the backend call fails.
     */
    #[\Override]
    public function getDocument(string $path, string $id): ?array
    {
        $envelope = $this->send(['op' => 'get', 'path' => $path, 'id' => $id]);

        $document = $envelope['document'] ?? null;
        if ($document === null) {
            return null;
        }

        if (!is_array($document)) {
            throw new DatabaseException('Firestore "get" response carries a non-object document.');
        }

        return $this->normaliser->normalise($document);
    }

    /**
     * @param list<array{field: string, op: string, value: int|float|string|bool|null}> $filters
     *
     * @return list<array<string, int|float|string|bool|null>>
     *
     * @throws DatabaseException When the backend call fails.
     */
    #[\Override]
    public function queryCollection(string $path, array $filters, ?int $limit): array
    {
        $envelope = $this->send(['op' => 'query', 'path' => $path, 'filters' => $filters, 'limit' => $limit]);

        $documents = $envelope['documents'] ?? null;
        if (!is_array($documents)) {
            throw new DatabaseException('Firestore "query" response carries no "documents" list.');
        }

        return $this->normaliser->normaliseAll($documents);
    }

    /**
     * @param array<string, int|float|string|bool|null> $row
     *
     * @throws DatabaseException When the backend call fails.
     */
    #[\Override]
    public function setDocument(string $path, ?string $id, array $row): string
    {
        $envelope = $this->send(['op' => 'set', 'path' => $path, 'id' => $id, 'data' => $row]);

        $assignedId = $envelope['id'] ?? null;
        if (!is_string($assignedId) || $assignedId === '') {
            throw new DatabaseException('Firestore "set" response carries no document id.');
        }

        return $assignedId;
    }

    /**
     * @throws DatabaseException When the backend call fails.
     */
    #[\Override]
    public function deleteDocument(string $path, string $id): void
    {
        $this->send(['op' => 'delete', 'path' => $path, 'id' => $id]);
    }

    /**
     * POST a JSON command envelope and return the decoded response object.
     *
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     *
     * @throws DatabaseException When the request cannot be encoded or sent, the
     *         endpoint answers a non-200 status, or the response is not a JSON object.
     */
    private function send(array $payload): array
    {
        try {
            $body = json_encode($payload, JSON_THROW_ON_ERROR);
        } catch (JsonException $error) {
            throw DatabaseException::fromThrowable($error, 'Failed to encode the Firestore request body.');
        }

        try {
            $request = $this->requestFactory
                ->createRequest('POST', $this->endpoint)
                ->withHeader('Content-Type', 'application/json')
                ->withHeader('Accept', 'application/json')
                ->withBody($this->streamFactory->createStream($body));
        } catch (InvalidArgumentException $error) {
            throw DatabaseException::fromThrowable($error, 'Failed to build the Firestore request.');
        }

        try {
            $response = $this->client->sendRequest($request);
        } catch (ClientExceptionInterface $error) {
            throw DatabaseException::fromThrowable($error, 'Firestore transport failure.');
        }

        if ($response->getStatusCode() !== 200) {
            throw new DatabaseException(sprintf('Firestore endpoint answered HTTP %d.', $response->getStatusCode()));
        }

        try {
            $decoded = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $error) {
            throw DatabaseException::fromThrowable($error, 'Firestore response is not valid JSON.');
        }

        if (!is_array($decoded)) {
            throw new DatabaseException('Firestore response must be a JSON object.');
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }
}
