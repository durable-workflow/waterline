<?php

namespace Waterline\Tests\Feature;

use Illuminate\Support\Carbon;
use Waterline\Tests\TestCase;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Enums\ScheduleStatus;
use Workflow\V2\Models\WorkflowSchedule;
use Workflow\V2\Models\WorkflowScheduleHistoryEvent;

class V2SchedulesHistoryControllerTest extends TestCase
{
    public function testHistoryReturns404WhenScheduleMissing(): void
    {
        $this->getJson('/waterline/api/v2/schedules/missing-id/history')
            ->assertStatus(404)
            ->assertJsonPath('error', 'Schedule not found.');
    }

    public function testHistoryReturnsEventsOrderedBySequence(): void
    {
        $schedule = $this->createSchedule('daily-invoice-sync');

        Carbon::setTestNow('2026-04-24T10:00:00+00:00');
        WorkflowScheduleHistoryEvent::record(
            $schedule,
            HistoryEventType::ScheduleCreated,
            [
                'spec' => ['cron_expressions' => ['0 2 * * *'], 'timezone' => 'UTC'],
                'action' => ['workflow_type' => 'workflow.test'],
                'overlap_policy' => 'skip',
                'next_fire_at' => '2026-04-25T02:00:00+00:00',
                'command_context' => ['source' => 'api'],
            ],
        );

        Carbon::setTestNow('2026-04-24T10:05:00+00:00');
        WorkflowScheduleHistoryEvent::record(
            $schedule,
            HistoryEventType::SchedulePaused,
            [
                'reason' => 'maintenance',
                'paused_at' => '2026-04-24T10:05:00+00:00',
                'command_context' => ['source' => 'waterline'],
            ],
        );

        Carbon::setTestNow('2026-04-24T10:10:00+00:00');
        WorkflowScheduleHistoryEvent::record(
            $schedule,
            HistoryEventType::ScheduleResumed,
            [
                'next_fire_at' => '2026-04-25T02:00:00+00:00',
                'command_context' => ['source' => 'waterline'],
            ],
        );

        $response = $this->getJson('/waterline/api/v2/schedules/daily-invoice-sync/history')
            ->assertStatus(200)
            ->assertJsonPath('schedule_id', 'daily-invoice-sync')
            ->assertJsonPath('namespace', 'default')
            ->assertJsonPath('has_more', false)
            ->assertJsonPath('next_cursor', null)
            ->assertJsonCount(3, 'events');

        $events = $response->json('events');

        $this->assertSame(1, $events[0]['sequence']);
        $this->assertSame('ScheduleCreated', $events[0]['event_type']);
        $this->assertSame('skip', $events[0]['payload']['overlap_policy']);

        $this->assertSame(2, $events[1]['sequence']);
        $this->assertSame('SchedulePaused', $events[1]['event_type']);
        $this->assertSame('maintenance', $events[1]['payload']['reason']);

        $this->assertSame(3, $events[2]['sequence']);
        $this->assertSame('ScheduleResumed', $events[2]['event_type']);
        $this->assertArrayHasKey('recorded_at', $events[2]);
        $this->assertArrayHasKey('id', $events[2]);
    }

