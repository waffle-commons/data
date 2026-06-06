<?php

declare(strict_types=1);

namespace WaffleTests\Commons\Data\Crud;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use Waffle\Commons\Data\Repository\KeyValueRepository;
use WaffleTests\Commons\Data\AbstractTestCase;
use WaffleTests\Commons\Data\Fixture\InMemoryKeyValueClient;
use WaffleTests\Commons\Data\Fixture\PersonMapper;
use WaffleTests\Commons\Data\Fixture\PersonRow;

#[CoversClass(KeyValueRepository::class)]
final class KeyValueCrudTest extends AbstractTestCase
{
    /**
     * @return KeyValueRepository<PersonRow>
     */
    private function repository(InMemoryKeyValueClient $client): KeyValueRepository
    {
        return new KeyValueRepository($client, PersonRow::class, mapper: new PersonMapper());
    }

    public function testSaveThenFindByIdRoundTrips(): void
    {
        $client = new InMemoryKeyValueClient();
        $repository = $this->repository($client);

        $repository->save(new PersonRow(1, 'ada', 9.5));

        // Stored under the namespaced key the read compiler also forms.
        self::assertSame('{"id":1,"name":"ada","score":9.5}', $client->get('people:1'));

        $found = $repository->findById(1);
        self::assertInstanceOf(PersonRow::class, $found);
        self::assertSame('ada', $found->name);
    }

    public function testDeleteRemovesTheKey(): void
    {
        $client = new InMemoryKeyValueClient();
        $repository = $this->repository($client);
        $repository->save(new PersonRow(1, 'ada', 9.5));

        $repository->delete(new PersonRow(1, 'ada', 9.5));

        self::assertNull($repository->findById(1));
    }

    public function testKeyValueWriteRequiresAnExplicitIdentity(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('explicit identity');
        $this->repository(new InMemoryKeyValueClient())->save(new PersonRow(0, 'no-id'));
    }

    public function testDeleteWithoutIdentityIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->repository(new InMemoryKeyValueClient())->delete(new PersonRow(0, 'no-id'));
    }

    public function testWriteOnAReadOnlyRepositoryIsRejected(): void
    {
        $readOnly = new KeyValueRepository(new InMemoryKeyValueClient(), PersonRow::class);

        $this->expectException(InvalidArgumentException::class);
        $readOnly->save(new PersonRow(1, 'ada'));
    }
}
