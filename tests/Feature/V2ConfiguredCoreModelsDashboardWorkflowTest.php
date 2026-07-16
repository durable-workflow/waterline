<?php

declare(strict_types=1);

namespace Waterline\Tests\Feature;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Queue;
use Waterline\Tests\Fixtures\V2\TestCommandContractWorkflow;
use Waterline\Tests\TestCase;
use Workflow\V2\Jobs\RunWorkflowTask;
use Workflow\V2\Models\WorkflowCommand;
use Workflow\V2\Models\WorkflowInstance;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\Models\WorkflowUpdate;
use Workflow\V2\WorkflowStub;

final class V2ConfiguredCoreModelsDashboardWorkflowTest extends TestCase
{
    public function testCanonicalInstanceDetailWorksWithConfiguredInstanceRunAndTaskModels(): void
    {
        config()->set('waterline.engine_source', 'v2');
        config()->set('queue.default', 'database');
        config()->set('queue.connections.database.driver', 'database');
        Queue::fake();

        $this->createConfiguredInstancesTable();
        $this->createConfiguredRunsTable();
        $this->createConfiguredTasksTable();

        config()->set('workflows.v2.instance_model', ConfiguredWaterlineCoreWorkflowInstance::class);
        config()->set('workflows.v2.run_model', ConfiguredWaterlineCoreWorkflowRun::class);
        config()->set('workflows.v2.task_model', ConfiguredWaterlineCoreWorkflowTask::class);

        $workflow = WorkflowStub::make(TestCommandContractWorkflow::class, 'waterline-configured-core-models');
        $workflow->start();

        $this->runReadyConfiguredWorkflowTask((string) $workflow->runId());

        $this->get('/waterline/api/instances/' . $workflow->id())
            ->assertOk()
            ->assertJsonPath('instance_id', $workflow->id())
            ->assertJsonPath('run_id', $workflow->runId())
            ->assertJsonPath('selected_run_id', $workflow->runId())
            ->assertJsonPath('status', 'completed');
    }

    private function runReadyConfiguredWorkflowTask(string $runId): void
    {
        /** @var WorkflowTask $task */
        $task = ConfiguredWaterlineCoreWorkflowTask::query()
            ->where('workflow_run_id', $runId)
            ->where('task_type', 'workflow')
            ->where('status', 'ready')
            ->orderBy('created_at')
            ->firstOrFail();

        $this->app->call([new RunWorkflowTask($task->id), 'handle']);
    }

    private function createConfiguredInstancesTable(): void
    {
        Schema::create('configured_waterline_workflow_instances', static function (Blueprint $table): void {
            $table->string('id', 191)->primary();
            $table->string('workflow_class');
            $table->string('workflow_type');
            $table->string('business_key', 191)->nullable();
            $table->json('visibility_labels')->nullable();
            $table->json('memo')->nullable();
            $table->unsignedInteger('execution_timeout_seconds')->nullable();
            $table->string('current_run_id', 26)->nullable()->index();
            $table->unsignedInteger('run_count')->default(0);
            $table->unsignedInteger('last_message_sequence')->default(0);
            $table->timestamp('reserved_at', 6)->nullable();
            $table->timestamp('started_at', 6)->nullable();
            $table->timestamps(6);
        });
    }

