<?php

declare(strict_types=1);

namespace Waterline\Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
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
        [$instance, $run] = $this->createRun(
            'workflow-stream-visibility',
            '01JSTREAMVISIBILITY000001',
            2,
        );

        foreach ([
            [1, MessageDirection::Inbound, MessageConsumeState::Consumed, null],
            [2, MessageDirection::Outbound, MessageConsumeState::Pending, null],
            [3, MessageDirection::Inbound, MessageConsumeState::Failed, 'delivery failed'],
            [4, MessageDirection::Inbound, MessageConsumeState::Pending, null],
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

        $contract = app(WorkflowStreamPresenter::class)->embedded($run);
        $rows = collect($contract['streams'])->keyBy('direction');
        $inbound = $rows->get(MessageDirection::Inbound->value);
        $outbound = $rows->get(MessageDirection::Outbound->value);

        $this->assertSame(WorkflowStreamPresenter::STATE_AVAILABLE, $contract['state']);
        $this->assertTrue($contract['available']);
        $this->assertNull($contract['reason']);
        $this->assertCount(2, $rows);
        $this->assertSame('embedded', $inbound['mode']);
        $this->assertSame('orders', $inbound['stream_name']);
        $this->assertSame('errored', $inbound['status']);
        $this->assertSame(4, $inbound['last_offset']);
        $this->assertSame(2, $inbound['run_cursor_offset']);
        $this->assertArrayNotHasKey('cursor_offset', $inbound);
        $this->assertSame(3, $inbound['total_items']);
        $this->assertSame(1, $inbound['pending_items']);
        $this->assertSame('delivery failed', $inbound['error_reason']);
        $this->assertTrue($inbound['supports_inbound_workflow_messaging']);
        $this->assertTrue($inbound['continue_as_new_cursor_transfer']);
        $this->assertSame('open', $outbound['status']);
        $this->assertSame(2, $outbound['last_offset']);
        $this->assertNull($outbound['run_cursor_offset']);
        $this->assertSame(1, $outbound['total_items']);
        $this->assertSame(0, $outbound['pending_items']);
        $this->assertNull($outbound['error_reason']);
    }

    public function test_failed_lifecycle_remains_errored_without_a_delivery_error_message(): void
    {
        [$instance, $run] = $this->createRun(
            'workflow-stream-failed-lifecycle',
            '01JSTREAMFAILEDLIFECYCLE',
            0,
        );
        WorkflowMessage::query()->create([
            'workflow_instance_id' => $instance->id,
            'workflow_run_id' => $run->id,
            'direction' => MessageDirection::Inbound->value,
            'channel' => 'workflow_message',
            'stream_key' => 'failed-without-detail',
            'sequence' => 1,
            'consume_state' => MessageConsumeState::Failed->value,
            'last_delivery_error' => null,
        ]);

        $contract = app(WorkflowStreamPresenter::class)->embedded($run);

        $this->assertSame('errored', $contract['streams'][0]['status']);
        $this->assertNull($contract['streams'][0]['error_reason']);
    }

    public function test_large_multi_stream_histories_are_summarized_by_one_bounded_aggregate_query(): void
    {
        [$instance, $run] = $this->createRun(
            'workflow-stream-large-history',
            '01JSTREAMLARGEHISTORY001',
            393,
        );
        $messages = [];

        for ($stream = 1; $stream <= 12; $stream++) {
            for ($sequence = 1; $sequence <= 400; $sequence++) {
                $inbound = $sequence % 2 === 1;
                $messages[] = [
                    'workflow_instance_id' => $instance->id,
                    'workflow_run_id' => $run->id,
                    'direction' => $inbound
                        ? MessageDirection::Inbound->value
                        : MessageDirection::Outbound->value,
                    'channel' => 'workflow_message',
                    'stream_key' => sprintf('stream-%02d', $stream),
                    'sequence' => $sequence,
                    'consume_state' => $inbound && $sequence % 8 !== 1
                        ? MessageConsumeState::Consumed->value
                        : MessageConsumeState::Pending->value,
                    'last_delivery_error' => match (true) {
                        $inbound && $sequence === 399 => 'inbound delivery failed',
                        ! $inbound && $sequence === 400 => 'outbound mirror failed',
                        default => null,
                    },
                ];
            }
        }

        foreach (array_chunk($messages, 500) as $chunk) {
            WorkflowMessage::query()->insert($chunk);
        }

        DB::flushQueryLog();
        DB::enableQueryLog();
        $contract = app(WorkflowStreamPresenter::class)->embedded($run);
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $this->assertSame(WorkflowStreamPresenter::STATE_AVAILABLE, $contract['state']);
        $this->assertCount(24, $contract['streams']);
        $this->assertCount(1, $queries);
        $this->assertStringContainsString('count(*)', strtolower($queries[0]['query']));
        $this->assertStringContainsString('group by', strtolower($queries[0]['query']));
        $this->assertStringNotContainsString('select *', strtolower($queries[0]['query']));

        $firstStream = collect($contract['streams'])
            ->where('stream_name', 'stream-01')
            ->keyBy('direction');
        $inbound = $firstStream->get(MessageDirection::Inbound->value);
        $outbound = $firstStream->get(MessageDirection::Outbound->value);

        $this->assertSame(200, $inbound['total_items']);
        $this->assertSame(50, $inbound['pending_items']);
        $this->assertSame(399, $inbound['last_offset']);
        $this->assertSame(393, $inbound['run_cursor_offset']);
        $this->assertSame('inbound delivery failed', $inbound['error_reason']);
        $this->assertSame(200, $outbound['total_items']);
        $this->assertSame(0, $outbound['pending_items']);
        $this->assertSame(400, $outbound['last_offset']);
        $this->assertNull($outbound['run_cursor_offset']);
        $this->assertSame('outbound mirror failed', $outbound['error_reason']);
    }

    public function test_embedded_schema_failure_is_a_typed_degraded_api_state(): void
    {
        config()->set('waterline.engine_source', 'v2');
        [$instance, $run] = $this->createRun(
            'workflow-stream-schema-failure',
            '01JSTREAMSCHEMAFAILURE01',
            0,
        );

        Schema::drop('workflow_messages');

        $this->getJson('/waterline/api/instances/'.$instance->id.'/runs/'.$run->id)
            ->assertOk()
            ->assertJsonPath('workflow_streams', [])
            ->assertJsonPath('workflow_streams_mode', 'embedded')
            ->assertJsonPath('workflow_streams_state', WorkflowStreamPresenter::STATE_DEGRADED)
            ->assertJsonPath('workflow_streams_available', false)
            ->assertJsonPath(
                'workflow_streams_unavailable_reason',
                WorkflowStreamPresenter::REASON_SCHEMA_UNAVAILABLE,
            );
    }

    public function test_embedded_collection_failure_preserves_a_typed_machine_reason(): void
    {
        [, $run] = $this->createRun(
            'workflow-stream-collection-failure',
            '01JSTREAMCOLLECTFAILURE1',
            0,
        );
        DB::listen(static function (): void {
            throw new RuntimeException('Synthetic collection failure.');
        });

        $contract = app(WorkflowStreamPresenter::class)->embedded($run);

        $this->assertSame(WorkflowStreamPresenter::STATE_DEGRADED, $contract['state']);
        $this->assertFalse($contract['available']);
        $this->assertSame(WorkflowStreamPresenter::REASON_COLLECTION_FAILED, $contract['reason']);
        $this->assertSame([], $contract['streams']);
    }

    /**
     * @return array{WorkflowInstance, WorkflowRun}
     */
    private function createRun(string $instanceId, string $runId, int $cursor): array
    {
        $instance = WorkflowInstance::query()->create([
            'id' => $instanceId,
            'workflow_class' => 'Tests\\StreamingWorkflow',
            'workflow_type' => 'tests.streaming',
            'run_count' => 1,
            'started_at' => now(),
        ]);
        $run = WorkflowRun::query()->create([
            'id' => $runId,
            'workflow_instance_id' => $instance->id,
            'run_number' => 1,
            'workflow_class' => 'Tests\\StreamingWorkflow',
            'workflow_type' => 'tests.streaming',
            'status' => RunStatus::Running->value,
            'message_cursor_position' => $cursor,
            'started_at' => now(),
            'last_progress_at' => now(),
        ]);
        $instance->forceFill(['current_run_id' => $run->id])->save();

        return [$instance, $run];
    }
}
