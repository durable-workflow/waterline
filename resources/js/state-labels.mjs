// Only documented machine states get a presentation label. Unknown values
// retain their spelling, so a newer backend remains diagnosable.
const stateMessages = {
    pending: 'Pending', running: 'Running', waiting: 'Waiting', completed: 'Completed',
    failed: 'Failed (status)', cancelled: 'Cancelled', terminated: 'Terminated', continued: 'Continued',
    accepted: 'Accepted', started: 'Started', requested: 'Requested', delivered: 'Delivered',
    cleaning_up: 'Cleaning up', deadline_expired: 'Deadline expired', lost_authority: 'Authority lost',
    not_requested: 'Not requested', unknown: 'Unknown', blocked: 'Blocked', ready: 'Ready',
    active: 'Active', stale: 'Stale', expired: 'Expired', leased: 'Leased', healthy: 'Healthy',
    paused: 'Paused', deleted: 'Deleted', open: 'Open', closed: 'Closed', archived: 'Archived',
    try_cancel: 'Try cancellation', wait_cancellation_completed: 'Wait for cancellation completion',
    abandon: 'Abandon', reported_stopped: 'Reported stopped', observed: 'Observed', selected: 'Selected',
    draining: 'Draining', active_with_draining: 'Active with draining', stale_only: 'Only stale workers',
    timed_out: 'Timed out', configured: 'Configured', missing: 'Missing', available: 'Available',
    unavailable: 'Unavailable', partial: 'Partial', pruned: 'Pruned', read_only: 'Read-only',
    skipped: 'Skipped', fired: 'Fired', satisfied: 'Satisfied', suppressed: 'Suppressed',
    warning: 'Warning', error: 'Error', ok: 'OK (status)', rejected: 'Rejected', refused: 'Refused',
    resolved: 'Resolved', retrying: 'Retrying', repairable: 'Repairable', not_needed: 'Not Needed',
    cancelled_by_caller: 'Cancelled by caller', deadline_reached: 'Deadline reached',
    permanent_failure: 'Permanent failure', retryable_failure: 'Retryable failure',
};

export function localizedState(value, translate) {
    if (typeof value !== 'string') return translate('Not recorded');
    return Object.hasOwn(stateMessages, value) ? translate(stateMessages[value]) : value;
}
