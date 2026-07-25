import assert from 'node:assert/strict';
import test from 'node:test';

import {
  workerIdPrefixBindingEvidence,
  sharedServerReceiptFailures,
  workerStatusWorkerIds,
} from '../../scripts/conformance/worker-status-shared-topology.mjs';

const expected = {
  serverVersion: '0.2.700',
  serverImage: 'durableworkflow/server:0.2.700',
  namespace: 'heartbeat-wave',
  taskQueue: 'waterline-status-abc123',
};

function receipt(workerIdPrefix = 'waterline-') {
  return {
    schema: 'durable-workflow.v2.heartbeat-runtime.shared-server-bootstrap',
    version: 1,
    server: {
      version: expected.serverVersion,
      requested_reference: expected.serverImage,
      exact_published_image_verified: true,
    },
    clean_bootstrap: {
      status: 'pass',
      migrations_completed: true,
      fresh_compose_project: true,
    },
    lifecycle: {
      owner: 'heartbeat-wave-runner',
      cleanup_required: true,
      cleanup_status: 'pending',
    },
    cell_isolation: {
      waterline: {
        namespace: expected.namespace,
        task_queue_prefix: 'waterline-status-',
        worker_id_prefix: workerIdPrefix,
      },
    },
    endpoint: {
      host_url: 'http://127.0.0.1:43123',
      container_url: 'http://server:8080',
    },
    compose: {
      network: 'heartbeat-wave_default',
    },
  };
}

test('matching receipt prefix constructs and validates both Waterline worker IDs', () => {
  const state = receipt();
  const workerIds = workerStatusWorkerIds('1722222222222-ABCDEF0123456789');

  assert.deepEqual(workerIds, {
    stale_worker_id: 'waterline-stale-abcdef0123456789',
    fresh_worker_id: 'waterline-fresh-abcdef0123456789',
  });
  assert.deepEqual(sharedServerReceiptFailures(state, { ...expected, workerIds }), []);
  assert.deepEqual(workerIdPrefixBindingEvidence(state, workerIds, {
    topology: {
      stale_worker_id: workerIds.stale_worker_id,
      fresh_worker_id: workerIds.fresh_worker_id,
    },
  }), {
    worker_id_prefix: 'waterline-',
    actual_stale_worker_id: 'waterline-stale-abcdef0123456789',
    actual_fresh_worker_id: 'waterline-fresh-abcdef0123456789',
    worker_id_prefix_binding_proven: true,
  });
});

test('mismatched receipt prefix is rejected before the beta.12 workers can run', () => {
  const workerIds = workerStatusWorkerIds('shared-cell-1234');
  const state = receipt('different-cell-');

  assert.deepEqual(sharedServerReceiptFailures(state, { ...expected, workerIds }), [
    'shared heartbeat server worker-ID prefix does not match the planned Waterline workers',
  ]);
});

test('shared receipt validation retains the topology, image, lifecycle, and cleanup guards', () => {
  const workerIds = workerStatusWorkerIds('shared-cell-1234');
  const cases = [
    {
      mutate: (state) => { state.cell_isolation.waterline.namespace = 'another-namespace'; },
      failure: 'shared heartbeat server did not prescribe isolated Waterline cell identities',
    },
    {
      mutate: (state) => { state.cell_isolation.waterline.task_queue_prefix = 'another-queue-'; },
      failure: 'shared heartbeat server did not prescribe isolated Waterline cell identities',
    },
    {
      mutate: (state) => { state.server.requested_reference = 'durableworkflow/server:0.2.699'; },
      failure: 'shared heartbeat server state does not bind the selected exact server image',
    },
    {
      mutate: (state) => { state.clean_bootstrap.migrations_completed = false; },
      failure: 'shared heartbeat server state does not prove clean bootstrap and migrations',
    },
    {
      mutate: (state) => { state.lifecycle.owner = 'waterline-focused-cell'; },
      failure: 'shared heartbeat server lifecycle is not owned by an active wave',
    },
    {
      mutate: (state) => { state.lifecycle.cleanup_status = 'completed'; },
      failure: 'shared heartbeat server lifecycle is not owned by an active wave',
    },
  ];

  for (const scenario of cases) {
    const state = receipt();
    scenario.mutate(state);
    assert.equal(
      sharedServerReceiptFailures(state, { ...expected, workerIds }).includes(scenario.failure),
      true,
    );
  }
});
