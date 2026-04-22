<?php

namespace Waterline\Tests\Feature;

use Illuminate\Support\Str;
use Waterline\Repositories\Workflow\Infrastructure\V2WorkflowRepository;
use Waterline\Tests\TestCase;
use Workflow\Serializers\Serializer;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Models\WorkflowFailure;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowInstance;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowRunSummary;

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
            ->assertJsonPath('workflow.run_id', $billingRun->id);

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
            ->assertJsonPath('workflow.run_id', $billingRun->id);

        $this->get('/waterline/api/instances/'.$billingRun->workflow_instance_id.'/runs/'.$billingRun->id.'/history-export')
            ->assertOk()
            ->assertJsonPath('workflow.run_id', $billingRun->id);

        $this->get('/waterline/api/instances/'.$shippingRun->workflow_instance_id)
            ->assertNotFound();

        $this->get('/waterline/api/instances/'.$shippingRun->workflow_instance_id.'/runs/'.$shippingRun->id)
            ->assertNotFound();

        $this->get('/waterline/api/instances/'.$shippingRun->workflow_instance_id.'/history-export')
            ->assertNotFound();

        $this->get('/waterline/api/instances/'.$shippingRun->workflow_instance_id.'/runs/'.$shippingRun->id.'/history-export')
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
            ->assertJsonPath('operator_metrics.projections.run_summaries.summaries', 1);
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

    private function createCompletedRun(string $instanceId, string $namespace): WorkflowRun
    {
        $instance = WorkflowInstance::create([
            'id' => $instanceId,
            'workflow_class' => 'WorkflowClass',
            'workflow_type' => 'workflow.namespace-scope',
            'run_count' => 1,
            'namespace' => $namespace,
            'started_at' => now()->subMinutes(5),
        ]);

        $run = WorkflowRun::create([
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
        ]);

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
}
