<?php

declare(strict_types=1);

namespace WaffleTests\Commons\Data\Fixture;

use Waffle\Commons\Contracts\Data\Mapper\DataMapperInterface;

/**
 * A mapper whose target collection is blank — used to prove the Firestore scope
 * rejects a path that cannot be isolated (Rule 1).
 *
 * @implements DataMapperInterface<PersonRow>
 */
final class BlankCollectionMapper implements DataMapperInterface
{
    #[\Override]
    public function target(): string
    {
        return '';
    }

    #[\Override]
    public function identityField(): string
    {
        return 'id';
    }

    #[\Override]
    public function fields(): array
    {
        return ['id'];
    }

    #[\Override]
    public function identify(object $entity): int|string|null
    {
        return $entity->id !== 0 ? $entity->id : null;
    }

    #[\Override]
    public function toRow(object $entity): array
    {
        return ['id' => $entity->id, 'name' => $entity->name, 'score' => $entity->score];
    }
}
