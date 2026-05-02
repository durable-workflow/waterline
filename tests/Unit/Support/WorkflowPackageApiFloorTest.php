<?php

namespace Waterline\Tests\Unit\Support;

use ReflectionClass;
use RuntimeException;
use Waterline\Support\WorkflowPackageApiFloor;
use Waterline\Tests\TestCase;
use Workflow\V2\CommandContext;
use Workflow\V2\Support\HealthCheck;
use Workflow\V2\Support\OperatorMetrics;
use Workflow\V2\Support\ScheduleManager;

/**
 * Pins the API floor Waterline relies on from durable-workflow/workflow.
 *
 * If one of these assertions fails against the currently resolved workflow
 * package, Waterline is pinned against a workflow package that is too old —
 * upgrade the package rather than relaxing the check.
 *
 * The boot-time gating behavior (only asserting when the resolved engine
 * source is v2) is covered by `assertIfActive()` tests so v1 and
 * auto-fallback installs continue to boot cleanly.
 */
class WorkflowPackageApiFloorTest extends TestCase
{
    public function test_assert_passes_on_the_currently_resolved_workflow_package(): void
    {
        WorkflowPackageApiFloor::assert();

        $this->expectNotToPerformAssertions();
    }

    public function test_command_context_class_exists(): void
    {
        $this->assertTrue(class_exists(CommandContext::class));
    }

    /**
     * @dataProvider contextAcceptingScheduleMethodProvider
     */
    public function test_schedule_manager_method_accepts_context_parameter(string $method): void
    {
        $reflection = new ReflectionClass(ScheduleManager::class);
        $reflectionMethod = $reflection->getMethod($method);

        $this->assertTrue($reflectionMethod->isPublic(), "$method is not public");
        $this->assertTrue($reflectionMethod->isStatic(), "$method is not static");

        $parameterNames = array_map(
            static fn ($parameter): string => $parameter->getName(),
            $reflectionMethod->getParameters(),
        );

        $this->assertContains('context', $parameterNames, "$method does not declare a \$context parameter");
    }

    public function test_find_missing_returns_empty_list_on_current_workflow_package(): void
    {
        $this->assertSame([], WorkflowPackageApiFloor::findMissing());
    }

    public function test_health_check_snapshot_accepts_namespace_parameter(): void
    {
        $reflection = new ReflectionClass(HealthCheck::class);
        $reflectionMethod = $reflection->getMethod('snapshot');

        $this->assertTrue($reflectionMethod->isPublic(), 'snapshot is not public');
        $this->assertTrue($reflectionMethod->isStatic(), 'snapshot is not static');

        $parameterNames = array_map(
            static fn ($parameter): string => $parameter->getName(),
            $reflectionMethod->getParameters(),
        );

        $this->assertContains('namespace', $parameterNames, 'snapshot does not declare a $namespace parameter');
    }

    public function test_operator_metrics_snapshot_accepts_namespace_parameter(): void
    {
        $reflection = new ReflectionClass(OperatorMetrics::class);
        $reflectionMethod = $reflection->getMethod('snapshot');

        $this->assertTrue($reflectionMethod->isPublic(), 'snapshot is not public');
        $this->assertTrue($reflectionMethod->isStatic(), 'snapshot is not static');

        $parameterNames = array_map(
            static fn ($parameter): string => $parameter->getName(),
            $reflectionMethod->getParameters(),
        );

        $this->assertContains('namespace', $parameterNames, 'snapshot does not declare a $namespace parameter');
    }

    public function test_find_missing_reports_missing_command_context_class(): void
    {
        $missing = WorkflowPackageApiFloor::findMissing(
            contextClass: 'Waterline\\Tests\\Unit\\Support\\NonExistentCommandContext',
            requirements: [],
        );

        $this->assertSame(
            ['Waterline\\Tests\\Unit\\Support\\NonExistentCommandContext'],
            $missing,
        );
    }

    public function test_find_missing_reports_missing_schedule_method(): void
    {
        $missing = WorkflowPackageApiFloor::findMissing(
            requirements: [
                [ScheduleManager::class, 'thisMethodDoesNotExist', 'context'],
            ],
        );

        $this->assertSame(
            [sprintf('%s::thisMethodDoesNotExist()', ScheduleManager::class)],
            $missing,
        );
    }

    public function test_find_missing_reports_missing_parameter_on_existing_method(): void
    {
        $missing = WorkflowPackageApiFloor::findMissing(
            requirements: [
                [ScheduleManager::class, 'pause', 'nonexistent_parameter_name'],
            ],
        );

        $this->assertSame(
            [sprintf('%s::pause($nonexistent_parameter_name)', ScheduleManager::class)],
            $missing,
        );
    }

    public function test_assert_if_active_skips_when_engine_source_is_explicit_v1(): void
    {
        config()->set('waterline.engine_source', 'v1');

        WorkflowPackageApiFloor::assertIfActive();

        $this->expectNotToPerformAssertions();
    }

    public function test_assert_if_active_skips_when_auto_falls_back_to_v1(): void
    {
        config()->set('waterline.engine_source', 'auto');
        config()->set('workflows.v2.run_summary_model', MissingWorkflowRunSummaryForFloorGuard::class);

        WorkflowPackageApiFloor::assertIfActive();

        $this->expectNotToPerformAssertions();
    }

    public function test_assert_if_active_runs_when_engine_source_is_v2_and_passes_on_current_package(): void
    {
        config()->set('waterline.engine_source', 'v2');

        WorkflowPackageApiFloor::assertIfActive();

        $this->expectNotToPerformAssertions();
    }

    public function test_assert_if_active_runs_when_auto_resolves_to_v2_and_passes_on_current_package(): void
    {
        config()->set('waterline.engine_source', 'auto');

        WorkflowPackageApiFloor::assertIfActive();

        $this->expectNotToPerformAssertions();
    }

    public function test_assert_against_throws_with_aggregated_diagnostic_when_floor_is_violated(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('older than the API floor Waterline requires');
        $this->expectExceptionMessage('NonExistentCommandContext');
        $this->expectExceptionMessage('thisMethodDoesNotExist');
        $this->expectExceptionMessage('namespace-scoped health snapshot signatures');

        WorkflowPackageApiFloor::assertAgainst(
            contextClass: 'Waterline\\Tests\\Unit\\Support\\NonExistentCommandContext',
            requirements: [
                [ScheduleManager::class, 'thisMethodDoesNotExist', 'context'],
            ],
        );
    }

    public function test_assert_against_passes_when_explicit_requirements_are_met(): void
    {
        WorkflowPackageApiFloor::assertAgainst(
            contextClass: CommandContext::class,
            requirements: [
                [ScheduleManager::class, 'pause', 'context'],
            ],
        );

        $this->expectNotToPerformAssertions();
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function contextAcceptingScheduleMethodProvider(): array
    {
        return [
            'pause' => ['pause'],
            'resume' => ['resume'],
            'trigger' => ['trigger'],
            'triggerDetailed' => ['triggerDetailed'],
            'backfill' => ['backfill'],
            'delete' => ['delete'],
        ];
    }
}

final class MissingWorkflowRunSummaryForFloorGuard extends \Workflow\V2\Models\WorkflowRunSummary
{
    protected $table = 'missing_workflow_run_summaries_for_floor_guard';
}
