import assert from 'node:assert/strict';
import test from 'node:test';
import { projectionMetric, projectionMetricLabel, projectionRebuildTotal } from '../../resources/js/projection-metrics.mjs';

test('deferred history audits remain unknown rather than reporting zero', () => {
    const metrics = { projections: {
        run_summaries: { needs_rebuild: 2 },
        run_waits: { rows: 0, needs_rebuild: null },
        run_timeline_entries: { needs_rebuild: null },
        run_timer_entries: { needs_rebuild: null },
        run_lineage_entries: { needs_rebuild: null },
    } };

    assert.equal(projectionMetric(metrics, 'run_waits', 'needs_rebuild'), null);
    assert.equal(projectionMetricLabel(projectionMetric(metrics, 'run_waits', 'needs_rebuild')), 'Unknown');
    assert.equal(projectionMetricLabel(projectionMetric(metrics, 'run_waits', 'rows')), '0');
    assert.equal(projectionRebuildTotal(metrics), null);
});

test('a complete audit still reports its numeric total', () => {
    const metrics = { projections: Object.fromEntries([
        ['run_summaries', { needs_rebuild: 2, missing: 0 }],
        ['run_waits', { needs_rebuild: '3' }],
        ['run_timeline_entries', { needs_rebuild: 0 }],
        ['run_timer_entries', { needs_rebuild: 4 }],
        ['run_lineage_entries', { needs_rebuild: 1 }],
    ]) };

    assert.equal(projectionRebuildTotal(metrics), 10);
    assert.equal(projectionMetric(metrics, 'missing'), 0);
    assert.equal(projectionMetricLabel(projectionRebuildTotal(metrics)), '10');
});

test('missing or invalid backend counts cannot imply a clean audit', () => {
    assert.equal(projectionMetric(null, 'missing'), null);
    assert.equal(projectionRebuildTotal({ projections: {} }), null);
    for (const value of [null, undefined, '', ' ', 'not a count', false, -1]) {
        assert.equal(projectionMetric({ projections: { run_waits: { needs_rebuild: value } } }, 'run_waits', 'needs_rebuild'), null);
    }
});
