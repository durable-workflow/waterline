<?php

namespace Waterline\Tests\Unit\Support;

use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;
use Waterline\Support\RunWaitSummary;

final class RunWaitSummaryTest extends TestCase
{
    public function testFutureRetryUsesItsDurableTaskTimingAndAttemptPolicy(): void
    {
        $detail = RunWaitSummary::annotate([
            'waits' => [[
                'id' => 'activity:a', 'kind' => 'activity', 'status' => 'open',
                'task_id' => 'retry', 'resume_source_id' => 'a', 'target_type' => 'Import',
                'opened_at' => '2026-10-06T08:00:00Z',
            ]],
            'tasks' => [[
                'id' => 'retry', 'status' => 'ready', 'available_at' => '2026-10-06T18:00:00Z',
                'retry_after_attempt' => 2, 'retry_max_attempts' => 5,
                'summary' => 'Retry Import after attempt 2.',
            ]],
        ], CarbonImmutable::parse('2026-10-06T15:00:00Z'));

        $wait = $detail['current_waits'][0];
        self::assertSame('activity_retry', $wait['kind']);
        self::assertSame('planned', $wait['state']);
        self::assertSame('2026-10-06T18:00:00+00:00', $wait['next_scheduled_resume_at']);
        self::assertSame(3, $wait['attempt_number']);
        self::assertSame(5, $wait['attempt_limit']);
        self::assertSame('a', $wait['dependency_id']);
    }

    public function testOldExternalWaitWithoutResumeTimeDoesNotBecomeOverdue(): void
    {
        $detail = RunWaitSummary::annotate([
            'waits' => [[
                'id' => 'signal:approval', 'kind' => 'signal', 'status' => 'open',
                'opened_at' => '2020-01-01T00:00:00Z', 'target_name' => 'approval',
            ]],
        ], CarbonImmutable::parse('2026-10-06T15:00:00Z'));

        self::assertSame('resume_time_unknown', $detail['current_waits'][0]['state']);
        self::assertNull($detail['current_waits'][0]['next_scheduled_resume_at']);
        self::assertNull($detail['current_waits'][0]['deadline_at']);
    }

    public function testEligibleRetryDoesNotInventAnOverdueDeadline(): void
    {
        $detail = RunWaitSummary::annotate([
            'waits' => [['id' => 'activity:a', 'kind' => 'activity', 'status' => 'open', 'task_id' => 'retry']],
            'tasks' => [['id' => 'retry', 'status' => 'ready', 'available_at' => '2026-10-06T14:59:00Z', 'retry_after_attempt' => 1]],
        ], CarbonImmutable::parse('2026-10-06T15:00:00Z'));

        self::assertSame('eligible', $detail['current_waits'][0]['state']);
        self::assertNull($detail['current_waits'][0]['deadline_at']);
    }

    public function testTimerDeadlineIsAResumeBoundaryAndUnsupportedHistoryIsExplicit(): void
    {
        $detail = RunWaitSummary::annotate([
            'waits' => [
                ['id' => 'timer:a', 'kind' => 'timer', 'status' => 'open', 'deadline_at' => '2026-10-06T15:01:00Z'],
                ['id' => 'timer:b', 'kind' => 'timer', 'status' => 'open', 'deadline_at' => '2026-10-06T14:00:00Z'],
                ['id' => 'activity:c', 'kind' => 'activity', 'status' => 'open', 'diagnostic_only' => true,
                    'history_unsupported_reason' => 'typed_history_unavailable', 'deadline_at' => '2026-10-06T14:00:00Z'],
            ],
        ], CarbonImmutable::parse('2026-10-06T15:00:00Z'));

        self::assertSame('planned', $detail['current_waits'][0]['state']);
        self::assertSame('eligible', $detail['current_waits'][1]['state']);
        self::assertNull($detail['current_waits'][1]['deadline_at']);
        self::assertSame('unavailable', $detail['current_waits'][2]['state']);
        self::assertSame('typed_history_unavailable', $detail['current_waits'][2]['unavailable_reason']);
        self::assertNull($detail['current_waits'][2]['deadline_at']);
    }

    public function testSummaryIsBoundedAndExcludesResolvedWaits(): void
    {
        $waits = array_fill(0, 55, ['kind' => 'signal', 'status' => 'open']);
        $waits[] = ['kind' => 'activity', 'status' => 'resolved'];
        $detail = RunWaitSummary::annotate(['waits' => $waits]);

        self::assertCount(50, $detail['current_waits']);
        self::assertSame(55, $detail['current_waits_count']);
        self::assertTrue($detail['current_waits_truncated']);
        self::assertSame(50, $detail['current_waits_limit']);
    }

    public function testPrunedAndUnavailableDetailsRemainDistinct(): void
    {
        self::assertSame('unavailable', RunWaitSummary::annotate([])['current_waits_state']);
        self::assertSame('available', RunWaitSummary::annotate(['waits' => []])['current_waits_state']);
        self::assertSame('pruned', RunWaitSummary::annotate([
            'waits' => [], 'details_pruned_at' => '2026-10-01T00:00:00Z',
        ])['current_waits_state']);
    }
}
