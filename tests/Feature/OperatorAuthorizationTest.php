<?php

declare(strict_types=1);

namespace Waterline\Tests\Feature;

use Illuminate\Auth\GenericUser;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Queue;
use Waterline\Support\BackendConfiguration;
use Waterline\Tests\Fixtures\V2\TestOperatorCommandWorkflow;
use Waterline\Tests\TestCase;
use Waterline\Waterline;
use Workflow\V2\Models\WorkflowCommand;
use Workflow\V2\Support\ScheduleManager;
use Workflow\V2\WorkflowStub;

final class OperatorAuthorizationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Waterline::auth(static fn ($request): bool => $request->user() !== null);
        $this->actingAs(new GenericUser(['id' => 7, 'role' => 'viewer']));
        foreach (['messages', 'repair', 'cancel', 'terminate', 'archive', 'schedules'] as $group) {
            config()->set('waterline.authorization.gates.'.$group, 'waterline-'.$group);
            Gate::define('waterline-'.$group, static fn ($user): bool => $user->role === 'operator');
        }
    }

    public function testViewerCanReadButEveryMutationAliasIsRefusedBeforeTargetLookup(): void
    {
        $this->getJson('/waterline/api/flows/running')->assertOk();
        $this->getJson('/waterline/api/v2/schedules')->assertOk();

        foreach ($this->mutationRoutes() as [$method, $uri, $action]) {
            $this->json($method, $uri, ['arguments' => []])
                ->assertForbidden()
                ->assertJsonPath('reason', 'waterline_action_forbidden')
                ->assertJsonPath('capability', $action);
        }
    }

    public function testGlobalReadOnlyOverridesEvenAnAllowedOperator(): void
    {
        $this->actingAs(new GenericUser(['id' => 8, 'role' => 'operator']));
        config()->set('waterline.read_only', true);

        foreach ($this->mutationRoutes() as [$method, $uri, $action]) {
            $this->json($method, $uri, ['arguments' => []])
                ->assertForbidden()
                ->assertJsonPath('reason', 'waterline_read_only')
                ->assertJsonPath('capability', $action);
        }
        $this->getJson('/waterline/api/flows/running')->assertOk();
    }

    public function testViewerCanInspectARealRunAndCannotWriteCommandsOrChangeASchedule(): void
    {
        Queue::fake();
        $workflow = WorkflowStub::make(TestOperatorCommandWorkflow::class, 'authorization-run');
        $workflow->start();
        $uri = '/waterline/api/instances/'.$workflow->id().'/runs/'.$workflow->runId();
        $commands = WorkflowCommand::query()->count();

        $this->getJson($uri)->assertOk()
            ->assertJsonPath('backend.permissions.signal.allowed', false)
            ->assertJsonPath('can_signal', false)
            ->assertJsonPath('actionability.actions.cancel.allowed', false)
            ->assertJsonPath('actionability.actions.cancel.reason', 'waterline_action_forbidden');
        $this->postJson($uri.'/signals/name-provided', ['arguments' => 'Viewer'])
            ->assertForbidden();
        $this->assertSame($commands, WorkflowCommand::query()->count());

        $schedule = ScheduleManager::create('authorization-schedule', TestOperatorCommandWorkflow::class, '0 * * * *');
        $this->postJson('/waterline/api/v2/schedules/authorization-schedule/pause')->assertForbidden();
        $this->assertSame('active', $schedule->refresh()->status->value);

        $this->actingAs(new GenericUser(['id' => 8, 'role' => 'operator']));
        $this->postJson('/waterline/api/v2/schedules/authorization-schedule/pause')->assertOk();
        $this->assertSame('paused', $schedule->refresh()->status->value);
        $this->postJson($uri.'/signals/name-provided', ['arguments' => 'Operator'])
            ->assertOk()->assertJsonPath('command_status', 'accepted');
        $this->assertSame($commands + 1, WorkflowCommand::query()->count());
    }

    public function testIndependentGatesDefaultsAndServiceAccessModeAreReflectedInTheDashboard(): void
    {
        request()->setUserResolver(fn () => $this->app['auth']->user());
        Gate::define('allow-cancel', static fn ($user, $request): bool => $user->id === 7 && $request->path() !== 'never');
        config()->set('waterline.authorization.gates.cancel', 'allow-cancel');
        $permissions = BackendConfiguration::payload()['permissions'];
        $this->assertTrue($permissions['cancel']['allowed']);
        $this->assertFalse($permissions['terminate']['allowed']);
        $this->assertFalse($permissions['signal']['allowed']);
        $this->assertFalse($permissions['update']['allowed']);

        $this->get('/waterline')->assertOk()->assertSee('waterline_action_forbidden');
        config()->set('waterline.authorization.gates', []);
        foreach (BackendConfiguration::payload()['permissions'] as $permission) {
            $this->assertTrue($permission['allowed']);
        }

        config()->set('waterline.backend', 'service');
        config()->set('waterline.read_only', true);
        config()->set('waterline.authorization.gates.cancel', 'undefined-gate');
        config()->set('waterline.service.access_mode', 'operator');
        $this->assertTrue(BackendConfiguration::payload()['permissions']['cancel']['allowed']);
        config()->set('waterline.service.access_mode', 'read_only');
        $this->assertSame('waterline_read_only', BackendConfiguration::payload()['permissions']['cancel']['reason']);
    }

    public function testUndefinedInvalidAndGuestGatesRefuseWritesWithoutGrantingViewingAccess(): void
    {
        foreach (['undefined-gate', '', false, []] as $gate) {
            config()->set('waterline.authorization.gates.cancel', $gate);
            $this->postJson('/waterline/api/instances/missing/cancel')->assertForbidden()
                ->assertJsonPath('reason', 'waterline_action_forbidden');
        }

        $this->app['auth']->forgetGuards();
        Waterline::auth(static fn (): bool => true);
        config()->set('waterline.authorization.gates.cancel', 'waterline-cancel');
        $this->postJson('/waterline/api/instances/missing/cancel')->assertForbidden();

        $this->actingAs(new GenericUser(['id' => 8, 'role' => 'operator']));
        Waterline::auth(static fn (): bool => false);
        $this->getJson('/waterline/api/flows/running')->assertForbidden();
        $this->postJson('/waterline/api/instances/missing/cancel')->assertForbidden();
    }

    /** @return list<array{string, string, string}> */
    private function mutationRoutes(): array
    {
        $routes = [];
        foreach (['flows/missing', 'instances/missing', 'instances/missing/runs/missing'] as $target) {
            foreach (['signal' => 'signals/name', 'update' => 'updates/name', 'repair' => 'repair',
                'cancel' => 'cancel', 'terminate' => 'terminate', 'archive' => 'archive'] as $action => $suffix) {
                $routes[] = ['POST', '/waterline/api/'.$target.'/'.$suffix, $action];
            }
        }
        foreach (['pause', 'resume', 'trigger', 'backfill'] as $action) {
            $routes[] = ['POST', '/waterline/api/v2/schedules/missing/'.$action, 'schedules'];
        }
        $routes[] = ['DELETE', '/waterline/api/v2/schedules/missing', 'schedules'];

        return $routes;
    }
}
