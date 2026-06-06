<?php

declare(strict_types=1);

namespace Waffle\Commons\Data\Compiler;

use InvalidArgumentException;

use function is_int;
use function preg_match;
use function sprintf;

/**
 * Compiles a mapper row + identity into a parameterised GraphQL
 * {@see CompiledGraphQLMutation}, following the Hasura mutation convention the
 * read {@see GraphQLCompiler} already assumes (`_eq` filters, auto-derived root
 * fields):
 *
 *  - insert → `insert_{target}_one(object: $object)`
 *  - update → `update_{target}_by_pk(pk_columns: $pk, _set: $set)`
 *  - delete → `delete_{target}_by_pk({idField}: $id)`
 *
 * Every value travels as a declared variable (never inlined). The pk scalar type
 * is inferred from the PHP identity (`Int` / `String`) — adequate for the common
 * `Int`/`String`/`uuid` keys; a deployment with an exotic key type can wrap this
 * compiler.
 */
final class GraphQLMutationCompiler
{
    private const string NAME_PATTERN = '/^[_A-Za-z][_0-9A-Za-z]*$/';

    /**
     * @param array<string, int|float|string|bool|null> $row
     *
     * @throws InvalidArgumentException When the target or identity field is not a valid GraphQL name.
     */
    public function compileInsert(string $target, string $idField, array $row): CompiledGraphQLMutation
    {
        $this->assertName($target, 'target');
        $this->assertName($idField, 'identity field');

        $query = sprintf(
            'mutation Insert($object: %1$s_insert_input!) { insert_%1$s_one(object: $object) { %2$s } }',
            $target,
            $idField,
        );

        return new CompiledGraphQLMutation($query, ['object' => $row]);
    }

    /**
     * @param array<string, int|float|string|bool|null> $row
     *
     * @throws InvalidArgumentException When the target or identity field is not a valid GraphQL name.
     */
    public function compileUpdate(string $target, string $idField, int|string $id, array $row): CompiledGraphQLMutation
    {
        $this->assertName($target, 'target');
        $this->assertName($idField, 'identity field');

        $query = sprintf(
            'mutation Update($pk: %1$s_pk_columns_input!, $set: %1$s_set_input!) '
            . '{ update_%1$s_by_pk(pk_columns: $pk, _set: $set) { %2$s } }',
            $target,
            $idField,
        );

        return new CompiledGraphQLMutation($query, ['pk' => [$idField => $id], 'set' => $row]);
    }

    /**
     * @throws InvalidArgumentException When the target or identity field is not a valid GraphQL name.
     */
    public function compileDelete(string $target, string $idField, int|string $id): CompiledGraphQLMutation
    {
        $this->assertName($target, 'target');
        $this->assertName($idField, 'identity field');

        $query = sprintf(
            'mutation Delete($id: %1$s!) { delete_%2$s_by_pk(%3$s: $id) { %3$s } }',
            is_int($id) ? 'Int' : 'String',
            $target,
            $idField,
        );

        return new CompiledGraphQLMutation($query, ['id' => $id]);
    }

    /**
     * @throws InvalidArgumentException When the name is not a valid GraphQL name.
     */
    private function assertName(string $name, string $label): void
    {
        if (preg_match(self::NAME_PATTERN, $name) !== 1) {
            throw new InvalidArgumentException(sprintf('Invalid GraphQL %s name "%s".', $label, $name));
        }
    }
}
