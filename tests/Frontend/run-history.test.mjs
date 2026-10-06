import assert from 'node:assert/strict'
import test from 'node:test'
import { appendRunHistoryPage } from '../../resources/js/run-history.mjs'

const run = { instance_id: 'order-1', selected_run_id: 'run-1' }
const started = { sequence: 1, event_type: 'WorkflowStarted', payload: { note: '<untrusted>' } }
const activity = { sequence: 2, event_type: 'ActivityCompleted', payload: { result: 'unchanged' } }
const completed = { sequence: 3, event_type: 'WorkflowCompleted', payload: {} }

test('continuation pages preserve earlier durable events and leave an incomplete total unknown', () => {
    const current = { ...run, timeline: [started], history_next_page_token: 'first' }
    const page = { ...run, timeline: [activity], history_next_page_token: 'second' }
    const combined = appendRunHistoryPage(current, page)
    assert.deepEqual(combined.timeline, [started, activity])
    assert.equal(combined.history_next_page_token, 'second')
    assert.equal(combined.timeline_total_count, null)
    assert.equal(combined.timeline_returned_count, 2)
    assert.deepEqual(current.timeline, [started])
    assert.deepEqual(page.timeline, [activity])
})

test('an overlapping retry of the final page neither duplicates events nor loses the completed range', () => {
    const current = { ...run, timeline: [activity, started], history_next_page_token: 'last' }
    const page = { ...run, timeline: [activity, completed], history_next_page_token: null }
    const combined = appendRunHistoryPage(current, page)
    assert.deepEqual(combined.timeline, [started, activity, completed])
    assert.equal(combined.timeline_returned_count, 3)
    assert.equal(combined.timeline_total_count, 3)
    assert.equal(combined.timeline_window_start_sequence, 1)
    assert.equal(combined.timeline_window_end_sequence, 3)
})

test('a late response cannot append events from another instance or selected run', () => {
    const current = { ...run, timeline: [started] }
    assert.throws(() => appendRunHistoryPage(current, { ...run, instance_id: 'order-2', timeline: [completed] }))
    assert.throws(() => appendRunHistoryPage(current, { ...run, selected_run_id: 'run-2', timeline: [completed] }))
    assert.deepEqual(current.timeline, [started])
})

test('reaching the end after a direct failure jump leaves the total history unknown', () => {
    const current = {
        ...run, timeline: [activity], history_window_from_start: false,
        history_start_page_token: 'opaque-failure-cursor', history_next_page_token: 'last',
    }
    const page = { ...run, timeline: [completed], history_next_page_token: null }
    const combined = appendRunHistoryPage(current, page)
    assert.equal(combined.history_window_from_start, false)
    assert.equal(combined.history_start_page_token, 'opaque-failure-cursor')
    assert.equal(combined.timeline_total_count, null)
    assert.equal(combined.history_event_count, null)
    assert.equal(combined.timeline_returned_count, 2)
})

test('ending a bounded or pruned window does not replace original history counters with the loaded count', () => {
    const current = { ...run, read_mode: 'bounded', timeline: [started], history_event_count: 50 }
    const page = { ...run, read_mode: 'bounded', timeline: [completed], history_next_page_token: null, history_event_count: 50 }
    const complete = appendRunHistoryPage(current, page)
    assert.equal(complete.timeline_total_count, 2)
    assert.equal(complete.history_event_count, 50)
    const pruned = appendRunHistoryPage(current, {
        ...page, details_pruned_at: '2026-10-06', history_state: 'pruned', timeline: [], history_event_count: null,
    })
    assert.equal(pruned.timeline_total_count, null)
    assert.equal(pruned.history_event_count, null)
})
