function string(value) {
  return typeof value === 'string' ? value : '';
}

function matchesPrefix(value, prefix) {
  return prefix !== '' && value !== '' && value.startsWith(prefix);
}

export function verifySharedWaterlineIsolation(
  state,
  productEvidence,
  workflowIds = {},
  taskQueue = '',
) {
  const receipt = state?.cell_isolation?.waterline ?? {};
  const topology = productEvidence?.topology ?? {};
  const prescribed = {
    namespace: string(receipt.namespace),
    task_queue_prefix: string(receipt.task_queue_prefix),
    workflow_id_prefix: string(receipt.workflow_id_prefix),
    worker_id_prefix: string(receipt.worker_id_prefix),
  };
  const observed = {
    namespace: string(topology.namespace),
    task_queue: string(topology.task_queue),
    stale_worker_id: string(topology.stale_worker_id),
    fresh_worker_id: string(topology.fresh_worker_id),
    initial_workflow_id: string(topology.initial_workflow_id),
    after_stale_workflow_id: string(topology.after_stale_workflow_id),
  };
  const planned = {
    task_queue: string(taskQueue),
    initial_workflow_id: string(workflowIds.initial_workflow_id),
    after_stale_workflow_id: string(workflowIds.after_stale_workflow_id),
  };
  const checks = {
    namespace_matches_receipt:
      prescribed.namespace !== '' && observed.namespace === prescribed.namespace,
    planned_task_queue_matches_prefix:
      matchesPrefix(planned.task_queue, prescribed.task_queue_prefix),
    task_queue_matches_prefix:
      matchesPrefix(observed.task_queue, prescribed.task_queue_prefix),
    task_queue_matches_plan:
      planned.task_queue !== '' && observed.task_queue === planned.task_queue,
    stale_worker_matches_prefix:
      matchesPrefix(observed.stale_worker_id, prescribed.worker_id_prefix),
    fresh_worker_matches_prefix:
      matchesPrefix(observed.fresh_worker_id, prescribed.worker_id_prefix),
    planned_initial_workflow_matches_prefix:
      matchesPrefix(planned.initial_workflow_id, prescribed.workflow_id_prefix),
    planned_after_stale_workflow_matches_prefix:
      matchesPrefix(planned.after_stale_workflow_id, prescribed.workflow_id_prefix),
    initial_workflow_matches_prefix:
      matchesPrefix(observed.initial_workflow_id, prescribed.workflow_id_prefix),
    after_stale_workflow_matches_prefix:
      matchesPrefix(observed.after_stale_workflow_id, prescribed.workflow_id_prefix),
    initial_workflow_matches_plan:
      planned.initial_workflow_id !== ''
        && observed.initial_workflow_id === planned.initial_workflow_id,
    after_stale_workflow_matches_plan:
      planned.after_stale_workflow_id !== ''
        && observed.after_stale_workflow_id === planned.after_stale_workflow_id,
  };

  return {
    prescribed,
    planned,
    observed,
    checks,
    passed: Object.values(checks).every((passed) => passed === true),
  };
}
