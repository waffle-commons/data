<?php

declare(strict_types=1);

namespace WaffleTests\Commons\Data\RateLimit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use Redis;
use RedisException;
use Waffle\Commons\Data\RateLimit\RedisLimiterStateStorage;
use WaffleTests\Commons\Data\AbstractTestCase;
use WaffleTests\Commons\Data\Fixture\LimiterWorker;

/**
 * [NET-01] acceptance, "limiter correct under concurrent multi-worker load (no over-admission beyond bucket
 * size)": separate PHP processes, each on its own Redis connection, take from one bucket at the same
 * instant, and together they are allowed exactly what the bucket holds.
 */
#[CoversClass(RedisLimiterStateStorage::class)]
#[RequiresPhpExtension('redis')]
final class RedisLimiterConcurrencyTest extends AbstractTestCase
{
    private const int WORKERS = 8;

    private const int ATTEMPTS = 100;

    private const int CAPACITY = 50;

    private string $key = '';

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->key = 'test:concurrency:' . bin2hex(random_bytes(6));
    }

    #[\Override]
    protected function tearDown(): void
    {
        self::connect()->del(RedisLimiterStateStorage::DEFAULT_PREFIX . $this->key);

        parent::tearDown();
    }

    public function testConcurrentWorkersAreNeverAllowedMoreThanTheBucketHolds(): void
    {
        self::connect();
        // Far enough ahead for every process to boot and connect, so all of them wait for it.
        $startAt = microtime(true) + 3.0;
        $workers = [];
        for ($worker = 0; $worker < self::WORKERS; $worker++) {
            $workers[] = $this->spawn($startAt);
        }

        $allowed = 0;
        foreach ($workers as [$process, $stdout, $stderr]) {
            $output = (string) stream_get_contents($stdout);
            $errors = (string) stream_get_contents($stderr);
            fclose($stdout);
            fclose($stderr);
            static::assertSame(0, proc_close($process), $errors);

            $fields = explode(' ', trim($output));
            static::assertMatchesRegularExpression('/^\d+$/', $fields[0], $output . $errors);
            static::assertSame(
                '1',
                $fields[1] ?? '',
                'A worker started after the shared instant: the run was not concurrent.',
            );
            $allowed += (int) $fields[0];
        }

        // 800 calls on a 50-token bucket: one more allowed call is an over-admission, one fewer a lost token.
        static::assertSame(self::CAPACITY, $allowed);
    }

    /**
     * @return array{0: resource, 1: resource, 2: resource} The process, its standard output and its errors.
     */
    private function spawn(float $startAt): array
    {
        $code = sprintf(
            'require %s; echo \\%s::run(%s, %s, %d, %d, %s);',
            var_export(dirname(__DIR__, 3) . '/vendor/autoload.php', true),
            LimiterWorker::class,
            var_export(self::host(), true),
            var_export($this->key, true),
            self::CAPACITY,
            self::ATTEMPTS,
            var_export($startAt, true),
        );
        $pipes = [];
        $process = proc_open([PHP_BINARY, '-r', $code], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, [
            'XDEBUG_MODE' => 'off',
        ] + getenv());
        static::assertIsResource($process);
        $stdout = $pipes[1] ?? null;
        $stderr = $pipes[2] ?? null;
        static::assertIsResource($stdout);
        static::assertIsResource($stderr);

        return [$process, $stdout, $stderr];
    }

    private static function host(): string
    {
        $host = getenv('WAFFLE_TEST_REDIS_HOST');

        return is_string($host) && $host !== '' ? $host : 'waffle-redis';
    }

    private static function connect(): Redis
    {
        $redis = new Redis();
        try {
            $redis->connect(self::host(), 6379, 1.5);
        } catch (RedisException) {
            self::markTestSkipped('No reachable Redis instance for the integration test.');
        }

        return $redis;
    }
}
