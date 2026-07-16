<?php

namespace Waterline\Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Mockery\MockInterface;
use RuntimeException;
use Waterline\Repositories\Workflow\Infrastructure\V2WorkflowRepository;
use Waterline\Tests\TestCase;
use Workflow\Serializers\Serializer;
use Workflow\V2\Contracts\OperatorObservabilityRepository;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Enums\ScheduleStatus;
use Workflow\V2\Models\ActivityExecution;
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

        $billingAttributes = [
            'tenant_marker' => 'billing-visible',
            'order_total_cents' => 7500,
            'is_priority' => true,
            'tags' => ['urgent', 'oversized'],
        ];
        $billingRun = $this->createCompletedRun(
            'waterline-search-attributes-billing',
            'billing',
            $billingAttributes,
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
            ->assertJsonPath('data.0.search_attributes.order_total_cents', 7500)
            ->assertJsonPath('data.0.search_attributes.is_priority', true)
            ->assertJsonPath('data.0.search_attributes.tags.0', 'urgent')
            ->assertJsonPath('data.0.search_attributes.tags.1', 'oversized')
            ->assertJsonPath('operator_scope.namespace', 'billing')
            ->assertJsonPath('visibility_filters.applied.namespace', 'billing');

        $this->assertFalse(
            collect($listResponse->json('data'))->contains('id', $shippingRun->id),
            'Search-attribute list payloads must not include runs from another namespace.',
        );
        $this->assertStringNotContainsString('shipping-secret', json_encode($listResponse->json(), JSON_THROW_ON_ERROR));

        $this->mock(OperatorObservabilityRepository::class, static function (MockInterface $mock): void {
            $mock->shouldReceive('runDetail')
                ->once()
                ->andReturnUsing(static fn (WorkflowRun $run): array => [
                    'id' => $run->id,
                    'instance_id' => $run->workflow_instance_id,
                    'run_id' => $run->id,
                    'status' => 'completed',
                    'status_bucket' => 'completed',
                    'timeline' => [],
                ]);
        });

        $detailResponse = $this->get('/waterline/api/flows/'.$billingRun->id)
            ->assertOk()
            ->assertJsonPath('run_id', $billingRun->id)
            ->assertJsonPath('namespace', 'billing')
            ->assertJsonPath('search_attributes.tenant_marker', 'billing-visible')
            ->assertJsonPath('search_attributes.order_total_cents', 7500)
            ->assertJsonPath('search_attributes.is_priority', true)
            ->assertJsonPath('search_attributes.tags.0', 'urgent')
            ->assertJsonPath('search_attributes.tags.1', 'oversized')
            ->assertJsonPath('operator_scope.namespace', 'billing');

        $this->assertStringNotContainsString('shipping-secret', json_encode($detailResponse->json(), JSON_THROW_ON_ERROR));

        $this->get('/waterline/api/flows/'.$shippingRun->id)
            ->assertNotFound();
    }

    public function testDetailFallbackIncludesOnlySelectedRunTypedSearchAttributes(): void
    {
        config()->set('waterline.engine_source', 'v2');
        config()->set('waterline.namespace', 'billing');

        $billingRun = $this->createCompletedRun(
            'waterline-fallback-search-attributes-billing',
            'billing',
            [
                'tenant_marker' => 'billing-visible',
                'order_total_cents' => 7500,
                'is_priority' => true,
                'tags' => ['urgent', 'oversized'],
            ],
        );
        $shippingRun = $this->createCompletedRun(
            'waterline-fallback-search-attributes-shipping',
            'shipping',
            ['tenant_marker' => 'shipping-secret'],
        );

        $this->mock(OperatorObservabilityRepository::class, static function (MockInterface $mock): void {
            $mock->shouldReceive('runDetail')
                ->once()
                ->andThrow(new RuntimeException('Selected-run projection unavailable.'));
        });

        $detailResponse = $this->get('/waterline/api/flows/'.$billingRun->id)
            ->assertOk()
            ->assertJsonPath('run_id', $billingRun->id)
            ->assertJsonPath('namespace', 'billing')
            ->assertJsonPath('search_attributes.tenant_marker', 'billing-visible')
            ->assertJsonPath('search_attributes.order_total_cents', 7500)
            ->assertJsonPath('search_attributes.is_priority', true)
            ->assertJsonPath('search_attributes.tags.0', 'urgent')
            ->assertJsonPath('search_attributes.tags.1', 'oversized')
            ->assertJsonPath('operator_visibility_degraded.reason', 'selected_run_projection_unavailable')
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

    public function testSelectedRunDetailCanUseRunNamespaceWhenInstanceProjectionIsMissingNamespace(): void
    {
        config()->set('waterline.engine_source', 'v2');
        config()->set('waterline.namespace', 'default');

        $run = $this->createCompletedRun('sagas-python-operator-visible-run', 'default');
        $this->recordCompletedActivity($run, 'pause_after_refund');
        WorkflowRun::whereKey($run->id)->update([
            'status' => 'waiting',
            'closed_reason' => null,
            'closed_at' => null,
            'last_history_sequence' => 4,
        ]);
        WorkflowRunSummary::whereKey($run->id)->update([
            'status' => 'waiting',
            'status_bucket' => 'running',
            'closed_reason' => null,
            'closed_at' => null,
            'history_event_count' => 4,
        ]);

        WorkflowInstance::whereKey($run->workflow_instance_id)->update([
            'namespace' => null,
        ]);

        $this->get('/waterline/api/instances/'.$run->workflow_instance_id.'/runs/'.$run->id.'?history_limit=all')
            ->assertOk()
            ->assertJsonPath('workflow_instance_id', $run->workflow_instance_id)
            ->assertJsonPath('workflow_run_id', $run->id)
            ->assertJsonPath('instance_id', $run->workflow_instance_id)
            ->assertJsonPath('run_id', $run->id)
            ->assertJsonPath('namespace', 'default')
            ->assertJsonPath('status', 'waiting')
            ->assertJsonPath('status_bucket', 'running')
            ->assertJsonPath('current_compensation_marker', 'pause_after_refund')
            ->assertJsonPath('compensation_visibility.current_marker', 'pause_after_refund')
            ->assertJsonPath('activities.0.type', 'pause_after_refund')
            ->assertJsonPath('activities.0.status', 'completed');
    }

    public function testRunningListCanUseRunNamespaceWhenSummaryProjectionIsMissingNamespace(): void
    {
        config()->set('waterline.engine_source', 'v2');
        config()->set('waterline.namespace', 'default');

        $run = $this->createCompletedRun('sagas-python-running-summary-fallback', 'default');
        $this->recordCompletedActivity($run, 'pause_after_refund');
        $hiddenRun = $this->createCompletedRun('sagas-python-running-shipping', 'shipping');

        WorkflowRun::whereKey($run->id)->update([
            'status' => 'waiting',
            'closed_reason' => null,
            'closed_at' => null,
        ]);
        WorkflowRunSummary::whereKey($run->id)->update([
            'status' => 'waiting',
            'status_bucket' => 'running',
            'closed_reason' => null,
            'closed_at' => null,
            'namespace' => null,
        ]);
        WorkflowRun::whereKey($hiddenRun->id)->update([
            'status' => 'waiting',
            'closed_reason' => null,
            'closed_at' => null,
        ]);
        WorkflowRunSummary::whereKey($hiddenRun->id)->update([
            'status' => 'waiting',
            'status_bucket' => 'running',
            'closed_reason' => null,
            'closed_at' => null,
        ]);

        $response = $this->get('/waterline/api/flows/running')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.workflow_instance_id', $run->workflow_instance_id)
            ->assertJsonPath('data.0.run_id', $run->id)
            ->assertJsonPath('data.0.status', 'waiting')
            ->assertJsonPath('data.0.status_bucket', 'running')
            ->assertJsonPath('data.0.namespace', 'default')
            ->assertJsonPath('data.0.current_compensation_marker', 'pause_after_refund')
            ->assertJsonPath('data.0.compensation_visibility.current_marker', 'pause_after_refund');

        $this->assertFalse(
            collect($response->json('data'))->contains('run_id', $hiddenRun->id),
            'Runs from another namespace must not appear through run-namespace fallback.',
        );
    }

    public function testRunningListCanUseDurableRunWhenSummaryProjectionIsMissing(): void
    {
        config()->set('waterline.engine_source', 'v2');
        config()->set('waterline.namespace', 'default');

        $run = $this->createCompletedRun('sagas-python-durable-running-fallback', 'default');
        $this->recordCompletedActivity($run, 'pause_after_refund');
        $hiddenRun = $this->createCompletedRun('sagas-python-durable-running-shipping', 'shipping');

        WorkflowRun::whereKey($run->id)->update([
            'status' => 'waiting',
            'closed_reason' => null,
            'closed_at' => null,
            'last_history_sequence' => 4,
        ]);
        WorkflowRun::whereKey($hiddenRun->id)->update([
            'status' => 'waiting',
            'closed_reason' => null,
            'closed_at' => null,
        ]);
        WorkflowRunSummary::whereKey($run->id)->delete();
        WorkflowRunSummary::whereKey($hiddenRun->id)->update([
            'status' => 'waiting',
            'status_bucket' => 'running',
            'closed_reason' => null,
            'closed_at' => null,
        ]);

        $response = $this->get('/waterline/api/flows/running')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.workflow_instance_id', $run->workflow_instance_id)
            ->assertJsonPath('data.0.run_id', $run->id)
            ->assertJsonPath('data.0.status', 'waiting')
            ->assertJsonPath('data.0.status_bucket', 'running')
            ->assertJsonPath('data.0.namespace', 'default')
            ->assertJsonPath('data.0.current_compensation_marker', 'pause_after_refund')
            ->assertJsonPath('data.0.compensation_visibility.current_marker', 'pause_after_refund')
            ->assertJsonPath('data.0.operator_visibility_degraded.reason', 'run_summary_projection_unavailable');

        $this->assertFalse(
            collect($response->json('data'))->contains('run_id', $hiddenRun->id),
            'Runs from another namespace must not appear through durable-run fallback.',
        );
    }

    public function testDirectRunDetailUsesDurableFallbackWhenOptionalSelectedRunProjectionTableIsMissing(): void
    {
        config()->set('waterline.engine_source', 'v2');
        config()->set('waterline.namespace', 'default');

        $run = $this->createCompletedRun('sagas-python-detail-missing-signal-projection', 'default');
        $this->recordCompletedActivity($run, 'pause_after_refund');

        WorkflowRun::whereKey($run->id)->update([
            'status' => 'waiting',
            'closed_reason' => null,
            'closed_at' => null,
            'last_history_sequence' => 4,
        ]);
        WorkflowRunSummary::whereKey($run->id)->update([
            'status' => 'waiting',
            'status_bucket' => 'running',
            'closed_reason' => null,
            'closed_at' => null,
            'history_event_count' => 4,
        ]);

        Schema::dropIfExists('workflow_signal_records');

        try {
            $this->get('/waterline/api/instances/'.$run->workflow_instance_id.'/runs/'.$run->id.'?history_limit=all')
                ->assertOk()
                ->assertJsonPath('workflow_instance_id', $run->workflow_instance_id)
                ->assertJsonPath('workflow_run_id', $run->id)
                ->assertJsonPath('instance_id', $run->workflow_instance_id)
                ->assertJsonPath('run_id', $run->id)
                ->assertJsonPath('namespace', 'default')
                ->assertJsonPath('status', 'waiting')
                ->assertJsonPath('status_bucket', 'running')
                ->assertJsonPath('current_compensation_marker', 'pause_after_refund')
                ->assertJsonPath('compensation_visibility.current_marker', 'pause_after_refund')
                ->assertJsonPath('activities.0.type', 'pause_after_refund')
                ->assertJsonPath('activities.0.status', 'completed')
                ->assertJsonPath('operator_visibility_degraded.reason', 'selected_run_projection_unavailable');

            $this->get('/waterline/api/flows/'.$run->id.'?history_limit=all')
                ->assertOk()
                ->assertJsonPath('workflow_instance_id', $run->workflow_instance_id)
                ->assertJsonPath('workflow_run_id', $run->id)
                ->assertJsonPath('instance_id', $run->workflow_instance_id)
                ->assertJsonPath('run_id', $run->id)
                ->assertJsonPath('namespace', 'default')
                ->assertJsonPath('status', 'waiting')
                ->assertJsonPath('status_bucket', 'running')
                ->assertJsonPath('current_compensation_marker', 'pause_after_refund')
                ->assertJsonPath('compensation_visibility.current_marker', 'pause_after_refund')
                ->assertJsonPath('activities.0.type', 'pause_after_refund')
                ->assertJsonPath('activities.0.status', 'completed')
                ->assertJsonPath('operator_visibility_degraded.reason', 'selected_run_projection_unavailable');

            $this->get('/waterline/api/flows/'.$run->id.'/history-export')
                ->assertOk()
                ->assertJsonPath('workflow.run_id', $run->id)
                ->assertJsonPath('workflow.namespace', 'default')
                ->assertJsonPath('operator_visibility_degraded.reason', 'selected_run_projection_unavailable');

            $this->postJson('/waterline/api/flows/'.$run->id.'/queries/current_state')
                ->assertStatus(409)
                ->assertJsonPath('run_id', $run->id)
                ->assertJsonPath('target_scope', 'run');
        } finally {
            $this->recreateWorkflowSignalRecordsTable();
        }
    }

    public function testDirectRunDetailUsesDurableFallbackWhenDetailProjectionInspectionFailsTransiently(): void
    {
        config()->set('waterline.engine_source', 'v2');
        config()->set('waterline.namespace', 'default');
        config()->set('workflows.v2.run_summary_model', TransientWaterlineDetailRunSummary::class);

        $run = $this->createCompletedRun('sagas-python-detail-transient-projection-failure', 'default');
        $this->recordCompletedActivity($run, 'pause_after_refund');

        WorkflowRun::whereKey($run->id)->update([
            'status' => 'waiting',
            'closed_reason' => null,
            'closed_at' => null,
            'last_history_sequence' => 4,
        ]);
        WorkflowRunSummary::whereKey($run->id)->update([
            'status' => 'waiting',
            'status_bucket' => 'running',
            'closed_reason' => null,
            'closed_at' => null,
            'history_event_count' => 4,
        ]);

        TransientWaterlineDetailRunSummary::failAfterReadinessInspection();

        try {
            $this->get('/waterline/api/instances/'.$run->workflow_instance_id.'/runs/'.$run->id.'?history_limit=all')
                ->assertOk()
                ->assertJsonPath('workflow_instance_id', $run->workflow_instance_id)
                ->assertJsonPath('workflow_run_id', $run->id)
                ->assertJsonPath('instance_id', $run->workflow_instance_id)
                ->assertJsonPath('run_id', $run->id)
                ->assertJsonPath('namespace', 'default')
                ->assertJsonPath('status', 'waiting')
                ->assertJsonPath('status_bucket', 'running')
                ->assertJsonPath('current_compensation_marker', 'pause_after_refund')
                ->assertJsonPath('compensation_visibility.current_marker', 'pause_after_refund')
                ->assertJsonPath('activities.0.type', 'pause_after_refund')
                ->assertJsonPath('activities.0.status', 'completed')
                ->assertJsonPath('operator_visibility_degraded.reason', 'selected_run_projection_unavailable');

            $this->get('/waterline/api/flows/'.$run->id.'?history_limit=all')
                ->assertOk()
                ->assertJsonPath('workflow_instance_id', $run->workflow_instance_id)
                ->assertJsonPath('workflow_run_id', $run->id)
                ->assertJsonPath('run_id', $run->id)
                ->assertJsonPath('namespace', 'default')
                ->assertJsonPath('status', 'waiting')
                ->assertJsonPath('status_bucket', 'running')
                ->assertJsonPath('current_compensation_marker', 'pause_after_refund')
                ->assertJsonPath('compensation_visibility.current_marker', 'pause_after_refund')
                ->assertJsonPath('activities.0.type', 'pause_after_refund')
                ->assertJsonPath('activities.0.status', 'completed')
                ->assertJsonPath('operator_visibility_degraded.reason', 'selected_run_projection_unavailable');
        } finally {
            TransientWaterlineDetailRunSummary::resetTransientFailure();
        }
    }

    public function testDirectRunDetailAndRunningListUseDurableHistoryWhenActivityProjectionIsMissing(): void
    {
        config()->set('waterline.engine_source', 'v2');
        config()->set('waterline.namespace', 'default');
        config()->set('workflows.v2.activity_execution_model', MissingWaterlineDetailActivityExecution::class);

        $run = $this->createCompletedRun('sagas-python-detail-missing-activity-projection', 'default');
        $this->recordRunningActivity($run, 'pause_after_refund');

        WorkflowRun::whereKey($run->id)->update([
            'status' => 'waiting',
            'closed_reason' => null,
            'closed_at' => null,
            'last_history_sequence' => 4,
        ]);
        WorkflowRunSummary::whereKey($run->id)->update([
            'status' => 'waiting',
            'status_bucket' => 'running',
            'closed_reason' => null,
            'closed_at' => null,
            'history_event_count' => 4,
        ]);

        $this->get('/waterline/api/flows/'.$run->id.'?history_limit=all')
            ->assertOk()
            ->assertJsonPath('workflow_instance_id', $run->workflow_instance_id)
            ->assertJsonPath('workflow_run_id', $run->id)
            ->assertJsonPath('instance_id', $run->workflow_instance_id)
            ->assertJsonPath('run_id', $run->id)
            ->assertJsonPath('namespace', 'default')
            ->assertJsonPath('status', 'waiting')
            ->assertJsonPath('status_bucket', 'running')
            ->assertJsonPath('current_compensation_marker', 'pause_after_refund')
            ->assertJsonPath('compensation_visibility.current_marker', 'pause_after_refund')
            ->assertJsonPath('activities.0.type', 'pause_after_refund')
            ->assertJsonPath('activities.0.status', 'running')
            ->assertJsonPath('activities.0.history_authority', 'durable_history_events')
            ->assertJsonPath('operator_visibility_degraded.reason', 'selected_run_projection_unavailable');

        $this->get('/waterline/api/flows/running')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.workflow_instance_id', $run->workflow_instance_id)
            ->assertJsonPath('data.0.run_id', $run->id)
            ->assertJsonPath('data.0.status', 'waiting')
            ->assertJsonPath('data.0.status_bucket', 'running')
            ->assertJsonPath('data.0.current_compensation_marker', 'pause_after_refund')
            ->assertJsonPath('data.0.compensation_visibility.current_marker', 'pause_after_refund');
    }

    public function testPublishedHostRoutesExposePausedSagaRunFromSharedDurableStorage(): void
    {
        config()->set('waterline.engine_source', 'v2');
        config()->set('waterline.namespace', 'default');
        config()->set('waterline.health.task_dispatch_mode', 'poll');

        $run = $this->createCompletedRun('sagas-python-operator_visible_mid_compensation_status', 'default');
        $this->recordRunningActivity($run, 'pause_after_refund');

        WorkflowRun::whereKey($run->id)->update([
            'status' => 'waiting',
            'closed_reason' => null,
            'closed_at' => null,
            'last_history_sequence' => 4,
        ]);
        WorkflowRunSummary::whereKey($run->id)->delete();

        config()->set('workflows.v2.run_summary_model', MissingWaterlineHostRunSummary::class);
        config()->set('workflows.v2.activity_execution_model', MissingWaterlineDetailActivityExecution::class);

        $this->get('/waterline/api/v2/health')
            ->assertOk()
            ->assertJsonPath('healthy', true)
            ->assertJsonPath('engine_source.status', 'v2_pinned_degraded')
            ->assertJsonPath('engine_source.uses_v2', true)
            ->assertJsonPath('engine_source.degraded_operator_surface', true)
            ->assertJsonPath('checks.0.name', 'engine_source')
            ->assertJsonPath('checks.0.status', 'ok');

        $this->get('/waterline/api/flows/running')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.workflow_instance_id', $run->workflow_instance_id)
            ->assertJsonPath('data.0.instance_id', $run->workflow_instance_id)
            ->assertJsonPath('data.0.run_id', $run->id)
            ->assertJsonPath('data.0.selected_run_id', $run->id)
            ->assertJsonPath('data.0.status', 'waiting')
            ->assertJsonPath('data.0.status_bucket', 'running')
            ->assertJsonPath('data.0.namespace', 'default')
            ->assertJsonPath('data.0.current_compensation_marker', 'pause_after_refund')
            ->assertJsonPath('data.0.compensation_visibility.current_marker', 'pause_after_refund')
            ->assertJsonPath('data.0.operator_visibility_degraded.reason', 'run_summary_projection_unavailable');

        $this->get('/waterline/api/instances/'.$run->workflow_instance_id.'/runs/'.$run->id.'?history_limit=all')
            ->assertOk()
            ->assertJsonPath('id', $run->id)
            ->assertJsonPath('workflow_instance_id', $run->workflow_instance_id)
            ->assertJsonPath('workflow_run_id', $run->id)
            ->assertJsonPath('instance_id', $run->workflow_instance_id)
            ->assertJsonPath('run_id', $run->id)
            ->assertJsonPath('selected_run_id', $run->id)
            ->assertJsonPath('namespace', 'default')
            ->assertJsonPath('status', 'waiting')
            ->assertJsonPath('status_bucket', 'running')
            ->assertJsonPath('current_compensation_marker', 'pause_after_refund')
            ->assertJsonPath('compensation_visibility.current_marker', 'pause_after_refund')
            ->assertJsonPath('activities.0.type', 'pause_after_refund')
            ->assertJsonPath('activities.0.status', 'running')
            ->assertJsonPath('activities.0.history_authority', 'durable_history_events')
            ->assertJsonPath('operator_visibility_degraded.reason', 'selected_run_projection_unavailable');
    }

    public function testDirectRunDetailMergesDurableHistoryWhenActivityProjectionLacksCompensationMarker(): void
    {
        config()->set('waterline.engine_source', 'v2');
        config()->set('waterline.namespace', 'default');

        $run = $this->createCompletedRun('sagas-python-detail-incomplete-activity-projection', 'default');
        $this->recordRunningActivity($run, 'pause_after_refund');

        $projectedActivityId = (string) Str::ulid();
        ActivityExecution::create([
            'id' => $projectedActivityId,
            'workflow_run_id' => $run->id,
            'sequence' => 1,
            'activity_class' => 'ChargeCardActivity',
            'activity_type' => 'charge_card',
            'status' => 'completed',
            'arguments' => Serializer::serialize(['order-123']),
            'connection' => 'redis',
            'queue' => 'default',
            'started_at' => now()->subSeconds(40),
            'closed_at' => now()->subSeconds(30),
        ]);

        $this->app->instance(OperatorObservabilityRepository::class, new class($projectedActivityId) implements OperatorObservabilityRepository {
            public function __construct(private string $activityId)
            {
            }

            public function runDetail(WorkflowRun $run, ?int $timelineLimit = null): array
            {
                return [
                    'id' => $run->id,
                    'instance_id' => $run->workflow_instance_id,
                    'selected_run_id' => $run->id,
                    'run_id' => $run->id,
                    'workflow_instance_id' => $run->workflow_instance_id,
                    'workflow_run_id' => $run->id,
                    'run_number' => $run->run_number,
                    'is_current_run' => true,
                    'current_run_id' => $run->id,
                    'engine_source' => 'v2',
                    'class' => $run->workflow_class,
                    'workflow_type' => $run->workflow_type,
                    'namespace' => $run->namespace,
                    'status' => 'waiting',
                    'status_bucket' => 'running',
                    'closed_reason' => null,
                    'closed_at' => null,
                    'activities_scope' => 'selected_run',
                    'activities' => [
                        [
                            'id' => $this->activityId,
                            'idempotency_key' => $this->activityId,
                            'sequence' => 1,
                            'type' => 'charge_card',
                            'class' => 'ChargeCardActivity',
                            'status' => 'completed',
                            'row_status' => 'completed',
                            'history_authority' => 'typed_history',
                            'history_event_types' => [],
                        ],
                    ],
                    'timeline' => [],
                    'timeline_total_count' => 0,
                    'timeline_returned_count' => 0,
                ];
            }

            public function listItem(WorkflowRunSummary $summary): array
            {
                return [];
            }

            public function runHistoryExport(
                WorkflowRun $run,
                ?\Carbon\CarbonInterface $exportedAt = null,
                \Workflow\V2\Contracts\HistoryExportRedactor|callable|null $redactor = null,
            ): array {
                return [];
            }

            public function dashboardSummary(?\Carbon\CarbonInterface $now = null, ?string $namespace = null): array
            {
                return [];
            }

            public function metrics(?\Carbon\CarbonInterface $now = null, ?string $namespace = null): array
            {
                return [];
            }
        });

        WorkflowRun::whereKey($run->id)->update([
            'status' => 'waiting',
            'closed_reason' => null,
            'closed_at' => null,
            'last_history_sequence' => 4,
        ]);
        WorkflowRunSummary::whereKey($run->id)->update([
            'status' => 'waiting',
            'status_bucket' => 'running',
            'closed_reason' => null,
            'closed_at' => null,
            'history_event_count' => 4,
        ]);

        $response = $this->get('/waterline/api/flows/'.$run->id.'?history_limit=all')
            ->assertOk()
            ->assertJsonPath('workflow_instance_id', $run->workflow_instance_id)
            ->assertJsonPath('workflow_run_id', $run->id)
            ->assertJsonPath('instance_id', $run->workflow_instance_id)
            ->assertJsonPath('run_id', $run->id)
            ->assertJsonPath('namespace', 'default')
            ->assertJsonPath('status', 'waiting')
            ->assertJsonPath('status_bucket', 'running')
            ->assertJsonPath('current_compensation_marker', 'pause_after_refund')
            ->assertJsonPath('compensation_visibility.current_marker', 'pause_after_refund')
            ->assertJsonPath('operator_visibility_degraded.reason', 'selected_run_projection_incomplete');

        $activities = collect($response->json('activities'));

        $this->assertTrue(
            $activities->contains(static fn (array $activity): bool => ($activity['type'] ?? null) === 'charge_card'),
            'The selected-run activity projection should remain in the merged detail payload.',
        );
        $this->assertTrue(
            $activities->contains(static fn (array $activity): bool => ($activity['type'] ?? null) === 'pause_after_refund'
                && ($activity['status'] ?? null) === 'running'
                && ($activity['history_authority'] ?? null) === 'durable_history_events'),
            'The durable activity fallback should recover the visible compensation marker.',
        );
    }

    public function testSelectedPausedCompensationRunUsesDurableIdentityWhenProjectionsDrift(): void
    {
        config()->set('waterline.engine_source', 'v2');
        config()->set('waterline.namespace', 'default');

        $run = $this->createCompletedRun('sagas-python-operator_visible_mid_compensation_status', 'default');
        $this->recordRunningActivity($run, 'pause_after_refund');

        $projectedActivityId = (string) Str::ulid();
        ActivityExecution::create([
            'id' => $projectedActivityId,
            'workflow_run_id' => $run->id,
            'sequence' => 1,
            'activity_class' => 'ChargeCardActivity',
            'activity_type' => 'charge_card',
            'status' => 'completed',
            'arguments' => Serializer::serialize(['order-123']),
            'connection' => 'redis',
            'queue' => 'default',
            'started_at' => now()->subSeconds(40),
            'closed_at' => now()->subSeconds(30),
        ]);

        $this->app->instance(OperatorObservabilityRepository::class, new class($projectedActivityId) implements OperatorObservabilityRepository {
            public function __construct(private string $activityId)
            {
            }

            public function runDetail(WorkflowRun $run, ?int $timelineLimit = null): array
            {
                return [
                    'id' => 'stale-projected-run',
                    'instance_id' => 'stale-projected-instance',
                    'selected_run_id' => 'stale-projected-run',
                    'run_id' => 'stale-projected-run',
                    'workflow_instance_id' => 'stale-projected-instance',
                    'workflow_run_id' => 'stale-projected-run',
                    'run_number' => $run->run_number,
                    'is_current_run' => true,
                    'current_run_id' => 'stale-projected-run',
                    'engine_source' => 'v2',
                    'class' => $run->workflow_class,
                    'workflow_type' => $run->workflow_type,
                    'namespace' => $run->namespace,
                    'status' => 'waiting',
                    'status_bucket' => 'running',
                    'closed_reason' => null,
                    'closed_at' => null,
                    'activities_scope' => 'selected_run',
                    'activities' => [
                        [
                            'id' => $this->activityId,
                            'idempotency_key' => $this->activityId,
                            'sequence' => 1,
                            'type' => 'charge_card',
                            'class' => 'ChargeCardActivity',
                            'status' => 'completed',
                            'row_status' => 'completed',
                            'history_authority' => 'typed_history',
                            'history_event_types' => [],
                        ],
                    ],
                    'timeline' => [],
                    'timeline_total_count' => 0,
                    'timeline_returned_count' => 0,
                ];
            }

            public function listItem(WorkflowRunSummary $summary): array
            {
                return [];
            }

            public function runHistoryExport(
                WorkflowRun $run,
                ?\Carbon\CarbonInterface $exportedAt = null,
                \Workflow\V2\Contracts\HistoryExportRedactor|callable|null $redactor = null,
            ): array {
                return [];
            }

            public function dashboardSummary(?\Carbon\CarbonInterface $now = null, ?string $namespace = null): array
            {
                return [];
            }

            public function metrics(?\Carbon\CarbonInterface $now = null, ?string $namespace = null): array
            {
                return [];
            }
        });

        WorkflowRun::whereKey($run->id)->update([
            'status' => 'waiting',
            'closed_reason' => null,
            'closed_at' => null,
            'last_history_sequence' => 4,
        ]);
        WorkflowRunSummary::whereKey($run->id)->update([
            'workflow_instance_id' => 'stale-summary-instance',
            'status' => 'waiting',
            'status_bucket' => 'running',
            'closed_reason' => null,
            'closed_at' => null,
            'history_event_count' => 4,
        ]);

        $this->get('/waterline/api/instances/'.$run->workflow_instance_id.'/runs/'.$run->id.'?history_limit=all')
            ->assertOk()
            ->assertJsonPath('id', $run->id)
            ->assertJsonPath('workflow_instance_id', $run->workflow_instance_id)
            ->assertJsonPath('workflow_run_id', $run->id)
            ->assertJsonPath('instance_id', $run->workflow_instance_id)
            ->assertJsonPath('run_id', $run->id)
            ->assertJsonPath('selected_run_id', $run->id)
            ->assertJsonPath('status', 'waiting')
            ->assertJsonPath('status_bucket', 'running')
            ->assertJsonPath('current_compensation_marker', 'pause_after_refund')
            ->assertJsonPath('compensation_visibility.current_marker', 'pause_after_refund');

        $this->get('/waterline/api/flows/running')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.workflow_instance_id', $run->workflow_instance_id)
            ->assertJsonPath('data.0.instance_id', $run->workflow_instance_id)
            ->assertJsonPath('data.0.run_id', $run->id)
            ->assertJsonPath('data.0.selected_run_id', $run->id)
            ->assertJsonPath('data.0.status', 'waiting')
            ->assertJsonPath('data.0.status_bucket', 'running')
            ->assertJsonPath('data.0.current_compensation_marker', 'pause_after_refund')
            ->assertJsonPath('data.0.compensation_visibility.current_marker', 'pause_after_refund');
    }

    public function testSummaryBackedListRowsDoNotReadDurableHistoryWhenActivityProjectionIsMissing(): void
    {
        config()->set('waterline.engine_source', 'v2');
        config()->set('waterline.namespace', 'default');

        for ($index = 1; $index <= 3; $index++) {
            $this->createCompletedRun(sprintf('waterline-list-no-activity-projection-%02d', $index), 'default');
        }

        DB::enableQueryLog();

        try {
            $this->get('/waterline/api/flows/completed')
                ->assertOk()
                ->assertJsonCount(3, 'data')
                ->assertJsonPath('data.0.current_compensation_marker', null)
                ->assertJsonPath('data.0.compensation_visibility.current_marker', null);

            $historySelects = array_values(array_filter(
                DB::getQueryLog(),
                static function (array $query): bool {
                    $sql = strtolower((string) ($query['query'] ?? ''));

                    return str_starts_with(ltrim($sql), 'select')
                        && preg_match(
                            '/\b(?:from|join)\s+["`\[]?workflow_history_events(?:["`\]]|\b)/',
                            $sql,
                        ) === 1;
                },
            ));
        } finally {
            DB::disableQueryLog();
            DB::flushQueryLog();
        }

        $this->assertSame(
            [],
            $historySelects,
            'Summary-backed list rows without activity projections should not fall back to durable history scans.',
        );
    }

    public function testRunningListDurableFallbackOnlyMergesRunsWithoutRunningSummaryProjection(): void
    {
        config()->set('waterline.engine_source', 'v2');
        config()->set('waterline.namespace', 'default');

        $missingRun = $this->createCompletedRun('waterline-running-missing-summary', 'default');

        WorkflowRun::whereKey($missingRun->id)->update([
            'status' => 'waiting',
            'closed_reason' => null,
            'closed_at' => null,
            'last_history_sequence' => 4,
        ]);
        WorkflowRunSummary::whereKey($missingRun->id)->delete();

        $summaryBackedRunIds = [];

        for ($i = 1; $i <= 51; $i++) {
            $run = $this->createCompletedRun(sprintf('waterline-running-summary-backed-%02d', $i), 'default');
            $summaryBackedRunIds[] = $run->id;

            WorkflowRun::whereKey($run->id)->update([
                'status' => 'waiting',
                'closed_reason' => null,
                'closed_at' => null,
            ]);
            WorkflowRunSummary::whereKey($run->id)->update([
                'status' => 'waiting',
                'status_bucket' => 'running',
                'closed_reason' => null,
                'closed_at' => null,
            ]);
        }

        $response = $this->get('/waterline/api/flows/running')
            ->assertOk()
            ->assertJsonCount(50, 'data');

        $rows = collect($response->json('data'));
        $durableRows = $rows->filter(
            static fn (array $row): bool => ($row['operator_visibility_degraded']['reason'] ?? null)
                === 'run_summary_projection_unavailable',
        );

        $this->assertSame(52, $response->json('total'));
        $this->assertCount(1, $durableRows);
        $this->assertSame($missingRun->id, $durableRows->first()['run_id'] ?? null);
        $this->assertRunningListContractFields($durableRows->first());
        $summaryRow = $rows
            ->reject(
                static fn (array $row): bool => ($row['operator_visibility_degraded']['reason'] ?? null)
                    === 'run_summary_projection_unavailable',
            )
            ->first();
        $this->assertIsArray($summaryRow);
        $this->assertRunningListContractFields($summaryRow);
        $this->assertFalse(
            $durableRows->contains(
                static fn (array $row): bool => in_array($row['run_id'] ?? null, $summaryBackedRunIds, true),
            ),
            'Summary-backed runs from later pages must not be rendered again by the durable fallback.',
        );

        $pageTwoResponse = $this->get('/waterline/api/flows/running?page=2')
            ->assertOk()
            ->assertJsonCount(2, 'data');

        $pageOneRunIds = $rows->pluck('run_id');
        $pageTwoRows = collect($pageTwoResponse->json('data'));
        $pageTwoRunIds = $pageTwoRows->pluck('run_id');
        $allPagedSummaryRunIds = $pageOneRunIds
            ->merge($pageTwoRunIds)
            ->reject(static fn (mixed $runId): bool => $runId === $missingRun->id)
            ->values()
            ->all();

        $this->assertSame(52, $pageTwoResponse->json('total'));
        $this->assertFalse(
            $pageTwoRunIds->contains($missingRun->id),
            'Missing-summary durable fallback rows must not repeat on page 2.',
        );
        $this->assertEmpty(
            $pageOneRunIds->intersect($pageTwoRunIds)->values()->all(),
            'Mixed durable and summary pagination must not repeat rows across pages.',
        );
        $this->assertEqualsCanonicalizing($summaryBackedRunIds, $allPagedSummaryRunIds);
        $this->assertFalse(
            $pageTwoRows->contains(
                static fn (array $row): bool => ($row['operator_visibility_degraded']['reason'] ?? null)
                    === 'run_summary_projection_unavailable',
            ),
            'Page 2 should contain summary-backed rows after the page-1 durable fallback has been consumed.',
        );
    }

    public function testRunningListMixedDurableFallbackRowsUseAnnotatedListItemContract(): void
    {
        config()->set('waterline.engine_source', 'v2');
        config()->set('waterline.namespace', 'default');

        $missingRun = $this->createCompletedRun('waterline-running-contract-missing-summary', 'default');
        $summaryBackedRun = $this->createCompletedRun('waterline-running-contract-summary-backed', 'default');

        foreach ([$missingRun, $summaryBackedRun] as $run) {
            WorkflowRun::whereKey($run->id)->update([
                'status' => 'waiting',
                'closed_reason' => null,
                'closed_at' => null,
                'last_history_sequence' => 4,
            ]);
        }

        WorkflowRunSummary::whereKey($missingRun->id)->delete();
        WorkflowRunSummary::whereKey($summaryBackedRun->id)->update([
            'status' => 'waiting',
            'status_bucket' => 'running',
            'closed_reason' => null,
            'closed_at' => null,
            'history_event_count' => 4,
        ]);

        $rows = collect(
            $this->get('/waterline/api/flows/running')
                ->assertOk()
                ->assertJsonCount(2, 'data')
                ->json('data'),
        );

        $durableRow = $rows->first(
            static fn (array $row): bool => ($row['operator_visibility_degraded']['reason'] ?? null)
                === 'run_summary_projection_unavailable',
        );
        $summaryRow = $rows->first(
            static fn (array $row): bool => ($row['run_id'] ?? null) === $summaryBackedRun->id,
        );

        $this->assertIsArray($durableRow);
        $this->assertIsArray($summaryRow);
        $this->assertSame($missingRun->id, $durableRow['run_id'] ?? null);
        $this->assertRunningListContractFields($durableRow);
        $this->assertRunningListContractFields($summaryRow);
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
            ->assertJsonPath('search_attributes.tenant_marker', 'billing-schedule')
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

    /**
     * @param array<string, mixed> $row
     */
    private function assertRunningListContractFields(array $row): void
    {
        foreach ([
            'history_budget_indicator',
            'compatibility_supported',
            'compatibility_reason',
            'compatibility_supported_in_fleet',
            'compatibility_fleet_reason',
            'compatibility_namespace',
            'compatibility_semantics',
            'actionability',
            'detail_action',
        ] as $field) {
            $this->assertArrayHasKey($field, $row);
        }

        $this->assertIsArray($row['history_budget_indicator']);
        $this->assertIsArray($row['compatibility_semantics']);
        $this->assertIsArray($row['actionability']);
        $this->assertIsArray($row['detail_action']);
        $this->assertSame('waterline.actionability', $row['actionability']['schema'] ?? null);
        $this->assertSame(1, $row['actionability']['version'] ?? null);
        $this->assertSame('Run Detail', $row['detail_action']['label'] ?? null);
        $this->assertTrue($row['detail_action']['available'] ?? false);
    }

    private function recreateWorkflowSignalRecordsTable(): void
    {
        if (Schema::hasTable('workflow_signal_records')) {
            return;
        }

        Schema::create('workflow_signal_records', static function (Blueprint $table): void {
            $table->string('id', 26)
                ->primary();
            $table->string('workflow_command_id', 26)
                ->unique();
            $table->string('workflow_instance_id', 191)
                ->nullable()
                ->index();
            $table->string('workflow_run_id', 26)
                ->nullable()
                ->index();
            $table->string('target_scope')
                ->default('instance');
            $table->string('requested_workflow_run_id', 26)
                ->nullable()
                ->index();
            $table->string('resolved_workflow_run_id', 26)
                ->nullable()
                ->index();
            $table->string('signal_name')
                ->index();
            $table->string('signal_wait_id')
                ->nullable()
                ->index();
            $table->string('status')
                ->index();
            $table->string('outcome')
                ->nullable()
                ->index();
            $table->unsignedInteger('command_sequence')
                ->nullable()
                ->index();
            $table->unsignedInteger('workflow_sequence')
                ->nullable()
                ->index();
            $table->string('payload_codec')
                ->nullable();
            $table->longText('arguments')
                ->nullable();
            $table->json('validation_errors')
                ->nullable();
            $table->string('rejection_reason')
                ->nullable();
            $table->timestamp('received_at', 6)
                ->nullable();
            $table->timestamp('applied_at', 6)
                ->nullable();
            $table->timestamp('rejected_at', 6)
                ->nullable();
            $table->timestamp('closed_at', 6)
                ->nullable();
            $table->timestamps(6);
        });
    }

    private function recordCompletedActivity(WorkflowRun $run, string $activityType): void
    {
        $activityId = (string) Str::ulid();
        $attemptId = (string) Str::ulid();
        $scheduledAt = now()->subSeconds(20);
        $startedAt = now()->subSeconds(15);
        $closedAt = now()->subSeconds(10);

        WorkflowHistoryEvent::create([
            'id' => (string) Str::ulid(),
            'workflow_run_id' => $run->id,
            'sequence' => 3,
            'event_type' => HistoryEventType::ActivityScheduled->value,
            'payload' => [
                'activity_execution_id' => $activityId,
                'activity_type' => $activityType,
                'activity_class' => 'SagaPauseActivity',
                'sequence' => 1,
                'activity' => [
                    'id' => $activityId,
                    'sequence' => 1,
                    'type' => $activityType,
                    'class' => 'SagaPauseActivity',
                    'attempt_id' => $attemptId,
                    'status' => 'pending',
                    'attempt_count' => 1,
                    'connection' => 'redis',
                    'queue' => 'default',
                    'created_at' => $scheduledAt->jsonSerialize(),
                ],
            ],
            'recorded_at' => $scheduledAt,
        ]);

        WorkflowHistoryEvent::create([
            'id' => (string) Str::ulid(),
            'workflow_run_id' => $run->id,
            'sequence' => 4,
            'event_type' => HistoryEventType::ActivityCompleted->value,
            'payload' => [
                'activity_execution_id' => $activityId,
                'activity_type' => $activityType,
                'activity_class' => 'SagaPauseActivity',
                'sequence' => 1,
                'activity' => [
                    'id' => $activityId,
                    'sequence' => 1,
                    'type' => $activityType,
                    'class' => 'SagaPauseActivity',
                    'attempt_id' => $attemptId,
                    'status' => 'completed',
                    'attempt_count' => 1,
                    'connection' => 'redis',
                    'queue' => 'default',
                    'created_at' => $scheduledAt->jsonSerialize(),
                    'started_at' => $startedAt->jsonSerialize(),
                    'closed_at' => $closedAt->jsonSerialize(),
                ],
            ],
            'recorded_at' => $closedAt,
        ]);
    }

    private function recordRunningActivity(WorkflowRun $run, string $activityType): void
    {
        $activityId = (string) Str::ulid();
        $attemptId = (string) Str::ulid();
        $scheduledAt = now()->subSeconds(20);
        $startedAt = now()->subSeconds(15);

        WorkflowHistoryEvent::create([
            'id' => (string) Str::ulid(),
            'workflow_run_id' => $run->id,
            'sequence' => 3,
            'event_type' => HistoryEventType::ActivityScheduled->value,
            'payload' => [
                'activity_execution_id' => $activityId,
                'activity_type' => $activityType,
                'activity_class' => 'SagaPauseActivity',
                'sequence' => 1,
                'activity' => [
                    'id' => $activityId,
                    'sequence' => 1,
                    'type' => $activityType,
                    'class' => 'SagaPauseActivity',
                    'attempt_id' => $attemptId,
                    'status' => 'pending',
                    'attempt_count' => 1,
                    'connection' => 'redis',
                    'queue' => 'default',
                    'created_at' => $scheduledAt->jsonSerialize(),
                ],
            ],
            'recorded_at' => $scheduledAt,
        ]);

        WorkflowHistoryEvent::create([
            'id' => (string) Str::ulid(),
            'workflow_run_id' => $run->id,
            'sequence' => 4,
            'event_type' => HistoryEventType::ActivityStarted->value,
            'payload' => [
                'activity_execution_id' => $activityId,
                'activity_attempt_id' => $attemptId,
                'activity_type' => $activityType,
                'activity_class' => 'SagaPauseActivity',
                'sequence' => 1,
                'attempt_number' => 1,
                'activity' => [
                    'id' => $activityId,
                    'sequence' => 1,
                    'type' => $activityType,
                    'class' => 'SagaPauseActivity',
                    'attempt_id' => $attemptId,
                    'status' => 'running',
                    'attempt_count' => 1,
                    'connection' => 'redis',
                    'queue' => 'default',
                    'created_at' => $scheduledAt->jsonSerialize(),
                    'started_at' => $startedAt->jsonSerialize(),
                ],
            ],
            'recorded_at' => $startedAt,
        ]);
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
            'search_attributes' => ['tenant_marker' => $namespace.'-schedule'],
            'fires_count' => 0,
            'failures_count' => 0,
            'skipped_trigger_count' => 0,
            'jitter_seconds' => 0,
            'next_fire_at' => now()->addHour(),
        ]);
    }
}

final class MissingWaterlineDetailActivityExecution extends \Workflow\V2\Models\ActivityExecution
{
    protected $table = 'missing_activity_executions';
}

final class MissingWaterlineHostRunSummary extends WorkflowRunSummary
{
    protected $table = 'missing_host_workflow_run_summaries';
}

final class TransientWaterlineDetailRunSummary extends WorkflowRunSummary
{
    private static bool $failAfterFirstTableResolution = false;

    private static int $tableResolutions = 0;

    public static function failAfterReadinessInspection(): void
    {
        self::$failAfterFirstTableResolution = true;
        self::$tableResolutions = 0;
    }

    public static function resetTransientFailure(): void
    {
        self::$failAfterFirstTableResolution = false;
        self::$tableResolutions = 0;
    }

    public function getTable()
    {
        if (self::$failAfterFirstTableResolution) {
            self::$tableResolutions++;

            if (self::$tableResolutions > 1) {
                throw new RuntimeException('Transient selected-run projection schema inspection failure.');
            }
        }

        return parent::getTable();
    }
}
