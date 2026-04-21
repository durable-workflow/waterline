<?php

namespace Waterline\Tests\Feature;

use Illuminate\Support\Str;
use Waterline\Tests\TestCase;
use Workflow\Serializers\Serializer;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowInstance;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowRunSummary;

class V2NamespaceScopedVisibilityTest extends TestCase
{
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
