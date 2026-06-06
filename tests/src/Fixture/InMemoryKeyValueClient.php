<?php

declare(strict_types=1);

namespace WaffleTests\Commons\Data\Fixture;

use Waffle\Commons\Data\Driver\KeyValue\KeyValueClientInterface;

/**
 * Deterministic key-value transport over a plain array — a hit returns the
 * stored JSON document, a miss returns null, exactly like the live adapters.
 */
final class InMemoryKeyValueClient implements KeyValueClientInterface
{
    /**
     * @param array<string, string> $items
     */
    public function __construct(
        private readonly array $items = [],
    ) {}

    #[\Override]
    public function get(string $key): ?string
    {
        return $this->items[$key] ?? null;
    }

    #[\Override]
    public function getMany(array $keys): array
    {
        $values = [];
        foreach ($keys as $key) {
            $values[$key] = $this->items[$key] ?? null;
        }

        return $values;
    }
}
