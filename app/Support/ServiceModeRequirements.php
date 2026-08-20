<?php

declare(strict_types=1);

namespace Waterline\Support;

use LogicException;

final class ServiceModeRequirements
{
    public const SDK_VERSION = '2.0.0-rc.40';

    /**
     * @param  (callable(class-string): bool)|null  $classExists
     */
    public static function assertSdkInstalled(?callable $classExists = null): void
    {
        $classExists ??= static fn (string $class): bool => class_exists($class);

        if ($classExists(\DurableWorkflow\Client::class)) {
            return;
        }

        throw new LogicException(sprintf(
            'Waterline service mode requires the optional durable-workflow/sdk package. Install it with `composer require durable-workflow/sdk:%s`, then retry; or set WATERLINE_BACKEND=embedded.',
            self::SDK_VERSION,
        ));
    }
}
