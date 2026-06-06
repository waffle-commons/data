<?php

declare(strict_types=1);

namespace Waffle\Commons\Data\Compiler;

/**
 * The key-value output of compiling an SQR
 * {@see \Waffle\Commons\Data\Query\Query} for a key-value store (Redis,
 * DynamoDB): an {@see KeyValueOperation} and the fully-qualified keys it targets.
 *
 * A key-value store has no field-level filter or projection semantics, so the
 * compiled command never carries predicates, ordering, or a projection — only
 * the namespace it was scoped to and the resolved keys, each prefixed with that
 * namespace (`{namespace}:{value}`) so distinct logical collections never
 * collide in a shared keyspace.
 */
final readonly class CompiledKeyValueCommand
{
    /**
     * @param KeyValueOperation $operation Single (`GET`) or multi (`MGET`) lookup.
     * @param string $namespace Logical collection the keys are scoped to.
     * @param non-empty-list<string> $keys Fully-qualified, namespace-prefixed keys.
     */
    public function __construct(
        public KeyValueOperation $operation,
        public string $namespace,
        public array $keys,
    ) {}
}
