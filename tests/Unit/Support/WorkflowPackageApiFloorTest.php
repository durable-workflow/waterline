<?php

namespace Waterline\Tests\Unit\Support;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Waterline\Support\WorkflowPackageApiFloor;
use Workflow\V2\CommandContext;
use Workflow\V2\Support\ScheduleManager;

/**
 * Pins the API floor Waterline relies on from durable-workflow/workflow.
 * If one of these assertions fails, Waterline is pinned against a
 * workflow package that is too old — upgrade the package rather than
 * relaxing the check.
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
