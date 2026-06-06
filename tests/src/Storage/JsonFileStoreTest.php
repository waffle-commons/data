<?php

declare(strict_types=1);

namespace WaffleTests\Commons\Data\Storage;

use PHPUnit\Framework\Attributes\CoversClass;
use Waffle\Commons\Data\Exception\DatabaseException;
use Waffle\Commons\Data\Query\Criteria;
use Waffle\Commons\Data\Query\Query;
use Waffle\Commons\Data\Storage\JsonFileStore;
use WaffleTests\Commons\Data\AbstractTestCase;

use function bin2hex;
use function file_put_contents;
use function glob;
use function is_dir;
use function mkdir;
use function random_bytes;
use function rmdir;
use function sys_get_temp_dir;
use function unlink;

#[CoversClass(JsonFileStore::class)]
final class JsonFileStoreTest extends AbstractTestCase
{
    private string $directory;

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = sys_get_temp_dir() . '/waffle-json-store-' . bin2hex(random_bytes(6));
        mkdir($this->directory);
    }

    #[\Override]
    protected function tearDown(): void
    {
        if (is_dir($this->directory)) {
            $files = glob($this->directory . '/*');
            foreach ($files === false ? [] : $files as $file) {
                unlink($file);
            }

            rmdir($this->directory);
        }

        parent::tearDown();
    }

    private function path(): string
    {
        return $this->directory . '/store.json';
    }

    public function testWriteThenReadRoundTripsRows(): void
    {
        // Note: whole-number floats (7.0) are excluded on purpose — JSON has a
        // single number type, so they encode as `7` and read back as int.
        $rows = [
            ['id' => 1, 'name' => 'alice', 'active' => true, 'score' => 9.5, 'deleted_at' => null],
            ['id' => 2, 'name' => 'bob', 'active' => false, 'score' => 7.25, 'deleted_at' => null],
        ];

        $store = new JsonFileStore();
        $store->write($this->path(), $rows);

        self::assertSame($rows, $store->read($this->path()));
    }

    public function testReadMissingFileReturnsEmptyList(): void
    {
        self::assertSame([], new JsonFileStore()->read($this->path()));
    }

    public function testReadEmptyFileReturnsEmptyList(): void
    {
        file_put_contents($this->path(), '');

        self::assertSame([], new JsonFileStore()->read($this->path()));
    }

    public function testQueryFiltersSortsAndPaginatesInMemory(): void
    {
        $store = new JsonFileStore();
        $store->write($this->path(), [
            ['id' => 1, 'age' => 30],
            ['id' => 2, 'age' => 25],
            ['id' => 3, 'age' => 35],
        ]);

        $result = $store->query(
            $this->path(),
            Query::select()->where(Criteria::gt('age', 26))->orderBy('age')->limit(1),
        );

        self::assertSame([['id' => 1, 'age' => 30]], $result);
    }

    public function testWriteLeavesNoTemporaryFileBehind(): void
    {
        new JsonFileStore()->write($this->path(), [['id' => 1]]);

        self::assertSame([$this->path()], glob($this->directory . '/*'));
    }

    public function testWriteReplacesPreviousContentAtomically(): void
    {
        $store = new JsonFileStore();
        $store->write($this->path(), [['id' => 1]]);
        $store->write($this->path(), [['id' => 2]]);

        self::assertSame([['id' => 2]], $store->read($this->path()));
    }

    public function testUnencodableRowsAreRejected(): void
    {
        $this->expectException(DatabaseException::class);

        // An invalid UTF-8 byte sequence cannot be JSON-encoded.
        new JsonFileStore()->write($this->path(), [['bad' => "\xB1\x31"]]);
    }

    public function testMissingDirectoryIsRejected(): void
    {
        $this->expectException(DatabaseException::class);

        new JsonFileStore()->write($this->directory . '/absent/store.json', [['id' => 1]]);
    }

    public function testInvalidJsonIsRejected(): void
    {
        file_put_contents($this->path(), '{not json');

        $this->expectException(DatabaseException::class);

        new JsonFileStore()->read($this->path());
    }

    public function testNonArrayTopLevelIsRejected(): void
    {
        file_put_contents($this->path(), '42');

        $this->expectException(DatabaseException::class);

        new JsonFileStore()->read($this->path());
    }

    public function testNonObjectRowIsRejected(): void
    {
        file_put_contents($this->path(), '[1, 2]');

        $this->expectException(DatabaseException::class);

        new JsonFileStore()->read($this->path());
    }

    public function testNestedRowValueIsRejected(): void
    {
        file_put_contents($this->path(), '[{"a": [1, 2]}]');

        $this->expectException(DatabaseException::class);

        new JsonFileStore()->read($this->path());
    }
}
