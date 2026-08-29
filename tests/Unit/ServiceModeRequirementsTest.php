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
            static fn (): string => ServiceModeRequirements::SDK_VERSION,
        );

        $this->addToAssertionCount(1);
    }

    public function testServiceModeReportsPrereleaseChannelRemediationWhenThePhpSdkIsMissing(): void
    {
        try {
            ServiceModeRequirements::assertSdkInstalled(static fn (string $class): bool => false);
            $this->fail('Missing service-mode SDK must report installation guidance.');
        } catch (LogicException $exception) {
            $message = $exception->getMessage();

            $this->assertStringContainsString(
                'composer require --with-all-dependencies "durable-workflow/sdk:^2.0@RC"',
                $message,
            );
            $this->assertDoesNotMatchRegularExpression(
                '/durable-workflow\/sdk:2\.0\.0-(?:alpha|beta|rc)\.[0-9]+/i',
                $message,
            );
        }
    }

    public function testServiceModeRejectsAnOlderPrereleaseWithoutACompatibilityShim(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage(
            'Waterline service mode requires durable-workflow/sdk 2.0.0-rc.53 exactly; installed 2.0.0-rc.40.',
        );

        ServiceModeRequirements::assertSdkInstalled(
            static fn (string $class): bool => true,
            static fn (): string => '2.0.0-rc.40',
        );
    }
}
