<?php

declare(strict_types=1);

namespace Waffle\Commons\Data\Compiler;

use function json_encode;

use const JSON_THROW_ON_ERROR;

/**
 * The API-family output of compiling a write into a GraphQL **mutation**: a
 * parameterised document plus the variables that fill its `$`-placeholders.
 *
 * Unlike {@see CompiledGraphQLQuery} (read), a mutation's variables include the
 * object inputs a write carries (`insert_input`, `_set`, `pk_columns`), so the
 * variable map permits one level of nested scalar maps. As with reads, no
 * operand is ever spliced into the document text — every value is a declared
 * GraphQL variable — keeping the mutation injection-safe.
 */
final readonly class CompiledGraphQLMutation
{
    /**
     * @param string $query Executable GraphQL mutation document.
     * @param array<string, int|float|string|bool|null|array<string, int|float|string|bool|null>> $variables
     */
    public function __construct(
        public string $query,
        public array $variables,
    ) {}

    /**
     * Render the standard `{query, variables}` POST body. An empty variable set
     * is encoded as a JSON object (`{}`), not an array.
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
