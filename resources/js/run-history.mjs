export function appendRunHistoryPage(current, page) {
    if (!current.instance_id || !current.selected_run_id
        || current.instance_id !== page.instance_id
        || current.selected_run_id !== page.selected_run_id) {
        throw new Error('History page belongs to a different run')
    }

    const bySequence = new Map()
    for (const event of [...(current.timeline || []), ...(page.timeline || [])]) {
        bySequence.set(event.sequence, event)
    }
    const timeline = [...bySequence.values()].sort((a, b) => a.sequence - b.sequence)
    const fromStart = current.history_window_from_start !== false && !current.history_start_page_token
    const complete = fromStart && !page.history_next_page_token
        && !page.details_pruned_at && page.history_state !== 'pruned'

    return {
        ...page,
        history_window_from_start: fromStart,
        history_start_page_token: current.history_start_page_token || null,
        timeline,
        timeline_returned_count: timeline.length,
        timeline_total_count: complete ? timeline.length : null,
        history_event_count: page.read_mode === 'bounded'
            ? page.history_event_count ?? null : complete ? timeline.length : null,
        timeline_window_start_sequence: timeline[0]?.sequence ?? null,
        timeline_window_end_sequence: timeline.at(-1)?.sequence ?? null,
    }
}
