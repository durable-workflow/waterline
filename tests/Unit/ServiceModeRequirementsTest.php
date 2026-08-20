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
        ServiceModeRequirements::assertSdkInstalled(static fn (string $class): bool => true);

        $this->addToAssertionCount(1);
    }

    public function testServiceModeReportsTheExactRemediationWhenThePhpSdkIsMissing(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage(
            'Waterline service mode requires the optional durable-workflow/sdk package. '
            .'Install it with `composer require durable-workflow/sdk:2.0.0-rc.40`, then retry; '
            .'or set WATERLINE_BACKEND=embedded.',
        );

        ServiceModeRequirements::assertSdkInstalled(static fn (string $class): bool => false);
    }
}
