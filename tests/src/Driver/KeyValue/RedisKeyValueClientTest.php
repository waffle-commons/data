<?php

declare(strict_types=1);

namespace WaffleTests\Commons\Data\Driver\KeyValue;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use Redis;
use RedisException;
use Waffle\Commons\Data\Driver\KeyValue\RedisKeyValueClient;
use Waffle\Commons\Data\Exception\DatabaseException;
use WaffleTests\Commons\Data\AbstractTestCase;

#[CoversClass(RedisKeyValueClient::class)]
#[RequiresPhpExtension('redis')]
final class RedisKeyValueClientTest extends AbstractTestCase
{
    public function testGetReturnsTheStoredString(): void
    {
        $redis = $this->createMock(Redis::class);
        $redis->expects($this->once())->method('get')->with('people:1')->willReturn('{"id": 1}');

        self::assertSame('{"id": 1}', new RedisKeyValueClient($redis)->get('people:1'));
    }

    public function testGetMapsAMissToNull(): void
    {
        $redis = $this->createMock(Redis::class);
        $redis->expects($this->once())->method('get')->with('people:404')->willReturn(false);

        self::assertNull(new RedisKeyValueClient($redis)->get('people:404'));
    }

    public function testGetWrapsDriverFailures(): void
    {
        $redis = $this->createMock(Redis::class);
        $redis->expects($this->once())->method('get')->willThrowException(new RedisException('socket gone'));

        $this->expectException(DatabaseException::class);

        new RedisKeyValueClient($redis)->get('people:1');
    }

    public function testGetManyKeysHitsAndMissesByRequestedKey(): void
    {
        $redis = $this->createMock(Redis::class);
        $redis
            ->expects($this->once())
            ->method('mGet')
            ->with(['a', 'b', 'c'])
            ->willReturn(['{"id": 1}', false, '{"id": 3}']);

        $values = new RedisKeyValueClient($redis)->getMany(['a', 'b', 'c']);

        self::assertSame(['a' => '{"id": 1}', 'b' => null, 'c' => '{"id": 3}'], $values);
    }

    public function testGetManyRejectsAnUnexpectedAnswerShape(): void
    {
        $redis = $this->createMock(Redis::class);
        $redis->expects($this->once())->method('mGet')->willReturn(false);

        $this->expectException(DatabaseException::class);

        new RedisKeyValueClient($redis)->getMany(['a']);
    }

    public function testGetManyWrapsDriverFailures(): void
    {
        $redis = $this->createMock(Redis::class);
        $redis->expects($this->once())->method('mGet')->willThrowException(new RedisException('socket gone'));

        $this->expectException(DatabaseException::class);

        new RedisKeyValueClient($redis)->getMany(['a']);
    }

    public function testSetStoresTheValue(): void
    {
        $redis = $this->createMock(Redis::class);
        $redis->expects($this->once())->method('set')->with('people:1', '{"id":1}')->willReturn(true);

        new RedisKeyValueClient($redis)->set('people:1', '{"id":1}');
    }

    public function testSetWrapsDriverFailures(): void
    {
        $redis = $this->createMock(Redis::class);
        $redis->expects($this->once())->method('set')->willThrowException(new RedisException('socket gone'));

        $this->expectException(DatabaseException::class);
        $this->expectExceptionMessage('Redis SET failed.');

        new RedisKeyValueClient($redis)->set('people:1', '{}');
    }

    public function testDeleteRemovesTheKey(): void
    {
        $redis = $this->createMock(Redis::class);
        $redis->expects($this->once())->method('del')->with('people:1')->willReturn(1);

        new RedisKeyValueClient($redis)->delete('people:1');
    }

    public function testDeleteWrapsDriverFailures(): void
    {
        $redis = $this->createMock(Redis::class);
        $redis->expects($this->once())->method('del')->willThrowException(new RedisException('socket gone'));

        $this->expectException(DatabaseException::class);
        $this->expectExceptionMessage('Redis DEL failed.');

        new RedisKeyValueClient($redis)->delete('people:1');
    }
}
