<?php

declare(strict_types=1);

namespace Waterline\Tests\Feature;

use Illuminate\Contracts\Auth\Authenticatable;
use Waterline\Models\UserPreference;
use Waterline\Tests\TestCase;

class UserPreferencesControllerTest extends TestCase
{
    public function testPreferencesCanBePersistedForOperatorSurface(): void
    {
        config()->set('waterline.preferences.scope', 'ops');

        $this->putJson('/waterline/api/preferences/workflow-list', [
            'preferences' => [
                'tab' => 'timeline',
                'sort_direction' => 'asc',
                'row_density' => 'dense',
                'saved_view_id' => 'system:running',
                'columns' => ['workflow_id', 'status', 'status'],
            ],
        ])
            ->assertOk()
            ->assertJsonPath('surface', 'workflow-list')
            ->assertJsonPath('scope', 'ops')
            ->assertJsonPath('preferences.tab', 'timeline')
            ->assertJsonPath('preferences.sort_direction', 'asc')
            ->assertJsonPath('preferences.row_density', 'dense')
            ->assertJsonPath('preferences.saved_view_id', 'system:running')
            ->assertJsonPath('preferences.columns', ['workflow_id', 'status'])
            ->assertJsonPath('effective_preferences.columns', ['workflow_id', 'status']);

        $this->assertDatabaseHas('waterline_user_preferences', [
            'scope' => 'ops',
            'subject_key' => 'scope:ops',
            'surface' => 'workflow-list',
        ]);
    }

    public function testUrlQueryParametersOverrideStoredPreferencesWithoutMutatingThem(): void
    {
        config()->set('waterline.preferences.scope', 'ops');

        UserPreference::create([
            'scope' => 'ops',
            'subject_key' => 'scope:ops',
            'surface' => 'workflow-list',
            'preferences' => [
                'tab' => 'events',
                'sort_direction' => 'desc',
                'row_density' => 'comfortable',
                'saved_view_id' => 'system:completed',
                'columns' => ['workflow_id', 'status'],
            ],
        ]);

        $this->getJson('/waterline/api/preferences/workflow-list?tab=timeline&sort=asc&density=dense&saved_view=system:running&columns=workflow_id,task_queue')
            ->assertOk()
            ->assertJsonPath('preferences.tab', 'events')
            ->assertJsonPath('preferences.sort_direction', 'desc')
            ->assertJsonPath('preferences.row_density', 'comfortable')
            ->assertJsonPath('preferences.saved_view_id', 'system:completed')
            ->assertJsonPath('preferences.columns', ['workflow_id', 'status'])
            ->assertJsonPath('effective_preferences.tab', 'timeline')
            ->assertJsonPath('effective_preferences.sort_direction', 'asc')
            ->assertJsonPath('effective_preferences.row_density', 'dense')
            ->assertJsonPath('effective_preferences.saved_view_id', 'system:running')
            ->assertJsonPath('effective_preferences.columns', ['workflow_id', 'task_queue'])
            ->assertJsonPath('overrides.columns', ['workflow_id', 'task_queue']);

        $this->assertDatabaseHas('waterline_user_preferences', [
            'scope' => 'ops',
            'subject_key' => 'scope:ops',
            'surface' => 'workflow-list',
        ]);
    }

    public function testPreferencesAreScopedByAuthenticatedUserWhenAvailable(): void
    {
        config()->set('waterline.preferences.scope', 'ops');
        $this->actingAs(new PreferenceTestUser('42'));

        $this->putJson('/waterline/api/preferences/workflow-list', [
            'preferences' => [
                'row_density' => 'dense',
            ],
        ])->assertOk();

        $this->assertDatabaseHas('waterline_user_preferences', [
            'scope' => 'ops',
            'subject_key' => PreferenceTestUser::class.':42',
            'surface' => 'workflow-list',
        ]);
    }

    public function testUnknownPreferenceSurfaceReturnsNotFound(): void
    {
        $this->getJson('/waterline/api/preferences/billing-dashboard')
            ->assertNotFound();
    }
}

final class PreferenceTestUser implements Authenticatable
{
    public function __construct(private readonly string $id)
    {
    }

    public function getAuthIdentifierName(): string
    {
        return 'id';
    }

    public function getAuthIdentifier(): string
    {
        return $this->id;
    }

    public function getAuthPassword()
    {
        return null;
    }

    public function getAuthPasswordName(): string
    {
        return 'password';
    }

    public function getRememberToken()
    {
        return null;
    }

    public function setRememberToken($value): void
    {
    }

    public function getRememberTokenName(): string
    {
        return 'remember_token';
    }
}
