<?php

declare(strict_types=1);

namespace Waterline\Tests\Unit\Support;

use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;
use Waterline\Support\ScheduleRow;
use Workflow\V2\Enums\ScheduleStatus;

final class ScheduleRowTest extends TestCase
{
    private const INTERNAL_KEY = '01JD8Z6Q3K9V7X2M4N5P6R7S8T';

    public function testEmbeddedSourceMapsToARowKeyedByScheduleId(): void
    {
        $row = ScheduleRow::row($this->embeddedSource());

        self::assertSame([
            'id' => 'nightly-invoices',
            'schedule_id' => 'nightly-invoices',
            'namespace' => 'billing',
            'workflow_type' => 'invoices.sync',
            'workflow_class' => 'App\\Workflows\\SyncInvoices',
            'status' => 'paused',
            'paused' => true,
            'note' => 'month-end freeze',
            'spec' => [
                'cron_expressions' => ['0 2 * * *'],
                'intervals' => [['every' => 'PT6H', 'offset' => 'PT5M']],
                'timezone' => 'Europe/Berlin',
            ],
            'overlap_policy' => 'skip',
            'next_fire_at' => '2026-10-21T00:00:00.000000+00:00',
            'last_fired_at' => '2026-10-20T00:00:01.000000+00:00',
            'last_outcome' => [
                'outcome' => 'scheduled',
                'at' => '2026-10-20T00:00:01+00:00',
                'reason' => null,
                'workflow_id' => 'schedule:nightly-invoices:1760918401000',
                'run_id' => 'run-7',
            ],
            'fires_count' => 7,
            'failures_count' => 1,
            'skipped_trigger_count' => 2,
            'remaining_actions' => 3,
            'search_attributes' => ['tenant' => 'acme'],
            'created_at' => '2026-10-01T09:30:00.000000+00:00',
            'updated_at' => '2026-10-20T00:00:01.000000+00:00',
        ], $row);
    }

    public function testEmbeddedDetailAddsDialogFactsAndNormalizedRecentActions(): void
    {
        $detail = ScheduleRow::detail($this->embeddedSource());

        self::assertSame('nightly-invoices', $detail['id']);
        self::assertSame(['workflow_type' => 'invoices.sync', 'workflow_class' => 'App\\Workflows\\SyncInvoices'], $detail['action']);
        self::assertSame(10, $detail['max_runs']);
        self::assertSame(30, $detail['jitter_seconds']);
        self::assertSame('2026-10-20T08:00:00.000000+00:00', $detail['paused_at']);
        self::assertSame('overlap_policy_skip', $detail['last_skip_reason']);
        self::assertSame('2026-10-19T00:00:00.000000+00:00', $detail['last_skipped_at']);
        self::assertSame('schedule:nightly-invoices:1760918401000', $detail['latest_workflow_instance_id']);
        self::assertSame([
            [
                'outcome' => 'failed',
                'fired_at' => '2026-10-19T00:00:02+00:00',
                'reason' => 'Workflow class missing.',
                'workflow_id' => null,
                'run_id' => null,
                'occurrence_time' => null,
            ],
            [
                'outcome' => 'scheduled',
                'fired_at' => '2026-10-20T00:00:01+00:00',
                'reason' => null,
                'workflow_id' => 'schedule:nightly-invoices:1760918401000',
                'run_id' => 'run-7',
                'occurrence_time' => '2026-10-20T00:00:00.000000+00:00',
            ],
        ], $detail['recent_actions']);
        self::assertSame([], $detail['buffered_actions']);
        self::assertSame(['owner' => 'finance'], $detail['memo']);
        self::assertSame(['tenant' => 'acme'], $detail['search_attributes']);
    }

