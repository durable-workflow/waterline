<?php

declare(strict_types=1);

namespace Waterline\Tests\Feature;

use Waterline\Tests\TestCase;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowRunWait;

final class EngineSourceContractTest extends TestCase
{
    public function testStatsEndpointIncludesEngineSourceDiagnostics(): void
    {
        config()->set('waterline.engine_source', 'auto');

        $this->get('/waterline/api/stats')
            ->assertOk()
            ->assertJsonPath('engine_source.configured', 'auto')
            ->assertJsonPath('engine_source.resolved', 'v2')
            ->assertJsonPath('engine_source.status', 'v2_auto')
            ->assertJsonPath('engine_source.uses_v2', true)
            ->assertJsonPath('engine_source.readiness_contract.version', 1)
            ->assertJsonPath(
                'engine_source.readiness_contract.effective_states.boot_install.state',
                'v2_operator_surface_available'
            )
            ->assertJsonPath('engine_source.readiness_contract.effective_states.stats.state', 'v2_operator_metrics');
    }

    public function testStatsEndpointReturnsUnavailableWhenV2IsPinnedButOperatorSurfaceIsMissing(): void
    {
        config()->set('waterline.engine_source', 'v2');
        config()->set('workflows.v2.run_model', MissingWorkflowRun::class);

        $this->get('/waterline/api/stats')
            ->assertStatus(503)
            ->assertJsonPath('engine_source.configured', 'v2')
            ->assertJsonPath('engine_source.resolved', 'v2')
            ->assertJsonPath('engine_source.status', 'v2_pinned_unavailable')
            ->assertJsonPath('engine_source.uses_v2', false)
            ->assertJsonPath('engine_source.issues.0.reason', 'missing_table')
            ->assertJsonPath('engine_source.readiness_contract.effective_states.stats.state', 'unavailable_503');
    }

    public function testV2HealthEndpointReturnsEngineSourceErrorWhenAutoFallsBackToV1(): void
    {
        config()->set('waterline.engine_source', 'auto');
        config()->set('workflows.v2.run_model', MissingWorkflowRun::class);

        $this->get('/waterline/api/v2/health')
            ->assertStatus(503)
            ->assertJsonPath('checks.0.name', 'engine_source')
            ->assertJsonPath('checks.0.status', 'error')
            ->assertJsonPath('engine_source.status', 'auto_fallback_to_v1')
            ->assertJsonPath('engine_source.resolved', 'v1')
            ->assertJsonPath('readiness_contract.effective_states.health.http_status_when_requested', 503);
    }

    public function testV2HealthEndpointStaysReadableWhenOptionalSelectedRunProjectionIsMissing(): void
    {
        config()->set('waterline.engine_source', 'v2');
        config()->set('workflows.v2.run_wait_model', MissingWorkflowRunWait::class);

        $this->get('/waterline/api/v2/health')
            ->assertOk()
            ->assertJsonPath('status', 'warning')
            ->assertJsonPath('healthy', true)
            ->assertJsonPath('engine_source.status', 'v2_pinned_degraded')
            ->assertJsonPath('engine_source.uses_v2', true)
            ->assertJsonPath('engine_source.degraded_operator_surface', true)
            ->assertJsonPath('checks.0.name', 'engine_source')
            ->assertJsonPath('checks.0.status', 'ok')
            ->assertJsonPath('checks.1.name', 'operator_snapshot')
            ->assertJsonPath('checks.1.status', 'warning');
    }

    public function testSavedViewsReturnUnavailableWhenV2IsPinnedButOperatorSurfaceIsMissing(): void
    {
        config()->set('waterline.engine_source', 'v2');
        config()->set('workflows.v2.run_model', MissingWorkflowRun::class);

        $this->get('/waterline/api/saved-views?bucket=running')
            ->assertStatus(503)
            ->assertJsonPath('engine_source.status', 'v2_pinned_unavailable')
            ->assertJsonPath('engine_source.issues.0.table', 'missing_workflow_runs');
    }

    public function testInstanceRoutesReturnNotFoundWhenAutoFallsBackToLegacyRepository(): void
    {
        config()->set('waterline.engine_source', 'auto');
        config()->set('workflows.v2.run_model', MissingWorkflowRun::class);

        $this->get('/waterline/api/instances/example-instance')
            ->assertNotFound();
    }

    public function testInstanceRoutesReturnUnavailableWhenV2IsPinnedButOperatorSurfaceIsMissing(): void
    {
        config()->set('waterline.engine_source', 'v2');
        config()->set('workflows.v2.run_model', MissingWorkflowRun::class);

        $this->get('/waterline/api/instances/example-instance')
            ->assertStatus(503)
            ->assertJsonPath('engine_source.status', 'v2_pinned_unavailable');
    }
}

final class MissingWorkflowRun extends WorkflowRun
{
    protected $table = 'missing_workflow_runs';
}

final class MissingWorkflowRunWait extends WorkflowRunWait
{
    protected $table = 'missing_workflow_run_waits';
}
