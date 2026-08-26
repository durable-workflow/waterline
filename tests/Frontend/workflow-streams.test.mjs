import assert from 'node:assert/strict'
import test from 'node:test'

import { presentWorkflowStreams } from '../../resources/js/workflow-streams.mjs'

test('supported empty service streams remain visible as an available empty state', () => {
    const presentation = presentWorkflowStreams({
        workflow_streams: [],
        workflow_streams_mode: 'service',
        workflow_streams_state: 'available',
        workflow_streams_available: true,
        workflow_streams_unavailable_reason: null,
    })

    assert.equal(presentation.state, 'available')
    assert.equal(presentation.visible, true)
    assert.equal(presentation.empty, true)
    assert.equal(presentation.unavailable, false)
    assert.equal(typeof presentation.notice, 'string')
    assert.equal(presentation.noticeClass, 'alert-secondary')
})

test('service capability unavailability is non-fatal without exposing its machine reason', () => {
    const reason = 'workflow_streams_route_unsupported'
    const presentation = presentWorkflowStreams({
        workflow_streams: [],
        workflow_streams_mode: 'service',
        workflow_streams_state: 'unavailable',
        workflow_streams_available: false,
        workflow_streams_unavailable_reason: reason,
    })

    assert.equal(presentation.state, 'unavailable')
    assert.equal(presentation.visible, true)
    assert.equal(presentation.unavailable, true)
    assert.equal(presentation.notice.includes(reason), false)
    assert.equal(presentation.noticeClass, 'alert-warning')
})

test('embedded collection degradation stays distinct from empty and ignores its typed reason in copy', () => {
    const reason = 'workflow_streams_schema_unavailable'
    const presentation = presentWorkflowStreams({
        workflow_streams: [],
        workflow_streams_mode: 'embedded',
        workflow_streams_state: 'degraded',
        workflow_streams_available: false,
        workflow_streams_unavailable_reason: reason,
    })

    assert.equal(presentation.state, 'degraded')
    assert.equal(presentation.visible, true)
    assert.equal(presentation.empty, false)
    assert.equal(presentation.unavailable, true)
    assert.equal(presentation.notice.includes(reason), false)
})

test('legacy stream rows remain visible without an availability envelope', () => {
    const presentation = presentWorkflowStreams({
        workflow_streams: [{ stream_name: 'orders', direction: 'inbound' }],
    })

    assert.equal(presentation.state, 'available')
    assert.equal(presentation.visible, true)
    assert.equal(presentation.rows.length, 1)
    assert.equal(presentation.notice, null)
})
