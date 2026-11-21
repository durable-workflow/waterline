<?php

declare(strict_types=1);

namespace Waterline\Tests\Compatibility;

use Composer\InstalledVersions;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Waterline\Support\TaskAttention;
use Waterline\Tests\TestCase;
use Workflow\V2\Models\WorkflowInstance;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowRunSummary;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\Support\RunSummarySortKey;

class V2TaskAttentionTest extends TestCase
{
    public function testEmbeddedListDetailAndMetricsExposeRepeatedLeaseLoss(): void
    {
        config()->set('waterline.engine_source', 'v2');
        if (! $this->currentWorkflowPackage()) {
            $this->markTestSkipped('This check needs Workflow 2.34.0 or newer.');
        }
        $this->assertTrue(TaskAttention::supported());

        $affected = $this->runningSummary('affected');
        $healthy = $this->runningSummary('healthy');
        $task = WorkflowTask::create([
            'id' => 'workflow-task-at-threshold',
            'workflow_run_id' => $affected->id,
            'task_type' => 'workflow',
            'status' => 'ready',
            'lost_lease_count' => 3,
            'last_lease_lost_at' => now(),
        ]);
        $attention = [
            'reason' => 'workflow_task_lease_lost',
            'consecutive' => 3,
            'threshold' => 3,
            'workflow_task_id' => $task->id,
            'last_lease_lost_at' => now()->toJSON(),
        ];
        $affected->update(['task_attention' => $attention]);

        $this->getJson('/waterline/api/flows/running?task_attention=true')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $affected->id)
            ->assertJsonPath('data.0.task_attention', $attention)
            ->assertJsonPath('visibility_filters.applied.task_attention', true)
            ->assertJsonPath('visibility_filters.definition.fields.task_attention.filterable', true);

        $this->getJson('/waterline/api/flows/running?task_attention=false')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $healthy->id);

        $this->getJson('/waterline/api/flows/'.$affected->id)
            ->assertOk()
            ->assertJsonPath('task_attention', $attention);

        $this->getJson('/waterline/api/flows/'.$affected->id.'?observation=bounded')
            ->assertOk()
            ->assertJsonPath('task_attention', $attention);

        $this->getJson('/waterline/api/stats')
            ->assertOk()
            ->assertJsonPath('operator_metrics.runs.task_attention', 1)
            ->assertJsonPath('operator_metrics.tasks.lease_lost_repeatedly', 1)
            ->assertJsonPath('operator_metrics.tasks.max_lost_lease_count', 3);

        Schema::table('workflow_run_summaries', static fn (Blueprint $table) => $table->dropColumn('task_attention'));
        $this->getJson('/waterline/api/flows/'.$affected->id.'?observation=bounded')
            ->assertOk()
            ->assertJsonPath('task_attention', null);
        $this->getJson('/waterline/api/flows/running?task_attention=true')
            ->assertStatus(501)
            ->assertJsonPath('capability', 'task_attention_workflow_list');
    }

    public function testOlderEmbeddedPackageKeepsOrdinaryListsAvailable(): void
    {
        config()->set('waterline.engine_source', 'v2');
        if ($this->currentWorkflowPackage()) {
            $this->markTestSkipped('This check needs a Workflow package before 2.34.0.');
        }
        $this->assertFalse(TaskAttention::supported());
        $run = $this->runningSummary('legacy');

        $this->getJson('/waterline/api/flows/running')
            ->assertOk()
            ->assertJsonPath('data.0.id', $run->id)
            ->assertJsonPath('visibility_filters.definition.fields.task_attention.filterable', false);

        $this->getJson('/waterline/api/flows/running?task_attention=true')
            ->assertStatus(501)
            ->assertJsonPath('capability', 'task_attention_workflow_list');
    }

    private function currentWorkflowPackage(): bool
    {
        return version_compare(InstalledVersions::getVersion('durable-workflow/workflow'), '2.34.0', '>=');
    }

    private function runningSummary(string $id): WorkflowRunSummary
    {
        $instance = WorkflowInstance::create([
            'id' => 'instance-'.$id,
            'workflow_class' => 'WorkflowClass',
            'workflow_type' => 'workflow.test',
            'run_count' => 1,
        ]);
        $startedAt = now();
        $run = WorkflowRun::create([
            'id' => 'run-'.$id,
            'workflow_instance_id' => $instance->id,
            'run_number' => 1,
            'workflow_class' => 'WorkflowClass',
            'workflow_type' => 'workflow.test',
            'status' => 'waiting',
            'started_at' => $startedAt,
        ]);
        $instance->update(['current_run_id' => $run->id]);

        return WorkflowRunSummary::create([
            'id' => $run->id,
            'workflow_instance_id' => $instance->id,
            'run_number' => 1,
            'is_current_run' => true,
            'engine_source' => 'v2',
            'class' => 'WorkflowClass',
            'workflow_type' => 'workflow.test',
            'status' => 'waiting',
            'status_bucket' => 'running',
            'started_at' => $startedAt,
            'sort_timestamp' => $startedAt,
            'sort_key' => RunSummarySortKey::key($startedAt, $startedAt, $startedAt, $run->id),
        ]);
    }
}