    public function testServerListItemMapsToTheSameRowShapeWithoutAnOutcome(): void
    {
        $row = ScheduleRow::row($this->serverListItem());

        self::assertSame('orders-hourly', $row['id']);
        self::assertSame('orders-hourly', $row['schedule_id']);
        self::assertSame('orders', $row['namespace']);
        self::assertSame('orders.process', $row['workflow_type']);
        self::assertNull($row['workflow_class']);
        self::assertSame('active', $row['status']);
        self::assertFalse($row['paused']);
        self::assertSame(['cron_expressions' => ['0 * * * *'], 'intervals' => [], 'timezone' => 'UTC'], $row['spec']);
        self::assertSame('allow_all', $row['overlap_policy']);
        self::assertSame('2026-10-20T13:00:00+00:00', $row['next_fire_at']);
        self::assertSame('2026-10-20T12:00:00+00:00', $row['last_fired_at']);
        self::assertSame(12, $row['fires_count']);
        self::assertSame(8, $row['remaining_actions']);
        self::assertSame(['region' => 'eu'], $row['search_attributes']);
        // A list item carries no fire outcomes or failure counters.
        self::assertNull($row['last_outcome']);
        self::assertNull($row['failures_count']);
        self::assertNull($row['skipped_trigger_count']);
        self::assertSame(array_keys(ScheduleRow::row($this->embeddedSource())), array_keys($row));
    }

    public function testServerListItemFallsBackToTheShortFireKeys(): void
    {
        $item = $this->serverListItem();
        unset($item['next_fire_at'], $item['last_fired_at']);

        $row = ScheduleRow::row($item);

        self::assertSame('2026-10-20T13:00:00+00:00', $row['next_fire_at']);
        self::assertSame('2026-10-20T12:00:00+00:00', $row['last_fired_at']);
    }

    public function testServerDetailFlattensStateAndInfoWithTopLevelKeysWinning(): void
    {
        $detail = ScheduleRow::detail($this->serverDetail());

        self::assertSame('orders-hourly', $detail['id']);
        self::assertSame('orders', $detail['namespace']);
        self::assertSame('orders.process', $detail['workflow_type']);
        // Top-level status wins over the nested state copy.
        self::assertSame('paused', $detail['status']);
        self::assertTrue($detail['paused']);
        self::assertSame('top-level note', $detail['note']);
        self::assertSame('2026-10-20T13:00:00+00:00', $detail['next_fire_at']);
        self::assertSame(12, $detail['fires_count']);
        self::assertSame(2, $detail['failures_count']);
        // Only the nested info carries skip facts and fire history.
        self::assertSame(4, $detail['skipped_trigger_count']);
        self::assertSame('overlap_policy_skip', $detail['last_skip_reason']);
        self::assertSame('2026-10-20T11:30:00+00:00', $detail['last_skipped_at']);
        self::assertCount(2, $detail['recent_actions']);
        self::assertSame([['occurrence_time' => '2026-10-20T13:00:00+00:00']], $detail['buffered_actions']);
        self::assertSame('2026-10-20T09:00:00+00:00', $detail['paused_at']);
        self::assertSame(0, $detail['jitter_seconds']);
        self::assertSame(20, $detail['max_runs']);
        self::assertSame(8, $detail['remaining_actions']);
        self::assertSame('schedule:orders-hourly:1760961600000', $detail['latest_workflow_instance_id']);
        self::assertSame(['owner' => 'ops'], $detail['memo']);
        self::assertSame([
            'outcome' => 'scheduled',
            'at' => '2026-10-20T12:00:00+00:00',
            'reason' => null,
            'workflow_id' => 'schedule:orders-hourly:1760961600000',
            'run_id' => 'run-12',
        ], $detail['last_outcome']);
    }

    public function testNestedInfoFillsGapsWhenTopLevelKeysAreAbsent(): void
    {
        $source = $this->serverDetail();
        unset($source['status'], $source['note'], $source['fires_count'], $source['next_fire_at']);

        $row = ScheduleRow::row($source);

        self::assertSame('active', $row['status']);
        self::assertFalse($row['paused']);
        self::assertSame('state note', $row['note']);
        self::assertSame(11, $row['fires_count']);
        self::assertSame('2026-10-20T13:00:00+00:00', $row['next_fire_at']);
    }

    public function testDetailReadsTheOlderLatestInstanceKey(): void
    {
        $detail = ScheduleRow::detail(['schedule_id' => 'legacy', 'latest_instance_id' => 'instance-9']);

        self::assertSame('instance-9', $detail['latest_workflow_instance_id']);
    }

