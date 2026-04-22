<?php

declare(strict_types=1);

namespace Waterline\Tests\Feature;

use Waterline\Tests\TestCase;
use Workflow\V2\Models\WorkflowRunSummary;

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
        config()->set('workflows.v2.run_summary_model', MissingWorkflowRunSummary::class);

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
        config()->set('workflows.v2.run_summary_model', MissingWorkflowRunSummary::class);

        $this->get('/waterline/api/v2/health')
            ->assertStatus(503)
            ->assertJsonPath('checks.0.name', 'engine_source')
            ->assertJsonPath('checks.0.status', 'error')
            ->assertJsonPath('engine_source.status', 'auto_fallback_to_v1')
            ->assertJsonPath('engine_source.resolved', 'v1')
            ->assertJsonPath('readiness_contract.effective_states.health.http_status_when_requested', 503);
    }

    public function testSavedViewsReturnUnavailableWhenV2IsPinnedButOperatorSurfaceIsMissing(): void
    {
        config()->set('waterline.engine_source', 'v2');
        config()->set('workflows.v2.run_summary_model', MissingWorkflowRunSummary::class);

        $this->get('/waterline/api/saved-views?bucket=running')
            ->assertStatus(503)
            ->assertJsonPath('engine_source.status', 'v2_pinned_unavailable')
            ->assertJsonPath('engine_source.issues.0.table', 'missing_workflow_run_summaries');
    }

    public function testInstanceRoutesReturnNotFoundWhenAutoFallsBackToLegacyRepository(): void
    {
        config()->set('waterline.engine_source', 'auto');
        config()->set('workflows.v2.run_summary_model', MissingWorkflowRunSummary::class);

        $this->get('/waterline/api/instances/example-instance')
            ->assertNotFound();
    }

    public function testInstanceRoutesReturnUnavailableWhenV2IsPinnedButOperatorSurfaceIsMissing(): void
    {
        config()->set('waterline.engine_source', 'v2');
        config()->set('workflows.v2.run_summary_model', MissingWorkflowRunSummary::class);

        $this->get('/waterline/api/instances/example-instance')
            ->assertStatus(503)
            ->assertJsonPath('engine_source.status', 'v2_pinned_unavailable');
    }
}

final class MissingWorkflowRunSummary extends WorkflowRunSummary
{
    protected $table = 'missing_workflow_run_summaries';
}
