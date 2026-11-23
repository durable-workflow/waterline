export function attachContinuationLineage(flow, entries) {
    const rows = [...entries]
    const instanceId = flow.instance_id
    const selectedRun = flow.selected_run_id || flow.run_id
    if (!instanceId || !selectedRun) {
        return rows
    }

    for (const [field, direction] of [['from_predecessor', 'parent'], ['to_successor', 'child']]) {
        const transition = flow.continue_as_new_build_transitions?.[field]
        if (!transition || transition.run_id === selectedRun) {
            continue
        }
        const index = rows.findIndex((row) => row.direction === direction
            && row.link_type === 'continue_as_new' && row.run_id === transition.run_id
            && (!row.instance_id || row.instance_id === instanceId))
        if (index >= 0) {
            rows[index] = { ...rows[index], instance_id: instanceId, build_transition: transition }
        } else {
            rows.push({
                key: field + '-' + transition.run_id,
                direction,
                link_type: 'continue_as_new',
                display_id: transition.run_id,
                instance_id: instanceId,
                run_id: transition.run_id,
                build_transition: transition,
            })
        }
    }

    return rows
}
