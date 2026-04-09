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
use Workflow\V2\Support\HistoryExport;

class V2HistoryExportControllerTest extends TestCase
{
    public function testCanonicalRouteExportsSelectedRunHistoryBundle(): void
    {
        config()->set('waterline.engine_source', 'v2');

        [$instance, $run] = $this->createCompletedRunWithHistory();

        $this->get('/waterline/api/instances/'.$instance->id.'/runs/'.$run->id.'/history-export')
            ->assertStatus(200)
            ->assertJsonPath('schema', HistoryExport::SCHEMA)
            ->assertJsonPath('schema_version', HistoryExport::SCHEMA_VERSION)
            ->assertJsonPath('history_complete', true)
            ->assertJsonPath('workflow.instance_id', $instance->id)
            ->assertJsonPath('workflow.run_id', $run->id)
            ->assertJsonPath('workflow.status', 'completed')
            ->assertJsonPath('payloads.arguments.available', true)
            ->assertJsonPath('summary.history_event_count', 2)
            ->assertJsonPath('history_events.0.type', 'WorkflowStarted')
            ->assertJsonPath('history_events.1.type', 'WorkflowCompleted');
    }

    public function testLegacyRunRouteExportsTheSameBundleShape(): void
    {
        config()->set('waterline.engine_source', 'v2');

        [$instance, $run] = $this->createCompletedRunWithHistory();

        $this->get('/waterline/api/flows/'.$run->id.'/history-export')
            ->assertStatus(200)
            ->assertJsonPath('schema', HistoryExport::SCHEMA)
            ->assertJsonPath('workflow.instance_id', $instance->id)
            ->assertJsonPath('workflow.run_id', $run->id)
            ->assertJsonPath('history_events.0.type', 'WorkflowStarted')
            ->assertJsonPath('history_events.1.type', 'WorkflowCompleted');
    }

    /**
     * @return array{WorkflowInstance, WorkflowRun}
     */
    private function createCompletedRunWithHistory(): array
    {
        $instance = WorkflowInstance::create([
            'id' => 'history-export-waterline',
            'workflow_class' => 'WorkflowClass',
            'workflow_type' => 'workflow.export',
            'run_count' => 1,
        ]);

        $run = WorkflowRun::create([
            'id' => (string) Str::ulid(),
            'workflow_instance_id' => $instance->id,
            'run_number' => 1,
            'workflow_class' => 'WorkflowClass',
            'workflow_type' => 'workflow.export',
            'status' => 'completed',
            'closed_reason' => 'completed',
            'payload_codec' => config('workflows.serializer'),
            'arguments' => Serializer::serialize(['order-123']),
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
            'workflow_type' => 'workflow.export',
            'status' => 'completed',
            'status_bucket' => 'completed',
            'closed_reason' => 'completed',
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
            'payload' => ['workflow_type' => 'workflow.export'],
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

        return [$instance, $run];
    }
}