    public function testSpecNormalizationDropsJunkEntries(): void
    {
        $row = ScheduleRow::row([
            'schedule_id' => 'messy',
            'spec' => [
                'cron_expressions' => ['  */5 * * * *  ', '', '   ', 42, null, ['0 0 * * *'], '0 3 * * 1'],
                'intervals' => [
                    ['every' => 'PT1H'],
                    ['every' => ' PT30M ', 'offset' => ' PT1M '],
                    ['every' => 'PT2H', 'offset' => ''],
                    ['every' => ''],
                    ['offset' => 'PT5M'],
                    ['every' => 3600],
                    'PT1H',
                    null,
                ],
                'timezone' => '   ',
            ],
        ]);

        self::assertSame([
            'cron_expressions' => ['*/5 * * * *', '0 3 * * 1'],
            'intervals' => [
                ['every' => 'PT1H'],
                ['every' => 'PT30M', 'offset' => 'PT1M'],
                ['every' => 'PT2H'],
            ],
            'timezone' => null,
        ], $row['spec']);
    }

    public function testMissingOrMalformedSpecBecomesAnEmptyCadence(): void
    {
        $empty = ['cron_expressions' => [], 'intervals' => [], 'timezone' => null];

        self::assertSame($empty, ScheduleRow::row(['schedule_id' => 'a'])['spec']);
        self::assertSame($empty, ScheduleRow::row(['schedule_id' => 'b', 'spec' => 'every hour'])['spec']);
        self::assertSame([...$empty, 'cron' => '0 0 * * *'], ScheduleRow::row([
            'schedule_id' => 'c',
            'spec' => ['cron' => '0 0 * * *', 'cron_expressions' => '0 0 * * *', 'intervals' => 'PT1H'],
        ])['spec']);
    }

    public function testUnknownSpecKeysAreKeptAfterTheCadence(): void
    {
        self::assertSame([
            'cron_expressions' => ['0 2 * * *'],
            'intervals' => [],
            'timezone' => 'UTC',
            'start_at' => '2026-11-01T00:00:00Z',
        ], ScheduleRow::row([
            'schedule_id' => 'a',
            'spec' => ['start_at' => '2026-11-01T00:00:00Z', 'timezone' => 'UTC', 'cron_expressions' => ['0 2 * * *']],
        ])['spec']);
    }

    public function testIdentityIsPassedThroughWithoutTrimming(): void
    {
        $row = ScheduleRow::row(['schedule_id' => ' padded ', 'namespace' => 'Orders ']);

        self::assertSame(' padded ', $row['schedule_id']);
        self::assertSame(' padded ', $row['id']);
        self::assertSame('Orders ', $row['namespace']);
    }

    public function testFinerLastFireTimeOrdersAFireAfterASkipInTheSameSecond(): void
    {
        $outcome = ScheduleRow::lastOutcome([
            'last_fired_at' => '2026-10-20T12:30:00.700000+00:00',
            'recent_actions' => [['outcome' => 'scheduled', 'fired_at' => '2026-10-20T12:30:00+00:00', 'workflow_id' => 'wf-1']],
            'last_skipped_at' => '2026-10-20T12:30:00.200000+00:00',
            'last_skip_reason' => 'overlap_policy_skip',
        ]);

        self::assertSame('scheduled', $outcome['outcome']);
        self::assertSame('wf-1', $outcome['workflow_id']);
    }

    public function testFinerLastFireTimeOrdersASkipAfterAFireInTheSameSecond(): void
    {
        $outcome = ScheduleRow::lastOutcome([
            'last_fired_at' => '2026-10-20T12:30:00.200000+00:00',
            'recent_actions' => [['outcome' => 'scheduled', 'fired_at' => '2026-10-20T12:30:00+00:00']],
            'last_skipped_at' => '2026-10-20T12:30:00.700000+00:00',
        ]);

        self::assertSame('skipped', $outcome['outcome']);
    }

    public function testASkipAndFireWithTheSameWholeSecondReportTheSkip(): void
    {
        $outcome = ScheduleRow::lastOutcome([
            'last_fired_at' => '2026-10-20T12:30:00+00:00',
            'info' => [
                'recent_actions' => [['outcome' => 'backfilled', 'fired_at' => '2026-10-20T12:30:00+00:00']],
                'last_skipped_at' => '2026-10-20T12:30:00+00:00',
                'last_skip_reason' => 'overlap_policy_skip',
            ],
        ]);

        self::assertSame('skipped', $outcome['outcome']);
        self::assertSame('overlap_policy_skip', $outcome['reason']);
    }

