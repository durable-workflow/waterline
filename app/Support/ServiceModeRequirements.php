<?php

declare(strict_types=1);

namespace Waterline\Support;

use Composer\InstalledVersions;
use LogicException;

final class ServiceModeRequirements
{
    public const SDK_VERSION = '2.0.0-rc.54';

    public const SDK_ONBOARDING_CONSTRAINT = '^2.0@RC';

    /**
     * @param  (callable(class-string): bool)|null  $classExists
     * @param  (callable(): ?string)|null  $installedVersion
     */
    public static function assertSdkInstalled(
        ?callable $classExists = null,
        ?callable $installedVersion = null,
    ): void {
        $classExists ??= static fn (string $class): bool => class_exists($class);

        if (! $classExists(\DurableWorkflow\Client::class)) {
            throw new LogicException(sprintf(
                'Waterline service mode requires the optional durable-workflow/sdk package. Install it with `composer require --with-all-dependencies "durable-workflow/sdk:%s"`, then retry; or set WATERLINE_BACKEND=embedded.',
                self::SDK_ONBOARDING_CONSTRAINT,
            ));
        }

        $installedVersion ??= static fn (): ?string => InstalledVersions::getPrettyVersion('durable-workflow/sdk');
        $actual = $installedVersion();
        if ($actual !== self::SDK_VERSION) {
            throw new LogicException(sprintf(
                'Waterline service mode requires durable-workflow/sdk %s exactly; installed %s. Update the SDK before starting Waterline service mode.',
                self::SDK_VERSION,
                $actual ?? '<unknown>',
            ));
        }
    }
}
