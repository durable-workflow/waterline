<?php

namespace Waterline\Support;

use ReflectionClass;
use ReflectionException;
use ReflectionMethod;
use RuntimeException;

/**
 * Enforces the minimum `durable-workflow/workflow` API surface Waterline
 * relies on at runtime.
 *
 * Waterline's composer constraint (`^1.0 || ^2.0`) intentionally covers a
 * wide range. Inside the v2 band, however, specific methods that carry
 * `CommandContext` for audit attribution landed only in workflow commit
 * `e59e6f2`. An older v2 install that predates that commit lacks the
 * context-accepting signatures and fails the Waterline schedule
 * pause/resume/trigger/backfill/delete routes with unknown-named-parameter
 * or argument-count errors.
 *
 * Assert the floor at boot so broken pairings surface with a clear
 * diagnostic instead of a 500 the first time an operator clicks "Pause"
 * in the UI.
 *
 * @see https://github.com/zorporation/durable-workflow/issues/355
 */
final class WorkflowPackageApiFloor
{
    /**
     * Each entry is `[FQCN, method, required_parameter]`. The class and
     * method must exist, the method must be public-static, and the named
     * parameter must be declared on its signature.
     */
    private const REQUIRED_PARAMETERS = [
        [\Workflow\V2\Support\ScheduleManager::class, 'pause', 'context'],
        [\Workflow\V2\Support\ScheduleManager::class, 'resume', 'context'],
        [\Workflow\V2\Support\ScheduleManager::class, 'trigger', 'context'],
        [\Workflow\V2\Support\ScheduleManager::class, 'triggerDetailed', 'context'],
        [\Workflow\V2\Support\ScheduleManager::class, 'backfill', 'context'],
        [\Workflow\V2\Support\ScheduleManager::class, 'delete', 'context'],
    ];

    public const COMMAND_CONTEXT_CLASS = \Workflow\V2\CommandContext::class;

    /**
     * Assert every required API surface is present. Throws with a single
     * aggregated diagnostic when the installed workflow package is too old.
     */
    public static function assert(): void
    {
        $missing = [];

        if (! class_exists(self::COMMAND_CONTEXT_CLASS)) {
            $missing[] = self::COMMAND_CONTEXT_CLASS;
        }

        foreach (self::REQUIRED_PARAMETERS as [$class, $method, $parameter]) {
            $reflectionMethod = self::reflectStaticMethod($class, $method);

            if ($reflectionMethod === null) {
                $missing[] = sprintf('%s::%s()', $class, $method);

                continue;
            }

            if (! self::methodHasParameter($reflectionMethod, $parameter)) {
                $missing[] = sprintf('%s::%s($%s)', $class, $method, $parameter);
            }
        }

        if ($missing === []) {
            return;
        }

        throw new RuntimeException(sprintf(
            "Installed durable-workflow/workflow package is older than the API floor Waterline requires. "
            ."Missing: %s. Upgrade the workflow package to a v2 snapshot that includes CommandContext "
            .'and the context-accepting schedule mutation signatures (see repos/workflow commit e59e6f2).',
            implode(', ', $missing),
        ));
    }

    private static function reflectStaticMethod(string $class, string $method): ?ReflectionMethod
    {
        if (! class_exists($class)) {
            return null;
        }

        try {
            $reflection = new ReflectionClass($class);
            $reflectionMethod = $reflection->getMethod($method);
        } catch (ReflectionException) {
            return null;
        }

        if (! $reflectionMethod->isPublic() || ! $reflectionMethod->isStatic()) {
            return null;
        }

        return $reflectionMethod;
    }

    private static function methodHasParameter(ReflectionMethod $method, string $parameter): bool
    {
        foreach ($method->getParameters() as $reflectionParameter) {
            if ($reflectionParameter->getName() === $parameter) {
                return true;
            }
        }

        return false;
    }
}
