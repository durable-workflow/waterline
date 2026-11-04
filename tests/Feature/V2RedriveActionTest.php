<?php

declare(strict_types=1);

namespace Waterline\Tests\Feature;

use Illuminate\Auth\GenericUser;
use Illuminate\Support\Facades\Gate;
use Mockery;
use Waterline\Support\ActionabilityContract;
use Waterline\Tests\TestCase;
use Waterline\Waterline;
use Workflow\V2\CommandContext;
use Workflow\V2\Contracts\WorkflowControlPlane;

/**
 * Embedded redrive: the route, its permission, and how the engine's array
 * answer becomes the command response the run detail page reads.
 */
final class V2RedriveActionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('waterline.engine_source', 'v2');
        Waterline::auth(static fn (): bool => true);
    }

    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    public function testRedriveStartsTheSuccessorAndReturnsItsRun(): void
    {
        config()->set('waterline.namespace', 'orders');
        $this->bindControlPlane(function (string $instanceId, string $runId, array $options): array {
            $this->assertSame('order-1', $instanceId);
            $this->assertSame('run-failed', $runId);
            $this->assertSame('orders', $options['namespace'] ?? null);
            $this->assertSame('request-1', $options['request_id'] ?? null);
            $this->assertInstanceOf(CommandContext::class, $options['command_context'] ?? null);

            return [
                'accepted' => true,
                'workflow_instance_id' => 'order-1',
                'workflow_run_id' => 'run-successor',
                'continued_from_run_id' => 'run-failed',
                'resume_step_sequence' => 3,
                'task_id' => 'task-1',
                'reason' => null,
                'status' => 202,
            ];
        });

        $this->postJson('/waterline/api/instances/order-1/runs/run-failed/redrive', [
            'request_id' => 'request-1',
        ])
            ->assertStatus(202)
            ->assertJsonPath('accepted', true)
            ->assertJsonPath('outcome', 'redriven')
            ->assertJsonPath('command_status', 'accepted')
            ->assertJsonPath('run_id', 'run-successor')
            ->assertJsonPath('continued_from_run_id', 'run-failed')
            ->assertJsonPath('resume_step_sequence', 3)
            ->assertJsonPath('rejection_reason', null);
    }

    public function testARefusedRedriveReturnsTheEnginesReason(): void
    {
        $this->bindControlPlane(static fn (): array => [
            'accepted' => false,
            'workflow_instance_id' => 'order-1',
            'workflow_run_id' => null,
            'continued_from_run_id' => 'run-1',
            'resume_step_sequence' => null,
            'task_id' => null,
            'reason' => 'run_not_failed',
            'status' => 409,
        ]);

        $this->postJson('/waterline/api/instances/order-1/runs/run-1/redrive')
            ->assertStatus(409)
            ->assertJsonPath('accepted', false)
            ->assertJsonPath('run_id', null)
            ->assertJsonPath('rejection_reason', 'run_not_failed');
    }

    public function testAnUnknownRunKeepsTheEnginesNotFoundStatus(): void
    {
        $this->bindControlPlane(static fn (): array => [
            'accepted' => false,
            'workflow_instance_id' => 'missing',
            'workflow_run_id' => null,
            'continued_from_run_id' => 'missing',
            'resume_step_sequence' => null,
            'task_id' => null,
            'reason' => 'instance_not_found',
            'status' => 404,
        ]);

        $this->postJson('/waterline/api/instances/missing/runs/missing/redrive')
            ->assertNotFound()
            ->assertJsonPath('rejection_reason', 'instance_not_found');
    }

    public function testRedriveFollowsTheRepairGateWhenTheHostConfigHasNoRedriveKey(): void
    {
        Waterline::auth(static fn ($request): bool => $request->user() !== null);
        // A host config published before the redrive gate existed.
        config()->set('waterline.authorization.gates', [
            'messages' => null,
            'repair' => 'waterline-repair',
            'cancel' => null,
            'terminate' => null,
            'archive' => null,
            'schedules' => null,
        ]);
        Gate::define('waterline-repair', static fn ($user): bool => $user->role === 'operator');
        $this->bindControlPlane(static fn (): array => [
            'accepted' => false,
            'reason' => 'run_not_failed',
            'status' => 409,
        ]);

        $this->actingAs(new GenericUser(['id' => 7, 'role' => 'viewer']));
        $this->postJson('/waterline/api/instances/order-1/runs/run-1/redrive')
            ->assertForbidden()
            ->assertJsonPath('reason', 'waterline_action_forbidden')
            ->assertJsonPath('capability', 'redrive');

        $this->actingAs(new GenericUser(['id' => 8, 'role' => 'operator']));
        $this->postJson('/waterline/api/instances/order-1/runs/run-1/redrive')
            ->assertStatus(409);

        // The shipped null redrive gate follows the repair gate too.
        config()->set('waterline.authorization.gates.redrive', null);
        $this->actingAs(new GenericUser(['id' => 7, 'role' => 'viewer']));
        $this->postJson('/waterline/api/instances/order-1/runs/run-1/redrive')
            ->assertForbidden()
            ->assertJsonPath('capability', 'redrive');

        // A named redrive gate authorizes redrive on its own.
        config()->set('waterline.authorization.gates.redrive', 'waterline-redrive');
        Gate::define('waterline-redrive', static fn ($user): bool => true);
        $this->postJson('/waterline/api/instances/order-1/runs/run-1/redrive')
            ->assertStatus(409);
    }

    public function testAnEngineWithoutRedriveAnswersAsAMissingCapability(): void
    {
        $this->app->instance(WorkflowControlPlane::class, new class {});

        $this->postJson('/waterline/api/instances/order-1/runs/run-1/redrive')
            ->assertStatus(501)
            ->assertJsonPath('reason', 'backend_capability_unavailable')
            ->assertJsonPath('capability', 'redrive');
    }

    public function testActionabilityCarriesTheEnginesRedriveDecision(): void
    {
        $allowed = ActionabilityContract::annotateRun([
            'can_redrive' => true,
            'redrive_blocked_reason' => null,
        ]);
        $this->assertSame(
            ['allowed' => true, 'reason' => null, 'derived_from' => 'command_contract'],
            $allowed['actionability']['actions']['redrive'],
        );

        $blocked = ActionabilityContract::annotateRun([
            'can_redrive' => false,
            'redrive_blocked_reason' => 'already_redriven',
        ]);
        $this->assertSame(
            ['allowed' => false, 'reason' => 'already_redriven', 'derived_from' => 'command_contract'],
            $blocked['actionability']['actions']['redrive'],
        );

        // An engine without redrive eligibility leaves the action out, so the
        // page does not offer it.
        $this->assertArrayNotHasKey(
            'redrive',
            ActionabilityContract::annotateRun([])['actionability']['actions'] ?? [],
        );
    }

    private function bindControlPlane(\Closure $redrive): void
    {
        $controlPlane = Mockery::mock(WorkflowControlPlane::class);
        $controlPlane->shouldReceive('redrive')
            ->andReturnUsing($redrive);
        $this->app->instance(WorkflowControlPlane::class, $controlPlane);
    }
}
