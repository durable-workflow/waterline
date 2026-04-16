<?php

namespace Workbench\Database\Seeders;

use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Waterline\Tests\Fixtures\V2\TestAsyncWorkflow;
use Waterline\Tests\Fixtures\V2\TestAwaitWithTimeoutWorkflow;
use Waterline\Tests\Fixtures\V2\TestMixedParallelWorkflow;
use Waterline\Tests\Fixtures\V2\TestParallelActivityWorkflow;
use Waterline\Tests\Fixtures\V2\TestTimerChildWorkflow;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Enums\TaskType;
use Workflow\V2\Jobs\RunWorkflowTask;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\WorkflowStub;

/**
 * Waterline Development Seeder
 *
 * Seeds the workbench with realistic workflow data for UI development and testing.
 * Creates workflows in various states to showcase all Waterline features:
 * - Completed workflows with full history
 * - Running workflows with activities in progress
 * - Failed workflows with error details
 * - Workflows with timers, child workflows, and parallel activities
 * - Worker registrations and heartbeats
 * - Workflow schedules
 */
class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        config()->set('waterline.engine_source', 'v2');
        config()->set('queue.default', 'sync'); // Run synchronously for seeding

        $this->command->info('🌱 Seeding Waterline workbench...');

        // Create workflows in various states
        $this->command->info('  Creating completed workflows...');
        $this->seedCompletedWorkflows();

        $this->command->info('  Creating running workflows...');
        $this->seedRunningWorkflows();

        $this->command->info('  Creating failed workflows...');
        $this->seedFailedWorkflows();

        $this->command->info('  Creating workflows with timers and children...');
        $this->seedComplexWorkflows();

        $this->command->info('  Creating worker registrations...');
        $this->seedWorkerRegistrations();

        $this->command->info('  Creating workflow schedules...');
        $this->seedWorkflowSchedules();

        $this->command->newLine();
        $this->command->info('✅ Workbench seeded successfully!');
        $this->command->info('🌐 Visit http://localhost:18280/waterline to view the dashboard');
    }

    /**
     * Create completed workflows with full history
     */
    private function seedCompletedWorkflows(): void
    {
        // Simple completed workflow
        $workflow1 = WorkflowStub::make(TestAsyncWorkflow::class, 'completed-workflow-1');
        $workflow1->start('Alice');
        $this->runAllWorkflowTasks($workflow1->runId());

        // Parallel activity workflow - completed
        $workflow2 = WorkflowStub::make(TestParallelActivityWorkflow::class, 'completed-parallel-1');
        $workflow2->start(['tasks' => ['task1', 'task2', 'task3']]);
        $this->runAllWorkflowTasks($workflow2->runId());

        // Mixed parallel workflow - completed
        $workflow3 = WorkflowStub::make(TestMixedParallelWorkflow::class, 'completed-mixed-1');
        $workflow3->start(['count' => 5]);
        $this->runAllWorkflowTasks($workflow3->runId());
    }

    /**
     * Create workflows that are currently running
     */
    private function seedRunningWorkflows(): void
    {
        // Start workflows but don't complete them
        $workflow1 = WorkflowStub::make(TestAsyncWorkflow::class, 'running-workflow-1');
        $workflow1->start('Bob');
        // Run first task only
        $this->runNextWorkflowTask($workflow1->runId());

        $workflow2 = WorkflowStub::make(TestParallelActivityWorkflow::class, 'running-parallel-1');
        $workflow2->start(['tasks' => ['long-task-1', 'long-task-2']]);
        // Run first task only
        $this->runNextWorkflowTask($workflow2->runId());

        $workflow3 = WorkflowStub::make(TestMixedParallelWorkflow::class, 'running-mixed-1');
        $workflow3->start(['count' => 10]);
        // Run partway through
        $this->runNextWorkflowTask($workflow3->runId());
        $this->runNextWorkflowTask($workflow3->runId());
    }

    /**
     * Create workflows that failed with errors
     */
    private function seedFailedWorkflows(): void
    {
        // Create a workflow and mark it as failed by updating the summary directly
        $workflow1 = WorkflowStub::make(TestAsyncWorkflow::class, 'failed-workflow-1');
        $workflow1->start('Charlie');

        // Run first task to generate history
        $this->runNextWorkflowTask($workflow1->runId());

        // Manually mark as failed (in real scenarios, this would happen through exception handling)
        DB::table('workflow_run_summaries')
            ->where('run_id', $workflow1->runId())
            ->update([
                'status' => 'failed',
                'status_bucket' => 'failed',
                'closed_at' => CarbonImmutable::now(),
                'closed_reason' => 'exception',
                'updated_at' => CarbonImmutable::now(),
            ]);

        // Create a workflow failure record
        DB::table('workflow_failures')->insert([
            'run_id' => $workflow1->runId(),
            'failure_type' => 'application',
            'failure_category' => 'exception',
            'exception_class' => 'RuntimeException',
            'exception_message' => 'Test failure: Something went wrong during workflow execution',
            'stack_trace' => json_encode([
                'file' => '/app/src/Workflows/TestWorkflow.php',
                'line' => 42,
                'trace' => 'Stack trace details...',
            ]),
            'created_at' => CarbonImmutable::now(),
            'updated_at' => CarbonImmutable::now(),
        ]);

        // Another failed workflow
        $workflow2 = WorkflowStub::make(TestParallelActivityWorkflow::class, 'failed-parallel-1');
        $workflow2->start(['tasks' => ['failing-task']]);
        $this->runNextWorkflowTask($workflow2->runId());

        DB::table('workflow_run_summaries')
            ->where('run_id', $workflow2->runId())
            ->update([
                'status' => 'failed',
                'status_bucket' => 'failed',
                'closed_at' => CarbonImmutable::now(),
                'closed_reason' => 'activity_failure',
                'updated_at' => CarbonImmutable::now(),
            ]);

        DB::table('workflow_failures')->insert([
            'run_id' => $workflow2->runId(),
            'failure_type' => 'activity',
            'failure_category' => 'timeout',
            'exception_class' => 'ActivityTimeoutException',
            'exception_message' => 'Activity timed out after 30 seconds',
            'stack_trace' => json_encode([
                'activity_id' => 'activity-123',
                'timeout' => 30,
            ]),
            'created_at' => CarbonImmutable::now(),
            'updated_at' => CarbonImmutable::now(),
        ]);
    }

    /**
     * Create workflows with timers, child workflows, and complex scenarios
     */
    private function seedComplexWorkflows(): void
    {
        // Workflow with timer
        $workflow1 = WorkflowStub::make(TestTimerChildWorkflow::class, 'workflow-with-timer-1');
        $workflow1->start(['delay' => 5]);
        $this->runNextWorkflowTask($workflow1->runId());

        // Workflow with timeout scenario
        $workflow2 = WorkflowStub::make(TestAwaitWithTimeoutWorkflow::class, 'workflow-timeout-1');
        $workflow2->start(['timeout' => 30]);
        $this->runNextWorkflowTask($workflow2->runId());
    }

    /**
     * Create worker registrations to populate worker health view
     */
    private function seedWorkerRegistrations(): void
    {
        $now = CarbonImmutable::now();

        // Active worker
        DB::table('workflow_worker_compatibility_heartbeats')->insert([
            'worker_id' => 'worker-' . uniqid(),
            'scope_key' => 'default:default',
            'namespace' => 'default',
            'host' => 'waterline-worker-1.localhost',
            'process_id' => (string) getmypid(),
            'connection' => 'redis',
            'queue' => 'default',
            'supported' => json_encode([
                'workflows' => ['TestAsyncWorkflow', 'TestParallelActivityWorkflow'],
                'activities' => ['TestParallelGreetingActivity'],
            ]),
            'recorded_at' => $now,
            'expires_at' => $now->addMinutes(5),
            'created_at' => $now->subHours(2),
            'updated_at' => $now,
        ]);

        // Another active worker
        DB::table('workflow_worker_compatibility_heartbeats')->insert([
            'worker_id' => 'worker-' . uniqid(),
            'scope_key' => 'default:default',
            'namespace' => 'default',
            'host' => 'waterline-worker-2.localhost',
            'process_id' => (string) (getmypid() + 1),
            'connection' => 'redis',
            'queue' => 'default',
            'supported' => json_encode([
                'workflows' => ['TestMixedParallelWorkflow', 'TestTimerChildWorkflow'],
                'activities' => ['TestParallelGreetingActivity'],
            ]),
            'recorded_at' => $now->subSeconds(30),
            'expires_at' => $now->addMinutes(5)->subSeconds(30),
            'created_at' => $now->subHours(1),
            'updated_at' => $now->subSeconds(30),
        ]);

        // Stale worker (expired heartbeat)
        DB::table('workflow_worker_compatibility_heartbeats')->insert([
            'worker_id' => 'worker-' . uniqid(),
            'scope_key' => 'default:default',
            'namespace' => 'default',
            'host' => 'waterline-worker-stale.localhost',
            'process_id' => (string) (getmypid() + 2),
            'connection' => 'redis',
            'queue' => 'default',
            'supported' => json_encode([
                'workflows' => ['TestAsyncWorkflow'],
                'activities' => [],
            ]),
            'recorded_at' => $now->subMinutes(10),
            'expires_at' => $now->subMinutes(5), // Expired
            'created_at' => $now->subHours(24),
            'updated_at' => $now->subMinutes(10),
        ]);
    }

    /**
     * Create workflow schedules for the schedule view
     */
    private function seedWorkflowSchedules(): void
    {
        $now = CarbonImmutable::now();

        // Active schedule - fires every hour
        DB::table('workflow_schedules')->insert([
            'schedule_id' => 'schedule-' . uniqid(),
            'namespace' => 'default',
            'workflow_type' => 'TestAsyncWorkflow',
            'workflow_id_prefix' => 'scheduled-async-',
            'task_queue' => 'default',
            'cron_expression' => '0 * * * *',
            'timezone' => 'UTC',
            'input' => json_encode(['scheduled' => true]),
            'state' => 'active',
            'next_fire_time' => $now->addHour()->startOfHour(),
            'last_fire_time' => $now->startOfHour(),
            'last_fire_result' => 'success',
            'created_at' => $now->subDays(7),
            'updated_at' => $now->startOfHour(),
        ]);

        // Paused schedule
        DB::table('workflow_schedules')->insert([
            'schedule_id' => 'schedule-' . uniqid(),
            'namespace' => 'default',
            'workflow_type' => 'TestParallelActivityWorkflow',
            'workflow_id_prefix' => 'scheduled-parallel-',
            'task_queue' => 'default',
            'cron_expression' => '0 0 * * *',
            'timezone' => 'UTC',
            'input' => json_encode(['tasks' => ['scheduled-task-1', 'scheduled-task-2']]],
            'state' => 'paused',
            'next_fire_time' => null,
            'last_fire_time' => $now->subDays(1)->startOfDay(),
            'last_fire_result' => 'success',
            'created_at' => $now->subDays(30),
            'updated_at' => $now->subDays(1),
        ]);

        // Schedule with recent failure
        DB::table('workflow_schedules')->insert([
            'schedule_id' => 'schedule-' . uniqid(),
            'namespace' => 'default',
            'workflow_type' => 'TestMixedParallelWorkflow',
            'workflow_id_prefix' => 'scheduled-mixed-',
            'task_queue' => 'default',
            'cron_expression' => '*/15 * * * *',
            'timezone' => 'UTC',
            'input' => json_encode(['count' => 3]),
            'state' => 'active',
            'next_fire_time' => $now->addMinutes(15)->startOfMinute(),
            'last_fire_time' => $now->subMinutes(15)->startOfMinute(),
            'last_fire_result' => 'failed',
            'created_at' => $now->subDays(3),
            'updated_at' => $now->subMinutes(15),
        ]);
    }

    /**
     * Run all workflow tasks until completion
     */
    private function runAllWorkflowTasks(string $runId): void
    {
        $maxIterations = 100; // Safety limit
        $iterations = 0;

        while ($iterations < $maxIterations) {
            if (!$this->runNextWorkflowTask($runId)) {
                break;
            }
            $iterations++;
        }
    }

    /**
     * Run the next ready workflow task
     *
     * @return bool Whether a task was run
     */
    private function runNextWorkflowTask(string $runId): bool
    {
        /** @var WorkflowTask|null $task */
        $task = WorkflowTask::query()
            ->where('workflow_run_id', $runId)
            ->where('task_type', TaskType::Workflow->value)
            ->where('status', TaskStatus::Ready->value)
            ->orderBy('created_at')
            ->first();

        if (!$task) {
            return false;
        }

        try {
            $this->app->call([new RunWorkflowTask($task->id), 'handle']);
            return true;
        } catch (\Exception $e) {
            // Task execution failed - this is expected for some test scenarios
            return false;
        }
    }
}
