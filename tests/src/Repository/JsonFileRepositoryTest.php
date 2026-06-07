<?php

declare(strict_types=1);

namespace WaffleTests\Commons\Data\Repository;

use PHPUnit\Framework\Attributes\CoversClass;
use Waffle\Commons\Contracts\Exception\Validation\ValidationExceptionInterface;
use Waffle\Commons\Data\Query\Criteria;
use Waffle\Commons\Data\Query\Query;
use Waffle\Commons\Data\Repository\JsonFileRepository;
use Waffle\Commons\Data\Storage\JsonFileStore;
use WaffleTests\Commons\Data\AbstractTestCase;
use WaffleTests\Commons\Data\Fixture\PersonRow;

use function array_map;
use function bin2hex;
use function glob;
use function is_dir;
use function iterator_to_array;
use function mkdir;
use function random_bytes;
use function rmdir;
use function sys_get_temp_dir;
use function unlink;

#[CoversClass(JsonFileRepository::class)]
final class JsonFileRepositoryTest extends AbstractTestCase
{
    private string $directory;

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = sys_get_temp_dir() . '/waffle-json-repo-' . bin2hex(random_bytes(6));
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
        return $this->directory . '/people.json';
    }

    /**
     * @return JsonFileRepository<PersonRow>
     */
    private function repository(): JsonFileRepository
    {
        return new JsonFileRepository($this->path(), PersonRow::class);
    }

    private function seed(): void
    {
        new JsonFileStore()->write($this->path(), [
            ['id' => 1, 'name' => 'alice', 'score' => 9.5],
            ['id' => 2, 'name' => 'bob', 'score' => null],
            ['id' => 3, 'name' => 'carol', 'score' => 7.25],
        ]);
    }

    public function testFindFiltersSortsAndHydrates(): void
    {
        $this->seed();

        $people = $this->repository()->find(Query::select()->where(Criteria::gt('id', 1))->orderBy('id'));

        self::assertContainsOnlyInstancesOf(PersonRow::class, $people);
        self::assertSame(['bob', 'carol'], array_map(static fn(PersonRow $person): string => $person->name, $people));
        self::assertSame([null, 7.25], array_map(static fn(PersonRow $person): ?float => $person->score, $people));
    }

    public function testFindOnMissingFileReturnsEmptyList(): void
    {
        self::assertSame([], $this->repository()->find(Query::select()));
    }

    public function testFindOneReturnsFirstMatchOrNull(): void
    {
        $this->seed();
        $repository = $this->repository();

        $carol = $repository->findOne(Query::select()->where(Criteria::eq('name', 'carol')));
        self::assertSame(3, $carol?->id);

        self::assertNull($repository->findOne(Query::select()->where(Criteria::eq('name', 'mallory'))));
    }

    public function testStreamYieldsHydratedRows(): void
    {
        $this->seed();

        $people = iterator_to_array($this->repository()->stream(Query::select()->orderBy('id')));

        self::assertContainsOnlyInstancesOf(PersonRow::class, $people);
        self::assertSame([1, 2, 3], array_map(static fn(PersonRow $person): int => $person->id, $people));
    }

    public function testPoisonedRowIsRejectedDuringHydration(): void
    {
        // An integer in the string column must be rejected by the hydration
        // layer, never silently widened.
        new JsonFileStore()->write($this->path(), [['id' => 1, 'name' => 5, 'score' => null]]);

        $this->expectException(ValidationExceptionInterface::class);

        $this->repository()->find(Query::select());
    }
}
