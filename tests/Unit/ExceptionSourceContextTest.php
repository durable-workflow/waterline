<?php

namespace Waterline\Tests\Unit;

use Illuminate\Http\Request;
use Waterline\Support\ExceptionSourceContext;
use Waterline\Tests\TestCase;
use Waterline\Waterline;

final class ExceptionSourceContextTest extends TestCase
{
    private array $paths = [];

    protected function requiresDatabaseMigrations(): bool
    {
        return false;
    }

    protected function tearDown(): void
    {
        foreach (array_reverse($this->paths) as $path) {
            if (is_file($path) || is_link($path)) {
                chmod($path, 0600);
                unlink($path);
            }
        }
        parent::tearDown();
    }

    public function testSourceAtStartMiddleAndEndKeepsActualLineNumbersAndTrace(): void
    {
        $path = $this->fixture(implode("\n", range(1, 10)));
        foreach ([1 => [1, 4], 5 => [2, 8], 10 => [7, 10]] as $line => [$start, $end]) {
            $result = $this->annotate($path, $line);
            self::assertSame('available', $result['source_context']['status']);
            self::assertSame(implode("\n", range($start, $end)), $result['source_context']['excerpt']);
            self::assertSame($start, $result['source_context']['start_line']);
            self::assertSame($line, $result['source_context']['throw_line']);
            self::assertSame('trace preview', $result['trace_preview']);
            self::assertSame('trace preview', $result['code']);
            self::assertSame([['function' => 'failed']], $result['exception']['trace']);
        }
    }

    public function testUntrustedPathsAndInvalidFilesNeverBecomeSource(): void
    {
        $valid = $this->fixture("<?php\nthrow new Exception();");
        $outside = $this->fixture('secret outside application', '.php', sys_get_temp_dir());
        $link = $this->fixture('');
        unlink($link);
        symlink($outside, $link);
        $unreadable = $this->fixture('not readable');
        chmod($unreadable, 0000);
        $directory = dirname($valid);
        $forgedConfig = $this->fixture('<?php return ["secret" => "fixture"];', '.php', app()->bootstrapPath('cache'));
        foreach ([
            [$valid, 0], [$valid, -1], [$valid, '1'], [$valid, 3], [$valid, PHP_INT_MAX],
            ['relative.php', 1], [$valid."\0", 1], ['php://filter/resource='.$valid, 1],
            ['file://'.$valid, 1], [$directory.'/../app/'.basename($valid), 1],
            [$valid.'.missing', 1], [$outside, 1], [$link, 1], [$unreadable, 1], [$directory, 1],
            [$forgedConfig, 1], [$this->fixture('<?php', '.txt'), 1],
            [$this->fixture(str_repeat('x', 1048577)), 1],
            [$this->fixture(str_repeat('x', 4097)), 1],
            [$this->fixture("<?php\n\xff"), 1], [$this->fixture("<?php\n\0"), 1],
        ] as [$path, $line]) {
            $result = $this->annotate($path, $line);
            self::assertSame('unavailable', $result['source_context']['status'], (string) $path);
            self::assertNull($result['source_context']['excerpt']);
            self::assertSame('trace preview', $result['trace_preview']);
        }
    }

    public function testAdditionalRootsRequireExplicitConfiguration(): void
    {
        $path = $this->fixture('<?php explicit package source', '.php', sys_get_temp_dir());
        self::assertSame('unavailable', $this->annotate($path, 1)['source_context']['status']);
        config()->set('waterline.source_context.additional_roots', [dirname($path)]);
        self::assertSame('available', $this->annotate($path, 1)['source_context']['status']);
    }

    public function testDisabledUnauthenticatedAndServiceModesDoNotReadMatchingLocalSource(): void
    {
        $path = $this->fixture('local source must not leak');
        config()->set('waterline.source_context.enabled', false);
        self::assertSame('disabled', $this->annotate($path, 1)['source_context']['reason']);
        config()->set('waterline.source_context.enabled', true);
        config()->set('waterline.allow_unauthenticated', true);
        self::assertSame('unauthenticated', $this->annotate($path, 1)['source_context']['reason']);
        config()->set('waterline.allow_unauthenticated', false);
        Waterline::auth(static fn () => false);
        self::assertSame('unauthenticated', $this->annotate($path, 1)['source_context']['reason']);
        Waterline::auth(static fn () => true);
        config()->set('waterline.backend', 'service');
        self::assertSame('unsupported_mode', $this->annotate($path, 1)['source_context']['reason']);
    }

    public function testResponseBudgetsBoundRepeatedExceptionsAndTotalReadAndExcerptBytes(): void
    {
        $small = $this->fixture('<?php throw new Exception();');
        $large = $this->fixture(str_repeat(str_repeat('x', 4095)."\n", 256));
        $excerpt = $this->fixture(str_repeat(str_repeat('x', 2047)."\n", 7));
        foreach ([[$small, 1, 21, 20], [$large, 1, 3, 2], [$excerpt, 4, 6, 4]] as [$path, $line, $count, $available]) {
            $rows = array_fill(0, $count, ['exception' => ['file' => $path, 'line' => $line], 'code' => 'trace']);
            $result = ExceptionSourceContext::annotate(['exceptions' => $rows], Request::create('/'));
            self::assertCount($available, array_filter($result['exceptions'], static fn ($row) => $row['source_context']['status'] === 'available'));
            self::assertSame('unavailable', $result['exceptions'][$available]['source_context']['status']);
        }
    }

    public function testOversizedExcerptIsUnavailableAndHostileSourceRemainsRawData(): void
    {
        $oversized = $this->fixture(str_repeat(str_repeat('x', 4095)."\n", 7));
        self::assertSame('unavailable', $this->annotate($oversized, 4)['source_context']['status']);
        $hostile = '<script>alert(1)</script><img src=x onerror=alert(2)>';
        self::assertSame($hostile, $this->annotate($this->fixture($hostile), 1)['source_context']['excerpt']);
    }

    private function annotate(mixed $path, mixed $line): array
    {
        return ExceptionSourceContext::annotate(['exceptions' => [[
            'code' => 'trace preview',
            'exception' => ['file' => $path, 'line' => $line, 'trace' => [['function' => 'failed']]],
        ]]], Request::create('/'))['exceptions'][0];
    }

    private function fixture(string $text, string $suffix = '.php', ?string $directory = null): string
    {
        $directory ??= app_path();
        if (! is_dir($directory)) {
            mkdir($directory, 0700, true);
        }
        $path = $directory.'/source-context-'.bin2hex(random_bytes(8)).$suffix;
        file_put_contents($path, $text);
        $this->paths[] = $path;

        return $path;
    }
}
