<?php

declare(strict_types=1);

namespace WaffleTests\Commons\Data\Fixture;

use Waffle\Commons\Contracts\Data\Mapper\DataMapperInterface;

/**
 * A {@see PersonRow} mapper whose target/identity/column names carry a
 * CQL-breaking double quote (plus a statement-splitting payload on the
 * table name) — used to prove {@see \Waffle\Commons\Data\Repository\CassandraRepository::save()}
 * and {@see \Waffle\Commons\Data\Repository\CassandraRepository::delete()} route
 * identifiers through {@see \Waffle\Commons\Data\Compiler\CassandraCompiler::quoteIdentifier()}
 * (FIX-01) exactly like the read path — which now REJECTS a hostile identifier
 * outright (allow-list, not just escape-then-embed), so the write path must
 * reject it too, instead of splicing it raw into CQL.
 *
 * @implements DataMapperInterface<PersonRow>
 */
final class HostileIdentifierPersonMapper implements DataMapperInterface
{
    #[\Override]
    public function target(): string
    {
        return 'people"; DROP TABLE users; --';
    }

    #[\Override]
    public function identityField(): string
    {
        return 'id"evil';
    }

    #[\Override]
    public function fields(): array
    {
        return ['id"evil', 'name"evil'];
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
        return ['id"evil' => $entity->id, 'name"evil' => $entity->name];
    }
}
