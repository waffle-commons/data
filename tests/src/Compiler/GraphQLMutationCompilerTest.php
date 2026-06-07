<?php

declare(strict_types=1);

namespace WaffleTests\Commons\Data\Compiler;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use Waffle\Commons\Data\Compiler\CompiledGraphQLMutation;
use Waffle\Commons\Data\Compiler\GraphQLMutationCompiler;
use WaffleTests\Commons\Data\AbstractTestCase;

#[CoversClass(GraphQLMutationCompiler::class)]
#[CoversClass(CompiledGraphQLMutation::class)]
final class GraphQLMutationCompilerTest extends AbstractTestCase
{
    private function compiler(): GraphQLMutationCompiler
    {
        return new GraphQLMutationCompiler();
    }

    public function testCompileInsertBindsTheObjectVariable(): void
    {
        $mutation = $this->compiler()->compileInsert('people', 'id', ['id' => 1, 'name' => 'ada']);

        self::assertStringContainsString('insert_people_one(object: $object)', $mutation->query);
        self::assertSame(['object' => ['id' => 1, 'name' => 'ada']], $mutation->variables);
    }

    public function testCompileUpdateBindsPkAndSetVariables(): void
    {
        $mutation = $this->compiler()->compileUpdate('people', 'id', 7, ['name' => 'ada']);

        self::assertStringContainsString('update_people_by_pk(pk_columns: $pk, _set: $set)', $mutation->query);
        self::assertSame(['pk' => ['id' => 7], 'set' => ['name' => 'ada']], $mutation->variables);
    }

    public function testCompileDeleteTypesTheIdVariableFromThePhpType(): void
    {
        $intDelete = $this->compiler()->compileDelete('people', 'id', 7);
        self::assertStringContainsString('mutation Delete($id: Int!)', $intDelete->query);
        self::assertStringContainsString('delete_people_by_pk(id: $id)', $intDelete->query);
        self::assertSame(['id' => 7], $intDelete->variables);

        $stringDelete = $this->compiler()->compileDelete('account', 'uuid', 'abc');
        self::assertStringContainsString('mutation Delete($id: String!)', $stringDelete->query);
        self::assertStringContainsString('delete_account_by_pk(uuid: $id)', $stringDelete->query);
    }

    public function testToJsonRendersTheStandardEnvelope(): void
    {
        $json = $this->compiler()->compileInsert('people', 'id', ['name' => 'ada'])->toJson();

        self::assertJsonStringEqualsJsonString(
            '{"query":"mutation Insert($object: people_insert_input!) '
            . '{ insert_people_one(object: $object) { id } }","variables":{"object":{"name":"ada"}}}',
            $json,
        );
    }

    public function testInvalidTargetNameIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->compiler()->compileInsert('not a name', 'id', ['name' => 'ada']);
    }

    public function testInvalidIdentityFieldNameIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->compiler()->compileDelete('people', 'bad-field', 1);
    }
}
