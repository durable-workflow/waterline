<?php

namespace Waterline\Support;

use ReflectionClass;
use ReflectionException;
use ReflectionMethod;
use RuntimeException;

/**
 * Enforces the minimum `durable-workflow/workflow` API surface Waterline
 * relies on when the v2 operator bridge is active.
 *
 * Waterline now requires the published v2 alpha line of
 * `durable-workflow/workflow`, but the API floor still only matters when the
 * resolved engine source is v2. A v1 install or an `auto`-mode install that
 * falls back to v1 never calls the v2 schedule mutation signatures and must
 * boot cleanly even when the v2 classes are absent.
 *
 * Inside the v2 band, however, Waterline now depends on specific schedule
 * mutation signatures and the namespace-scoped v2 health snapshot. Older
 * v2 installs that predate those contracts fail schedule mutation routes
 * with unknown-named-parameter or argument-count errors, or silently lose
 * namespace scoping on the operator health surface. `assertIfActive()` is
 * called at boot so those broken pairings surface with a clear diagnostic
 * instead of a 500 or a cross-namespace health payload at runtime.
 */
final class WorkflowPackageApiFloor
{
    /**
     * Each entry is `[FQCN, method, required_parameter]`. The class and
     * method must exist, the method must be public-static, and the named
     * parameter must be declared on its signature.
     *
     * @var list<array{0: class-string, 1: string, 2: string}>
     */
    private const REQUIRED_PARAMETERS = [
        [\Workflow\V2\Support\ScheduleManager::class, 'pause', 'context'],
        [\Workflow\V2\Support\ScheduleManager::class, 'resume', 'context'],
        [\Workflow\V2\Support\ScheduleManager::class, 'trigger', 'context'],
        [\Workflow\V2\Support\ScheduleManager::class, 'triggerDetailed', 'context'],
        [\Workflow\V2\Support\ScheduleManager::class, 'backfill', 'context'],
        [\Workflow\V2\Support\ScheduleManager::class, 'delete', 'context'],
        [\Workflow\V2\Support\HealthCheck::class, 'snapshot', 'namespace'],
    ];

    public const COMMAND_CONTEXT_CLASS = \Workflow\V2\CommandContext::class;

    /**
     * Assert the v2 API floor only when the resolved engine source is v2.
     *
     * v1 installs and `auto`-mode installs that fall back to v1 skip the
     * check entirely so they continue to boot even when the v2 classes
     * are absent or incomplete.
     */
    public static function assertIfActive(): void
    {
        if (! WorkflowEngineSourceResolver::usesV2()) {
            return;
        }

        self::assert();
    }

    /**
     * Assert every required API surface is present. Throws with a single
     * aggregated diagnostic when the installed workflow package is too old.
     */
    public static function assert(): void
    {
        self::assertAgainst();
    }

    /**
     * Assert against an explicit context class and requirement list. Exposed
     * so regression tests can verify the throw path without mutating global
     * class state. `assert()` calls this with the real REQUIRED_PARAMETERS.
     *
     * @param list<array{0: string, 1: string, 2: string}>|null $requirements
     */
    public static function assertAgainst(
        ?string $contextClass = null,
        ?array $requirements = null,
    ): void {
        $missing = self::findMissing($contextClass, $requirements);

        if ($missing === []) {
            return;
        }

        throw new RuntimeException(sprintf(
            "Installed durable-workflow/workflow package is older than the API floor Waterline requires. "
            ."Missing: %s. Upgrade the workflow package to a v2 snapshot that includes CommandContext "
            .'plus the context-accepting schedule mutation and namespace-scoped health snapshot signatures.',
            implode(', ', $missing),
        ));
    }

    /**
     * Return the list of missing API-floor entries, or an empty list when
     * the installed workflow package meets the floor. Exposed so regression
     * tests can verify the detector fires without catching exceptions.
     *
     * @param list<array{0: string, 1: string, 2: string}>|null $requirements
     * @return list<string>
     */
    public static function findMissing(
        ?string $contextClass = null,
        ?array $requirements = null,
    ): array {
        $contextClass ??= self::COMMAND_CONTEXT_CLASS;
        $requirements ??= self::REQUIRED_PARAMETERS;
        $missing = [];

        if (! class_exists($contextClass)) {
            $missing[] = $contextClass;
        }

        foreach ($requirements as [$class, $method, $parameter]) {
            $reflectionMethod = self::reflectStaticMethod($class, $method);

            if ($reflectionMethod === null) {
                $missing[] = sprintf('%s::%s()', $class, $method);

                continue;
            }

            if (! self::methodHasParameter($reflectionMethod, $parameter)) {
                $missing[] = sprintf('%s::%s($%s)', $class, $method, $parameter);
            }
        }

        return $missing;
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
