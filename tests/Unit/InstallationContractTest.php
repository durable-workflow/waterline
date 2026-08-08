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
        $releaseVersion = $manifest['extra']['durable-workflow']['product-train'] ?? null;
        $sdkVersion = '2.0.0-rc.11';
        $workflowVersion = '2.0.0-rc.13';

        $this->assertSame('2.0.0-rc.12', $releaseVersion);
        $this->assertSame($sdkVersion, $manifest['require']['durable-workflow/sdk'] ?? null);
        $this->assertArrayNotHasKey('durable-workflow/workflow', $manifest['require'] ?? []);
        $this->assertSame($workflowVersion, $manifest['require-dev']['durable-workflow/workflow'] ?? null);
        $this->assertArrayHasKey('durable-workflow/workflow', $manifest['suggest'] ?? []);
    }

    public function testStandaloneManifestAndLockContainOnlyTheRemoteRuntimePackages(): void
    {
        $root = dirname(__DIR__, 2);
        $packageManifest = json_decode(
            (string) file_get_contents($root.'/composer.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $serviceManifest = json_decode(
            (string) file_get_contents($root.'/standalone/composer.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
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

        $this->assertSame(
            $packageManifest['require']['durable-workflow/sdk'] ?? null,
            $serviceManifest['require']['durable-workflow/sdk'] ?? null,
        );
        $this->assertSame(
            $serviceManifest['require']['durable-workflow/sdk'] ?? null,
            $packages['durable-workflow/sdk'] ?? null,
        );
        $this->assertArrayNotHasKey('durable-workflow/waterline', $packages);
        $this->assertArrayNotHasKey('durable-workflow/workflow', $packages);
    }
}
