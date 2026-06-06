<?php

declare(strict_types=1);

namespace WaffleTests\Commons\Data\Driver\KeyValue;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use Redis;
use RedisException;
use Waffle\Commons\Data\Driver\KeyValue\RedisKeyValueClient;
use Waffle\Commons\Data\Query\Criteria;
use Waffle\Commons\Data\Query\Query;
use Waffle\Commons\Data\Repository\KeyValueRepository;
use WaffleTests\Commons\Data\AbstractTestCase;
use WaffleTests\Commons\Data\Fixture\PersonRow;

use function array_map;
use function getenv;
use function is_string;

/**
 * End-to-end proof against the sandbox `waffle-redis` service: real sockets,
 * real `GET`/`MGET`, real hydration. Skipped cleanly when no Redis is reachable
 * (e.g. a bare CI runner), so the suite stays self-contained everywhere.
 */
#[CoversClass(RedisKeyValueClient::class)]
#[CoversClass(KeyValueRepository::class)]
#[RequiresPhpExtension('redis')]
final class RedisKeyValueClientIntegrationTest extends AbstractTestCase
{
    private function connect(): Redis
    {
        $host = getenv('WAFFLE_TEST_REDIS_HOST');
        $redis = new Redis();

        try {
            $redis->connect(is_string($host) && $host !== '' ? $host : 'waffle-redis', 6379, 1.5);
        } catch (RedisException) {
            self::markTestSkipped('No reachable Redis instance for the integration test.');
        }

        return $redis;
    }

    public function testLiveRoundTripThroughTheRepository(): void
    {
        $redis = $this->connect();
        $redis->set('it:people:1', '{"id": 1, "name": "alice", "score": 9.5}');
        $redis->set('it:people:3', '{"id": 3, "name": "carol", "score": null}');
        $redis->del('it:people:2');

        try {
            $repository = new KeyValueRepository(new RedisKeyValueClient($redis), PersonRow::class);

            $people = $repository->find(
                Query::select()
                    ->from('it:people')
                    ->where(Criteria::in('id', [1, 2, 3])),
            );

            self::assertSame([1, 3], array_map(static fn(PersonRow $person): int => $person->id, $people));

            $alice = $repository->findOne(Query::select()->from('it:people')->where(Criteria::eq('id', 1)));
            self::assertSame('alice', $alice?->name);
        } finally {
            $redis->del('it:people:1', 'it:people:3');
        }
    }
}