    private function createConfiguredRunsTable(): void
    {
        Schema::create('configured_waterline_workflow_runs', static function (Blueprint $table): void {
            $table->string('id', 26)->primary();
            $table->string('workflow_instance_id', 191)->index('cfg_waterline_runs_instance_idx');
            $table->unsignedInteger('run_number');
            $table->string('workflow_class');
            $table->string('workflow_type');
            $table->string('business_key', 191)->nullable();
            $table->json('visibility_labels')->nullable();
            $table->json('memo')->nullable();
            $table->json('search_attributes')->nullable();
            $table->string('status');
            $table->string('closed_reason')->nullable();
            $table->string('compatibility')->nullable();
            $table->string('payload_codec')->nullable();
            $table->string('output_payload_codec')->nullable();
            $table->longText('arguments')->nullable();
            $table->longText('output')->nullable();
            $table->string('connection')->nullable();
            $table->string('queue')->nullable();
            $table->unsignedInteger('message_cursor_position')->default(0);
            $table->unsignedInteger('run_timeout_seconds')->nullable();
            $table->timestamp('execution_deadline_at', 6)->nullable();
            $table->timestamp('run_deadline_at', 6)->nullable();
            $table->unsignedInteger('last_history_sequence')->default(0);
            $table->unsignedInteger('last_command_sequence')->default(0);
            $table->timestamp('started_at', 6)->nullable();
            $table->timestamp('closed_at', 6)->nullable();
            $table->timestamp('archived_at', 6)->nullable();
            $table->string('archive_command_id', 26)->nullable();
            $table->string('archive_reason')->nullable();
            $table->timestamp('last_progress_at', 6)->nullable();
            $table->timestamps(6);

            $table->unique(['workflow_instance_id', 'run_number'], 'cfg_waterline_runs_instance_run_unique');
        });
    }

    private function createConfiguredTasksTable(): void
    {
        Schema::create('configured_waterline_workflow_tasks', static function (Blueprint $table): void {
            $table->string('id', 26)->primary();
            $table->string('workflow_run_id', 26)->index();
            $table->string('namespace')->nullable()->index();
            $table->string('task_type');
            $table->string('status');
            $table->json('payload')->nullable();
            $table->string('connection')->nullable();
            $table->string('queue')->nullable();
            $table->string('compatibility')->nullable();
            $table->timestamp('available_at', 6)->nullable();
            $table->timestamp('leased_at', 6)->nullable();
            $table->string('lease_owner')->nullable();
            $table->timestamp('lease_expires_at', 6)->nullable();
            $table->unsignedInteger('attempt_count')->default(0);
            $table->timestamp('last_dispatch_attempt_at', 6)->nullable();
            $table->timestamp('last_dispatched_at', 6)->nullable();
            $table->text('last_dispatch_error')->nullable();
            $table->timestamp('last_claim_failed_at', 6)->nullable();
            $table->text('last_claim_error')->nullable();
            $table->unsignedInteger('repair_count')->default(0);
            $table->timestamp('repair_available_at', 6)->nullable();
            $table->text('last_error')->nullable();
            $table->string('sticky_worker_id')->nullable()->index();
            $table->timestamp('sticky_until', 6)->nullable()->index();
            $table->string('sticky_replay_mode')->nullable()->index();
            $table->timestamp('sticky_claimed_at', 6)->nullable()->index();
            $table->timestamps(6);

            $table->index(['status', 'available_at']);
        });
    }
}

final class ConfiguredWaterlineCoreWorkflowInstance extends WorkflowInstance
{
    protected $table = 'configured_waterline_workflow_instances';

    public function currentRun(): BelongsTo
    {
        return $this->belongsTo(ConfiguredWaterlineCoreWorkflowRun::class, 'current_run_id');
    }

    public function runs(): HasMany
    {
        return $this->hasMany(ConfiguredWaterlineCoreWorkflowRun::class, 'workflow_instance_id');
    }

    public function commands(): HasMany
    {
        return $this->hasMany(WorkflowCommand::class, 'workflow_instance_id')
            ->oldest('created_at');
    }

    public function updates(): HasMany
    {
        return $this->hasMany(WorkflowUpdate::class, 'workflow_instance_id')
            ->orderBy('command_sequence')
            ->oldest('accepted_at')
            ->oldest('created_at')
            ->oldest('id');
    }
}

final class ConfiguredWaterlineCoreWorkflowRun extends WorkflowRun
{
    protected $table = 'configured_waterline_workflow_runs';
}

final class ConfiguredWaterlineCoreWorkflowTask extends WorkflowTask
{
    protected $table = 'configured_waterline_workflow_tasks';
}
