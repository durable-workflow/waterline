<?php

declare(strict_types=1);

namespace Waterline\Tests\Unit;

use Composer\Semver\Semver;
use PHPUnit\Framework\TestCase;

final class InstallationContractTest extends TestCase
{
    public function testStandaloneLockSupportsThePackagedPhpRuntime(): void
    {
        $root = dirname(__DIR__, 2);
        $manifest = json_decode((string) file_get_contents($root.'/standalone/composer.json'), true, 512, JSON_THROW_ON_ERROR);
        $lock = json_decode((string) file_get_contents($root.'/standalone/composer.lock'), true, 512, JSON_THROW_ON_ERROR);
        $dockerfile = (string) file_get_contents($root.'/Dockerfile');

        $this->assertSame(1, preg_match('/^FROM php:(\d+\.\d+\.\d+)-/m', $dockerfile, $runtime));
        $php = $runtime[1];
        $this->assertSame($php, $manifest['config']['platform']['php'] ?? null);
        $this->assertSame($php, $lock['platform-overrides']['php'] ?? null);
        $this->assertTrue(Semver::satisfies($php, $manifest['require']['php']));

        foreach ($lock['packages'] as $package) {
            $this->assertTrue(
                Semver::satisfies($php, $package['require']['php'] ?? '*'),
                $package['name'].' cannot run on the packaged PHP '.$php,
            );
        }
    }

    public function testServiceImageSmokeEntryPointIsExecutable(): void
    {
        $root = dirname(__DIR__, 2);

        $this->assertTrue(
            is_executable($root.'/scripts/ci/service-mode-image-smoke.sh'),
            'The service-image smoke entry point must be executable by CI.',
        );
    }

    public function testServiceCapacityTupleEntryPointIsExecutable(): void
    {
        $root = dirname(__DIR__, 2);

        $this->assertTrue(
            is_executable($root.'/scripts/ci/service-capacity-tuple.sh'),
            'The live service-capacity tuple entry point must be executable by CI.',
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
        $published = json_decode(
            (string) file_get_contents($root.'/release/current-product-tuple.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $publishedSdkVersion = $published['versions']['sdk-php'] ?? null;
        $qualifiedSdkVersion = $manifest['require-dev']['durable-workflow/sdk'] ?? null;
        $workflowVersion = $published['versions']['workflow'] ?? null;
        $qualifiedWorkflowVersion = $manifest['require-dev']['durable-workflow/workflow'] ?? null;

        $this->assertSame([
            'server' => '2.0.0-rc.32',
            'sdk-php' => '2.0.0-rc.14',
            'waterline' => '2.0.0-rc.19',
        ], $manifest['extra']['durable-workflow']['service-capacity-evidence']['first-supported-tuple'] ?? null);
        $this->assertSame(
            'durable-workflow.v2.namespace-capacity-evidence',
            $manifest['extra']['durable-workflow']['service-capacity-evidence']['schema'] ?? null,
        );
        $this->assertArrayNotHasKey('durable-workflow/sdk', $manifest['require'] ?? []);
        $this->assertArrayNotHasKey('durable-workflow/workflow', $manifest['require'] ?? []);
        $this->assertIsString($publishedSdkVersion);
        $this->assertIsString($qualifiedSdkVersion);
        $this->assertGreaterThanOrEqual(0, version_compare($qualifiedSdkVersion, $publishedSdkVersion));
        $this->assertIsString($workflowVersion);
        $this->assertIsString($qualifiedWorkflowVersion);
        $this->assertGreaterThanOrEqual(0, version_compare($qualifiedWorkflowVersion, $workflowVersion));
        $this->assertArrayHasKey('durable-workflow/sdk', $manifest['suggest'] ?? []);
        $this->assertArrayHasKey('durable-workflow/workflow', $manifest['suggest'] ?? []);
        \Waterline\Support\ServiceModeRequirements::assertSdkInstalled(
            static fn (string $class): bool => true,
            static fn (): string => $qualifiedSdkVersion,
        );
    }

    public function testCurrentCandidateAdvancesWithoutRewritingFirstSupportedCapacityEvidence(): void
    {
        $manifest = json_decode(
            (string) file_get_contents(dirname(__DIR__, 2).'/composer.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $firstSupported = $manifest['extra']['durable-workflow']['service-capacity-evidence']['first-supported-tuple'] ?? null;
        $currentSdk = $manifest['require-dev']['durable-workflow/sdk'] ?? null;
        $currentWaterline = $manifest['extra']['durable-workflow']['product-train'] ?? null;

        $this->assertSame([
            'server' => '2.0.0-rc.32',
            'sdk-php' => '2.0.0-rc.14',
            'waterline' => '2.0.0-rc.19',
        ], $firstSupported);
        $this->assertIsString($currentSdk);
        $this->assertIsString($currentWaterline);
        $this->assertGreaterThanOrEqual(0, version_compare($currentSdk, $firstSupported['sdk-php']));
        $this->assertGreaterThanOrEqual(0, version_compare($currentWaterline, $firstSupported['waterline']));
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

        // A Laravel 9-11 compatibility cell rewrites the root require-dev SDK
        // to its own older pin before this runs. The matrix contract, checked
        // before that rewrite, ties the Laravel 13 cell to the root pin.
        $matrix = json_decode(
            (string) file_get_contents($root.'/.github/laravel-matrix.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $cellSdks = array_column($matrix['include'], 'sdk', 'label');
        $this->assertContains($packageManifest['require-dev']['durable-workflow/sdk'] ?? null, $cellSdks);
        $this->assertSame(
            $cellSdks['Laravel 13'] ?? null,
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