    public function testDetailKeepsTheEarlierLatestInstanceName(): void
    {
        $detail = ScheduleRow::detail(['schedule_id' => 'a', 'latest_workflow_instance_id' => 'wf-9']);

        self::assertSame('wf-9', $detail['latest_workflow_instance_id']);
        self::assertSame('wf-9', $detail['latest_instance_id']);
    }

    public function testLastOutcomeComesFromTheLatestRecentAction(): void
    {
        $outcome = ScheduleRow::lastOutcome(['recent_actions' => [
            ['workflow_id' => 'wf-1', 'run_id' => 'run-1', 'outcome' => 'scheduled', 'fired_at' => '2026-10-20T10:00:00+00:00'],
            [
                'workflow_id' => 'wf-2', 'run_id' => 'run-2', 'outcome' => 'backfilled',
                'fired_at' => '2026-10-20T11:00:00+00:00', 'occurrence_time' => '2026-10-19T11:00:00.000000+00:00',
                'fire_lag_ms' => 86400000,
            ],
        ]]);

        self::assertSame([
            'outcome' => 'backfilled',
            'at' => '2026-10-20T11:00:00+00:00',
            'reason' => null,
            'workflow_id' => 'wf-2',
            'run_id' => 'run-2',
        ], $outcome);
    }

    public function testFailedFireReportsItsReason(): void
    {
        $outcome = ScheduleRow::lastOutcome(['recent_actions' => [
            ['workflow_id' => 'wf-1', 'run_id' => 'run-1', 'outcome' => 'scheduled', 'fired_at' => '2026-10-20T10:00:00+00:00'],
            ['outcome' => 'failed', 'reason' => 'Class [Missing] not found.', 'fired_at' => '2026-10-20T11:00:00+00:00'],
        ]]);

        self::assertSame([
            'outcome' => 'failed',
            'at' => '2026-10-20T11:00:00+00:00',
            'reason' => 'Class [Missing] not found.',
            'workflow_id' => null,
            'run_id' => null,
        ], $outcome);
    }

    public function testSkipAloneIsTheLastOutcome(): void
    {
        $outcome = ScheduleRow::lastOutcome([
            'recent_actions' => [],
            'last_skip_reason' => 'remaining_actions_exhausted',
            'last_skipped_at' => '2026-10-20T11:00:00+00:00',
        ]);

        self::assertSame([
            'outcome' => 'skipped',
            'at' => '2026-10-20T11:00:00+00:00',
            'reason' => 'remaining_actions_exhausted',
            'workflow_id' => null,
            'run_id' => null,
        ], $outcome);
    }

    public function testSkipAfterTheLatestFireWins(): void
    {
        $outcome = ScheduleRow::lastOutcome([
            'recent_actions' => [
                ['workflow_id' => 'wf-1', 'run_id' => 'run-1', 'outcome' => 'scheduled', 'fired_at' => '2026-10-20T10:00:00+00:00'],
            ],
            'last_skip_reason' => 'overlap_policy_skip',
            // 13:00+02:00 is 11:00 UTC, after the 10:00 UTC fire.
            'last_skipped_at' => '2026-10-20T13:00:00+02:00',
        ]);

        self::assertSame('skipped', $outcome['outcome'] ?? null);
        self::assertSame('overlap_policy_skip', $outcome['reason']);
    }

    public function testFireAfterTheLastSkipWins(): void
    {
        $outcome = ScheduleRow::lastOutcome([
            'recent_actions' => [
                ['workflow_id' => 'wf-1', 'run_id' => 'run-1', 'outcome' => 'scheduled', 'fired_at' => '2026-10-20T12:00:00+00:00'],
            ],
            'last_skip_reason' => 'overlap_policy_skip',
            'last_skipped_at' => '2026-10-20T11:00:00+00:00',
        ]);

        self::assertSame('scheduled', $outcome['outcome'] ?? null);
        self::assertSame('wf-1', $outcome['workflow_id']);
        self::assertNull($outcome['reason']);
    }

