<?php

declare(strict_types=1);

namespace Waterline\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class InstallationContractTest extends TestCase
{
    public function testServiceImageSmokeEntryPointIsExecutable(): void
    {
        $root = dirname(__DIR__, 2);

        $this->assertTrue(
            is_executable($root.'/scripts/ci/service-mode-image-smoke.sh'),
            'The service-image smoke entry point must be executable by CI.',
        );
    }

    public function testManifestSeparatesRemoteRuntimeFromOptionalEmbeddedIntegration(): void
    {
        $root = dirname(__DIR__, 2);
        $manifest = json_decode(
            (string) file_get_contents($root.'/composer.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $train = $manifest['extra']['durable-workflow']['product-train'] ?? null;

        $this->assertSame('2.0.0-beta.6', $train);
        $this->assertSame($train, $manifest['require']['durable-workflow/sdk'] ?? null);
        $this->assertArrayNotHasKey('durable-workflow/workflow', $manifest['require'] ?? []);
        $this->assertSame($train, $manifest['require-dev']['durable-workflow/workflow'] ?? null);
        $this->assertArrayHasKey('durable-workflow/workflow', $manifest['suggest'] ?? []);

        $expectedPins = [
            'durable-workflow/waterline:'.$train.'@beta',
            'durable-workflow/workflow:'.$train.'@beta',
            'durable-workflow/sdk:'.$train.'@beta',
        ];
        $readme = (string) file_get_contents($root.'/README.md');
        preg_match_all('/```bash\n(composer require .*?)\n```/s', $readme, $matches);

        $this->assertNotEmpty($matches[1], 'README must expose at least one Composer installation command.');
        foreach ($matches[1] as $command) {
            foreach ($expectedPins as $pin) {
                $this->assertStringContainsString($pin, $command);
            }
        }
    }

    public function testStandaloneLockContainsOnlyTheRemoteRuntimePackages(): void
    {
        $root = dirname(__DIR__, 2);
        $lock = json_decode(
            (string) file_get_contents($root.'/standalone/composer.lock'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $packages = [];

        foreach ($lock['packages'] ?? [] as $package) {
            if (is_array($package) && is_string($package['name'] ?? null)) {
                $packages[$package['name']] = $package['version'] ?? null;
            }
        }

        $this->assertSame('2.0.0-beta.6', $packages['durable-workflow/sdk'] ?? null);
        $this->assertArrayNotHasKey('durable-workflow/waterline', $packages);
        $this->assertArrayNotHasKey('durable-workflow/workflow', $packages);
    }
}
