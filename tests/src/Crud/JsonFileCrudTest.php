<?php

declare(strict_types=1);

namespace WaffleTests\Commons\Data\Crud;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use Waffle\Commons\Data\Repository\JsonFileRepository;
use Waffle\Commons\Data\Storage\JsonFileStore;
use WaffleTests\Commons\Data\AbstractTestCase;
use WaffleTests\Commons\Data\Fixture\PersonMapper;
use WaffleTests\Commons\Data\Fixture\PersonRow;

use function bin2hex;
use function is_dir;
use function mkdir;
use function random_bytes;
use function rmdir;
use function sys_get_temp_dir;
use function unlink;

#[CoversClass(JsonFileRepository::class)]
final class JsonFileCrudTest extends AbstractTestCase
{
    private string $directory;

    private string $path;

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->directory = sys_get_temp_dir() . '/waffle-json-crud-' . bin2hex(random_bytes(6));
        mkdir($this->directory);
        $this->path = $this->directory . '/people.json';
    }

    #[\Override]
    protected function tearDown(): void
    {
        if (is_dir($this->directory)) {
            foreach ((array) glob($this->directory . '/*') as $file) {
                if (!is_string($file)) {
                    continue;
                }

                unlink($file);
            }

            rmdir($this->directory);
        }

        parent::tearDown();
    }

    /**
     * @return JsonFileRepository<PersonRow>
     */
    private function repository(): JsonFileRepository
    {
        return new JsonFileRepository($this->path, PersonRow::class, new JsonFileStore(), new PersonMapper());
    }

    public function testInsertThenFindByIdRoundTrips(): void
    {
        $repository = $this->repository();

        $repository->save(new PersonRow(1, 'ada', 9.5));

        $found = $repository->findById(1);
        self::assertInstanceOf(PersonRow::class, $found);
        self::assertSame('ada', $found->name);
    }

    public function testSaveReplacesAMatchingRow(): void
    {
        $repository = $this->repository();
        $repository->save(new PersonRow(1, 'ada', 9.5));
        $repository->save(new PersonRow(1, 'ada-2', 1.0));

        $all = $repository->find(\Waffle\Commons\Data\Query\Query::select()->from($this->path));
        self::assertCount(1, $all);
        $first = $all[0] ?? null;
        self::assertInstanceOf(PersonRow::class, $first);
        self::assertSame('ada-2', $first->name);
    }

    public function testNullIdentityAppendsANewRow(): void
    {
        $repository = $this->repository();
        $repository->save(new PersonRow(1, 'ada', 9.5));
        $repository->save(new PersonRow(0, 'newcomer', 2.0));

        self::assertCount(2, $repository->find(\Waffle\Commons\Data\Query\Query::select()->from($this->path)));
    }

    public function testDeleteRemovesTheRow(): void
    {
        $repository = $this->repository();
        $repository->save(new PersonRow(1, 'ada', 9.5));
        $repository->save(new PersonRow(2, 'bob', 7.0));

        $repository->delete(new PersonRow(1, 'ada', 9.5));

        self::assertNull($repository->findById(1));
        self::assertInstanceOf(PersonRow::class, $repository->findById(2));
    }

    public function testFindByIdReturnsNullOnMiss(): void
    {
        self::assertNull($this->repository()->findById(404));
    }

    public function testWriteOnAReadOnlyRepositoryIsRejected(): void
    {
        $readOnly = new JsonFileRepository($this->path, PersonRow::class);

        $this->expectException(InvalidArgumentException::class);
        $readOnly->save(new PersonRow(1, 'ada'));
    }

    public function testDeleteWithoutIdentityIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->repository()->delete(new PersonRow(0, 'unsaved'));
    }
}
