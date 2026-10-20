<?php

declare(strict_types=1);

namespace Waterline\Tests\Feature;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Waterline\Tests\Fixtures\V2\TestOperatorCommandWorkflow;
use Waterline\Tests\TestCase;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Enums\ScheduleOverlapPolicy;
use Workflow\V2\Enums\ScheduleStatus;
use Workflow\V2\Models\WorkflowSchedule;
use Workflow\V2\Models\WorkflowScheduleHistoryEvent;
use Workflow\V2\Support\ScheduleManager;

final class V2SchedulesControllerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        Carbon::setTestNow('2026-10-20T12:30:00Z');
    }

    public function testListRowsAreKeyedByScheduleIdAndNeverExposeTheInternalKey(): void
    {
        $schedule = $this->createSchedule('nightly-orders');
        $instanceId = ScheduleManager::trigger($schedule);
        $this->assertIsString($instanceId);
        $schedule->refresh();

        $response = $this->getJson('/waterline/api/v2/schedules')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('operator_scope.mode', 'cluster');

        $row = $response->json('data.0');

        $this->assertNotSame($schedule->schedule_id, $schedule->id);
        $this->assertStringNotContainsString((string) $schedule->id, (string) json_encode($row));
        $this->assertSame('nightly-orders', $row['id']);
        $this->assertSame('nightly-orders', $row['schedule_id']);
        $this->assertSame('default', $row['namespace']);
        $this->assertSame('workflow.operator-command', $row['workflow_type']);
        $this->assertSame(TestOperatorCommandWorkflow::class, $row['workflow_class']);
        $this->assertSame('active', $row['status']);
        $this->assertFalse($row['paused']);
        $this->assertSame([
            'cron_expressions' => ['0 * * * *'],
            'intervals' => [['every' => 'PT6H']],
            'timezone' => 'UTC',
        ], $row['spec']);
        $this->assertSame('skip', $row['overlap_policy']);
        $this->assertSame('2026-10-20T13:00:00.000000+00:00', $row['next_fire_at']);
        $this->assertSame('2026-10-20T12:30:00.000000+00:00', $row['last_fired_at']);
        $this->assertSame(1, $row['fires_count']);
        $this->assertSame(0, $row['failures_count']);
        $this->assertSame([
            'outcome' => 'scheduled',
            'at' => '2026-10-20T12:30:00+00:00',
            'reason' => null,
            'workflow_id' => $instanceId,
            'run_id' => $schedule->recent_actions[0]['run_id'],
        ], $row['last_outcome']);
        $this->assertIsString($row['last_outcome']['run_id']);
    }

    public function testListRowReportsALaterSkipAsTheLastOutcome(): void
    {
        $schedule = $this->createSchedule('skipping');
        ScheduleManager::trigger($schedule);

        Carbon::setTestNow('2026-10-20T12:31:00Z');
        // The first run is still open, so the skip policy declines this fire.
        $this->assertNull(ScheduleManager::trigger($schedule->refresh()));

        $this->getJson('/waterline/api/v2/schedules')
            ->assertOk()
            ->assertJsonPath('data.0.last_outcome.outcome', 'skipped')
            ->assertJsonPath('data.0.last_outcome.reason', 'overlap_policy_skip')
            ->assertJsonPath('data.0.last_outcome.at', '2026-10-20T12:31:00.000000+00:00')
            ->assertJsonPath('data.0.skipped_trigger_count', 1);
    }

    public function testListRowReportsARecordedFailure(): void
    {
        $schedule = $this->createSchedule('failing');
        $schedule->recordFailure('Workflow class [Missing] does not exist.');
        $schedule->save();

        $this->getJson('/waterline/api/v2/schedules')
            ->assertOk()
            ->assertJsonPath('data.0.failures_count', 1)
            ->assertJsonPath('data.0.last_outcome.outcome', 'failed')
            ->assertJsonPath('data.0.last_outcome.reason', 'Workflow class [Missing] does not exist.')
            ->assertJsonPath('data.0.last_outcome.workflow_id', null);
    }

    public function testEveryRowActionResolvesTheListedScheduleIdAndNamespace(): void
    {
        $schedule = $this->createSchedule('row-actions');
        $row = $this->getJson('/waterline/api/v2/schedules')->assertOk()->json('data.0');
        $id = $row['schedule_id'];
        $scope = ['namespace' => $row['namespace']];

        $this->getJson("/waterline/api/v2/schedules/{$id}?namespace={$row['namespace']}")
            ->assertOk()
            ->assertJsonPath('id', 'row-actions')
            ->assertJsonPath('namespace', 'default')
            ->assertJsonPath('action.workflow_class', TestOperatorCommandWorkflow::class)
            ->assertJsonPath('recent_actions', [])
            ->assertJsonPath('operator_scope.mode', 'cluster');

        $this->postJson("/waterline/api/v2/schedules/{$id}/pause", [...$scope, 'note' => 'Supplier maintenance'])
            ->assertOk()
            ->assertJsonPath('schedule_id', 'row-actions')
            ->assertJsonPath('status', 'paused')
            ->assertJsonPath('paused', true)
            ->assertJsonPath('note', 'Supplier maintenance')
            ->assertJsonPath('paused_at', '2026-10-20T12:30:00.000000+00:00');
        $this->assertSame(ScheduleStatus::Paused, $schedule->refresh()->status);
        $this->assertSame('Supplier maintenance', $schedule->note);

        $this->postJson("/waterline/api/v2/schedules/{$id}/resume", $scope)
            ->assertOk()
            ->assertJsonPath('status', 'active')
            ->assertJsonPath('paused', false)
            ->assertJsonPath('next_fire_at', '2026-10-20T13:00:00.000000+00:00');
        $this->assertSame(ScheduleStatus::Active, $schedule->refresh()->status);

        $trigger = $this->postJson("/waterline/api/v2/schedules/{$id}/trigger", $scope)
            ->assertOk()
            ->assertJsonPath('schedule_id', 'row-actions')
            ->assertJsonPath('namespace', 'default')
            ->assertJsonPath('outcome', 'triggered')
            ->assertJsonPath('triggered', true)
            ->assertJsonPath('reason', null)
            ->assertJsonPath('operator_scope.mode', 'cluster');
        $schedule->refresh();
        $this->assertSame(1, $schedule->fires_count);
        $this->assertIsString($trigger->json('workflow_id'));
        $this->assertSame($schedule->latest_workflow_instance_id, $trigger->json('workflow_id'));
        $this->assertSame($trigger->json('workflow_id'), $trigger->json('instance_id'));
        $this->assertSame($schedule->recent_actions[0]['run_id'], $trigger->json('run_id'));

        $this->postJson("/waterline/api/v2/schedules/{$id}/backfill", [
            ...$scope,
            'from' => '2026-10-20T08:30:00Z',
            'to' => '2026-10-20T10:30:00Z',
            'overlap_policy' => 'allow_all',
        ])
            ->assertOk()
            ->assertJsonPath('schedule_id', 'row-actions')
            ->assertJsonPath('namespace', 'default')
            ->assertJsonPath('fires_attempted', 2)
            ->assertJsonPath('started_count', 2)
            ->assertJsonPath('skipped_count', 0)
            ->assertJsonPath('failed_count', 0)
            ->assertJsonPath('results.0.fire_time', '2026-10-20T09:00:00+00:00')
            ->assertJsonPath('results.0.outcome', 'started')
            ->assertJsonPath('results.1.fire_time', '2026-10-20T10:00:00+00:00')
            ->assertJsonPath('results.1.outcome', 'started');
        $this->assertSame(3, $schedule->refresh()->fires_count);

        $history = $this->getJson("/waterline/api/v2/schedules/{$id}/history?namespace={$row['namespace']}")
            ->assertOk()
            ->assertJsonPath('schedule_id', 'row-actions')
            ->assertJsonPath('namespace', 'default');
        $this->assertSame([
            'ScheduleCreated',
            'SchedulePaused',
            'ScheduleResumed',
            'ScheduleTriggered',
            'ScheduleTriggered',
            'ScheduleTriggered',
        ], array_column($history->json('events'), 'event_type'));

        $this->deleteJson("/waterline/api/v2/schedules/{$id}", $scope)
            ->assertOk()
            ->assertJsonPath('schedule_id', 'row-actions')
            ->assertJsonPath('status', 'deleted')
            ->assertJsonPath('next_fire_at', null);
        $this->assertSame(ScheduleStatus::Deleted, $schedule->refresh()->status);

        $this->getJson('/waterline/api/v2/schedules')->assertOk()->assertJsonCount(0, 'data');
    }

    public function testTriggerReportsASkippedFireWithItsReason(): void
    {
        $schedule = $this->createSchedule('busy');
        ScheduleManager::trigger($schedule);

        $this->postJson('/waterline/api/v2/schedules/busy/trigger')
            ->assertOk()
            ->assertJsonPath('outcome', 'skipped')
            ->assertJsonPath('triggered', false)
            ->assertJsonPath('workflow_id', null)
            ->assertJsonPath('instance_id', null)
            ->assertJsonPath('run_id', null)
            ->assertJsonPath('reason', 'overlap_policy_skip');

        $this->assertSame(1, $schedule->refresh()->fires_count);
    }

    public function testTriggerPassesAValidOverlapPolicyOverride(): void
    {
        $schedule = $this->createSchedule('override');
        ScheduleManager::trigger($schedule);
        // Manual triggers derive the workflow ID from the current millisecond.
        Carbon::setTestNow('2026-10-20T12:31:00Z');

        $this->postJson('/waterline/api/v2/schedules/override/trigger', ['overlap_policy' => 'allow_all'])
            ->assertOk()
            ->assertJsonPath('outcome', 'triggered')
            ->assertJsonPath('triggered', true);

        $this->assertSame(2, $schedule->refresh()->fires_count);
    }

    public function testBackfillReportsSkippedOccurrencesUnderTheSchedulePolicy(): void
    {
        $schedule = $this->createSchedule('backfill-skip');

        $this->postJson('/waterline/api/v2/schedules/backfill-skip/backfill', [
            'from' => '2026-10-20T08:30:00Z',
            'to' => '2026-10-20T10:30:00Z',
        ])
            ->assertOk()
            ->assertJsonPath('fires_attempted', 2)
            ->assertJsonPath('started_count', 1)
            ->assertJsonPath('skipped_count', 1)
            ->assertJsonPath('results.0.outcome', 'started')
            ->assertJsonPath('results.1.outcome', 'skipped')
            ->assertJsonPath('results.1.workflow_id', null);

        $this->assertSame(1, $schedule->refresh()->fires_count);
    }

    public function testUnknownOverlapPolicyIsRefusedBeforeAnyFire(): void
    {
        $schedule = $this->createSchedule('strict');

        $this->postJson('/waterline/api/v2/schedules/strict/trigger', ['overlap_policy' => 'run_twice'])
            ->assertStatus(422)
            ->assertJsonPath('reason', 'invalid_overlap_policy');

        $this->postJson('/waterline/api/v2/schedules/strict/backfill', [
            'from' => '2026-10-20T08:30:00Z',
            'to' => '2026-10-20T10:30:00Z',
            'overlap_policy' => 'run_twice',
        ])
            ->assertStatus(422)
            ->assertJsonPath('reason', 'invalid_overlap_policy');

        $schedule->refresh();
        $this->assertSame(0, $schedule->fires_count);
        $this->assertSame(0, (int) $schedule->skipped_trigger_count);
        $this->assertSame([HistoryEventType::ScheduleCreated], $this->eventTypes($schedule));
    }

    public function testClusterWideDuplicateIdRequiresANamespace(): void
    {
        $default = $this->createSchedule('shared-id');
        $tenant = $this->createSchedule('shared-id', 'tenant-b');

        $rows = $this->getJson('/waterline/api/v2/schedules')->assertOk()->json('data');
        $this->assertEqualsCanonicalizing(
            [['shared-id', 'default'], ['shared-id', 'tenant-b']],
            array_map(static fn (array $row): array => [$row['id'], $row['namespace']], $rows),
        );

        foreach ([
            ['GET', '/waterline/api/v2/schedules/shared-id'],
            ['GET', '/waterline/api/v2/schedules/shared-id/history'],
            ['POST', '/waterline/api/v2/schedules/shared-id/pause'],
            ['POST', '/waterline/api/v2/schedules/shared-id/resume'],
            ['POST', '/waterline/api/v2/schedules/shared-id/trigger'],
            ['POST', '/waterline/api/v2/schedules/shared-id/backfill'],
            ['DELETE', '/waterline/api/v2/schedules/shared-id'],
        ] as [$method, $uri]) {
            $this->json($method, $uri, $method === 'POST' && str_ends_with($uri, 'backfill')
                ? ['from' => '2026-10-20T08:30:00Z', 'to' => '2026-10-20T10:30:00Z']
                : [])
                ->assertStatus(409)
                ->assertJsonPath('reason', 'schedule_namespace_required')
                ->assertJsonPath('schedule_id', 'shared-id');
        }

        foreach ([$default, $tenant] as $schedule) {
            $schedule->refresh();
            $this->assertSame(ScheduleStatus::Active, $schedule->status);
            $this->assertSame(0, $schedule->fires_count);
            $this->assertSame([HistoryEventType::ScheduleCreated], $this->eventTypes($schedule));
        }
    }

    public function testClusterWideNamespaceParameterIsolatesDuplicateIds(): void
    {
        $default = $this->createSchedule('shared-id');
        $tenant = $this->createSchedule('shared-id', 'tenant-b');

        $this->postJson('/waterline/api/v2/schedules/shared-id/pause', ['namespace' => 'tenant-b'])
            ->assertOk()
            ->assertJsonPath('namespace', 'tenant-b')
            ->assertJsonPath('status', 'paused');
        $this->assertSame(ScheduleStatus::Paused, $tenant->refresh()->status);
        $this->assertSame(ScheduleStatus::Active, $default->refresh()->status);

        $this->postJson('/waterline/api/v2/schedules/shared-id/trigger', ['namespace' => 'default'])
            ->assertOk()
            ->assertJsonPath('namespace', 'default')
            ->assertJsonPath('outcome', 'triggered');
        $this->assertSame(1, $default->refresh()->fires_count);
        $this->assertSame(0, $tenant->refresh()->fires_count);

        $this->getJson('/waterline/api/v2/schedules/shared-id?namespace=tenant-b')
            ->assertOk()
            ->assertJsonPath('namespace', 'tenant-b')
            ->assertJsonPath('status', 'paused')
            ->assertJsonPath('fires_count', 0);

        $this->getJson('/waterline/api/v2/schedules/shared-id/history?namespace=default')
            ->assertOk()
            ->assertJsonPath('namespace', 'default')
            ->assertJsonCount(2, 'events')
            ->assertJsonPath('events.1.event_type', 'ScheduleTriggered');

        $this->postJson('/waterline/api/v2/schedules/shared-id/resume', ['namespace' => 'tenant-b'])
            ->assertOk()
            ->assertJsonPath('namespace', 'tenant-b')
            ->assertJsonPath('status', 'active');

        $this->postJson('/waterline/api/v2/schedules/shared-id/backfill', [
            'namespace' => 'tenant-b',
            'from' => '2026-10-20T09:30:00Z',
            'to' => '2026-10-20T10:30:00Z',
        ])
            ->assertOk()
            ->assertJsonPath('namespace', 'tenant-b')
            ->assertJsonPath('started_count', 1);
        $this->assertSame(1, $tenant->refresh()->fires_count);
        $this->assertSame(1, $default->refresh()->fires_count);

        $this->deleteJson('/waterline/api/v2/schedules/shared-id', ['namespace' => 'default'])
            ->assertOk()
            ->assertJsonPath('namespace', 'default')
            ->assertJsonPath('status', 'deleted');
        $this->assertSame(ScheduleStatus::Deleted, $default->refresh()->status);
        $this->assertSame(ScheduleStatus::Active, $tenant->refresh()->status);

        $this->getJson('/waterline/api/v2/schedules/shared-id?namespace=tenant-c')
            ->assertNotFound()
            ->assertJsonPath('reason', 'schedule_not_found');
    }

    public function testClusterWideUniqueIdResolvesWithoutANamespace(): void
    {
        $schedule = $this->createSchedule('tenant-only', 'tenant-b');

        $this->postJson('/waterline/api/v2/schedules/tenant-only/pause')
            ->assertOk()
            ->assertJsonPath('namespace', 'tenant-b')
            ->assertJsonPath('status', 'paused');

        $this->assertSame(ScheduleStatus::Paused, $schedule->refresh()->status);
    }

    public function testNamespaceScopedWaterlineHidesAndRefusesOtherNamespaces(): void
    {
        config()->set('waterline.namespace', 'default');

        $default = $this->createSchedule('shared-id');
        $tenant = $this->createSchedule('shared-id', 'tenant-b');
        $tenantOnly = $this->createSchedule('tenant-only', 'tenant-b');

        $this->getJson('/waterline/api/v2/schedules')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', 'shared-id')
            ->assertJsonPath('data.0.namespace', 'default')
            ->assertJsonPath('operator_scope.mode', 'namespace')
            ->assertJsonPath('operator_scope.namespace', 'default');

        $this->getJson('/waterline/api/v2/schedules/tenant-only')
            ->assertNotFound()
            ->assertJsonPath('reason', 'schedule_not_found');
        $this->getJson('/waterline/api/v2/schedules/tenant-only?namespace=tenant-b')
            ->assertNotFound()
            ->assertJsonPath('reason', 'schedule_not_found');

        foreach (['shared-id', 'tenant-only'] as $id) {
            foreach (['pause', 'trigger'] as $action) {
                $this->postJson("/waterline/api/v2/schedules/{$id}/{$action}", ['namespace' => 'tenant-b'])
                    ->assertNotFound()
                    ->assertJsonPath('error', 'Schedule not found.')
                    ->assertJsonPath('reason', 'schedule_not_found');
            }

            $this->getJson("/waterline/api/v2/schedules/{$id}/history?namespace=tenant-b")
                ->assertNotFound();
            $this->deleteJson("/waterline/api/v2/schedules/{$id}", ['namespace' => 'tenant-b'])
                ->assertNotFound();
        }

        foreach ([$tenant, $tenantOnly] as $schedule) {
            $schedule->refresh();
            $this->assertSame(ScheduleStatus::Active, $schedule->status);
            $this->assertSame(0, $schedule->fires_count);
        }

        // Without a namespace, or with its own, the scoped Waterline acts on its own row.
        $this->postJson('/waterline/api/v2/schedules/shared-id/pause')
            ->assertOk()
            ->assertJsonPath('namespace', 'default');
        $this->postJson('/waterline/api/v2/schedules/shared-id/resume', ['namespace' => 'default'])
            ->assertOk()
            ->assertJsonPath('namespace', 'default');
        $this->assertSame(
            [HistoryEventType::ScheduleCreated, HistoryEventType::SchedulePaused, HistoryEventType::ScheduleResumed],
            $this->eventTypes($default),
        );
        $this->assertSame(ScheduleStatus::Active, $tenant->refresh()->status);
    }

    public function testMissingScheduleIsNotFound(): void
    {
        $this->getJson('/waterline/api/v2/schedules/missing')
            ->assertNotFound()
            ->assertJsonPath('error', 'Schedule not found.')
            ->assertJsonPath('reason', 'schedule_not_found');

        $this->postJson('/waterline/api/v2/schedules/missing/pause')
            ->assertNotFound()
            ->assertJsonPath('reason', 'schedule_not_found');
    }

    public function testScheduleIdsWithSlashesCanBeOpenedAndActedOn(): void
    {
        $schedule = $this->createSchedule('billing/daily run');
        $path = '/waterline/api/v2/schedules/'.rawurlencode('billing/daily run');

        $this->getJson($path)
            ->assertOk()
            ->assertJsonPath('schedule_id', 'billing/daily run');
        $this->postJson($path.'/pause')
            ->assertOk()
            ->assertJsonPath('status', 'paused');
        $this->getJson($path.'/history')
            ->assertOk()
            ->assertJsonPath('schedule_id', 'billing/daily run')
            ->assertJsonPath('events.1.event_type', HistoryEventType::SchedulePaused->value);
        $this->assertSame(ScheduleStatus::Paused, $schedule->refresh()->status);
    }

    public function testBackfillRefusesAWindowThatEndsBeforeItStarts(): void
    {
        $this->createSchedule('reversed-window');

        $this->postJson('/waterline/api/v2/schedules/reversed-window/backfill', [
            'from' => '2026-10-20T10:00:00Z',
            'to' => '2026-10-20T09:00:00Z',
        ])
            ->assertStatus(422)
            ->assertJsonPath('reason', 'invalid_time_range');
    }

    public function testListRowsCarryTheDetailFacts(): void
    {
        $schedule = $this->createSchedule('detailed-row');
        $instanceId = ScheduleManager::trigger($schedule);

        $this->getJson('/waterline/api/v2/schedules')
            ->assertOk()
            ->assertJsonPath('data.0.action.workflow_class', TestOperatorCommandWorkflow::class)
            ->assertJsonPath('data.0.recent_actions.0.workflow_id', $instanceId)
            ->assertJsonPath('data.0.latest_workflow_instance_id', $instanceId)
            ->assertJsonPath('data.0.latest_instance_id', $instanceId)
            ->assertJsonPath('data.0.overlap_policy', 'skip');
    }

    public function testBackfillKeepsTheEarlierResultNames(): void
    {
        $this->createSchedule('named-results');

        $this->postJson('/waterline/api/v2/schedules/named-results/backfill', [
            'from' => '2026-10-20T09:30:00Z',
            'to' => '2026-10-20T10:30:00Z',
            'overlap_policy' => 'allow_all',
        ])
            ->assertOk()
            ->assertJsonPath('results.0.cron_time', '2026-10-20T10:00:00+00:00')
            ->assertJsonPath('results.0.fire_time', '2026-10-20T10:00:00+00:00')
            ->assertJsonPath('results.0.schedule_id', 'named-results')
            ->assertJsonPath('results.0.error', null);
    }

    private function createSchedule(string $scheduleId, string $namespace = 'default'): WorkflowSchedule
    {
        return ScheduleManager::createFromSpec(
            scheduleId: $scheduleId,
            spec: [
                'cron_expressions' => ['0 * * * *'],
                'intervals' => [['every' => 'PT6H']],
                'timezone' => 'UTC',
            ],
            action: [
                'workflow_type' => 'workflow.operator-command',
                'workflow_class' => TestOperatorCommandWorkflow::class,
            ],
            overlapPolicy: ScheduleOverlapPolicy::Skip,
            namespace: $namespace,
        );
    }

    /**
     * @return list<HistoryEventType>
     */
    private function eventTypes(WorkflowSchedule $schedule): array
    {
        return WorkflowScheduleHistoryEvent::query()
            ->where('workflow_schedule_id', $schedule->id)
            ->orderBy('sequence')
            ->get()
            ->map(static fn (WorkflowScheduleHistoryEvent $event): HistoryEventType => $event->event_type)
            ->all();
    }
}