    public function testHistorySupportsCursorPagination(): void
    {
        $schedule = $this->createSchedule('paginated-schedule');

        for ($i = 0; $i < 5; $i++) {
            WorkflowScheduleHistoryEvent::record(
                $schedule,
                HistoryEventType::ScheduleTriggered,
                [
                    'workflow_instance_id' => 'instance-'.$i,
                    'workflow_run_id' => 'run-'.$i,
                    'schedule_id' => 'paginated-schedule',
                    'schedule_ulid' => $schedule->id,
                    'cron_expression' => '*/5 * * * *',
                    'timezone' => 'UTC',
                    'overlap_policy' => 'skip',
                    'outcome' => 'started',
                    'effective_overlap_policy' => 'skip',
                    'trigger_number' => $i + 1,
                    'occurrence_time' => '2026-04-24T10:0'.$i.':00+00:00',
                    'command_context' => ['source' => 'tick'],
                ],
            );
        }

        $firstPage = $this->getJson('/waterline/api/v2/schedules/paginated-schedule/history?limit=2')
            ->assertStatus(200)
            ->assertJsonCount(2, 'events')
            ->assertJsonPath('has_more', true)
            ->assertJsonPath('next_cursor', 2);

        $this->assertSame(1, $firstPage->json('events.0.sequence'));
        $this->assertSame(2, $firstPage->json('events.1.sequence'));

        $secondPage = $this->getJson('/waterline/api/v2/schedules/paginated-schedule/history?limit=2&after_sequence=2')
            ->assertStatus(200)
            ->assertJsonCount(2, 'events')
            ->assertJsonPath('has_more', true)
            ->assertJsonPath('next_cursor', 4);

        $this->assertSame(3, $secondPage->json('events.0.sequence'));
        $this->assertSame(4, $secondPage->json('events.1.sequence'));

        $thirdPage = $this->getJson('/waterline/api/v2/schedules/paginated-schedule/history?limit=2&after_sequence=4')
            ->assertStatus(200)
            ->assertJsonCount(1, 'events')
            ->assertJsonPath('has_more', false)
            ->assertJsonPath('next_cursor', null);

        $this->assertSame(5, $thirdPage->json('events.0.sequence'));
    }

    public function testHistoryRespectsConfiguredNamespace(): void
    {
        config()->set('waterline.namespace', 'tenant-a');

        $scheduleA = $this->createSchedule('shared-id', namespace: 'tenant-a');
        $scheduleB = $this->createSchedule('shared-id', namespace: 'tenant-b');

        WorkflowScheduleHistoryEvent::record(
            $scheduleA,
            HistoryEventType::SchedulePaused,
            [
                'reason' => 'tenant-a-pause',
                'paused_at' => '2026-04-24T10:00:00+00:00',
                'command_context' => ['source' => 'api'],
            ],
        );

        WorkflowScheduleHistoryEvent::record(
            $scheduleB,
            HistoryEventType::SchedulePaused,
            [
                'reason' => 'tenant-b-pause',
                'paused_at' => '2026-04-24T10:00:00+00:00',
                'command_context' => ['source' => 'api'],
            ],
        );

        $response = $this->getJson('/waterline/api/v2/schedules/shared-id/history')
            ->assertStatus(200)
            ->assertJsonPath('namespace', 'tenant-a')
            ->assertJsonCount(1, 'events');

        $this->assertSame('tenant-a-pause', $response->json('events.0.payload.reason'));
    }

    public function testHistoryClampsLimitAtUpperBound(): void
    {
        $schedule = $this->createSchedule('big-limit');

        WorkflowScheduleHistoryEvent::record(
            $schedule,
            HistoryEventType::ScheduleCreated,
            [
                'spec' => ['cron_expressions' => ['0 * * * *'], 'timezone' => 'UTC'],
                'action' => ['workflow_type' => 'workflow.test'],
                'overlap_policy' => 'skip',
                'next_fire_at' => '2026-04-25T00:00:00+00:00',
                'command_context' => ['source' => 'api'],
            ],
        );

        $this->getJson('/waterline/api/v2/schedules/big-limit/history?limit=99999')
            ->assertStatus(200)
            ->assertJsonCount(1, 'events');

        $this->getJson('/waterline/api/v2/schedules/big-limit/history?limit=0')
            ->assertStatus(200)
            ->assertJsonCount(1, 'events');

        $this->getJson('/waterline/api/v2/schedules/big-limit/history?limit=-5')
            ->assertStatus(200)
            ->assertJsonCount(1, 'events');
    }

    private function createSchedule(string $scheduleId, string $namespace = 'default'): WorkflowSchedule
    {
        return WorkflowSchedule::create([
            'schedule_id' => $scheduleId,
            'namespace' => $namespace,
            'spec' => ['cron_expressions' => ['0 * * * *'], 'timezone' => 'UTC'],
            'action' => ['workflow_type' => 'workflow.test', 'workflow_class' => 'Test\\ScheduledWorkflow'],
            'status' => ScheduleStatus::Active,
            'overlap_policy' => 'skip',
            'fires_count' => 0,
            'failures_count' => 0,
            'skipped_trigger_count' => 0,
            'jitter_seconds' => 0,
            'next_fire_at' => Carbon::now()->addHour(),
        ]);
    }
}
