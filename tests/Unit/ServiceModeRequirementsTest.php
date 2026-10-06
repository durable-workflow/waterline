<?php

declare(strict_types=1);

namespace Waterline\Tests\Unit;

use LogicException;
use PHPUnit\Framework\TestCase;
use Waterline\Support\ServiceModeRequirements;

final class ServiceModeRequirementsTest extends TestCase
{
    public function testServiceModeAcceptsAnInstalledPhpSdk(): void
    {
        ServiceModeRequirements::assertSdkInstalled(
            static fn (string $class): bool => true,
            static fn (): string => ServiceModeRequirements::SDK_QUALIFIED_VERSION,
        );

        $this->addToAssertionCount(1);
    }

    public function testServiceModeAcceptsCompatibleStablePhpSdkReleases(): void
    {
        foreach (['2.0.0', '2.0.2', '2.1.0', '2.99.99'] as $version) {
            ServiceModeRequirements::assertSdkInstalled(
                static fn (string $class): bool => true,
                static fn (): string => $version,
            );
            $this->addToAssertionCount(1);
        }
    }

    public function testServiceModeReportsStableChannelRemediationWhenThePhpSdkIsMissing(): void
    {
        try {
            ServiceModeRequirements::assertSdkInstalled(static fn (string $class): bool => false);
            $this->fail('Missing service-mode SDK must report installation guidance.');
        } catch (LogicException $exception) {
            $message = $exception->getMessage();

            $this->assertStringContainsString(
                'composer require --with-all-dependencies "durable-workflow/sdk:^2.0"',
                $message,
            );
            $this->assertDoesNotMatchRegularExpression(
                '/durable-workflow\/sdk:2\.0\.0-(?:alpha|beta|rc)\.[0-9]+/i',
                $message,
            );
        }
    }

    public function testServiceModeAcceptsCancellationReleaseCandidates(): void
    {
        foreach (['2.2.0-rc.1', '2.3.0-rc.2'] as $version) {
            ServiceModeRequirements::assertSdkInstalled(
                static fn (string $class): bool => true,
                static fn (): string => $version,
            );
            $this->addToAssertionCount(1);
        }
    }

    public function testServiceModeRejectsHistoricalPrereleaseAndIncompatiblePhpSdkVersions(): void
    {
        foreach (['2.0.0-rc.54', '2.1.0-rc.1', '2.2.0-beta.1', '2.2.0-rc.0', '2.2.0-rc.01',
            '1.99.0', '3.0.0', '2.0.x-dev', 'invalid'] as $version) {
            try {
                ServiceModeRequirements::assertSdkInstalled(
                    static fn (string $class): bool => true,
                    static fn (): string => $version,
                );
                $this->fail("Incompatible PHP SDK {$version} must be rejected.");
            } catch (LogicException $exception) {
                $this->assertStringContainsString(
                    "requires a compatible durable-workflow/sdk release matching ^2.0, including cancellation release candidates; installed {$version}",
                    $exception->getMessage(),
                );
            }
        }
    }
}