    public function testNestedServerSkipIsComparedWithNestedRecentActions(): void
    {
        $detail = $this->serverDetail();
        $detail['info']['last_skipped_at'] = '2026-10-20T12:30:00+00:00';

        self::assertSame([
            'outcome' => 'skipped',
            'at' => '2026-10-20T12:30:00+00:00',
            'reason' => 'overlap_policy_skip',
            'workflow_id' => null,
            'run_id' => null,
        ], ScheduleRow::row($detail)['last_outcome']);
    }

    public function testNoRecordedFireOrSkipHasNoOutcome(): void
    {
        self::assertNull(ScheduleRow::lastOutcome([]));
        self::assertNull(ScheduleRow::lastOutcome(['recent_actions' => null, 'last_skip_reason' => 'buffer_full']));
        self::assertNull(ScheduleRow::lastOutcome(['recent_actions' => ['not-an-action', 7]]));
    }

    public function testInternalPrimaryKeyNeverLeaksIntoRowsOrDetail(): void
    {
        $source = [...$this->embeddedSource(), 'id' => self::INTERNAL_KEY, 'schedule_ulid' => self::INTERNAL_KEY];
        $serverDetail = [...$this->serverDetail(), 'id' => self::INTERNAL_KEY];

        foreach ([ScheduleRow::row($source), ScheduleRow::detail($source), ScheduleRow::detail($serverDetail)] as $payload) {
            self::assertSame($payload['schedule_id'], $payload['id']);
            self::assertStringNotContainsString(self::INTERNAL_KEY, (string) json_encode($payload));
        }
    }

    public function testMissingScheduleIdYieldsEmptyIdentifiersRatherThanAnotherKey(): void
    {
        $row = ScheduleRow::row(['id' => self::INTERNAL_KEY, 'status' => 'active']);

        self::assertSame('', $row['id']);
        self::assertSame('', $row['schedule_id']);
    }

    public function testCountersAcceptNumericStringsAndRejectJunk(): void
    {
        $row = ScheduleRow::row([
            'schedule_id' => 'counts',
            'fires_count' => '5',
            'failures_count' => 'many',
            'skipped_trigger_count' => 1.5,
            'remaining_actions' => null,
        ]);

        self::assertSame(5, $row['fires_count']);
        self::assertNull($row['failures_count']);
        self::assertNull($row['skipped_trigger_count']);
        self::assertNull($row['remaining_actions']);
    }

    /**
     * Mirrors V2SchedulesController::scheduleSource for a stored schedule.
     *
     * @return array<string, mixed>
     */
    private function embeddedSource(): array
    {
        return [
            'schedule_id' => 'nightly-invoices',
            'namespace' => 'billing',
            'spec' => [
                'cron_expressions' => ['0 2 * * *'],
                'intervals' => [['every' => 'PT6H', 'offset' => 'PT5M']],
                'timezone' => 'Europe/Berlin',
            ],
            'action' => ['workflow_type' => 'invoices.sync', 'workflow_class' => 'App\\Workflows\\SyncInvoices'],
            'status' => ScheduleStatus::Paused,
            'note' => 'month-end freeze',
            'overlap_policy' => 'skip',
            'next_fire_at' => CarbonImmutable::parse('2026-10-21T00:00:00Z'),
            'last_fired_at' => CarbonImmutable::parse('2026-10-20T00:00:01Z'),
            'recent_actions' => [
                ['outcome' => 'failed', 'reason' => 'Workflow class missing.', 'fired_at' => '2026-10-19T00:00:02+00:00'],
                [
                    'workflow_id' => 'schedule:nightly-invoices:1760918401000',
                    'run_id' => 'run-7',
                    'outcome' => 'scheduled',
                    'fired_at' => '2026-10-20T00:00:01+00:00',
                    'occurrence_time' => '2026-10-20T00:00:00.000000+00:00',
                    'fire_lag_ms' => 1000,
                ],
            ],
            'buffered_actions' => null,
            'fires_count' => 7,
            'failures_count' => 1,
            'skipped_trigger_count' => 2,
            'last_skip_reason' => 'overlap_policy_skip',
            'last_skipped_at' => CarbonImmutable::parse('2026-10-19T00:00:00Z'),
            'remaining_actions' => 3,
            'max_runs' => 10,
            'jitter_seconds' => 30,
            'latest_workflow_instance_id' => 'schedule:nightly-invoices:1760918401000',
            'memo' => ['owner' => 'finance'],
            'search_attributes' => ['tenant' => 'acme'],
            'paused_at' => CarbonImmutable::parse('2026-10-20T08:00:00Z'),
            'created_at' => CarbonImmutable::parse('2026-10-01T09:30:00Z'),
            'updated_at' => CarbonImmutable::parse('2026-10-20T00:00:01Z'),
        ];
    }

