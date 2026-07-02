<?php

namespace Waterline\Tests\Feature;

use Illuminate\Support\Str;
use Waterline\Tests\TestCase;
use Workflow\Serializers\Serializer;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Models\WorkflowCommand;
use Workflow\V2\Models\WorkflowFailure;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowInstance;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowRunSummary;
use Workflow\V2\Models\WorkflowUpdate;

class V2WorkflowUpdateDiagnosticsTest extends TestCase
{
    public function testSelectedRunDetailExposesReusableWorkflowUpdateDiagnostics(): void
    {
        config()->set('waterline.engine_source', 'v2');

        $instance = WorkflowInstance::create([
            'id' => 'update-diagnostics-instance',
            'workflow_class' => 'App\\Workflows\\UpdateDiagnosticsWorkflow',
            'workflow_type' => 'workflow.update-diagnostics',
            'run_count' => 1,
            'started_at' => now()->subMinutes(5),
        ]);

        $run = WorkflowRun::create([
            'id' => (string) Str::ulid(),
            'workflow_instance_id' => $instance->id,
            'run_number' => 1,
            'workflow_class' => 'App\\Workflows\\UpdateDiagnosticsWorkflow',
            'workflow_type' => 'workflow.update-diagnostics',
            'status' => 'waiting',
            'arguments' => Serializer::serialize([]),
            'connection' => 'redis',
            'queue' => 'default',
            'started_at' => now()->subMinutes(5),
            'last_progress_at' => now()->subMinute(),
        ]);

        $instance->update(['current_run_id' => $run->id]);

        $accepted = $this->seedUpdate($instance, $run, 1, 'accepted', 'queue-approval', ['order-1']);
        WorkflowHistoryEvent::record($run, HistoryEventType::UpdateAccepted, [
            'workflow_command_id' => $accepted['command']->id,
            'update_id' => $accepted['update']->id,
            'workflow_instance_id' => $instance->id,
            'workflow_run_id' => $run->id,
            'update_name' => 'queue-approval',
            'arguments' => Serializer::serialize(['order-1']),
        ], null, $accepted['command']);

        $completed = $this->seedUpdate($instance, $run, 2, 'completed', 'approve-order', ['order-2', true], [
            'result' => ['approved' => true, 'source' => 'operator'],
            'outcome' => 'update_completed',
            'closed_at' => now()->subSeconds(30),
        ]);
        WorkflowHistoryEvent::record($run, HistoryEventType::UpdateAccepted, [
            'workflow_command_id' => $completed['command']->id,
            'update_id' => $completed['update']->id,
            'workflow_instance_id' => $instance->id,
            'workflow_run_id' => $run->id,
            'update_name' => 'approve-order',
            'arguments' => Serializer::serialize(['order-2', true]),
        ], null, $completed['command']);
        WorkflowHistoryEvent::record($run, HistoryEventType::UpdateCompleted, [
            'workflow_command_id' => $completed['command']->id,
            'update_id' => $completed['update']->id,
            'workflow_instance_id' => $instance->id,
            'workflow_run_id' => $run->id,
            'update_name' => 'approve-order',
            'sequence' => 1,
            'result' => Serializer::serialize(['approved' => true, 'source' => 'operator']),
        ], null, $completed['command']);

        $failed = $this->seedUpdate($instance, $run, 3, 'failed', 'ship-order', ['order-3'], [
            'outcome' => 'update_failed',
            'closed_at' => now()->subSeconds(20),
        ]);
        $failure = WorkflowFailure::create([
            'id' => (string) Str::ulid(),
            'workflow_run_id' => $run->id,
            'source_kind' => 'workflow_command',
            'source_id' => $failed['command']->id,
            'propagation_kind' => 'update',
            'handled' => false,
            'exception_class' => 'App\\Exceptions\\InventoryUnavailable',
            'message' => 'inventory unavailable',
            'file' => __FILE__,
            'line' => 1,
            'trace_preview' => '',
        ]);
        $failed['update']->update(['failure_id' => $failure->id]);
        WorkflowHistoryEvent::record($run, HistoryEventType::UpdateAccepted, [
            'workflow_command_id' => $failed['command']->id,
            'update_id' => $failed['update']->id,
            'workflow_instance_id' => $instance->id,
            'workflow_run_id' => $run->id,
            'update_name' => 'ship-order',
            'arguments' => Serializer::serialize(['order-3']),
        ], null, $failed['command']);
        WorkflowHistoryEvent::record($run, HistoryEventType::UpdateCompleted, [
            'workflow_command_id' => $failed['command']->id,
            'update_id' => $failed['update']->id,
            'workflow_instance_id' => $instance->id,
            'workflow_run_id' => $run->id,
            'update_name' => 'ship-order',
            'sequence' => 2,
            'failure_id' => $failure->id,
            'exception_class' => 'App\\Exceptions\\InventoryUnavailable',
            'message' => 'inventory unavailable',
            'code' => 409,
            'exception' => [
                'class' => 'App\\Exceptions\\InventoryUnavailable',
                'message' => 'inventory unavailable',
                'code' => 409,
                'file' => __FILE__,
                'line' => 44,
                'trace' => [],
                'properties' => [],
            ],
        ], null, $failed['command']);

        $refused = $this->seedUpdate($instance, $run, 4, 'rejected', 'cancel-order', ['order-4'], [
            'outcome' => 'rejected_invalid_arguments',
            'rejection_reason' => 'invalid_operator_payload',
            'validation_errors' => ['reason' => ['The reason field is required.']],
            'closed_at' => now()->subSeconds(10),
        ]);
        WorkflowHistoryEvent::record($run, HistoryEventType::UpdateRejected, [
            'workflow_command_id' => $refused['command']->id,
            'update_id' => $refused['update']->id,
            'workflow_instance_id' => $instance->id,
            'workflow_run_id' => $run->id,
            'update_name' => 'cancel-order',
            'arguments' => Serializer::serialize(['order-4']),
            'validation_errors' => ['reason' => ['The reason field is required.']],
        ], null, $refused['command']);

        WorkflowRunSummary::create([
            'id' => $run->id,
            'workflow_instance_id' => $instance->id,
            'run_number' => 1,
            'is_current_run' => true,
            'engine_source' => 'v2',
            'class' => 'App\\Workflows\\UpdateDiagnosticsWorkflow',
            'workflow_type' => 'workflow.update-diagnostics',
            'status' => 'waiting',
            'status_bucket' => 'running',
            'connection' => 'redis',
            'queue' => 'default',
            'history_event_count' => 6,
            'history_size_bytes' => 1024,
            'started_at' => $run->started_at,
            'created_at' => now()->subMinutes(5),
            'updated_at' => now()->subMinute(),
        ]);

        $this->getJson('/waterline/api/instances/'.$instance->id.'/runs/'.$run->id)
            ->assertOk()
            ->assertJsonPath('updates_scope', 'selected_run')
            ->assertJsonPath('updates_projection_source', 'workflow_history_events')
            ->assertJsonPath('update_diagnostics.surface', 'selected_run_detail')
            ->assertJsonPath('update_diagnostics.state_counts.accepted', 1)
            ->assertJsonPath('update_diagnostics.state_counts.completed', 1)
            ->assertJsonPath('update_diagnostics.state_counts.failed', 1)
            ->assertJsonPath('update_diagnostics.state_counts.refused', 1)
            ->assertJsonPath('observer_state.paths.selected_run_update_template', '/waterline/api/instances/'.$instance->id.'/runs/'.$run->id.'/updates/{update}')
            ->assertJsonPath('observer_state.paths.selected_run_update_lookup_template', '/waterline/api/instances/'.$instance->id.'/runs/'.$run->id.'/updates/{updateId}')
            ->assertJsonPath('observer_state.paths.selected_run_history_export', '/waterline/api/instances/'.$instance->id.'/runs/'.$run->id.'/history-export')
            ->assertJsonPath('observer_state.updates.count', 4)
            ->assertJsonPath('observer_state.updates.state_counts.refused', 1)
            ->assertJsonPath('updates.0.id', $accepted['update']->id)
            ->assertJsonPath('updates.0.status', 'accepted')
            ->assertJsonPath('updates.0.request_id', 'req-update-1')
            ->assertJsonPath('updates.0.correlation_id', 'corr-update-1')
            ->assertJsonPath('updates.0.payload_available', true)
            ->assertJsonPath('updates.0.payload.name', 'queue-approval')
            ->assertJsonPath('updates.0.payload.arguments', ['order-1'])
            ->assertJsonPath('updates.0.history_event_types', ['UpdateAccepted'])
            ->assertJsonPath('updates.1.id', $completed['update']->id)
            ->assertJsonPath('updates.1.status', 'completed')
            ->assertJsonPath('updates.1.outcome', 'update_completed')
            ->assertJsonPath('updates.1.result_available', true)
            ->assertJsonPath('updates.1.result', ['approved' => true, 'source' => 'operator'])
            ->assertJsonPath('updates.1.history_event_types', ['UpdateAccepted', 'UpdateCompleted'])
            ->assertJsonPath('updates.2.id', $failed['update']->id)
            ->assertJsonPath('updates.2.status', 'failed')
            ->assertJsonPath('updates.2.reason', 'inventory unavailable')
            ->assertJsonPath('updates.2.error_available', true)
            ->assertJsonPath('updates.2.error.failure_id', $failure->id)
            ->assertJsonPath('updates.2.error.message', 'inventory unavailable')
            ->assertJsonPath('updates.2.history_event_types', ['UpdateAccepted', 'UpdateCompleted'])
            ->assertJsonPath('updates.3.id', $refused['update']->id)
            ->assertJsonPath('updates.3.status', 'rejected')
            ->assertJsonPath('updates.3.state_label', 'refused')
            ->assertJsonPath('updates.3.refused', true)
            ->assertJsonPath('updates.3.reason', 'invalid_operator_payload')
            ->assertJsonPath('updates.3.error_available', true)
            ->assertJsonPath('updates.3.error.rejection_reason', 'invalid_operator_payload')
            ->assertJsonPath('updates.3.history_event_types', ['UpdateRejected'])
            ->assertJsonPath('observer_state.updates.items.3.state_label', 'refused')
            ->assertJsonPath('observer_state.updates.items.3.error.rejection_reason', 'invalid_operator_payload');

        $this->getJson('/waterline/api/instances/'.$instance->id.'/runs/'.$run->id.'/history-export')
            ->assertOk()
            ->assertJsonPath('update_diagnostics.surface', 'selected_run_history_export')
            ->assertJsonPath('update_diagnostics.state_counts.accepted', 1)
            ->assertJsonPath('update_diagnostics.state_counts.completed', 1)
            ->assertJsonPath('update_diagnostics.state_counts.failed', 1)
            ->assertJsonPath('update_diagnostics.state_counts.refused', 1)
            ->assertJsonPath('updates.0.arguments', Serializer::serialize(['order-1']))
            ->assertJsonPath('updates.1.arguments', Serializer::serialize(['order-2', true]))
            ->assertJsonPath('updates.1.result', Serializer::serialize(['approved' => true, 'source' => 'operator']))
            ->assertJsonPath('updates.0.id', $accepted['update']->id)
            ->assertJsonPath('update_diagnostics.items.0.id', $accepted['update']->id)
            ->assertJsonPath('update_diagnostics.items.0.request_id', 'req-update-1')
            ->assertJsonPath('update_diagnostics.items.0.payload.name', 'queue-approval')
            ->assertJsonPath('update_diagnostics.items.0.payload.arguments', ['order-1'])
            ->assertJsonPath('update_diagnostics.items.0.history_event_types', ['UpdateAccepted'])
            ->assertJsonPath('update_diagnostics.items.1.result_available', true)
            ->assertJsonPath('update_diagnostics.items.1.result', ['approved' => true, 'source' => 'operator'])
            ->assertJsonPath('update_diagnostics.items.1.history_event_types', ['UpdateAccepted', 'UpdateCompleted'])
            ->assertJsonPath('update_diagnostics.items.2.error.failure_id', $failure->id)
            ->assertJsonPath('update_diagnostics.items.2.error.message', 'inventory unavailable')
            ->assertJsonPath('update_diagnostics.items.2.history_event_types', ['UpdateAccepted', 'UpdateCompleted'])
            ->assertJsonPath('update_diagnostics.items.3.state_label', 'refused')
            ->assertJsonPath('update_diagnostics.items.3.error.rejection_reason', 'invalid_operator_payload')
            ->assertJsonPath('update_diagnostics.items.3.history_event_types', ['UpdateRejected']);

        $this->getJson('/waterline/api/instances/'.$instance->id.'/runs/'.$run->id.'/updates/'.$refused['update']->id)
            ->assertOk()
            ->assertJsonPath('update_diagnostics.surface', 'selected_run_update_lookup')
            ->assertJsonPath('update.id', $refused['update']->id)
            ->assertJsonPath('status', 'rejected')
            ->assertJsonPath('state_label', 'refused')
            ->assertJsonPath('request_id', 'req-update-4')
            ->assertJsonPath('payload.name', 'cancel-order')
            ->assertJsonPath('payload.arguments', ['order-4'])
            ->assertJsonPath('error.rejection_reason', 'invalid_operator_payload')
            ->assertJsonPath('history_event_types', ['UpdateRejected']);

        $this->getJson('/waterline/api/instances/'.$instance->id.'/runs/'.$run->id.'/updates/'.$accepted['update']->id)
            ->assertStatus(202)
            ->assertJsonPath('update_diagnostics.surface', 'selected_run_update_lookup')
            ->assertJsonPath('update.id', $accepted['update']->id)
            ->assertJsonPath('status', 'accepted')
            ->assertJsonPath('request_id', 'req-update-1')
            ->assertJsonPath('history_event_types', ['UpdateAccepted']);
    }

