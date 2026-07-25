import assert from 'node:assert/strict';
import test from 'node:test';

import {
  verifySharedWaterlineIsolation,
} from '../../scripts/conformance/worker-status-shared-isolation.mjs';

function state() {
  return {
    cell_isolation: {
      waterline: {
        namespace: 'hb-wave-example-waterline',
        task_queue_prefix: 'waterline-status-',
        workflow_id_prefix: 'waterline-worker-status-',
        worker_id_prefix: 'waterline-',
      },
    },
  };
}

function evidence() {
  return {
    topology: {
      namespace: 'hb-wave-example-waterline',
      task_queue: 'waterline-status-cell',
      stale_worker_id: 'waterline-stale-cell',
      fresh_worker_id: 'waterline-fresh-cell',
      initial_workflow_id: 'waterline-worker-status-initial-cell',
      after_stale_workflow_id: 'waterline-worker-status-after-stale-cell',
    },
  };
}

test('actual shared-wave identities satisfy every prescribed receipt boundary', () => {
  const verification = verifySharedWaterlineIsolation(state(), evidence());

  assert.equal(verification.passed, true);
  assert.equal(verification.observed.stale_worker_id, 'waterline-stale-cell');
  assert.equal(verification.observed.fresh_worker_id, 'waterline-fresh-cell');
  assert.deepEqual(
    Object.values(verification.checks),
    [true, true, true, true, true, true],
  );
});

test('a mismatched actual worker identity fails the shared-wave receipt', () => {
  const observed = evidence();
  observed.topology.fresh_worker_id = 'another-cell-fresh';

  const verification = verifySharedWaterlineIsolation(state(), observed);

  assert.equal(verification.passed, false);
  assert.equal(verification.checks.stale_worker_matches_prefix, true);
  assert.equal(verification.checks.fresh_worker_matches_prefix, false);
});

test('missing actual worker identities cannot pass from prescribed values alone', () => {
  const observed = evidence();
  delete observed.topology.stale_worker_id;
  delete observed.topology.fresh_worker_id;

  const verification = verifySharedWaterlineIsolation(state(), observed);

  assert.equal(verification.passed, false);
  assert.equal(verification.observed.stale_worker_id, '');
  assert.equal(verification.observed.fresh_worker_id, '');
});
