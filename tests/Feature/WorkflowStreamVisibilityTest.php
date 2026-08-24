<?php

declare(strict_types=1);

namespace Waterline\Tests\Feature;

use Waterline\Support\WorkflowStreamPresenter;
use Waterline\Tests\TestCase;
use Workflow\V2\Enums\MessageConsumeState;
use Workflow\V2\Enums\MessageDirection;
use Workflow\V2\Enums\RunStatus;
use Workflow\V2\Models\WorkflowInstance;
use Workflow\V2\Models\WorkflowMessage;
use Workflow\V2\Models\WorkflowRun;

final class WorkflowStreamVisibilityTest extends TestCase
{
    public function test_embedded_message_streams_share_lifecycle_offset_pending_and_error_fields(): void
    {
        $instance = WorkflowInstance::query()->create([
            'id' => 'workflow-stream-visibility',
            'workflow_class' => 'Tests\\StreamingWorkflow',
            'workflow_type' => 'tests.streaming',
            'run_count' => 1,
            'started_at' => now(),
        ]);
        $run = WorkflowRun::query()->create([
            'id' => '01JSTREAMVISIBILITY000001',
            'workflow_instance_id' => $instance->id,
            'run_number' => 1,
            'workflow_class' => 'Tests\\StreamingWorkflow',
            'workflow_type' => 'tests.streaming',
            'status' => RunStatus::Running->value,
            'message_cursor_position' => 2,
            'started_at' => now(),
            'last_progress_at' => now(),
        ]);
        $instance->forceFill(['current_run_id' => $run->id])->save();

        foreach ([
            [1, MessageDirection::Inbound, MessageConsumeState::Consumed, null],
            [2, MessageDirection::Outbound, MessageConsumeState::Pending, null],
            [3, MessageDirection::Inbound, MessageConsumeState::Failed, 'delivery failed'],
        ] as [$sequence, $direction, $state, $error]) {
            WorkflowMessage::query()->create([
                'workflow_instance_id' => $instance->id,
                'workflow_run_id' => $run->id,
                'direction' => $direction->value,
                'channel' => 'workflow_message',
                'stream_key' => 'orders',
                'sequence' => $sequence,
                'consume_state' => $state->value,
                'delivery_attempt_count' => $error === null ? 0 : 1,
                'last_delivery_error' => $error,
            ]);
        }

        $rows = app(WorkflowStreamPresenter::class)->embedded($run);

        $this->assertSame('embedded', $rows[0]['mode']);
        $this->assertSame('orders', $rows[0]['stream_name']);
        $this->assertSame('errored', $rows[0]['status']);
        $this->assertSame(3, $rows[0]['last_offset']);
        $this->assertSame(2, $rows[0]['run_cursor_offset']);
        $this->assertArrayNotHasKey('cursor_offset', $rows[0]);
        $this->assertSame(1, $rows[0]['pending_items']);
        $this->assertSame('delivery failed', $rows[0]['error_reason']);
        $this->assertTrue($rows[0]['supports_inbound_workflow_messaging']);
        $this->assertTrue($rows[0]['continue_as_new_cursor_transfer']);
    }
}
