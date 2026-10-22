<?php

declare(strict_types=1);

namespace Waterline\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

require_once dirname(__DIR__, 2).'/docker/verify-brotli.php';

final class ServiceImageBrotliTest extends TestCase
{
    /** @dataProvider versions */
    #[DataProvider('versions')]
    public function testDecoderFloorRejectsUnpatchedAndUnknownVersions(string $version, bool $allowed): void
    {
        if (!$allowed) {
            $this->expectException(RuntimeException::class);
        }
        $this->assertSame(preg_replace('~^brotli/~', '', $version), \requirePatchedBrotli('test decoder', $version));
    }

    public static function versions(): array
    {
        return [
            ['1.0.9', false], ['1.1.0', false], ['1.2.0', false],
            ['1.2.1', true], ['brotli/1.2.1', true], ['1.3.0', true],
            ['', false], ['1.2.1-extra', false], ['unknown', false],
        ];
    }

    public function testBothStagesUpgradeBeforeClientsAndRecheckFinalRuntime(): void
    {
        $stages = explode("\nFROM ", (string) file_get_contents(dirname(__DIR__, 2).'/Dockerfile'));
        foreach ($stages as $stage) {
            $upgrade = strpos($stage, "apk add --no-cache --upgrade 'brotli-libs>=1.2.1-r0'");
            $guard = strpos($stage, 'php /usr/local/share/waterline/verify-brotli.php');
            $this->assertIsInt($upgrade);
            $this->assertIsInt($guard);
            $this->assertLessThan($guard, $upgrade);
        }
        $this->assertLessThan(strpos($stages[0], 'RUN composer install'), strpos($stages[0], 'php /usr/local/share/waterline/verify-brotli.php'));
        $this->assertGreaterThan(strpos($stages[1], 'apk del .build-deps'), strrpos($stages[1], 'php /usr/local/share/waterline/verify-brotli.php'));
    }

    /** @dataProvider imageVersions */
    #[DataProvider('imageVersions')]
    public function testHistoricalSmokeCannotBeMistakenForDecoderQualification(string $version, int $expected): void
    {
        $helper = dirname(__DIR__, 2).'/scripts/ci/service-image-features.sh';
        $process = proc_open(['sh', '-c', '. "$1"; image_requires_brotli_guard "$2"', '--', $helper, $version], [], $pipes);
        $this->assertIsResource($process);
        $this->assertSame($expected, proc_close($process));
    }

    public static function imageVersions(): array
    {
        return [
            ['0.0.0-service-smoke', 0], ['2.12.2', 1], ['2.12.3', 0],
            ['2.12.10', 0], ['2.13.0-rc.1', 0], ['2.0.0-rc.32', 1],
            ['unknown', 2], ['2.12.bad', 2], ['2.012.3', 2],
        ];
    }
}
