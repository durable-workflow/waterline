<?php

declare(strict_types=1);

namespace Waterline\Support;

use Composer\InstalledVersions;
use LogicException;

final class ServiceModeRequirements
{
    public const SDK_QUALIFIED_VERSION = '2.0.1';

    public const SDK_ONBOARDING_CONSTRAINT = '^2.0';

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
        if (! self::supportsSdkVersion($actual)) {
            throw new LogicException(sprintf(
                'Waterline service mode requires a stable durable-workflow/sdk release matching %s; installed %s. Update the SDK before starting Waterline service mode.',
                self::SDK_ONBOARDING_CONSTRAINT,
                $actual ?? '<unknown>',
            ));
        }
    }

    private static function supportsSdkVersion(?string $version): bool
    {
        return is_string($version)
            && preg_match('/\A2\.(?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*)\z/D', $version) === 1;
    }
}
