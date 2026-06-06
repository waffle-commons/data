<?php

declare(strict_types=1);

namespace Waffle\Commons\Data\Driver\Graph;

use InvalidArgumentException;
use JsonException;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Waffle\Commons\Data\Compiler\CompiledGraphQLQuery;
use Waffle\Commons\Data\Exception\DatabaseException;
use Waffle\Commons\Data\Hydrator\RowNormaliser;

use function is_array;
use function is_string;
use function json_decode;
use function sprintf;

use const JSON_THROW_ON_ERROR;

/**
 * Live network executor for the GraphQL family (RFC-022 §4.3): POSTs a
 * {@see CompiledGraphQLQuery} as the standard `{query, variables}` body through
 * any PSR-18 client — the framework's async `http-client` in production, a stub
 * in tests — and unwraps the `data.{root}` row list from the response envelope.
 *
 * The executor holds only immutable collaborators (no per-request state) and
 * rethrows every transport or envelope failure as a {@see DatabaseException}
 * per the unified exception strategy (§7.3); a GraphQL `errors` array fails the
 * call rather than being silently ignored.
 */
final class GraphQLExecutor
{
    public function __construct(
        private readonly ClientInterface $client,
        private readonly RequestFactoryInterface $requestFactory,
        private readonly StreamFactoryInterface $streamFactory,
        private readonly string $endpoint,
        private readonly RowNormaliser $normaliser = new RowNormaliser(),
    ) {}

    /**
     * Execute the compiled query and return the raw result rows.
     *
     * @return list<array<string, int|float|string|bool|null>>
     *
     * @throws DatabaseException When the request cannot be encoded or sent, the
     *         endpoint answers a non-200 status or a GraphQL error, or the
     *         response envelope does not carry a flat row list under the root.
     */
    public function execute(CompiledGraphQLQuery $compiled): array
    {
        try {
            $body = $compiled->toJson();
        } catch (JsonException $error) {
            throw DatabaseException::fromThrowable($error, 'Failed to encode the GraphQL request body.');
        }

        try {
            $request = $this->requestFactory
                ->createRequest('POST', $this->endpoint)
                ->withHeader('Content-Type', 'application/json')
                ->withHeader('Accept', 'application/json')
                ->withBody($this->streamFactory->createStream($body));
        } catch (InvalidArgumentException $error) {
            throw DatabaseException::fromThrowable($error, 'Failed to build the GraphQL request.');
        }

        try {
            $response = $this->client->sendRequest($request);
        } catch (ClientExceptionInterface $error) {
            throw DatabaseException::fromThrowable($error, 'GraphQL transport failure.');
        }

        if ($response->getStatusCode() !== 200) {
            throw new DatabaseException(sprintf('GraphQL endpoint answered HTTP %d.', $response->getStatusCode()));
        }

        return $this->rows((string) $response->getBody(), $compiled->root);
    }

    /**
     * @return list<array<string, int|float|string|bool|null>>
     *
     * @throws DatabaseException When the envelope is malformed or carries errors.
     */
    private function rows(string $body, string $root): array
    {
        try {
            $decoded = json_decode($body, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $error) {
            throw DatabaseException::fromThrowable($error, 'GraphQL response is not valid JSON.');
        }

        if (!is_array($decoded)) {
            throw new DatabaseException('GraphQL response must be a JSON object.');
        }

        $errors = $decoded['errors'] ?? null;
        if (is_array($errors) && $errors !== []) {
            throw new DatabaseException(sprintf(
                'GraphQL endpoint returned an error: %s',
                $this->firstErrorMessage($errors),
            ));
        }

        $data = $decoded['data'] ?? null;
        if (!is_array($data)) {
            throw new DatabaseException('GraphQL response carries no data object.');
        }

        $rows = $data[$root] ?? null;
        if (!is_array($rows)) {
            throw new DatabaseException(sprintf('GraphQL data carries no "%s" row list.', $root));
        }

        return $this->normaliser->normaliseAll($rows);
    }

    /**
     * @param array<array-key, mixed> $errors
     */
    private function firstErrorMessage(array $errors): string
    {
        $first = $errors[0] ?? null;
        if (is_array($first)) {
            $message = $first['message'] ?? null;
            if (is_string($message)) {
                return $message;
            }
        }

        return 'unknown error';
    }
}
