// Replay-blocked runs (workflow#861): open runs whose workflow task can't replay.
// Workflow 2.24.0+ reports them; native Server doesn't yet (server#1154), so an
// absent field means "not reported", never zero.

function runs(metrics) {
    return (metrics && metrics.runs) || {};
}

export function replayBlockedReported(metrics) {
    const value = runs(metrics).replay_blocked;

    return value !== undefined && value !== null;
}

export function replayBlockedCount(metrics) {
    return replayBlockedReported(metrics) ? Number(runs(metrics).replay_blocked || 0) : null;
}

export function replayBlockedAgeAvailable(metrics) {
    const current = runs(metrics);

    if (current.max_replay_blocked_age_ms === undefined || current.max_replay_blocked_age_ms === null) {
        return false;
    }

    return Number(current.replay_blocked || 0) > 0 || Number(current.max_replay_blocked_age_ms || 0) > 0;
}

export function replayBlockedOldestAt(metrics) {
    return runs(metrics).oldest_replay_blocked_at || null;
}

export function replayBlockedRunsRoute() {
    return { path: '/running', query: { liveness_state: 'workflow_replay_blocked' } };
}
