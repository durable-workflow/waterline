<?php

declare(strict_types=1);

namespace Waterline\Tests\Feature;

use Illuminate\Auth\GenericUser;
use Illuminate\Support\Facades\Gate;
use Waterline\Tests\TestCase;
use Waterline\Waterline;

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
