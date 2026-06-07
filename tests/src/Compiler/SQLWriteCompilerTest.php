<?php

declare(strict_types=1);

namespace WaffleTests\Commons\Data\Compiler;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use Waffle\Commons\Data\Compiler\CompiledWrite;
use Waffle\Commons\Data\Compiler\SQLDialect;
use Waffle\Commons\Data\Compiler\SQLWriteCompiler;
use WaffleTests\Commons\Data\AbstractTestCase;

#[CoversClass(SQLWriteCompiler::class)]
#[CoversClass(CompiledWrite::class)]
final class SQLWriteCompilerTest extends AbstractTestCase
{
    private function compiler(): SQLWriteCompiler
    {
        return new SQLWriteCompiler(SQLDialect::PostgreSQL);
    }

    public function testCompileInsertEmitsParameterisedInsert(): void
    {
        $compiled = $this->compiler()->compileInsert('people', ['id' => 1, 'name' => 'ada', 'score' => 9.5]);

        self::assertSame('INSERT INTO "people" ("id", "name", "score") VALUES (?, ?, ?)', $compiled->sql);
        self::assertSame([1, 'ada', 9.5], $compiled->parameters);
    }

    public function testCompileUpdateExcludesTheIdentityColumnFromTheSetClause(): void
    {
        $compiled = $this->compiler()->compileUpdate('people', ['id' => 7, 'name' => 'ada', 'score' => 9.5], 'id', 7);

        self::assertSame('UPDATE "people" SET "name" = ?, "score" = ? WHERE "id" = ?', $compiled->sql);
        self::assertSame(['ada', 9.5, 7], $compiled->parameters);
    }

    public function testCompileDeleteTargetsTheIdentity(): void
    {
        $compiled = $this->compiler()->compileDelete('people', 'id', 42);

        self::assertSame('DELETE FROM "people" WHERE "id" = ?', $compiled->sql);
        self::assertSame([42], $compiled->parameters);
    }

    public function testInsertRejectsAnEmptyRow(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->compiler()->compileInsert('people', []);
    }

    public function testUpdateRejectsARowWithNoNonIdentityColumn(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('non-identity');
        $this->compiler()->compileUpdate('people', ['id' => 1], 'id', 1);
    }
}
