<?php

declare(strict_types=1);

namespace WaffleTests\Commons\Data\Warmup;

use phpmock\phpunit\PHPMock;
use PHPUnit\Framework\Attributes\CoversClass;
use Waffle\Commons\Data\Compiler\SQLCompiler;
use Waffle\Commons\Data\Exception\WarmupException;
use Waffle\Commons\Data\Query\Criteria;
use Waffle\Commons\Data\Query\Query;
use Waffle\Commons\Data\Warmup\QueryWarmer;
use WaffleTests\Commons\Data\AbstractTestCase;

use function bin2hex;
use function file_put_contents;
use function is_dir;
use function mkdir;
use function random_bytes;
use function restore_error_handler;
use function rmdir;
use function scandir;
use function set_error_handler;
use function sys_get_temp_dir;
use function unlink;

#[CoversClass(QueryWarmer::class)]
#[CoversClass(WarmupException::class)]
final class QueryWarmerTest extends AbstractTestCase
{
    use PHPMock;

    private const string WARMUP_NAMESPACE = 'Waffle\Commons\Data\Warmup';

    /** @var non-empty-string */
    private string $directory;

    #[\Override]
    public static function setUpBeforeClass(): void
    {
        // php-mock: namespaced fallbacks must exist BEFORE the first unqualified
        // call in the subject namespace, or PHP binds the global for good.
        $functions = [
            'file_put_contents',
            'rename',
            'unlink',
            'is_file',
            'function_exists',
            'ini_get',
            'opcache_compile_file',
        ];
        foreach ($functions as $function) {
            self::defineFunctionMock(self::WARMUP_NAMESPACE, $function);
        }
    }

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = sys_get_temp_dir() . '/waffle-warmup-' . bin2hex(random_bytes(6));
        mkdir($this->directory);
    }

    #[\Override]
    protected function tearDown(): void
    {
        $this->removeTree($this->directory);
        parent::tearDown();
    }

    /** @return non-empty-string */
    private function cacheFile(): string
    {
        return $this->directory . '/data-warmup.php';
    }

    public function testEmptyQueryMapWarmsNothingAndWritesNoFile(): void
    {
        $warmer = new QueryWarmer(queries: [], compiler: new SQLCompiler(), cacheFile: $this->cacheFile());

        static::assertSame([], $warmer->warmUp());
        static::assertFileDoesNotExist($this->cacheFile());
    }

    public function testCompilesNamedQueriesIntoARequirableArtifact(): void
    {
        $warmer = new QueryWarmer(
            queries: [
                'users.active' => Query::select('id', 'email')
                    ->from('users')
                    ->where(Criteria::eq('active', 1))
                    ->orderBy('id')
                    ->limit(10),
            ],
            compiler: new SQLCompiler(),
            cacheFile: $this->cacheFile(),
        );

        $artifacts = $warmer->warmUp();

        static::assertCount(1, $artifacts);
        $descriptor = $artifacts[0] ?? '';
        static::assertStringContainsString('1 compiled SQR tree(s)', $descriptor);
        static::assertStringContainsString($this->cacheFile(), $descriptor);
        static::assertFileExists($this->cacheFile());

        /** @var array<string, array{sql: string, parameters: list<null|bool|float|int|string>}> $compiled */
        $compiled = require $this->cacheFile();
        static::assertArrayHasKey('users.active', $compiled);
        $entry = $compiled['users.active'] ?? ['sql' => '', 'parameters' => []];
        static::assertStringContainsString('SELECT', $entry['sql']);
        static::assertStringContainsString('users', $entry['sql']);
        static::assertSame([1], $entry['parameters']);
    }

    public function testReRunReplacesTheArtifactAtomically(): void
    {
        $warmer = new QueryWarmer(
            queries: ['ping' => Query::select('id')->from('ping')],
            compiler: new SQLCompiler(),
            cacheFile: $this->cacheFile(),
        );

        $warmer->warmUp();
        $warmer->warmUp();

        static::assertFileExists($this->cacheFile());
        static::assertFileDoesNotExist($this->cacheFile() . '.tmp');

        /** @var array<string, array{sql: string, parameters: list<null|bool|float|int|string>}> $compiled */
        $compiled = require $this->cacheFile();
        static::assertArrayHasKey('ping', $compiled);
    }

    public function testCreatesMissingCacheDirectories(): void
    {
        $nested = $this->directory . '/var/cache/data-warmup.php';
        $warmer = new QueryWarmer(
            queries: ['ping' => Query::select('id')->from('ping')],
            compiler: new SQLCompiler(),
            cacheFile: $nested,
        );

        $warmer->warmUp();

        static::assertFileExists($nested);
    }

    public function testUnwritableArtifactFileRaisesWarmupException(): void
    {
        $writes = $this->getFunctionMock(self::WARMUP_NAMESPACE, 'file_put_contents');
        $writes->expects($this->once())->willReturn(false);

        $warmer = new QueryWarmer(
            queries: ['ping' => Query::select('id')->from('ping')],
            compiler: new SQLCompiler(),
            cacheFile: $this->cacheFile(),
        );

        $this->expectException(WarmupException::class);
        $this->expectExceptionMessageMatches('/Unable to write the warmup artifact/');

        $warmer->warmUp();
    }

    public function testFailedAtomicRenameCleansUpAndRaisesWarmupException(): void
    {
        $renames = $this->getFunctionMock(self::WARMUP_NAMESPACE, 'rename');
        $renames->expects($this->once())->willReturn(false);

        $unlinks = $this->getFunctionMock(self::WARMUP_NAMESPACE, 'unlink');
        $unlinks->expects($this->once())->willReturn(true);

        $warmer = new QueryWarmer(
            queries: ['ping' => Query::select('id')->from('ping')],
            compiler: new SQLCompiler(),
            cacheFile: $this->cacheFile(),
        );

        $this->expectException(WarmupException::class);
        $this->expectExceptionMessageMatches('/Unable to publish the warmup artifact/');

        $warmer->warmUp();
    }

    public function testPrimesOpcacheWhenAvailableOnTheCli(): void
    {
        $functionExists = $this->getFunctionMock(self::WARMUP_NAMESPACE, 'function_exists');
        $functionExists->expects($this->once())->with('opcache_compile_file')->willReturn(true);

        $iniGet = $this->getFunctionMock(self::WARMUP_NAMESPACE, 'ini_get');
        $iniGet->expects($this->once())->with('opcache.enable_cli')->willReturn('1');

        $opcacheCompile = $this->getFunctionMock(self::WARMUP_NAMESPACE, 'opcache_compile_file');
        $opcacheCompile->expects($this->once())->with($this->cacheFile())->willReturn(true);

        $warmer = new QueryWarmer(
            queries: ['ping' => Query::select('id')->from('ping')],
            compiler: new SQLCompiler(),
            cacheFile: $this->cacheFile(),
        );

        static::assertCount(1, $warmer->warmUp());
        static::assertFileExists($this->cacheFile());
    }

    public function testUnwritableCacheDirectoryRaisesWarmupException(): void
    {
        // A regular FILE blocks the directory path, so mkdir() cannot succeed.
        // The native E_WARNING is muted locally so only the domain exception
        // surfaces — the production code itself never suppresses errors.
        $blocker = $this->directory . '/blocker';
        file_put_contents($blocker, 'not a directory');

        $warmer = new QueryWarmer(
            queries: ['ping' => Query::select('id')->from('ping')],
            compiler: new SQLCompiler(),
            cacheFile: $blocker . '/nested/data-warmup.php',
        );

        $this->expectException(WarmupException::class);
        $this->expectExceptionMessageMatches('/Unable to create the warmup cache directory/');

        set_error_handler(static fn(): bool => true);

        try {
            $warmer->warmUp();
        } finally {
            restore_error_handler();
        }
    }

    private function removeTree(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        $entries = scandir($directory);
        foreach ($entries === false ? [] : $entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $directory . '/' . $entry;
            if (is_dir($path)) {
                $this->removeTree($path);

                continue;
            }

            unlink($path);
        }

        rmdir($directory);
    }
}
