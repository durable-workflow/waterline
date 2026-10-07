import assert from 'node:assert/strict'
import fs from 'node:fs'
import test from 'node:test'
import { parse } from '@vue/compiler-sfc'
import { createSSRApp } from 'vue'
import { renderToString } from 'vue/server-renderer'
import { createWaterlineI18n } from '../../resources/js/localization.mjs'
import { localizedState } from '../../resources/js/state-labels.mjs'

const source = fs.readFileSync(new URL('../../resources/js/components/CancellationCascadeView.vue', import.meta.url), 'utf8')
const { descriptor } = parse(source)
const component = Function('localizedState', descriptor.script.content.replace(/^import .*$/gm, '').replace('export default', 'return'))(localizedState)
component.template = descriptor.template.content
const render = (diagnostics, locale = 'en') => renderToString(createSSRApp(component, { diagnostics }).use(createWaterlineI18n(locale)))

// A display fixture. Connected runtime evidence is qualified separately.
function fixture() {
    const root = {
        root_request_id: 'root-request', request_id: 'root-request', parent_request_id: null,
        requested_at: '2026-10-02T00:00:00Z', cleanup_deadline_at: '2026-10-02T00:00:30Z',
        reason: 'Maintenance', requester: { id: 'fixture-operator' }, source: 'operator',
    }
    const stop = {
        activity_execution_id: 'php-work', activity_attempt_id: 'php-attempt', activity_type: 'php.local.work',
        execution_mode: 'local', callback_state: 'reported_stopped', fence_history_event_id: 8,
        stop_history_event_id: 9, evidence_source: 'joined_callback', acknowledged_at: '2026-10-02T00:00:01Z',
    }
    const parent = {
        run_id: 'parent-run', workflow_id: 'parent-workflow', workflow_type: 'php.parent',
        lifecycle: 'cleaning_up', projected_status: 'waiting', request: root, same_root_budget: true,
        delivery: { history_event_id: 13, sequence: 1, sequence_span: 2, call_kind: 'parallel' },
        cleanup: null, activity_stops: [stop],
        cleanup_recovery: [{ history_event_id: 17, activity_execution_id: 'php-cleanup',
            recorded_at: '2026-10-02T00:00:12Z', attempt: { original_lease_owner: 'old-worker',
                original_workflow_task_attempt: 1, lease_owner: 'replacement', workflow_task_attempt: 2,
                callback_stop_state: 'unknown' } }],
        child_propagation: [{ history_event_id: 10, child_run_id: 'child-run',
            policy: 'wait_cancellation_completed', request_outcome: 'requested' }],
    }
    const child = { ...parent, run_id: 'child-run', workflow_id: 'child-workflow', workflow_type: 'python.child',
        lifecycle: 'cancelled', projected_status: 'cancelled',
        request: { ...root, request_id: 'child-request', parent_request_id: 'root-request' },
        cleanup: { outcome: 'completed', finished_at: '2026-10-02T00:00:03Z' },
        terminal_event_type: 'WorkflowCancelled', terminal_history_event_id: 11,
        activity_stops: [{ ...stop, activity_execution_id: 'rust-work', activity_type: 'rust.remote.work', execution_mode: 'remote' }],
        cleanup_recovery: [], child_propagation: [],
    }
    return { cancellation_cascade_supported: true, cancellation_cascade: {
        schema: 'durable-workflow.cancellation-cascade/v1', selected_run_id: 'parent-run', root,
        runs: [parent, child], inspection_complete: true, truncated: false, findings: [],
        edges: [{ parent_run_id: 'parent-run', child_run_id: 'child-run', kind: 'child_workflow', reference_state: 'resolved' }],
    } }
}