    /**
     * @return array{command: WorkflowCommand, update: WorkflowUpdate}
     */
    private function seedUpdate(
        WorkflowInstance $instance,
        WorkflowRun $run,
        int $sequence,
        string $status,
        string $name,
        array $arguments,
        array $overrides = [],
    ): array {
        $outcome = $overrides['outcome'] ?? ($status === 'completed' ? 'update_completed' : null);
        $rejectionReason = $overrides['rejection_reason'] ?? null;
        $acceptedAt = now()->subSeconds(60 - $sequence);
        $closedAt = $overrides['closed_at'] ?? null;
        $commandStatus = $status === 'rejected' ? 'rejected' : 'accepted';

        $command = WorkflowCommand::create([
            'id' => (string) Str::ulid(),
            'workflow_instance_id' => $instance->id,
            'workflow_run_id' => $run->id,
            'command_sequence' => $sequence,
            'command_type' => 'update',
            'target_scope' => 'instance',
            'source' => 'api',
            'context' => [
                'caller' => [
                    'type' => 'operator',
                    'label' => 'Operator API',
                ],
                'principal' => [
                    'type' => 'service-account',
                    'id' => 'operator-diagnostics',
                    'label' => 'Operator Diagnostics',
                ],
                'auth' => [
                    'status' => 'verified',
                    'method' => 'bearer',
                ],
                'request' => [
                    'method' => 'POST',
                    'path' => '/waterline/api/instances/'.$instance->id.'/runs/'.$run->id.'/updates/'.$name,
                    'route_name' => 'waterline.instances.runs.update',
                    'fingerprint' => 'sha256:update-'.$sequence,
                    'request_id' => 'req-update-'.$sequence,
                    'correlation_id' => 'corr-update-'.$sequence,
                ],
            ],
            'status' => $commandStatus,
            'outcome' => $outcome,
            'rejection_reason' => $rejectionReason,
            'workflow_class' => $run->workflow_class,
            'workflow_type' => $run->workflow_type,
            'payload_codec' => config('workflows.serializer'),
            'payload' => Serializer::serialize([
                'name' => $name,
                'arguments' => $arguments,
            ]),
            'accepted_at' => $commandStatus === 'accepted' ? $acceptedAt : null,
            'rejected_at' => $commandStatus === 'rejected' ? $closedAt : null,
            'applied_at' => $status === 'completed' || $status === 'failed' ? $closedAt : null,
            'created_at' => $acceptedAt,
            'updated_at' => $closedAt ?? $acceptedAt,
        ]);

        $update = WorkflowUpdate::create([
            'id' => (string) Str::ulid(),
            'workflow_command_id' => $command->id,
            'workflow_instance_id' => $instance->id,
            'workflow_run_id' => $run->id,
            'target_scope' => 'instance',
            'requested_workflow_run_id' => null,
            'resolved_workflow_run_id' => $run->id,
            'update_name' => $name,
            'status' => $status,
            'outcome' => $outcome,
            'rejection_reason' => $rejectionReason,
            'validation_errors' => $overrides['validation_errors'] ?? [],
            'command_sequence' => $sequence,
            'workflow_sequence' => $status === 'accepted' || $status === 'rejected' ? null : $sequence - 1,
            'payload_codec' => config('workflows.serializer'),
            'arguments' => Serializer::serialize($arguments),
            'result' => array_key_exists('result', $overrides)
                ? Serializer::serialize($overrides['result'])
                : null,
            'accepted_at' => $commandStatus === 'accepted' ? $acceptedAt : null,
            'applied_at' => $status === 'completed' || $status === 'failed' ? $closedAt : null,
            'rejected_at' => $commandStatus === 'rejected' ? $closedAt : null,
            'closed_at' => $closedAt,
            'created_at' => $acceptedAt,
            'updated_at' => $closedAt ?? $acceptedAt,
        ]);

        return ['command' => $command, 'update' => $update];
    }
}
