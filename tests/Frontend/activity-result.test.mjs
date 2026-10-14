import assert from 'node:assert/strict'
import test from 'node:test'
import { activityResultSelection, activityResultPath, activityResultMatches, activityResultAvailable, activityResultMessage } from '../../resources/js/activity-result.mjs'

const flow = { engine_source: 'service', instance_id: 'order/雪', selected_run_id: 'run?1' }
const activity = { id: 'activity#1', status: 'completed' }

test('inspection binds and escapes the exact selected instance/run/activity', () => {
    const selection = activityResultSelection(flow, activity)
    assert.equal(activityResultPath(selection), '/api/instances/order%2F%E9%9B%AA/runs/run%3F1/activities/activity%231/result')
    assert.equal(activityResultSelection({ ...flow, engine_source: 'v2' }, activity), null)
    assert.equal(activityResultSelection({ ...flow, selected_run_id: null }, activity), null)
    assert.equal(activityResultSelection(flow, { ...activity, status: 'running' }), null)
})

test('a response from another run or activity cannot be displayed', () => {
    const selection = activityResultSelection(flow, activity)
    const response = { instance_id: flow.instance_id, run_id: flow.selected_run_id, activity_id: activity.id }
    assert.equal(activityResultMatches(selection, response), true)
    for (const key of ['instance_id', 'run_id', 'activity_id']) {
        assert.equal(activityResultMatches(selection, { ...response, [key]: 'another' }), false)
    }
    const next = activityResultSelection({ ...flow, selected_run_id: 'next-run' }, activity)
    assert.notEqual(activityResultPath(next), activityResultPath(selection))
})

test('a present null or false result is available while absent and pending results are not', () => {
    for (const result of [null, false, 0, '', ['$type', 'long']]) {
        assert.equal(activityResultAvailable({ state: 'available', available: true, result }), true)
    }
    assert.equal(activityResultAvailable({ state: 'available', available: true }), false)
    assert.equal(activityResultAvailable({ state: 'pending', available: false, result: null }), false)
    assert.equal(activityResultAvailable({ state: 'available', available: 'true', result: 1 }), false)
})

test('diagnostics preserve actionable SDK, Server, pruning and decode distinctions', () => {
    assert.match(activityResultMessage({ reason: 'activity_result_sdk_unavailable' }), /Update the PHP SDK/)
    assert.match(activityResultMessage({ reason: 'activity_result_server_unavailable' }), /Server does not support/)
    assert.match(activityResultMessage({ reason: 'activity_result_decode_failed' }), /could not be decoded/)
    assert.match(activityResultMessage({ state: 'pruned' }), /Retention removed/)
    assert.notEqual(activityResultMessage({ state: 'failed' }), activityResultMessage({ state: 'cancelled' }))
    assert.equal(activityResultMessage({ reason: '<script>untrusted</script>' }), 'The activity result could not be loaded.')
    assert.equal(activityResultMessage({ reason: 'constructor', state: '__proto__' }), 'The activity result could not be loaded.')
})
