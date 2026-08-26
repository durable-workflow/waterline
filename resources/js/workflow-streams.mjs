const STATES = new Set(['available', 'degraded', 'unavailable'])

export function presentWorkflowStreams(flow = {}) {
    const rows = Array.isArray(flow.workflow_streams) ? flow.workflow_streams : []
    const explicitState = STATES.has(flow.workflow_streams_state)
        ? flow.workflow_streams_state
        : null
    const hasAvailability = typeof flow.workflow_streams_available === 'boolean'
    const hasContract = explicitState !== null || hasAvailability
    const state = explicitState
        || (flow.workflow_streams_available === false ? 'unavailable' : 'available')
    const mode = flow.workflow_streams_mode === 'service' ? 'service' : 'embedded'
    let notice = null
    let noticeClass = null

    if (state === 'degraded') {
        notice = mode === 'service'
            ? 'Workflow Stream summaries could not be read from this service. Other run details remain available.'
            : 'Workflow Stream summaries could not be collected. Other run details remain available; verify the embedded workflow schema and storage connection.'
        noticeClass = 'alert-warning'
    } else if (state === 'unavailable') {
        notice = mode === 'service'
            ? 'Workflow Stream summaries are not available from this service. Other run details remain available.'
            : 'Workflow Stream summaries are not available for this run. Other run details remain available.'
        noticeClass = 'alert-warning'
    } else if (hasContract && rows.length === 0) {
        notice = 'No Workflow Streams were recorded for this run.'
        noticeClass = 'alert-secondary'
    }

    return {
        empty: state === 'available' && rows.length === 0,
        notice,
        noticeClass,
        rows,
        state,
        unavailable: state === 'degraded' || state === 'unavailable',
        visible: rows.length > 0 || hasContract,
    }
}
