<?php

declare(strict_types=1);

namespace WaffleTests\Commons\Data\Fixture;

use Waffle\Commons\Contracts\Data\Mapper\DataMapperInterface;

/**
 * A {@see PersonRow} mapper that always INSERTs with a database-assigned id: the
 * row it emits omits the identity column (so SQLite auto-assigns the rowid) and
 * {@see self::identify()} always returns null. Used by the transaction-affinity
 * integration test so repeated saves never collide on the primary key.
 *
 * @implements DataMapperInterface<PersonRow>
 */
final class AutoIdPersonMapper implements DataMapperInterface
{
    #[\Override]
    public function target(): string
    {
        return 'people';
    }

    #[\Override]
    public function identityField(): string
    {
        return 'id';
    }

    #[\Override]
    public function fields(): array
    {
        return ['name', 'score'];
    }

    #[\Override]
    public function identify(object $entity): int|string|null
    {
        // Always INSERT: the backend assigns the identity.
        return null;
    }

    #[\Override]
    public function toRow(object $entity): array
    {
        // Omit `id` so the column auto-increments and saves never collide.
        return ['name' => $entity->name, 'score' => $entity->score];
    }
}
