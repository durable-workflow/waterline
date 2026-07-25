function runIdentitySuffix(runId) {
  const normalizedRunId = String(runId).replace(/[^a-zA-Z0-9]/g, '') || 'run';
  return normalizedRunId.slice(-16).toLowerCase();
}

function plannedIdsMatchPrefix(prefix, plannedIds) {
  return typeof prefix === 'string'
    && prefix !== ''
    && prefix === prefix.trim()
    && !/[\u0000-\u001f\u007f]/.test(prefix)
    && plannedIds.every((plannedId) =>
      typeof plannedId === 'string' && plannedId.startsWith(prefix));
}

export function workerStatusWorkerIds(runId) {
  const suffix = runIdentitySuffix(runId);
  return {
    stale_worker_id: `waterline-stale-${suffix}`,
    fresh_worker_id: `waterline-fresh-${suffix}`,
  };
}

export function workerStatusWorkflowIds(runId) {
  const suffix = runIdentitySuffix(runId);
  return {
    initial_workflow_id: `waterline-worker-status-initial-${suffix}`,
    after_stale_workflow_id: `waterline-worker-status-after-stale-${suffix}`,
  };
}

export function taskQueuePrefixBindingEvidence(state, plannedTaskQueue, productEvidence) {
  const prescribedPrefix = state?.cell_isolation?.waterline?.task_queue_prefix ?? null;
  const observedTaskQueue = productEvidence?.topology?.task_queue ?? null;
  const prefixPrevalidated = state === null
    ? null
    : plannedIdsMatchPrefix(prescribedPrefix, [plannedTaskQueue]);
  const observedTaskQueueEqualsPlan = productEvidence === null
    ? null
    : observedTaskQueue === plannedTaskQueue;

  return {
    task_queue_prefix: prescribedPrefix,
    planned_task_queue: plannedTaskQueue,
    observed_task_queue: observedTaskQueue,
    task_queue_prefix_prevalidated: prefixPrevalidated,
    observed_task_queue_equals_plan: observedTaskQueueEqualsPlan,
    task_queue_prefix_binding_proven: prefixPrevalidated === null
      ? null
      : prefixPrevalidated && observedTaskQueueEqualsPlan === true,
  };
}

export function workerIdPrefixBindingEvidence(state, workerIds, productEvidence) {
  const prescribedPrefix = state?.cell_isolation?.waterline?.worker_id_prefix ?? null;
  const actualStaleWorkerId = productEvidence?.topology?.stale_worker_id ?? null;
  const actualFreshWorkerId = productEvidence?.topology?.fresh_worker_id ?? null;

  return {
    worker_id_prefix: prescribedPrefix,
    actual_stale_worker_id: actualStaleWorkerId,
    actual_fresh_worker_id: actualFreshWorkerId,
    worker_id_prefix_binding_proven: state === null
      ? null
      : typeof prescribedPrefix === 'string'
        && actualStaleWorkerId === workerIds.stale_worker_id
        && actualFreshWorkerId === workerIds.fresh_worker_id
        && actualStaleWorkerId.startsWith(prescribedPrefix)
        && actualFreshWorkerId.startsWith(prescribedPrefix),
  };
}

