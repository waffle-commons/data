<?php

declare(strict_types=1);

namespace Waffle\Commons\Data\Compiler;

use function json_encode;

use const JSON_THROW_ON_ERROR;

/**
 * The API-family output of compiling an SQR
 * {@see \Waffle\Commons\Data\Query\Query} for a GraphQL endpoint: a parameterised
 * query document and the variables that fill its `$`-placeholders.
 *
 * No operand is ever spliced into the document text — every predicate value is a
 * declared GraphQL variable bound separately — so a compiled query is injection-
 * safe regardless of its operands, the same guarantee the relational compiler
 * gives via bound `?` parameters. {@see self::toJson()} renders the standard
 * `{query, variables}` POST body executed through the async `http-client`.
 */
final readonly class CompiledGraphQLQuery
{
    /**
     * @param string $query Executable GraphQL document.
     * @param array<string, int|float|string|bool|null|list<int|float|string|bool|null>> $variables
     *        Operands keyed by the variable name referenced in the document.
     * @param string $root Root field the executor reads the result rows from
     *        (`data.{root}` in the response envelope).
     */
    public function __construct(
        public string $query,
        public array $variables,
        public string $root,
    ) {}

    /**
     * Render the standard GraphQL POST body. An empty variable set is encoded as
     * a JSON object (`{}`), not an array, so it remains a valid `variables` map.
     *
     * @throws \JsonException When the payload cannot be encoded.
     */
    public function toJson(): string
    {
        return json_encode([
            'query' => $this->query,
            'variables' => (object) $this->variables,
        ], JSON_THROW_ON_ERROR);
    }
}
