<?php

declare(strict_types=1);

namespace Waterline\Tests\Unit\Support;

use PHPUnit\Framework\TestCase;
use Waterline\Support\ScheduleDispatchSummary;

final class ScheduleDispatchSummaryTest extends TestCase
{
    private const ID = '01K9ZZEJF50000000000000000';

    public function testCompleteKnownAndNeverObservationsKeepTheirAuthority(): void
    {
        $known = $this->known();
        self::assertSame($known, ScheduleDispatchSummary::read($known));

        $never = ['version' => 1, 'state' => 'never', 'last_outcome' => null, 'buffered_actions_count' => 0];
        self::assertSame($never, ScheduleDispatchSummary::read($never));

        foreach (['buffered', 'skipped', 'failed'] as $kind) {
            $value = $known;
            $value['last_outcome']['outcome'] = $kind;
            $value['last_outcome']['workflow_instance_id'] = null;
            $value['last_outcome']['workflow_run_id'] = null;
            $value['last_outcome']['reason'] = $kind === 'buffered' ? null : 'overlap_policy_skip';
            self::assertSame($value, ScheduleDispatchSummary::read($value));
        }
    }

    public function testMissingRequiredNullableFieldsNeverGainAuthority(): void
    {
        $known = $this->known();
        foreach (array_keys($known) as $field) {
            $value = $known;
            unset($value[$field]);
            $this->assertUnavailable($value);
        }
        foreach (array_keys($known['last_outcome']) as $field) {
            $value = $known;
            unset($value['last_outcome'][$field]);
            $this->assertUnavailable($value);
        }
    }

    public function testMalformedUnsupportedAndContradictoryObservationsStayUnavailable(): void
    {
        foreach ([null, false, '', 'not JSON', [], ['version' => 1]] as $value) {
            $this->assertUnavailable($value);
        }

        foreach ([
            ['version', 2], ['version', '1'], ['version', true], ['state', 'future'],
            ['state', 'never'], ['state', 'unavailable'], ['last_outcome', null],
            ['last_outcome', []], ['buffered_actions_count', null], ['buffered_actions_count', -1],
            ['buffered_actions_count', 1.0], ['buffered_actions_count', '1'],
            ['buffered_actions_count', true], ['buffered_actions_count', 9007199254740992],
            ['extra', 'unsupported'],
        ] as [$field, $replacement]) {
            $value = $this->known();
            $value[$field] = $replacement;
            $this->assertUnavailable($value);
        }

        foreach (['buffered', 'skipped', 'failed'] as $kind) {
            $value = $this->known();
            $value['last_outcome']['outcome'] = $kind;
            $this->assertUnavailable($value); // These outcomes cannot claim a started run.
            $value['last_outcome']['workflow_instance_id'] = null;
            $value['last_outcome']['workflow_run_id'] = null;
            $value['last_outcome']['reason'] = $kind === 'buffered' ? 'unexpected' : null;
            $this->assertUnavailable($value);
        }

        foreach ([
            ['id', '81K9ZZEJF50000000000000000'], ['id', strtolower(self::ID)],
            ['id', str_repeat('I', 26)], ['id', null], ['recorded_at', '2026-11-13T10:00:00Z'],
            ['recorded_at', '2026-02-30T10:00:00.000000Z'], ['recorded_at', '2026-11-13T10:00:60.000000Z'],
            ['recorded_at', '2026-11-13T10:00:00.000000+00:00'],
            ['outcome', 'future'], ['reason', 'unexpected'], ['workflow_instance_id', null],
            ['workflow_run_id', 'run'], ['extra', 'unsupported'],
        ] as [$field, $replacement]) {
            $value = $this->known();
            $value['last_outcome'][$field] = $replacement;
            $this->assertUnavailable($value);
        }
    }

    public function testSafeCountsAndLiteralReasonsArePreservedWithoutTruncation(): void
    {
        foreach ([0, 9007199254740991] as $count) {
            $value = $this->known();
            $value['buffered_actions_count'] = $count;
            self::assertSame($value, ScheduleDispatchSummary::read($value));
        }

        foreach (['', '  ', "\0 reason \0", str_repeat('🙂', 64), str_repeat("e\u{0301}", 32)] as $reason) {
            $value = $this->known();
            $value['last_outcome'] = [
                ...$value['last_outcome'],
                'outcome' => 'skipped', 'reason' => $reason,
                'workflow_instance_id' => null, 'workflow_run_id' => null,
            ];
            self::assertSame($value, ScheduleDispatchSummary::read($value));
        }

        foreach ([str_repeat('x', 65), str_repeat('🙂', 65), str_repeat("e\u{0301}", 33), "\xff"] as $reason) {
            $value = $this->known();
            $value['last_outcome'] = [
                ...$value['last_outcome'],
                'outcome' => 'failed', 'reason' => $reason,
                'workflow_instance_id' => null, 'workflow_run_id' => null,
            ];
            $this->assertUnavailable($value);
        }
    }

    private function known(): array
    {
        return [
            'version' => 1,
            'state' => 'known',
            'last_outcome' => [
                'id' => self::ID, 'outcome' => 'started',
                'recorded_at' => '2026-11-13T10:00:00.000001Z',
                'reason' => null, 'workflow_instance_id' => self::ID, 'workflow_run_id' => self::ID,
            ],
            'buffered_actions_count' => 2,
        ];
    }

    private function assertUnavailable(mixed $value): void
    {
        self::assertSame(
            ['version' => 1, 'state' => 'unavailable', 'last_outcome' => null, 'buffered_actions_count' => null],
            ScheduleDispatchSummary::read($value),
        );
    }
}