export function workflowIdPrefixBindingEvidence(state, workflowIds, productEvidence) {
  const prescribedPrefix = state?.cell_isolation?.waterline?.workflow_id_prefix ?? null;
  const plannedInitialWorkflowId = workflowIds.initial_workflow_id;
  const plannedAfterStaleWorkflowId = workflowIds.after_stale_workflow_id;
  const observedInitialWorkflowId = productEvidence?.topology?.initial_workflow_id ?? null;
  const observedAfterStaleWorkflowId =
    productEvidence?.topology?.after_stale_workflow_id ?? null;
  const prefixPrevalidated = state === null
    ? null
    : plannedIdsMatchPrefix(prescribedPrefix, [
      plannedInitialWorkflowId,
      plannedAfterStaleWorkflowId,
    ]);
  const observedIdsEqualPlan = productEvidence === null
    ? null
    : observedInitialWorkflowId === plannedInitialWorkflowId
      && observedAfterStaleWorkflowId === plannedAfterStaleWorkflowId;

  return {
    workflow_id_prefix: prescribedPrefix,
    planned_initial_workflow_id: plannedInitialWorkflowId,
    planned_after_stale_workflow_id: plannedAfterStaleWorkflowId,
    observed_initial_workflow_id: observedInitialWorkflowId,
    observed_after_stale_workflow_id: observedAfterStaleWorkflowId,
    workflow_id_prefix_prevalidated: prefixPrevalidated,
    observed_workflow_ids_equal_plan: observedIdsEqualPlan,
    workflow_id_prefix_binding_proven: prefixPrevalidated === null
      ? null
      : prefixPrevalidated && observedIdsEqualPlan === true,
  };
}

export function sharedServerReceiptFailures(state, expected) {
  const isolation = state?.cell_isolation?.waterline;
  const failures = [];

  if (state?.schema !== 'durable-workflow.v2.heartbeat-runtime.shared-server-bootstrap'
    || state?.version !== 1) {
    failures.push('shared heartbeat server state has an unsupported schema');
  }
  if (state?.server?.version !== expected.serverVersion
    || state?.server?.requested_reference !== expected.serverImage
    || state?.server?.exact_published_image_verified !== true) {
    failures.push('shared heartbeat server state does not bind the selected exact server image');
  }
  if (state?.clean_bootstrap?.status !== 'pass'
    || state?.clean_bootstrap?.migrations_completed !== true
    || state?.clean_bootstrap?.fresh_compose_project !== true) {
    failures.push('shared heartbeat server state does not prove clean bootstrap and migrations');
  }
  if (state?.lifecycle?.owner !== 'heartbeat-wave-runner'
    || state?.lifecycle?.cleanup_required !== true
    || state?.lifecycle?.cleanup_status !== 'pending') {
    failures.push('shared heartbeat server lifecycle is not owned by an active wave');
  }
  if (!isolation || isolation.namespace !== expected.namespace) {
    failures.push('shared heartbeat server did not prescribe isolated Waterline cell identities');
  }

  const prescribedTaskQueuePrefix = isolation?.task_queue_prefix;
  if (!plannedIdsMatchPrefix(prescribedTaskQueuePrefix, [expected.taskQueue])) {
    failures.push('shared heartbeat server task-queue prefix does not match the planned Waterline task queue');
  }

  const prescribedPrefix = isolation?.worker_id_prefix;
  const plannedWorkerIds = [
    expected.workerIds?.stale_worker_id,
    expected.workerIds?.fresh_worker_id,
  ];
  if (!plannedIdsMatchPrefix(prescribedPrefix, plannedWorkerIds)) {
    failures.push('shared heartbeat server worker-ID prefix does not match the planned Waterline workers');
  }

  const prescribedWorkflowPrefix = isolation?.workflow_id_prefix;
  const plannedWorkflowIds = [
    expected.workflowIds?.initial_workflow_id,
    expected.workflowIds?.after_stale_workflow_id,
  ];
  if (!plannedIdsMatchPrefix(prescribedWorkflowPrefix, plannedWorkflowIds)) {
    failures.push('shared heartbeat server workflow-ID prefix does not match the planned Waterline workflows');
  }

  try {
    const parsed = new URL(state?.endpoint?.host_url ?? '');
    if (parsed.protocol !== 'http:'
      || !['127.0.0.1', 'localhost'].includes(parsed.hostname)
      || parsed.pathname !== '/') {
      failures.push('shared heartbeat server endpoint must be a loopback HTTP origin');
    }
  } catch {
    failures.push('shared heartbeat server endpoint is invalid');
  }
  if (!state?.compose?.network || state?.endpoint?.container_url !== 'http://server:8080') {
    failures.push('shared heartbeat server state has no private compose network handoff');
  }

  return failures;
}
