<?php

namespace Waterline\Tests\Unit\Support;

use Carbon\CarbonImmutable;
use Waterline\Support\RunDiagnostics;
use Waterline\Tests\TestCase;
use Workflow\V2\Models\WorkflowRun;

final class ConditionWaitDiagnosticsTest extends TestCase
{
    protected function requiresDatabaseMigrations(): bool
    {
        return false;
    }

    public function testKnownFutureDeadlineDoesNotProduceAnElapsedAgeWarning(): void
    {
        $this->assertSame([], $this->diagnostics('2026-10-07T15:00:00Z'));
    }

    public function testOldWaitWithoutResumeTimingIsInformationRatherThanEvidenceOfAStall(): void
    {
        $diagnostic = $this->diagnostics(null)[0];
        $this->assertSame('info', $diagnostic['severity']);
        $this->assertFalse($diagnostic['evidence']['resume_time_known']);
        $this->assertNull($diagnostic['evidence']['deadline_at']);
    }

    public function testPassedRecordedDeadlineRetainsAnActionableWarning(): void
    {
        $diagnostic = $this->diagnostics('2026-10-06T14:00:00Z')[0];
        $this->assertSame('warning', $diagnostic['severity']);
        $this->assertTrue($diagnostic['evidence']['resume_time_known']);
        $this->assertSame('2026-10-06T14:00:00+00:00', $diagnostic['evidence']['deadline_at']);
    }

    public function testMalformedDeadlineRemainsUnknownWithoutDiscardingOtherDiagnostics(): void
    {
        $diagnostic = $this->diagnostics('unavailable')[0];
        $this->assertSame('info', $diagnostic['severity']);
        $this->assertFalse($diagnostic['evidence']['resume_time_known']);
    }

    private function diagnostics(?string $deadline): array
    {
        $run = new WorkflowRun();
        foreach (['activityExecutions', 'failures', 'historyEvents', 'tasks'] as $relation) {
            $run->setRelation($relation, collect());
        }

        return (new RunDiagnostics())->forRun($run, [
            'waits' => [[
                'id' => 'condition:approval', 'kind' => 'condition', 'status' => 'open',
                'opened_at' => '2026-10-06T08:00:00Z', 'deadline_at' => $deadline,
                'target_name' => 'approval.ready',
            ]],
        ], CarbonImmutable::parse('2026-10-06T15:00:00Z'));
    }
}
