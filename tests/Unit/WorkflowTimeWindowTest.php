<?php

declare(strict_types=1);

namespace Waterline\Tests\Unit;

use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Waterline\Support\WorkflowTimeWindow;

final class WorkflowTimeWindowTest extends TestCase
{
    public function testRollingDurationIsPreservedWhileEachEvaluationMovesItsBounds(): void
    {
        $filters = ['started_time' => 'rolling', 'started_within_seconds' => '86400'];
        $saved = WorkflowTimeWindow::normalize($filters);

        $this->assertSame(['started_time' => 'rolling', 'started_within_seconds' => 86400], $saved);
        $first = WorkflowTimeWindow::evaluate($saved, new DateTimeImmutable('2026-01-10T12:00:00.123456Z'));
        $second = WorkflowTimeWindow::evaluate($saved, new DateTimeImmutable('2026-01-12T12:00:00.123456Z'));

        $this->assertSame('2026-01-09T12:00:00.123456Z', $first['from']);
        $this->assertSame('2026-01-10T12:00:00.123456Z', $first['before']);
        $this->assertSame('2026-01-11T12:00:00.123456Z', $second['from']);
        $this->assertSame('2026-01-12T12:00:00.123456Z', $second['before']);
        $this->assertSame($saved, WorkflowTimeWindow::normalize($saved));
    }

    public function testRollingWindowsUseElapsedUtcTimeAcrossADaylightSavingTransition(): void
    {
        $window = WorkflowTimeWindow::evaluate(
            ['started_time' => 'rolling', 'started_within_seconds' => 86400],
            new DateTimeImmutable('2026-03-08T03:30:00-04:00'),
        );

        $this->assertSame('2026-03-07T07:30:00.000000Z', $window['from']);
        $this->assertSame('2026-03-08T07:30:00.000000Z', $window['before']);
        $this->assertSame('started_at', $window['timestamp']);
    }

    public function testFixedOffsetBoundsAreCanonicalAndDoNotMoveWithTheClock(): void
    {
        $saved = WorkflowTimeWindow::normalize([
            'started_time' => 'fixed',
            'started_from' => '2026-01-10T10:15:30+02:00',
            'started_before' => '2026-01-11T10:15:30.123+02:00',
        ]);
        $first = WorkflowTimeWindow::evaluate($saved, new DateTimeImmutable('2026-01-15T00:00:00Z'));
        $second = WorkflowTimeWindow::evaluate($saved, new DateTimeImmutable('2026-02-01T00:00:00Z'));

        $this->assertSame('2026-01-10T08:15:30.000000Z', $saved['started_from']);
        $this->assertSame('2026-01-11T08:15:30.123000Z', $saved['started_before']);
        $this->assertSame($first['from'], $second['from']);
        $this->assertSame($first['before'], $second['before']);
    }

    public function testAnyTimeIsAnExplicitOverrideButHasNoEvaluatedBounds(): void
    {
        $this->assertSame([], WorkflowTimeWindow::normalize(['workflow_type' => 'orders']));
        $this->assertSame(['started_time' => 'any'], WorkflowTimeWindow::normalize(['started_time' => 'any']));
        $this->assertNull(WorkflowTimeWindow::evaluate([], new DateTimeImmutable()));
        $this->assertNull(WorkflowTimeWindow::evaluate(['started_time' => 'any'], new DateTimeImmutable()));
    }

    public function testInvalidModesDurationsAndFixedBoundsAreRefused(): void
    {
        $fixed = ['started_time' => 'fixed', 'started_from' => '2026-01-10T00:00:00Z', 'started_before' => '2026-01-11T00:00:00Z'];
        $cases = [
            ['started_time' => 'last_week'],
            ['started_time' => []],
            ['started_within_seconds' => 86400],
            ['started_time' => 'rolling'],
            ['started_time' => 'rolling', 'started_within_seconds' => 0],
            ['started_time' => 'rolling', 'started_within_seconds' => -1],
            ['started_time' => 'rolling', 'started_within_seconds' => 1.5],
            ['started_time' => 'rolling', 'started_within_seconds' => true],
            ['started_time' => 'rolling', 'started_within_seconds' => '1e4'],
            ['started_time' => 'rolling', 'started_within_seconds' => '999999999999999999999999'],
            ['started_time' => 'rolling', 'started_within_seconds' => WorkflowTimeWindow::MAX_SECONDS + 1],
            ['started_time' => 'rolling', 'started_within_seconds' => 60, 'started_from' => '2026-01-10T00:00:00Z'],
            ['started_time' => 'any', 'started_within_seconds' => 60],
            ['started_time' => 'fixed', 'started_from' => '2026-01-10T00:00:00Z'],
            [...$fixed, 'started_within_seconds' => 60],
            [...$fixed, 'started_from' => '2026-02-29T00:00:00Z'],
            [...$fixed, 'started_from' => '2026-01-10T25:00:00Z'],
            [...$fixed, 'started_from' => '2026-01-10T00:00:00'],
            [...$fixed, 'started_from' => 'now'],
            [...$fixed, 'started_from' => '0001-01-01T00:00:00Z'],
            [...$fixed, 'started_before' => '2026-01-10T00:00:00Z'],
            [...$fixed, 'started_before' => '2026-01-09T00:00:00Z'],
        ];

        foreach ($cases as $filters) {
            try {
                WorkflowTimeWindow::normalize($filters);
                $this->fail('An invalid time-window descriptor was accepted: '.json_encode($filters));
            } catch (InvalidArgumentException $exception) {
                $this->assertNotSame('', $exception->getMessage());
            }
        }
    }
}
