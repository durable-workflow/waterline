// Presentation and request helpers for the per-task-queue activity dispatch
// pause (native Server 3.8.0, task-queue-activity-dispatch-pause contract).
// A queue without `activity_dispatch` comes from a backend that has no pause
// (published PHP Server 2.x, older native, embedded Workflow) and shows
// nothing new.

// The Server refuses a longer reason with 422.
export const MAX_PAUSE_REASON_LENGTH = 1000;

function text(value) {
    return typeof value === 'string' && value.trim() !== '' ? value.trim() : null;
}

function isObject(value) {
    return value !== null && typeof value === 'object' && !Array.isArray(value);
}

// The queue's `activity_dispatch` object, or null when the backend reports none.
export function reportedActivityDispatch(taskQueue) {
    return isObject(taskQueue?.activity_dispatch) ? taskQueue.activity_dispatch : null;
}

// `paused` or `active`: the Server's `state`, else its `paused` flag.
export function activityDispatchState(dispatch) {
    if (!isObject(dispatch)) return null;
    if (dispatch.state === 'paused' || dispatch.state === 'active') return dispatch.state;
    if (dispatch.paused === true) return 'paused';
    if (dispatch.paused === false) return 'active';
    return null;
}

// A recorded principal (`type`, `id`, optional `label`) as display text:
// `Operator (auth:token role:operator)`, or `auth:token role:operator`
// without a label.
export function principalLabel(principal) {
    if (!isObject(principal)) return text(principal);
    const identity = [text(principal.type), text(principal.id)].filter(Boolean).join(' ');
    const label = text(principal.label);
    if (label && identity) return `${label} (${identity})`;
    return label || identity || null;
}

// The last pause and, once it ended, its resume. A paused queue has no
// resume, because a new pause replaces the last cycle; a queue that was never
// paused has no details. Labels are message keys.
export function activityDispatchDetails(dispatch) {
    const state = activityDispatchState(dispatch);
    if (state === null) return [];

    const rows = state === 'paused'
        ? [
            ['Reason', text(dispatch.reason), 'text'],
            ['Paused at', text(dispatch.paused_at), 'timestamp'],
            ['Paused by', principalLabel(dispatch.paused_by), 'text'],
        ]
        : [
            ['Last pause reason', text(dispatch.reason), 'text'],
            ['Last paused at', text(dispatch.paused_at), 'timestamp'],
            ['Last paused by', principalLabel(dispatch.paused_by), 'text'],
            ['Resumed at', text(dispatch.resumed_at), 'timestamp'],
            ['Resumed by', principalLabel(dispatch.resumed_by), 'text'],
        ];

    return rows
        .filter(([, value]) => value !== null)
        .map(([label, value, kind]) => ({ label, value, kind }));
}

// Pause and Resume are offered only when the backend reports the control as
// available (the Server advertises the capability and the SDK can call it),
// Waterline is not read-only, and the queue reports its state.
export function activityDispatchActions(taskQueue, control, permission = { allowed: true }) {
    const state = activityDispatchState(reportedActivityDispatch(taskQueue));
    if (state === null || control?.available !== true || permission?.allowed === false) {
        return { pause: false, resume: false };
    }
    return { pause: state === 'active', resume: state === 'paused' };
}

// Why the actions are not offered for a queue that reports its state, as a
// message key, or null when there is nothing to say.
export function activityDispatchUnavailableMessage(control, permission = { allowed: true }) {
    if (permission?.allowed === false || control?.reason === 'waterline_read_only') {
        return permission?.reason === 'waterline_action_forbidden'
            ? 'You are not authorized to pause activity dispatch.'
            : 'This Waterline is read-only. Pause and resume are unavailable.';
    }
    if (!isObject(control) || control.available === true) return null;
    if (control.reason === 'activity_dispatch_sdk_unavailable') {
        return 'Pause and resume need a PHP SDK that can pause activity dispatch.';
    }
    if (control.reason === 'activity_dispatch_server_unavailable') {
        return 'The Server does not advertise activity dispatch pause. It needs native Server 3.8.0 or later.';
    }
    return 'Waterline could not read the Server capabilities, so pause and resume are unavailable.';
}

// Client-side check of the optional reason. An empty reason is no reason.
export function normalizePauseReason(value) {
    const reason = typeof value === 'string' ? value.trim() : '';
    if (reason === '') return { valid: true, reason: null, length: 0 };
    const length = [...reason].length;
    return { valid: length <= MAX_PAUSE_REASON_LENGTH, reason, length };
}

export function activityDispatchRequest(basePath, taskQueue, action) {
    if (action !== 'pause' && action !== 'resume') {
        throw new Error(`Unknown activity dispatch action: ${action}`);
    }
    const name = typeof taskQueue === 'string' ? taskQueue : taskQueue?.name;
    if (typeof name !== 'string' || name === '') {
        throw new Error('The task queue has no name.');
    }
    return `${basePath}/api/v2/task-queues/${encodeURIComponent(name)}/activity-dispatch/${action}`;
}

// The dialog for a successful answer. `already_paused` and `not_paused` are
// informational: nothing changed.
export function activityDispatchOutcome(response) {
    switch (response?.outcome) {
        case 'paused':
            return { icon: 'success', title: 'Activity dispatch paused', text: 'No new activity task on this queue is leased until you resume it.' };
        case 'already_paused':
            return { icon: 'info', title: 'Already paused', text: 'Activity dispatch on this queue was already paused; nothing changed. The original pause and its reason are kept.' };
        case 'resumed':
            return { icon: 'success', title: 'Activity dispatch resumed', text: 'Ready activity tasks on this queue can be leased again. An activity whose deadline passed during the pause stays timed out.' };
        case 'not_paused':
            return { icon: 'info', title: 'Not paused', text: 'Activity dispatch on this queue was not paused; nothing changed.' };
        default:
            return { icon: 'info', title: 'Request completed', text: 'Refresh the queue to see its current activity dispatch state.' };
    }
}

function firstValidationMessage(errors) {
    if (!isObject(errors)) return null;
    for (const messages of Object.values(errors)) {
        const message = Array.isArray(messages) ? text(messages[0]) : text(messages);
        if (message) return message;
    }
    return null;
}

// Classifies a failed pause or resume for display: `forbidden` (Waterline
// read-only or the Server refused the token's role), `validation` (422),
// `unsupported` (501: nothing ran), `unknown` (no answer or a 5xx: the
// outcome is unknown) or `failed`. `message` is the backend's own text, if any.
export function activityDispatchError(error) {
    const response = error?.response;
    const data = isObject(response?.data) ? response.data : {};
    const status = response?.status;

    if (!response) return { kind: 'unknown', message: null };
    if (status === 422) {
        const remote = isObject(data.remote_details) ? data.remote_details : {};
        return {
            kind: 'validation',
            message: firstValidationMessage(data.errors) || firstValidationMessage(remote.errors) || text(data.message),
        };
    }
    if (status === 403) {
        return {
            kind: 'forbidden',
            reason: data.reason === 'waterline_read_only' ? 'waterline_read_only' : 'server_forbidden',
            message: text(data.message),
        };
    }
    if (status === 501) return { kind: 'unsupported', message: text(data.message) };
    if (status >= 500 || data.error === 'remote_transport_failure') return { kind: 'unknown', message: text(data.message) };
    return { kind: 'failed', message: text(data.message) || text(data.error) };
}
