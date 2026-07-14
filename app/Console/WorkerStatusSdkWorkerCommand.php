<?php

declare(strict_types=1);

namespace Waterline\Console;

use DurableWorkflow\Client;
use DurableWorkflow\Worker;
use DurableWorkflow\Worker\ActivityContext;
use DurableWorkflow\Worker\WorkflowContext;
use Illuminate\Console\Command;
use RuntimeException;

/**
 * Published-artifact worker process used by the focused worker-status cell.
 *
 * Registration, polling, task execution, and the heartbeat loop deliberately
 * remain owned by durable-workflow/sdk's managed Worker implementation.
 */
final class WorkerStatusSdkWorkerCommand extends Command
{
    protected $signature = 'waterline:worker-status-sdk-worker
        {--server-url= : Base URL of the exact published standalone server}
        {--worker-id= : Worker identity dedicated to this process}
        {--namespace= : Namespace dedicated to this run}
        {--task-queue= : Task queue dedicated to this run}
        {--workflow-type= : Workflow type handled by this process}
        {--activity-type= : Activity type advertised by this process}
        {--build-id= : Compatibility build identifier}
        {--heartbeat-interval=2 : SDK heartbeat interval in seconds}
        {--poll-timeout=1 : Worker poll timeout in seconds}';

    protected $description = 'Run the PHP SDK worker used by Waterline worker-status conformance';

    public function handle(): int
    {
        $client = new Client(
            $this->requiredOption('server-url'),
            token: $this->requiredEnvironment('DURABLE_WORKFLOW_AUTH_TOKEN'),
            namespace: $this->requiredOption('namespace'),
        );
        $worker = new Worker(
            $client,
            $this->requiredOption('task-queue'),
            $this->requiredOption('worker-id'),
            $this->positiveIntegerOption('heartbeat-interval'),
            $this->requiredOption('build-id'),
        );
        $worker->registerWorkflow(
            $this->requiredOption('workflow-type'),
            static fn (WorkflowContext $context): array => [
                'completed' => true,
                'surface' => 'waterline-worker-status',
                'workflow_id' => $context->workflowId,
            ],
        );
        $worker->registerActivity(
            $this->requiredOption('activity-type'),
            static fn (ActivityContext $context): array => [
                'completed' => true,
                'activity_type' => $context->activityType,
            ],
        );
        $worker->run($this->positiveIntegerOption('poll-timeout'));

        return self::SUCCESS;
    }

    private function requiredOption(string $name): string
    {
        $value = $this->option($name);

        if (! is_string($value) || trim($value) === '') {
            throw new RuntimeException('--'.$name.' is required');
        }

        return trim($value);
    }

    private function requiredEnvironment(string $name): string
    {
        $value = getenv($name);

        if (! is_string($value) || trim($value) === '') {
            throw new RuntimeException($name.' is required');
        }

        return trim($value);
    }

    private function positiveIntegerOption(string $name): int
    {
        $value = $this->option($name);

        if (! is_numeric($value) || (int) $value < 1) {
            throw new RuntimeException('--'.$name.' must be a positive integer');
        }

        return (int) $value;
    }
}
