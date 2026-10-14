export function activityResultSelection(flow, activity) {
    const instanceId = flow && flow.instance_id
    const runId = flow && (flow.selected_run_id || flow.run_id || flow.id)
    const activityId = activity && activity.id
    if (!flow || flow.engine_source !== 'service' || !activity || activity.status !== 'completed'
        || ![instanceId, runId, activityId].every(value => typeof value === 'string' && value !== '')) {
        return null
    }
    return { instanceId, runId, activityId }
}

export function activityResultPath(selection) {
    return '/api/instances/' + encodeURIComponent(selection.instanceId)
        + '/runs/' + encodeURIComponent(selection.runId)
        + '/activities/' + encodeURIComponent(selection.activityId) + '/result'
}

export function activityResultMatches(selection, inspection) {
    return inspection && inspection.instance_id === selection.instanceId
        && inspection.run_id === selection.runId && inspection.activity_id === selection.activityId
}

export function activityResultAvailable(inspection) {
    return inspection && inspection.available === true && inspection.state === 'available'
        && Object.prototype.hasOwnProperty.call(inspection, 'result')
}

export function activityResultMessage(inspection) {
    const messages = {
        activity_result_sdk_unavailable: 'Update the PHP SDK to inspect this activity result.',
        activity_result_server_unavailable: 'The connected Server does not support activity result inspection.',
        activity_result_decode_failed: 'The recorded activity result could not be decoded.',
        activity_not_found: 'This activity is not present in the selected run.',
    }
    const states = {
        pending: 'The activity has not produced a result yet.',
        pruned: 'Retention removed the activity result.',
        failed: 'The activity failed without a completed result.',
        cancelled: 'The activity was cancelled without a completed result.',
        unsupported: 'This activity result cannot be inspected by the connected backend.',
    }
    if (Object.prototype.hasOwnProperty.call(messages, inspection.reason)) return messages[inspection.reason]
    if (Object.prototype.hasOwnProperty.call(states, inspection.state)) return states[inspection.state]
    return 'The activity result could not be loaded.'
}
