import test from 'node:test';
import assert from 'node:assert/strict';
import { reportedTaskAttentionMetrics, workflowTaskAttention } from '../../resources/js/task-attention.mjs';

test('run badges require the supported reason and a count at the threshold', () => {
    const recorded = {
        reason: 'workflow_task_lease_lost',
        consecutive: 3,
        threshold: 3,
        workflow_task_id: 'task-1',
        last_lease_lost_at: '2026-11-21T10:00:00Z',
    };

    assert.equal(workflowTaskAttention({ task_attention: recorded }), recorded);
    assert.equal(workflowTaskAttention({ task_attention: { ...recorded, consecutive: 2 } }), null);
    assert.equal(workflowTaskAttention({ task_attention: { ...recorded, reason: 'other' } }), null);
    assert.equal(workflowTaskAttention({}), null);
});

test('the dashboard displays reported zero and leaves absent metrics hidden', () => {
    assert.deepEqual(reportedTaskAttentionMetrics({ runs: {}, tasks: {} }), []);
    assert.deepEqual(reportedTaskAttentionMetrics({
        runs: { task_attention: 1 },
        tasks: { lease_lost_repeatedly: 0, max_lost_lease_count: 3 },
    }).map(({ key, value }) => [key, value]), [['runs', 1], ['tasks', 0], ['max', 3]]);
});
