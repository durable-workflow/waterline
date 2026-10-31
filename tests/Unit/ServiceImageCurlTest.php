<?php

declare(strict_types=1);

namespace Waterline\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

require_once dirname(__DIR__, 2).'/docker/verify-curl.php';

final class ServiceImageCurlTest extends TestCase
{
    /** @dataProvider versions */
    #[DataProvider('versions')]
    public function testCurlFloorRejectsUnpatchedAndUnknownVersions(string $version, bool $allowed): void
    {
        if (!$allowed) {
            $this->expectException(RuntimeException::class);
        }
        $this->assertSame($version, \requirePatchedCurl('test client', $version));
    }

    public static function versions(): array
    {
        return [
            ['8.12.0', false], ['8.22.0', false], ['8.22.9', false],
            ['8.23.0', true], ['8.23.1', true], ['9.0.0', true],
            ['', false], ['8.23.0-dev', false], ['unknown', false],
        ];
    }

    public function testBothStagesUpgradeBeforeClientsAndRecheckFinalRuntime(): void
    {
        $stages = explode("\nFROM ", (string) file_get_contents(dirname(__DIR__, 2).'/Dockerfile'));
        foreach ($stages as $stage) {
            $upgrade = strpos($stage, "apk add --no-cache --upgrade 'curl>=8.23.0-r0' 'libcurl>=8.23.0-r0'");
            $guard = strpos($stage, 'php /usr/local/share/waterline/verify-curl.php');
            $this->assertIsInt($upgrade);
            $this->assertIsInt($guard);
            $this->assertLessThan($guard, $upgrade);
        }
        $this->assertLessThan(strpos($stages[0], 'RUN composer install'), strpos($stages[0], 'php /usr/local/share/waterline/verify-curl.php'));
        $this->assertGreaterThan(strpos($stages[1], 'apk del .build-deps'), strrpos($stages[1], 'php /usr/local/share/waterline/verify-curl.php'));
    }
}
