export function workflowTaskAttention(flow) {
    const attention = flow?.task_attention;

    if (attention?.reason !== 'workflow_task_lease_lost'
        || !Number.isSafeInteger(attention.threshold) || attention.threshold < 1
        || !Number.isSafeInteger(attention.consecutive) || attention.consecutive < attention.threshold) {
        return null;
    }

    return attention;
}

export function reportedTaskAttentionMetrics(metrics) {
    return [
        { key: 'runs', label: 'Runs with repeated task lease loss', value: metrics?.runs?.task_attention },
        { key: 'tasks', label: 'Tasks at or above the lease loss threshold', value: metrics?.tasks?.lease_lost_repeatedly },
        { key: 'max', label: 'Most consecutive task lease losses', value: metrics?.tasks?.max_lost_lease_count },
    ].filter(({ value }) => Number.isSafeInteger(value) && value >= 0);
}
