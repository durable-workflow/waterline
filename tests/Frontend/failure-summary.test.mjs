import assert from 'node:assert/strict'
import test from 'node:test'
import { failureSummary, failureEventIndex } from '../../resources/js/failure-summary.mjs'
import { appendRunHistoryPage } from '../../resources/js/run-history.mjs'

const event = {
    sequence: 42, event_type: 'ActivityFailed',
    payload: { failure_id: 'failure-1', activity_execution_id: 'activity-1' },
}

test('embedded failures link to their failure event and exclude handled notifications and payload secrets', () => {
    const flow = {
        exceptions: [{ id: 'failure-1', exception: { __constructor: 'Error', message: '<untrusted>', trace: ['secret'] } }],
        timeline: [event, { sequence: 43, event_type: 'FailureHandled', payload: { failure_id: 'failure-1' } }],
    }
    const summary = failureSummary(flow)
    assert.equal(summary.rows[0].event_sequence, 42)
    assert.equal(summary.rows[0].source_id, 'activity-1')
    assert.equal(summary.rows[0].message, '<untrusted>')
    assert.equal(summary.rows[0].handled, null)
    assert.ok(!JSON.stringify(summary).includes('secret'))
    assert.equal(failureEventIndex(flow, 42), 0)
    assert.equal(failureEventIndex(flow, 99), -1)
})

test('service summaries preserve unknown evidence until the supporting opaque-cursor page is loaded', () => {
    const run = { instance_id: 'instance-1', selected_run_id: 'run-1', engine_source: 'service' }
    const current = { ...run, recent_failures: [{ failure_id: 'failure-1', handled: false }], timeline: [], timeline_truncated: true }
    assert.equal(failureSummary(current).rows[0].evidence_state, 'outside_window')
    const page = { ...current, timeline: [event], history_next_page_token: null, timeline_truncated: false }
    const combined = appendRunHistoryPage(current, page)
    assert.equal(failureSummary(combined).rows[0].event_sequence, 42)
    assert.equal(failureSummary(combined).rows[0].handled, false)
    assert.equal(failureSummary(current).rows[0].event_sequence, null)
})

test('pruned and unavailable summaries never imply an execution had no failures', () => {
    assert.equal(failureSummary({}).state, 'unavailable')
    const pruned = failureSummary({ details_pruned_at: '2026-10-06', exceptions: [{ id: 'failure-1' }] })
    assert.equal(pruned.state, 'pruned')
    assert.equal(pruned.rows[0].evidence_state, 'pruned')
    assert.equal(pruned.rows[0].event_sequence, null)
    assert.equal(failureSummary({ exceptions: [] }).state, 'available')
})

test('many retained failures show the latest twenty with a bounded message and a visible truncation marker', () => {
    const failures = Array.from({ length: 500 }, (_, i) => ({ id: String(i), message: 'x'.repeat(600) }))
    const embedded = failureSummary({ exceptions: failures })
    assert.equal(embedded.rows.length, 20)
    assert.equal(embedded.rows[0].id, '499')
    assert.equal(embedded.rows[0].message.length, 512)
    assert.equal(embedded.truncated, true)
    const service = failureSummary({ engine_source: 'service', recent_failures: failures.slice(0, 20) })
    assert.equal(service.rows[0].id, '0')
    assert.equal(service.truncated, true)
})