test('one view explains the root budget, both language runs, stop receipts and cleanup recovery', async () => {
    const html = await render(fixture())
    for (const value of ['root-request', '2026-10-02T00:00:30Z', 'php.parent', 'python.child', 'Selected run',
        'Cleaning up', 'Cancelled', 'child-request', 'Original root budget', 'Callback reported stopped',
        'php.local.work', 'rust.remote.work', 'Cleanup worker recovery', 'old-worker', 'replacement',
        'Previous callback stop: Unknown', 'Wait for cancellation completion', 'WorkflowCancelled']) {
        assert.ok(html.includes(value), value)
    }
    assert.ok(!html.includes('SIGKILL'))
})

test('embedded and service presentations consume the same evidence without changing the deadline', async () => {
    const embedded = { ...fixture(), engine_source: 'v2' }
    const service = { ...fixture(), engine_source: 'service' }
    assert.equal(await render(embedded), await render(service))
})

test('Ukrainian cascade explains cleanup while preserving identities, deadlines and original evidence', async () => {
    const payload = fixture()
    payload.cancellation_cascade.root.reason = 'Original reason <script>unsafe()</script>'
    const before = structuredClone(payload)
    const html = await render(payload, 'uk')
    for (const value of ['Каскад скасування', 'Початковий термін очищення', 'Очищення', 'Скасовано',
        'root-request', 'child-request', '2026-10-02T00:00:30Z', 'php.parent', 'python.child', 'rust.remote.work',
        'Original reason &lt;script&gt;unsafe()&lt;/script&gt;', 'WorkflowCancelled']) assert.ok(html.includes(value), value)
    assert.ok(!html.includes('<script>'))
    assert.deepEqual(payload, before)
})

test('a fence without a matching receipt keeps callback exit unverified', async () => {
    const payload = fixture()
    const stop = payload.cancellation_cascade.runs[0].activity_stops[0]
    stop.callback_state = 'unknown'
    stop.stop_history_event_id = null
    const html = await render(payload)
    assert.match(html, /Callback stop unverified/)
    assert.match(html, /stop receipt Not recorded/)
})

test('missing or clipped evidence is shown with its findings and independent roots retain their budget', async () => {
    const payload = fixture()
    const view = payload.cancellation_cascade
    view.inspection_complete = false
    view.truncated = true
    view.findings = [{ run_id: 'parent-run', message: 'Open the run history for additional evidence.' }]
    view.runs[1].same_root_budget = false
    view.runs[1].request.root_request_id = 'independent-request'
    view.runs[1].request.cleanup_deadline_at = '2026-10-02T00:00:45Z'
    const html = await render(payload)
    assert.match(html, /Some cancellation evidence is unavailable/)
    assert.match(html, /Open the run history/)
    assert.match(html, /Independent root budget/)
    assert.match(html, /2026-10-02T00:00:45Z/)
})

test('legacy absence, unsupported runtime, empty request and future schema stay distinct', async () => {
    assert.ok(!(await render({})).includes('Cancellation cascade'))
    assert.match(await render({ cancellation_cascade_supported: false }), /unavailable with this runtime/)
    assert.match(await render({ cancellation_cascade_supported: true, cancellation_cascade: null }), /No cooperative cancellation request/)
    assert.match(await render({ cancellation_cascade: { schema: 'future', root: { reason: 'DO_NOT_RENDER' } } }), /newer Waterline version/)
    assert.ok(!(await render({ cancellation_cascade: { schema: 'future', root: { reason: 'DO_NOT_RENDER' } } })).includes('DO_NOT_RENDER'))
})

test('caller metadata is text and cannot create executable markup', async () => {
    const payload = fixture()
    payload.cancellation_cascade.root.reason = '<script>alert(1)</script>'
    payload.cancellation_cascade.runs[0].workflow_type = '<img src=x onerror=alert(1)>'
    const html = await render(payload)
    assert.match(html, /&lt;script&gt;alert\(1\)&lt;\/script&gt;/)
    assert.ok(!html.includes('<script>'))
    assert.ok(!html.includes('<img src=x'))
})