    /**
     * Shape of Server 2.9.8 ScheduleController::formatListItem.
     *
     * @return array<string, mixed>
     */
    private function serverListItem(): array
    {
        return [
            'schedule_id' => 'orders-hourly',
            'workflow_type' => 'orders.process',
            'status' => 'active',
            'paused' => false,
            'next_fire' => '2026-10-20T13:00:00+00:00',
            'last_fire' => '2026-10-20T12:00:00+00:00',
            'overlap_policy' => 'allow_all',
            'note' => null,
            'namespace' => 'orders',
            'spec' => ['cron_expressions' => ['0 * * * *'], 'timezone' => 'UTC'],
            'action' => ['workflow_type' => 'orders.process', 'task_queue' => 'orders'],
            'next_fire_at' => '2026-10-20T13:00:00+00:00',
            'last_fired_at' => '2026-10-20T12:00:00+00:00',
            'search_attributes' => ['region' => 'eu'],
            'created_at' => '2026-10-01T00:00:00+00:00',
            'updated_at' => '2026-10-20T12:00:00+00:00',
            'fires_count' => 12,
            'max_runs' => 20,
            'remaining_actions' => 8,
        ];
    }

    /**
     * Shape of Server 2.9.8 ScheduleController::formatDetail. The nested
     * state/info copies deliberately disagree with some top-level values so
     * precedence is observable.
     *
     * @return array<string, mixed>
     */
    private function serverDetail(): array
    {
        return [
            'schedule_id' => 'orders-hourly',
            'spec' => ['cron_expressions' => ['0 * * * *'], 'timezone' => 'UTC'],
            'action' => ['workflow_type' => 'orders.process', 'task_queue' => 'orders'],
            'overlap_policy' => 'buffer_one',
            'state' => ['status' => 'active', 'paused' => false, 'note' => 'state note'],
            'info' => [
                'next_fire' => '2026-10-20T13:00:00+00:00',
                'last_fire' => '2026-10-20T12:00:00+00:00',
                'fires_count' => 11,
                'failures_count' => 2,
                'recent_actions' => [
                    ['outcome' => 'failed', 'reason' => 'Server busy.', 'fired_at' => '2026-10-20T11:00:00+00:00'],
                    [
                        'workflow_id' => 'schedule:orders-hourly:1760961600000',
                        'run_id' => 'run-12',
                        'outcome' => 'scheduled',
                        'fired_at' => '2026-10-20T12:00:00+00:00',
                    ],
                ],
                'buffered_actions' => [['occurrence_time' => '2026-10-20T13:00:00+00:00']],
                'skipped_trigger_count' => 4,
                'last_skip_reason' => 'overlap_policy_skip',
                'last_skipped_at' => '2026-10-20T11:30:00+00:00',
            ],
            'memo' => ['owner' => 'ops'],
            'search_attributes' => null,
            'created_at' => '2026-10-01T00:00:00+00:00',
            'updated_at' => '2026-10-20T12:00:00+00:00',
            'namespace' => 'orders',
            'status' => 'paused',
            'paused' => true,
            'note' => 'top-level note',
            'fires_count' => 12,
            'failures_count' => 2,
            'next_fire_at' => '2026-10-20T13:00:00+00:00',
            'last_fired_at' => '2026-10-20T12:00:00+00:00',
            'paused_at' => '2026-10-20T09:00:00+00:00',
            'jitter_seconds' => 0,
            'max_runs' => 20,
            'remaining_actions' => 8,
            'latest_workflow_instance_id' => 'schedule:orders-hourly:1760961600000',
        ];
    }
}
