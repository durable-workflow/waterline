const FAILURE_EVENTS = new Set([
    'ActivityFailed', 'ActivityTimedOut', 'ChildRunFailed', 'ChildRunCancelled',
    'ChildRunTerminated', 'WorkflowFailed', 'WorkflowTimedOut', 'WorkflowCancelled',
    'WorkflowTerminated', 'UpdateCompleted',
])

const scalar = value => typeof value === 'string' && value.trim() ? value.slice(0, 512) : null

// Use only the selected run's response and loaded history window. This does not
// fetch history or infer that an absent event never happened.
export function failureSummary(flow) {
    const service = flow.engine_source === 'service'
    const source = service ? flow.recent_failures : flow.exceptions
    const known = Array.isArray(source) ? source : []
    const selected = service ? known.slice(0, 20) : known.slice(-20).reverse()
    const events = new Map()

    for (const event of flow.timeline || []) {
        const type = event.type || event.event_type
        const id = event.payload?.failure_id
        if (FAILURE_EVENTS.has(type) && typeof id === 'string'
            && Number.isInteger(event.sequence) && event.sequence > 0) {
            events.set(id, event)
        }
    }

    const pruned = Boolean(flow.details_pruned_at)
    return {
        state: pruned ? 'pruned' : Array.isArray(source) ? 'available' : 'unavailable',
        truncated: known.length > 20 || (service && known.length === 20),
        rows: selected.map(failure => {
            const id = failure.failure_id || failure.id
            const event = events.get(id)
            const exception = failure.exception && typeof failure.exception === 'object'
                ? failure.exception : {}
            const payload = event?.payload || {}
            return {
                id,
                type: scalar(failure.exception_class || exception.__constructor || exception.class),
                message: scalar(failure.message || exception.message),
                source_kind: scalar(failure.source_kind || payload.source_kind),
                source_id: scalar(failure.source_id || payload.source_id || payload.activity_execution_id),
                handled: typeof failure.handled === 'boolean' ? failure.handled : null,
                recorded_at: failure.created_at || event?.recorded_at || event?.timestamp || null,
                event_sequence: event?.sequence || null,
                evidence_state: event ? 'available' : pruned ? 'pruned'
                    : flow.timeline_truncated ? 'outside_window' : 'unavailable',
            }
        }),
    }
}

export function failureEventIndex(flow, sequence) {
    return (flow.timeline || []).findIndex(event => event.sequence === sequence)
}
