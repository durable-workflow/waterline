<?php

namespace Waterline\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;
use Workflow\V2\Models\ActivityExecution;
use Workflow\V2\Models\WorkflowFailure;
use Workflow\V2\Models\WorkflowRun;

/**
 * @mixin WorkflowRun
 */
class V2StoredWorkflowResource extends JsonResource
{
    public static $wrap = null;

    public function toArray($request)
    {
        $this->resource->loadMissing(['summary', 'activityExecutions', 'failures', 'instance.currentRun.summary']);

        $summary = $this->summary;
        $currentRun = $this->instance?->currentRun;
        $currentSummary = $currentRun?->summary;
        $isCurrentRun = $summary?->is_current_run ?? ($currentRun?->id === $this->id);
        $canIssueTerminalCommands = $isCurrentRun && in_array($this->status->value, ['pending', 'running', 'waiting'], true);
        $activityNames = $this->activityExecutions
            ->keyBy('id');

        return [
            'id' => $this->id,
            'instance_id' => $this->workflow_instance_id,
            'selected_run_id' => $this->id,
            'run_id' => $this->id,
            'is_current_run' => $isCurrentRun,
            'current_run_id' => $currentRun?->id,
            'current_run_status' => $currentRun?->status?->value,
            'current_run_status_bucket' => $currentSummary?->status_bucket,
            'current_run_closed_reason' => $currentSummary?->closed_reason ?? $currentRun?->closed_reason,
            'engine_source' => 'v2',
            'class' => $this->workflow_class,
            'workflow_type' => $this->workflow_type,
            'arguments' => serialize($this->workflowArguments()),
            'connection' => $this->connection,
            'queue' => $this->queue,
            'output' => $this->output === null ? serialize(null) : serialize($this->workflowOutput()),
            'status' => $this->status->value,
            'status_bucket' => $summary?->status_bucket,
            'closed_reason' => $summary?->closed_reason ?? $this->closed_reason,
            'can_issue_terminal_commands' => $canIssueTerminalCommands,
            'read_only_reason' => $canIssueTerminalCommands
                ? null
                : ($isCurrentRun
                    ? 'Run is closed.'
                    : 'Selected run is historical. Issue commands against the current active run.'),
            'created_at' => $summary?->started_at ?? $this->started_at ?? $this->created_at,
            'updated_at' => $summary?->closed_at ?? $this->last_progress_at ?? $this->updated_at,
            'logs' => $this->activityExecutions->map(
                fn (ActivityExecution $execution): array => [
                    'id' => $execution->id,
                    'index' => $execution->sequence - 1,
                    'now' => $execution->started_at ?? $execution->created_at,
                    'class' => $execution->activity_class,
                    'result' => $execution->result === null ? serialize(null) : serialize($execution->activityResult()),
                    'created_at' => $execution->closed_at ?? $execution->updated_at,
                ]
            )->values(),
            'exceptions' => $this->failures->map(
                fn (WorkflowFailure $failure): array => [
                    'id' => $failure->id,
                    'code' => $failure->trace_preview,
                    'exception' => serialize([
                        '__constructor' => $failure->exception_class,
                        'message' => $failure->message,
                        'file' => $failure->file,
                        'line' => $failure->line,
                        'trace' => [],
                    ]),
                    'class' => $activityNames[$failure->source_id]->activity_class
                        ?? $failure->exception_class,
                    'created_at' => $failure->created_at,
                ]
            )->values(),
            'parents' => [],
            'continuedWorkflows' => [],
            'chartData' => $this->chartData(),
        ];
    }

    protected function chartData(): array
    {
        $start = $this->timestampToMilliseconds($this->started_at ?? $this->created_at);
        $end = $this->timestampToMilliseconds($this->closed_at ?? $this->last_progress_at ?? $this->updated_at);

        $entries = [[
            'x' => $this->workflow_class,
            'type' => 'Workflow',
            'y' => [$start, $end],
        ]];

        foreach ($this->activityExecutions as $execution) {
            $entries[] = [
                'x' => $execution->activity_class,
                'type' => 'Activity',
                'y' => [
                    $this->timestampToMilliseconds($execution->started_at ?? $execution->created_at),
                    $this->timestampToMilliseconds($execution->closed_at ?? $execution->updated_at),
                ],
            ];
        }

        return $entries;
    }

    protected function timestampToMilliseconds($timestamp): int
    {
        return $timestamp->getTimestampMs();
    }
}
