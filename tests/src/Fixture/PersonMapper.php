<?php

declare(strict_types=1);

namespace WaffleTests\Commons\Data\Fixture;

use Waffle\Commons\Contracts\Data\Mapper\DataMapperInterface;

/**
 * Pure Data Mapper for {@see PersonRow} used across the CRUD tests.
 *
 * Convention: an `id` of `0` means "not yet persisted", so {@see self::identify()}
 * returns null for it — that is how a `save()` chooses INSERT over UPDATE.
 *
 * @implements DataMapperInterface<PersonRow>
 */
final class PersonMapper implements DataMapperInterface
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
        return ['id', 'name', 'score'];
    }

    #[\Override]
    public function identify(object $entity): int|string|null
    {
        // T is bound to PersonRow; an id of 0 means "not yet persisted" ⇒ INSERT.
        return $entity->id !== 0 ? $entity->id : null;
    }

    #[\Override]
    public function toRow(object $entity): array
    {
        return ['id' => $entity->id, 'name' => $entity->name, 'score' => $entity->score];
    }
}
