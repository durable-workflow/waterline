<?php

declare(strict_types=1);

namespace Waterline\Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Waterline\Models\WorkerRegistration;
use Waterline\Tests\TestCase;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowRunTimerEntry;
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
            ->assertJsonPath('engine_source.issues.0.code', 'v2_workflow_table_missing')
            ->assertJsonPath('engine_source.readiness_issues.0.code', 'v2_workflow_table_missing')
            ->assertJsonPath('engine_source.readiness_issues.0.category', 'workflow_schema')
            ->assertJsonPath('engine_source.readiness_issues.0.condition', 'model:run_model')
            ->assertJsonPath('engine_source.readiness_issues.0.reason', 'missing_table')
            ->assertJsonPath(
                'engine_source.readiness_issues.0.remediation',
                'Run the workflow v2 migrations on the shared workflow storage connection or point workflows.storage.connection at the migrated store.'
            )
            ->assertJsonPath('readiness_issue_codes.0', 'v2_workflow_table_missing')
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
            ->assertJsonPath('checks.0.meta.readiness_issue_codes.0', 'v2_workflow_table_missing')
            ->assertJsonPath('checks.0.meta.readiness_issues.0.category', 'workflow_schema')
            ->assertJsonPath('readiness_issues.0.code', 'v2_workflow_table_missing')
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

    public function testPackageHostHealthAndStatsStayReadableWhenWorkerVersioningTablesAreReachable(): void
    {
        Carbon::setTestNow('2026-04-09 12:00:00');
        $this->beforeApplicationDestroyed(static function (): void {
            Carbon::setTestNow();
        });

        config()->set('waterline.engine_source', 'v2');
        config()->set('waterline.namespace', 'worker-versioning-conformance');
        config()->set('waterline.worker_stale_after_seconds', 120);
        config()->set('workflows.v2.run_timer_entry_model', MissingWorkflowRunTimerEntry::class);

        $this->createWorkerRegistrationsTable();

        WorkerRegistration::create([
            'worker_id' => 'package-host-v2-worker',
            'namespace' => 'worker-versioning-conformance',
            'task_queue' => 'worker-versioning-shared',
            'runtime' => 'python',
            'sdk_version' => '0.4.89',
            'build_id' => 'build-v2',
            'supported_workflow_types' => ['Sequence'],
            'supported_activity_types' => ['activity.b'],
            'max_concurrent_workflow_tasks' => 8,
            'max_concurrent_activity_tasks' => 4,
            'available_workflow_slots' => 7,
            'last_heartbeat_at' => now()->subSeconds(5),
            'status' => 'active',
        ]);

        $healthPayload = $this->get('/waterline/api/v2/health')
            ->assertOk()
            ->assertJsonPath('healthy', true)
            ->assertJsonPath('engine_source.status', 'v2_pinned_degraded')
            ->assertJsonPath('engine_source.uses_v2', true)
            ->assertJsonPath('engine_source.v2_operator_surface_available', true)
            ->assertJsonPath('engine_source.degraded_operator_surface', true)
            ->assertJsonPath('queue_visibility.available', true)
            ->assertJsonPath('operator_metrics.workers.registrations.0.build_id', 'build-v2')
            ->json();

        $this->assertContains('build-v2', $healthPayload['worker_versioning']['worker_cohorts'] ?? []);

        $this->get('/waterline/api/stats')
            ->assertOk()
            ->assertJsonPath('engine_source.status', 'v2_pinned_degraded')
            ->assertJsonPath('engine_source.uses_v2', true);
    }

    public function testPackageHostDoesNotExposeGlobalV2SurfaceWhenDurableHistoryIsUnavailable(): void
    {
        config()->set('waterline.engine_source', 'v2');
        config()->set('waterline.namespace', 'worker-versioning-conformance');
        config()->set('workflows.v2.history_event_model', MissingWorkflowHistoryEvent::class);

        $this->createWorkerRegistrationsTable();

        $this->get('/waterline/api/v2/health')
            ->assertStatus(503)
            ->assertJsonPath('engine_source.status', 'v2_pinned_unavailable')
            ->assertJsonPath('engine_source.uses_v2', false)
            ->assertJsonPath('engine_source.v2_operator_surface_available', false)
            ->assertJsonPath('queue_visibility.available', false);

        $this->get('/waterline/api/stats')
            ->assertStatus(503)
            ->assertJsonPath('engine_source.status', 'v2_pinned_unavailable')
            ->assertJsonPath('engine_source.uses_v2', false);

        $this->get('/waterline/api/instances/example-instance/runs/example-run')
            ->assertStatus(503)
            ->assertJsonPath('engine_source.status', 'v2_pinned_unavailable');
    }

    public function testSavedViewsReturnUnavailableWhenV2IsPinnedButOperatorSurfaceIsMissing(): void
    {
        config()->set('waterline.engine_source', 'v2');
        config()->set('workflows.v2.run_model', MissingWorkflowRun::class);

        $this->get('/waterline/api/saved-views?bucket=running')
            ->assertStatus(503)
            ->assertJsonPath('engine_source.status', 'v2_pinned_unavailable')
            ->assertJsonPath('engine_source.issues.0.table', 'missing_workflow_runs')
            ->assertJsonPath('engine_source.readiness_issues.0.code', 'v2_workflow_table_missing');
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

    private function createWorkerRegistrationsTable(): void
    {
        Schema::dropIfExists('workflow_worker_registrations');
        Schema::create('workflow_worker_registrations', static function (Blueprint $table): void {
            $table->id();
            $table->string('worker_id')->unique();
            $table->string('namespace')->default('default')->index();
            $table->string('task_queue')->default('default')->index();
            $table->string('runtime')->nullable();
            $table->string('sdk_version')->nullable();
            $table->string('build_id')->nullable()->index();
            $table->json('supported_workflow_types')->nullable();
            $table->json('workflow_definition_fingerprints')->nullable();
            $table->json('supported_activity_types')->nullable();
            $table->unsignedInteger('max_concurrent_workflow_tasks')->nullable();
            $table->unsignedInteger('max_concurrent_activity_tasks')->nullable();
            $table->unsignedInteger('max_concurrent_worker_sessions')->nullable();
            $table->unsignedInteger('available_workflow_slots')->nullable();
            $table->unsignedInteger('available_activity_slots')->nullable();
            $table->unsignedInteger('available_session_slots')->nullable();
            $table->json('process_metrics')->nullable();
            $table->unsignedInteger('heartbeat_interval_seconds')->nullable();
            $table->timestamp('last_heartbeat_at')->nullable()->index();
            $table->string('status')->default('active')->index();
            $table->timestamps();
        });
    }
}

final class MissingWorkflowHistoryEvent extends WorkflowHistoryEvent
{
    protected $table = 'missing_workflow_history_events';
}

final class MissingWorkflowRunTimerEntry extends WorkflowRunTimerEntry
{
    protected $table = 'missing_workflow_run_timer_entries';
}

final class MissingWorkflowRun extends WorkflowRun
{
    protected $table = 'missing_workflow_runs';
}

final class MissingWorkflowRunWait extends WorkflowRunWait
{
    protected $table = 'missing_workflow_run_waits';
}
