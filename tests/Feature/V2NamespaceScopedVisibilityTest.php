<?php

namespace Waterline\Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Schema;
use Waterline\Repositories\Workflow\Infrastructure\V2WorkflowRepository;
use Waterline\Tests\TestCase;
use Workflow\Serializers\Serializer;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Enums\ScheduleStatus;
use Workflow\V2\Models\WorkflowFailure;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowInstance;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowRunSummary;
use Workflow\V2\Models\WorkflowSchedule;
use Workflow\V2\Models\WorkflowScheduleHistoryEvent;
use Workflow\V2\Models\WorkflowSearchAttribute;

class V2NamespaceScopedVisibilityTest extends TestCase
{
    public function testListRoutesAreScopedToConfiguredNamespace(): void
    {
        config()->set('waterline.engine_source', 'v2');
        config()->set('waterline.namespace', 'billing');

        $billingRun = $this->createCompletedRun('waterline-list-billing-run', 'billing');
        $shippingRun = $this->createCompletedRun('waterline-list-shipping-run', 'shipping');

        $response = $this->get('/waterline/api/flows/completed')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $billingRun->id)
            ->assertJsonPath('data.0.namespace', 'billing')
            ->assertJsonPath('visibility_filters.applied.namespace', 'billing');

        $this->assertFalse(
            collect($response->json('data'))->contains('id', $shippingRun->id),
            'Runs from another namespace must not appear in list route payloads.',
        );
    }

    public function testListAndDetailSearchAttributeDisplaysAreScopedToConfiguredNamespace(): void
    {
        config()->set('waterline.engine_source', 'v2');
        config()->set('waterline.namespace', 'billing');

        $billingRun = $this->createCompletedRun(
            'waterline-search-attributes-billing',
            'billing',
            ['tenant_marker' => 'billing-visible'],
        );
        $shippingRun = $this->createCompletedRun(
            'waterline-search-attributes-shipping',
            'shipping',
            ['tenant_marker' => 'shipping-secret'],
        );

        $listResponse = $this->get('/waterline/api/flows/completed')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $billingRun->id)
            ->assertJsonPath('data.0.namespace', 'billing')
            ->assertJsonPath('data.0.search_attributes.tenant_marker', 'billing-visible')
            ->assertJsonPath('operator_scope.namespace', 'billing')
            ->assertJsonPath('visibility_filters.applied.namespace', 'billing');

        $this->assertFalse(
            collect($listResponse->json('data'))->contains('id', $shippingRun->id),
            'Search-attribute list payloads must not include runs from another namespace.',
        );
        $this->assertStringNotContainsString('shipping-secret', json_encode($listResponse->json(), JSON_THROW_ON_ERROR));

        $detailResponse = $this->get('/waterline/api/flows/'.$billingRun->id)
            ->assertOk()
            ->assertJsonPath('run_id', $billingRun->id)
            ->assertJsonPath('namespace', 'billing')
            ->assertJsonPath('search_attributes.tenant_marker', 'billing-visible')
            ->assertJsonPath('operator_scope.namespace', 'billing');

        $this->assertStringNotContainsString('shipping-secret', json_encode($detailResponse->json(), JSON_THROW_ON_ERROR));

        $this->get('/waterline/api/flows/'.$shippingRun->id)
            ->assertNotFound();
    }

    public function testListRoutesStayReadableWhenLegacyRunSearchAttributesColumnIsAbsent(): void
    {
        config()->set('waterline.engine_source', 'v2');
        config()->set('waterline.namespace', 'billing');

        $billingRun = $this->createCompletedRun('waterline-list-billing-no-run-search-attributes', 'billing');
        $droppedSearchAttributes = Schema::hasColumn('workflow_runs', 'search_attributes');

        if ($droppedSearchAttributes) {
            Schema::table('workflow_runs', static function (Blueprint $table): void {
                $table->dropColumn('search_attributes');
            });
        }

        try {
            $this->get('/waterline/api/flows/completed')
                ->assertOk()
                ->assertJsonCount(1, 'data')
                ->assertJsonPath('data.0.id', $billingRun->id)
                ->assertJsonPath('data.0.namespace', 'billing');
        } finally {
            if ($droppedSearchAttributes) {
                Schema::table('workflow_runs', static function (Blueprint $table): void {
                    $table->json('search_attributes')->nullable();
                });
            }
        }
    }

    public function testDirectRunDetailAndExportRoutesAreScopedToConfiguredNamespace(): void
    {
        config()->set('waterline.engine_source', 'v2');
        config()->set('waterline.namespace', 'billing');

        $billingRun = $this->createCompletedRun('waterline-namespace-billing-run', 'billing');
        $shippingRun = $this->createCompletedRun('waterline-namespace-shipping-run', 'shipping');

        $this->get('/waterline/api/flows/'.$billingRun->id)
            ->assertOk()
            ->assertJsonPath('run_id', $billingRun->id);

        $this->get('/waterline/api/flows/'.$billingRun->id.'/history-export')
            ->assertOk()
            ->assertJsonPath('workflow.run_id', $billingRun->id)
            ->assertJsonPath('workflow.namespace', 'billing')
            ->assertJsonPath('namespace', 'billing')
            ->assertJsonPath('operator_scope.namespace', 'billing');

        $this->get('/waterline/api/flows/'.$shippingRun->id)
            ->assertNotFound();

        $this->get('/waterline/api/flows/'.$shippingRun->id.'/history-export')
            ->assertNotFound();
    }

    public function testDirectInstanceDetailAndExportRoutesAreScopedToConfiguredNamespace(): void
    {
        config()->set('waterline.engine_source', 'v2');
        config()->set('waterline.namespace', 'billing');

        $billingRun = $this->createCompletedRun('waterline-namespace-billing-instance', 'billing');
        $shippingRun = $this->createCompletedRun('waterline-namespace-shipping-instance', 'shipping');

        $this->get('/waterline/api/instances/'.$billingRun->workflow_instance_id)
            ->assertOk()
            ->assertJsonPath('run_id', $billingRun->id);

        $this->get('/waterline/api/instances/'.$billingRun->workflow_instance_id.'/runs/'.$billingRun->id)
            ->assertOk()
            ->assertJsonPath('run_id', $billingRun->id);

        $this->get('/waterline/api/instances/'.$billingRun->workflow_instance_id.'/history-export')
            ->assertOk()
            ->assertJsonPath('workflow.run_id', $billingRun->id)
            ->assertJsonPath('workflow.namespace', 'billing')
            ->assertJsonPath('operator_scope.namespace', 'billing');

        $this->get('/waterline/api/instances/'.$billingRun->workflow_instance_id.'/runs/'.$billingRun->id.'/history-export')
            ->assertOk()
            ->assertJsonPath('workflow.run_id', $billingRun->id)
            ->assertJsonPath('workflow.namespace', 'billing')
            ->assertJsonPath('operator_scope.namespace', 'billing');

        $this->get('/waterline/api/instances/'.$shippingRun->workflow_instance_id)
            ->assertNotFound();

        $this->get('/waterline/api/instances/'.$shippingRun->workflow_instance_id.'/runs/'.$shippingRun->id)
            ->assertNotFound();

        $this->get('/waterline/api/instances/'.$shippingRun->workflow_instance_id.'/history-export')
            ->assertNotFound();

        $this->get('/waterline/api/instances/'.$shippingRun->workflow_instance_id.'/runs/'.$shippingRun->id.'/history-export')
            ->assertNotFound();
    }

    public function testScheduleOperatorApiIsScopedToConfiguredNamespace(): void
    {
        config()->set('waterline.engine_source', 'v2');
        config()->set('waterline.namespace', 'billing');

        $billingSchedule = $this->createSchedule('shared-schedule', 'billing');
        $shippingSchedule = $this->createSchedule('shared-schedule', 'shipping');
        $shippingOnlySchedule = $this->createSchedule('shipping-only-schedule', 'shipping');

        WorkflowScheduleHistoryEvent::record(
            $billingSchedule,
            HistoryEventType::SchedulePaused,
            [
                'reason' => 'billing maintenance',
                'command_context' => ['source' => 'waterline'],
            ],
        );
        WorkflowScheduleHistoryEvent::record(
            $shippingSchedule,
            HistoryEventType::SchedulePaused,
            [
                'reason' => 'shipping maintenance',
                'command_context' => ['source' => 'waterline'],
            ],
        );

        $indexResponse = $this->getJson('/waterline/api/v2/schedules')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.schedule_id', 'shared-schedule')
            ->assertJsonPath('data.0.namespace', 'billing')
            ->assertJsonPath('operator_scope.namespace', 'billing');

        $this->assertFalse(
            collect($indexResponse->json('data'))->contains('id', $shippingOnlySchedule->id),
            'Schedule list payloads must not include schedules from another namespace.',
        );

        $this->getJson('/waterline/api/v2/schedules/shared-schedule')
            ->assertOk()
            ->assertJsonPath('schedule_id', 'shared-schedule')
            ->assertJsonPath('namespace', 'billing')
            ->assertJsonPath('operator_scope.namespace', 'billing');

        $historyResponse = $this->getJson('/waterline/api/v2/schedules/shared-schedule/history')
            ->assertOk()
            ->assertJsonPath('namespace', 'billing')
            ->assertJsonPath('operator_scope.namespace', 'billing')
            ->assertJsonCount(1, 'events');

        $this->assertSame('billing maintenance', $historyResponse->json('events.0.payload.reason'));
        $this->assertStringNotContainsString('shipping maintenance', json_encode($historyResponse->json(), JSON_THROW_ON_ERROR));

        $this->getJson('/waterline/api/v2/schedules/'.$shippingOnlySchedule->schedule_id)
            ->assertNotFound();

        $this->postJson('/waterline/api/v2/schedules/'.$shippingOnlySchedule->schedule_id.'/pause')
            ->assertNotFound();
    }

    public function testDashboardStatsAndOperatorMetricsAreScopedToConfiguredNamespace(): void
    {
        config()->set('waterline.engine_source', 'v2');
        config()->set('waterline.namespace', 'billing');

        $billingRun = $this->createCompletedRun('waterline-dashboard-billing-instance', 'billing');
        $shippingRun = $this->createCompletedRun('waterline-dashboard-shipping-instance', 'shipping');

        WorkflowRunSummary::whereKey($billingRun->id)->update([
            'duration_ms' => 60000,
            'exception_count' => 1,
        ]);
        WorkflowRunSummary::whereKey($shippingRun->id)->update([
            'status' => 'failed',
            'status_bucket' => 'failed',
            'closed_reason' => 'failed',
            'duration_ms' => 600000,
            'exception_count' => 9,
            'updated_at' => now(),
        ]);

        WorkflowFailure::create([
            'id' => 'waterline-dashboard0303405',
            'workflow_run_id' => $billingRun->id,
            'source_kind' => 'activity_execution',
            'source_id' => 'activity-1',
            'propagation_kind' => 'activity',
            'handled' => false,
            'exception_class' => \RuntimeException::class,
            'message' => 'billing boom',
            'file' => __FILE__,
            'line' => 42,
            'trace_preview' => 'trace',
            'created_at' => now(),
        ]);
        WorkflowFailure::create([
            'id' => 'waterline-dashboardE95E90A',
            'workflow_run_id' => $shippingRun->id,
            'source_kind' => 'activity_execution',
            'source_id' => 'activity-2',
            'propagation_kind' => 'activity',
            'handled' => false,
            'exception_class' => \RuntimeException::class,
            'message' => 'shipping boom',
            'file' => __FILE__,
            'line' => 43,
            'trace_preview' => 'trace',
            'created_at' => now(),
        ]);

        $this->get('/waterline/api/stats')
            ->assertOk()
            ->assertJsonPath('flows', 1)
            ->assertJsonPath('flows_past_hour', 1)
            ->assertJsonPath('exceptions_past_hour', 1)
            ->assertJsonPath('failed_flows_past_week', 0)
            ->assertJsonPath('max_duration_workflow.id', $billingRun->id)
            ->assertJsonPath('max_exceptions_workflow.id', $billingRun->id)
            ->assertJsonPath('operator_metrics.runs.total', 1)
            ->assertJsonPath('operator_metrics.runs.completed', 1)
            ->assertJsonPath('operator_metrics.runs.failed', 0)
            ->assertJsonPath('operator_metrics.projections.run_summaries.runs', 1)
            ->assertJsonPath('operator_metrics.projections.run_summaries.summaries', 1)
            ->assertJsonPath('operator_scope.namespace', 'billing');
    }

    public function testOperatorHealthApiSurfacesConfiguredNamespaceScope(): void
    {
        config()->set('queue.default', 'redis');
        config()->set('queue.connections.redis.driver', 'redis');
        config()->set('cache.default', 'file');
        config()->set('waterline.engine_source', 'v2');
        config()->set('waterline.namespace', 'billing');

        $this->getJson('/waterline/api/v2/health')
            ->assertOk()
            ->assertJsonPath('namespace', 'billing')
            ->assertJsonPath('operator_scope.namespace', 'billing');
    }

    public function testSavedViewsAndPreferencesDefaultToNamespacePersistenceScope(): void
    {
        config()->set('waterline.engine_source', 'v2');
        config()->set('waterline.namespace', 'billing');
        config()->set('waterline.saved_views.scope', 'default');
        config()->set('waterline.preferences.scope', 'default');

        $billingViewId = $this->postJson('/waterline/api/saved-views', [
            'name' => 'Billing customer filter',
            'bucket' => 'running',
            'filters' => [
                'search_attributes' => [
                    'customer_id' => 'billing-customer',
                ],
            ],
            'shared' => true,
        ])
            ->assertCreated()
            ->assertJsonPath('scope', 'namespace:billing')
            ->assertJsonPath('operator_scope.namespace', 'billing')
            ->json('id');

        $this->putJson('/waterline/api/preferences/workflow-list', [
            'preferences' => [
                'saved_view_id' => $billingViewId,
            ],
        ])
            ->assertOk()
            ->assertJsonPath('scope', 'namespace:billing')
            ->assertJsonPath('operator_scope.namespace', 'billing');

        config()->set('waterline.namespace', 'shipping');

        $this->getJson('/waterline/api/saved-views?bucket=running')
            ->assertOk()
            ->assertJsonMissing(['id' => $billingViewId])
            ->assertJsonPath('operator_scope.namespace', 'shipping')
            ->assertJsonPath('saved_view_policy.operator_scope.namespace', 'shipping');

        $this->getJson('/waterline/api/preferences/workflow-list')
            ->assertOk()
            ->assertJsonPath('scope', 'namespace:shipping')
            ->assertJsonPath('preferences', [])
            ->assertJsonPath('effective_preferences', [])
            ->assertJsonPath('operator_scope.namespace', 'shipping');

        $shippingViewId = $this->postJson('/waterline/api/saved-views', [
            'name' => 'Shipping customer filter',
            'bucket' => 'running',
            'filters' => [
                'search_attributes' => [
                    'customer_id' => 'shipping-customer',
                ],
            ],
            'shared' => true,
        ])
            ->assertCreated()
            ->assertJsonPath('scope', 'namespace:shipping')
            ->assertJsonPath('operator_scope.namespace', 'shipping')
            ->json('id');

        $this->assertDatabaseHas('waterline_saved_views', [
            'id' => $billingViewId,
            'scope' => 'namespace:billing',
        ]);
        $this->assertDatabaseHas('waterline_saved_views', [
            'id' => $shippingViewId,
            'scope' => 'namespace:shipping',
        ]);
        $this->assertDatabaseHas('waterline_user_preferences', [
            'scope' => 'namespace:billing',
            'subject_key' => 'scope:namespace:billing',
            'surface' => 'workflow-list',
        ]);
    }

    public function testRepositoryMetricHelpersAreScopedToConfiguredNamespace(): void
    {
        config()->set('waterline.engine_source', 'v2');
        config()->set('waterline.namespace', 'billing');

        $billingRun = $this->createCompletedRun('waterline-metrics-billing-instance', 'billing');
        $shippingRun = $this->createCompletedRun('waterline-metrics-shipping-instance', 'shipping');

        WorkflowRunSummary::whereKey($billingRun->id)->update([
            'duration_ms' => 60000,
            'exception_count' => 1,
            'updated_at' => now(),
        ]);
        WorkflowRunSummary::whereKey($shippingRun->id)->update([
            'status' => 'failed',
            'status_bucket' => 'failed',
            'closed_reason' => 'failed',
            'duration_ms' => 600000,
            'exception_count' => 9,
            'updated_at' => now(),
        ]);

        WorkflowFailure::create([
            'id' => 'waterline-metrics88F94A4',
            'workflow_run_id' => $billingRun->id,
            'source_kind' => 'activity_execution',
            'source_id' => 'activity-1',
            'propagation_kind' => 'activity',
            'handled' => false,
            'exception_class' => \RuntimeException::class,
            'message' => 'billing boom',
            'file' => __FILE__,
            'line' => 42,
            'trace_preview' => 'trace',
            'created_at' => now(),
        ]);
        WorkflowFailure::create([
            'id' => 'waterline-metrics6A82121',
            'workflow_run_id' => $shippingRun->id,
            'source_kind' => 'activity_execution',
            'source_id' => 'activity-2',
            'propagation_kind' => 'activity',
            'handled' => false,
            'exception_class' => \RuntimeException::class,
            'message' => 'shipping boom',
            'file' => __FILE__,
            'line' => 43,
            'trace_preview' => 'trace',
            'created_at' => now(),
        ]);

        $repository = app(V2WorkflowRepository::class);

        $this->assertSame(1, $repository->totalFlows());
        $this->assertSame(1, $repository->flowsPastHour());
        $this->assertSame(1, $repository->exceptionsPastHour());
        $this->assertSame(0, $repository->failedFlowsPastWeek());
        $this->assertSame($billingRun->id, $repository->maxDurationWorkflow()?->id);
        $this->assertSame($billingRun->id, $repository->maxExceptionsWorkflow()?->id);
    }

    /**
     * @param array<string, mixed> $searchAttributes
     */
    private function createCompletedRun(string $instanceId, string $namespace, array $searchAttributes = []): WorkflowRun
    {
        $instance = WorkflowInstance::create([
            'id' => $instanceId,
            'workflow_class' => 'WorkflowClass',
            'workflow_type' => 'workflow.namespace-scope',
            'run_count' => 1,
            'namespace' => $namespace,
            'started_at' => now()->subMinutes(5),
        ]);

        $runAttributes = [
            'id' => (string) Str::ulid(),
            'workflow_instance_id' => $instance->id,
            'run_number' => 1,
            'workflow_class' => 'WorkflowClass',
            'workflow_type' => 'workflow.namespace-scope',
            'status' => 'completed',
            'closed_reason' => 'completed',
            'namespace' => $namespace,
            'payload_codec' => config('workflows.serializer'),
            'arguments' => Serializer::serialize([]),
            'output' => Serializer::serialize(['ok' => true]),
            'connection' => 'redis',
            'queue' => 'default',
            'last_history_sequence' => 2,
            'started_at' => now()->subMinutes(5),
            'closed_at' => now()->subMinute(),
            'last_progress_at' => now()->subMinute(),
        ];

        if (Schema::hasColumn('workflow_runs', 'search_attributes')) {
            $runAttributes['search_attributes'] = $searchAttributes === [] ? null : $searchAttributes;
        }

        $run = WorkflowRun::create($runAttributes);

        $instance->update(['current_run_id' => $run->id]);

        WorkflowRunSummary::create([
            'id' => $run->id,
            'workflow_instance_id' => $instance->id,
            'run_number' => 1,
            'is_current_run' => true,
            'engine_source' => 'v2',
            'class' => 'WorkflowClass',
            'workflow_type' => 'workflow.namespace-scope',
            'status' => 'completed',
            'status_bucket' => 'completed',
            'closed_reason' => 'completed',
            'namespace' => $namespace,
            'started_at' => $run->started_at,
            'closed_at' => $run->closed_at,
            'duration_ms' => 240000,
            'exception_count' => 0,
            'history_event_count' => 2,
            'history_size_bytes' => 128,
            'continue_as_new_recommended' => false,
            'created_at' => now()->subMinutes(5),
            'updated_at' => now()->subMinute(),
        ]);

        foreach ($searchAttributes as $key => $value) {
            $attribute = new WorkflowSearchAttribute([
                'workflow_run_id' => $run->id,
                'workflow_instance_id' => $instance->id,
                'key' => $key,
                'upserted_at_sequence' => 1,
                'inherited_from_parent' => false,
            ]);
            $attribute->setTypedValueWithInference($value);
            $attribute->save();
        }

        WorkflowHistoryEvent::create([
            'id' => (string) Str::ulid(),
            'workflow_run_id' => $run->id,
            'sequence' => 1,
            'event_type' => HistoryEventType::WorkflowStarted->value,
            'payload' => [
                'workflow_type' => 'workflow.namespace-scope',
                'workflow_instance_id' => $instance->id,
                'workflow_run_id' => $run->id,
            ],
            'recorded_at' => now()->subMinutes(5),
        ]);

        WorkflowHistoryEvent::create([
            'id' => (string) Str::ulid(),
            'workflow_run_id' => $run->id,
            'sequence' => 2,
            'event_type' => HistoryEventType::WorkflowCompleted->value,
            'payload' => ['result_available' => true],
            'recorded_at' => now()->subMinute(),
        ]);

        return $run;
    }

    private function createSchedule(string $scheduleId, string $namespace): WorkflowSchedule
    {
        return WorkflowSchedule::create([
            'schedule_id' => $scheduleId,
            'namespace' => $namespace,
            'spec' => ['cron_expressions' => ['0 * * * *'], 'timezone' => 'UTC'],
            'action' => ['workflow_type' => 'workflow.namespace-schedule', 'workflow_class' => 'WorkflowClass'],
            'status' => ScheduleStatus::Active,
            'overlap_policy' => 'skip',
            'fires_count' => 0,
            'failures_count' => 0,
            'skipped_trigger_count' => 0,
            'jitter_seconds' => 0,
            'next_fire_at' => now()->addHour(),
        ]);
    }
}
