import assert from 'node:assert/strict';
import { spawnSync } from 'node:child_process';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import test from 'node:test';

import {
  taskQueuePrefixBindingEvidence,
  workerIdPrefixBindingEvidence,
  sharedServerReceiptFailures,
  workerStatusWorkerIds,
  workerStatusWorkflowIds,
  workflowIdPrefixBindingEvidence,
} from '../../scripts/conformance/worker-status-shared-topology.mjs';

const expected = {
  serverVersion: '0.2.700',
  serverImage: 'durableworkflow/server:0.2.700',
  namespace: 'heartbeat-wave',
  taskQueue: 'waterline-status-abc123',
};

function receipt({
  taskQueuePrefix = 'waterline-status-',
  workerIdPrefix = 'waterline-',
  workflowIdPrefix = 'waterline-worker-status-',
} = {}) {
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
        task_queue_prefix: taskQueuePrefix,
        workflow_id_prefix: workflowIdPrefix,
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

function executeSharedRunner(state, temporaryDirectoryPrefix) {
  const testRoot = fs.mkdtempSync(path.join(os.tmpdir(), temporaryDirectoryPrefix));
  const resultDir = path.join(testRoot, 'results');
  const fakeBin = path.join(testRoot, 'bin');
  const statePath = path.join(testRoot, 'shared-receipt.json');
  fs.mkdirSync(resultDir);
  fs.mkdirSync(fakeBin);

  try {
    for (const command of ['docker', 'curl']) {
      const commandPath = path.join(fakeBin, command);
      fs.writeFileSync(commandPath, '#!/bin/sh\nexit 99\n', 'utf8');
      fs.chmodSync(commandPath, 0o755);
    }
    fs.writeFileSync(statePath, `${JSON.stringify(state)}\n`, 'utf8');

    const runner = path.resolve(
      import.meta.dirname,
      '../../scripts/conformance/worker-status-published-artifacts.mjs',
    );
    const result = spawnSync(process.execPath, [runner], {
      env: {
        ...process.env,
        PATH: `${fakeBin}${path.delimiter}${process.env.PATH ?? ''}`,
        RESULT_DIR: resultDir,
        DW_SERVER_VERSION: expected.serverVersion,
        DW_SERVER_IMAGE: expected.serverImage,
        DW_CLI_VERSION: '0.1.86',
        DW_PHP_SDK_VERSION: '2.0.0-beta.13',
        DW_WORKFLOW_PHP_VERSION: '2.0.0-beta.13',
        DW_WATERLINE_VERSION: '2.0.0-beta.13',
        DW_WATERLINE_WORKER_STATUS_NAMESPACE: expected.namespace,
        DW_WATERLINE_WORKER_STATUS_SHARED_SERVER_STATE: statePath,
      },
      encoding: 'utf8',
    });
    const runResult = JSON.parse(fs.readFileSync(
      path.join(resultDir, 'waterline-worker-status-result.json'),
      'utf8',
    ));

    return { result, runResult };
  } finally {
    fs.rmSync(testRoot, { recursive: true, force: true });
  }
}

test('matching receipt prefix constructs and validates both Waterline worker IDs', () => {
  const state = receipt();
  const workerIds = workerStatusWorkerIds('1722222222222-ABCDEF0123456789');
  const workflowIds = workerStatusWorkflowIds('1722222222222-ABCDEF0123456789');

  assert.deepEqual(workerIds, {
    stale_worker_id: 'waterline-stale-abcdef0123456789',
    fresh_worker_id: 'waterline-fresh-abcdef0123456789',
  });
  assert.deepEqual(workflowIds, {
    initial_workflow_id: 'waterline-worker-status-initial-abcdef0123456789',
    after_stale_workflow_id: 'waterline-worker-status-after-stale-abcdef0123456789',
  });
  assert.deepEqual(
    sharedServerReceiptFailures(state, { ...expected, workerIds, workflowIds }),
    [],
  );
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
  assert.deepEqual(workflowIdPrefixBindingEvidence(state, workflowIds, {
    topology: workflowIds,
  }), {
    workflow_id_prefix: 'waterline-worker-status-',
    planned_initial_workflow_id: workflowIds.initial_workflow_id,
    planned_after_stale_workflow_id: workflowIds.after_stale_workflow_id,
    observed_initial_workflow_id: workflowIds.initial_workflow_id,
    observed_after_stale_workflow_id: workflowIds.after_stale_workflow_id,
    workflow_id_prefix_prevalidated: true,
    observed_workflow_ids_equal_plan: true,
    workflow_id_prefix_binding_proven: true,
  });
  assert.deepEqual(
    taskQueuePrefixBindingEvidence(state, expected.taskQueue, {
      topology: { task_queue: expected.taskQueue },
    }),
    {
      task_queue_prefix: 'waterline-status-',
      planned_task_queue: expected.taskQueue,
      observed_task_queue: expected.taskQueue,
      task_queue_prefix_prevalidated: true,
      observed_task_queue_equals_plan: true,
      task_queue_prefix_binding_proven: true,
    },
  );
});

test('mismatched receipt prefix is rejected before the beta.13 workers can run', () => {
  const workerIds = workerStatusWorkerIds('shared-cell-1234');
  const workflowIds = workerStatusWorkflowIds('shared-cell-1234');
  const state = receipt({ workerIdPrefix: 'different-cell-' });

  assert.deepEqual(sharedServerReceiptFailures(state, { ...expected, workerIds, workflowIds }), [
    'shared heartbeat server worker-ID prefix does not match the planned Waterline workers',
  ]);
});

test('missing, malformed, and partial workflow-ID prefixes fail preflight', () => {
  const workerIds = workerStatusWorkerIds('shared-cell-1234');
  const workflowIds = workerStatusWorkflowIds('shared-cell-1234');
  const cases = [
    undefined,
    '',
    ' waterline-worker-status-',
    'waterline-worker-status-\n',
    'waterline-worker-status-initial-',
    'different-cell-',
  ];

  for (const workflowIdPrefix of cases) {
    const state = receipt({ workflowIdPrefix });
    if (workflowIdPrefix === undefined) {
      delete state.cell_isolation.waterline.workflow_id_prefix;
    }

    assert.deepEqual(
      sharedServerReceiptFailures(state, { ...expected, workerIds, workflowIds }),
      ['shared heartbeat server workflow-ID prefix does not match the planned Waterline workflows'],
    );
  }
});

test('missing, empty, malformed, and non-covering task-queue prefixes fail preflight', () => {
  const workerIds = workerStatusWorkerIds('shared-cell-1234');
  const workflowIds = workerStatusWorkflowIds('shared-cell-1234');
  const cases = [
    undefined,
    null,
    42,
    '',
    ' waterline-status-',
    'waterline-status-\n',
    'waterline-status-abc123-extra',
    'different-cell-',
  ];

  for (const taskQueuePrefix of cases) {
    const state = receipt({ taskQueuePrefix });
    if (taskQueuePrefix === undefined) {
      delete state.cell_isolation.waterline.task_queue_prefix;
    }

    assert.deepEqual(
      sharedServerReceiptFailures(state, { ...expected, workerIds, workflowIds }),
      ['shared heartbeat server task-queue prefix does not match the planned Waterline task queue'],
    );
  }
});

test('mismatched workflow-ID prefix blocks the published command in the executable runner', () => {
  const { result, runResult } = executeSharedRunner(
    receipt({ workflowIdPrefix: 'different-cell-' }),
    'waterline-workflow-prefix-',
  );

  assert.equal(result.status, 1, result.stderr || result.stdout);
  assert.match(
    runResult.runner_error.message,
    /workflow-ID prefix does not match the planned Waterline workflows/,
  );
  assert.equal(runResult.runner_error.published_command_started, false);
  assert.equal(runResult.product_evidence, null);
  assert.equal(
    runResult.topology.isolation.workflow_id_prefix,
    'different-cell-',
  );
  assert.match(
    runResult.topology.isolation.planned_initial_workflow_id,
    /^waterline-worker-status-initial-/,
  );
  assert.match(
    runResult.topology.isolation.planned_after_stale_workflow_id,
    /^waterline-worker-status-after-stale-/,
  );
  assert.equal(runResult.topology.isolation.observed_initial_workflow_id, null);
  assert.equal(runResult.topology.isolation.observed_after_stale_workflow_id, null);
  assert.equal(runResult.topology.isolation.workflow_id_prefix_prevalidated, false);
  assert.equal(runResult.topology.isolation.observed_workflow_ids_equal_plan, null);
  assert.equal(runResult.topology.isolation.workflow_id_prefix_binding_proven, false);
  assert.deepEqual(runResult.cleanup.failures, []);
});

test('empty or mismatched task-queue prefix blocks the executable runner before product execution', () => {
  for (const taskQueuePrefix of ['', 'different-cell-']) {
    const { result, runResult } = executeSharedRunner(
      receipt({ taskQueuePrefix }),
      'waterline-task-queue-prefix-',
    );

    assert.equal(result.status, 1, result.stderr || result.stdout);
    assert.match(
      runResult.runner_error.message,
      /task-queue prefix does not match the planned Waterline task queue/,
    );
    assert.equal(runResult.runner_error.published_command_started, false);
    assert.equal(runResult.product_evidence, null);
    assert.equal(runResult.topology.namespace, expected.namespace);
    assert.equal(runResult.topology.isolation.prescribed_namespace, expected.namespace);
    assert.equal(runResult.topology.isolation.task_queue_prefix, taskQueuePrefix);
    assert.equal(
      runResult.topology.isolation.planned_task_queue,
      runResult.topology.task_queue,
    );
    assert.match(runResult.topology.isolation.planned_task_queue, /^waterline-status-/);
    assert.equal(runResult.topology.isolation.observed_task_queue, null);
    assert.equal(runResult.topology.isolation.task_queue_prefix_prevalidated, false);
    assert.equal(runResult.topology.isolation.observed_task_queue_equals_plan, null);
    assert.equal(runResult.topology.isolation.task_queue_prefix_binding_proven, false);
    assert.equal(runResult.topology.isolation.worker_id_prefix, 'waterline-');
    assert.equal(
      runResult.topology.isolation.workflow_id_prefix,
      'waterline-worker-status-',
    );
    assert.equal(
      runResult.artifact_sources.server,
      `docker://${expected.serverImage}`,
    );
    assert.deepEqual(runResult.cleanup.failures, []);
  }
});

test('shared receipt validation retains the topology, image, lifecycle, and cleanup guards', () => {
  const workerIds = workerStatusWorkerIds('shared-cell-1234');
  const workflowIds = workerStatusWorkflowIds('shared-cell-1234');
  const cases = [
    {
      mutate: (state) => { state.cell_isolation.waterline.namespace = 'another-namespace'; },
      failure: 'shared heartbeat server did not prescribe isolated Waterline cell identities',
    },
    {
      mutate: (state) => { state.cell_isolation.waterline.task_queue_prefix = 'another-queue-'; },
      failure: 'shared heartbeat server task-queue prefix does not match the planned Waterline task queue',
    },
    {
      mutate: (state) => { state.cell_isolation.waterline.worker_id_prefix = 'another-worker-'; },
      failure: 'shared heartbeat server worker-ID prefix does not match the planned Waterline workers',
    },
    {
      mutate: (state) => { state.cell_isolation.waterline.workflow_id_prefix = 'another-workflow-'; },
      failure: 'shared heartbeat server workflow-ID prefix does not match the planned Waterline workflows',
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
      mutate: (state) => { state.lifecycle.cleanup_required = false; },
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
      sharedServerReceiptFailures(
        state,
        { ...expected, workerIds, workflowIds },
      ).includes(scenario.failure),
      true,
    );
  }
});
