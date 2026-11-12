import assert from 'node:assert/strict';
import test from 'node:test';
import {
    replayBlockedAgeAvailable,
    replayBlockedCount,
    replayBlockedOldestAt,
    replayBlockedReported,
    replayBlockedRunsRoute,
} from '../../resources/js/replay-blocked-metrics.mjs';

test('a backend that does not report replay-blocked runs hides the metric instead of showing zero', () => {
    for (const metrics of [null, {}, { runs: {} }, { runs: { replay_blocked: null, repair_needed: 3 } }]) {
        assert.equal(replayBlockedReported(metrics), false);
        assert.equal(replayBlockedCount(metrics), null);
        assert.equal(replayBlockedAgeAvailable(metrics), false);
        assert.equal(replayBlockedOldestAt(metrics), null);
    }
});

test('reported replay-blocked runs show their count, oldest age and since time', () => {
    const metrics = { runs: {
        replay_blocked: 2,
        oldest_replay_blocked_at: '2026-11-12T01:00:00Z',
        max_replay_blocked_age_ms: 90000,
    } };

    assert.equal(replayBlockedReported(metrics), true);
    assert.equal(replayBlockedCount(metrics), 2);
    assert.equal(replayBlockedAgeAvailable(metrics), true);
    assert.equal(replayBlockedOldestAt(metrics), '2026-11-12T01:00:00Z');
});

test('a reported zero is shown as zero with no age line', () => {
    const metrics = { runs: { replay_blocked: 0, max_replay_blocked_age_ms: 0, oldest_replay_blocked_at: null } };

    assert.equal(replayBlockedReported(metrics), true);
    assert.equal(replayBlockedCount(metrics), 0);
    assert.equal(replayBlockedAgeAvailable(metrics), false);
});

test('the dashboard links to the run list filtered to the replay-blocked liveness state', () => {
    assert.deepEqual(replayBlockedRunsRoute(), {
        path: '/running',
        query: { liveness_state: 'workflow_replay_blocked' },
    });
});
