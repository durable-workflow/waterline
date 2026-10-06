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
    const complete = !page.history_next_page_token

    return {
        ...page,
        timeline,
        timeline_returned_count: timeline.length,
        timeline_total_count: complete ? timeline.length : null,
        history_event_count: complete ? timeline.length : null,
        timeline_window_start_sequence: timeline[0]?.sequence ?? null,
        timeline_window_end_sequence: timeline.at(-1)?.sequence ?? null,
    }
}
