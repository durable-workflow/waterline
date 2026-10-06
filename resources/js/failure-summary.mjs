const FAILURE_EVENTS = new Set([
    'ActivityFailed', 'ActivityTimedOut', 'ChildRunFailed', 'ChildRunCancelled',
    'ChildRunTerminated', 'WorkflowFailed', 'WorkflowTimedOut', 'WorkflowCancelled',
    'WorkflowTerminated', 'UpdateCompleted',
])

const scalar = value => typeof value === 'string' && value.trim() ? value.slice(0, 512) : null

// Use only the selected run's response and loaded history window. This does not
// fetch history or infer that an absent event never happened.
export function failureSummary(flow) {
    const recent = flow.engine_source === 'service' || flow.read_mode === 'bounded'
    const source = recent ? flow.recent_failures : flow.exceptions
    const known = Array.isArray(source) ? source : []
    const selected = recent ? known.slice(0, 20) : known.slice(-20).reverse()
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
        state: pruned ? 'pruned' : !Array.isArray(source) ? 'unavailable'
            : flow.read_mode === 'bounded' ? flow.recent_failures_state || 'partial' : 'available',
        truncated: known.length > 20 || (recent && (flow.recent_failures_truncated === true || known.length === 20)),
        rows: selected.map(failure => {
            const id = failure.failure_id || failure.id
            const event = events.get(id)
            const exception = failure.exception && typeof failure.exception === 'object'
                ? failure.exception : {}
            const payload = event?.payload || {}
            const reference = recent && failure.supporting_event?.state === 'retained'
                && Number.isInteger(failure.supporting_event.sequence)
                && failure.supporting_event.sequence > 0
                && FAILURE_EVENTS.has(failure.supporting_event.event_type)
                && typeof failure.supporting_event.next_page_token === 'string'
                && failure.supporting_event.next_page_token.length > 0
                && failure.supporting_event.next_page_token.length <= 4096
                ? failure.supporting_event : null
            return {
                id,
                type: scalar(failure.exception_class || exception.__constructor || exception.class),
                message: scalar(failure.message || exception.message),
                source_kind: scalar(failure.source_kind || payload.source_kind),
                source_id: scalar(failure.source_id || payload.source_id || payload.activity_execution_id),
                handled: typeof failure.handled === 'boolean' ? failure.handled : null,
                recorded_at: failure.created_at || event?.recorded_at || event?.timestamp || reference?.recorded_at || null,
                event_sequence: event?.sequence || reference?.sequence || null,
                history_page_token: reference?.next_page_token || null,
                evidence_state: event ? 'available' : pruned ? 'pruned'
                    : failure.supporting_event?.state === 'pruned' ? 'pruned'
                    : reference || flow.timeline_truncated ? 'outside_window' : 'unavailable',
            }
        }),
    }
}

export function failureEventIndex(flow, sequence) {
    return (flow.timeline || []).findIndex(event => event.sequence === sequence)
}
