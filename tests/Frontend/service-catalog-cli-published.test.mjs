import assert from 'node:assert/strict';
import test from 'node:test';
import {parseExactJson, requireOriginalHistory} from '../../scripts/ci/service-catalog-cli-published.mjs';

test('CLI evidence preserves integer changes that ordinary JSON decoding collapses', () => {
    const original = '{"long":9223372036854775807}';
    const changed = '{"long":9223372036854775806}';
    assert.deepEqual(JSON.parse(original), JSON.parse(changed));
    assert.notDeepEqual(parseExactJson(original), parseExactJson(changed));
    assert.deepEqual(parseExactJson('{"n":null,"text":"λ雪"}'), {n: null, text: 'λ雪'});
});

test('matching CLI/API histories cannot qualify a different original call or run', () => {
    const original = {id: 'original-call', caller_workflow_instance_id: 'caller', caller_workflow_run_id: 'original-run', status: 'completed'};
    const history = {workflow_id: 'caller', run_id: 'original-run', events: [
        {sequence: 1, event_type: 'ServiceCallStarted', payload: {service_call_id: 'original-call'}},
        {sequence: 2, event_type: 'ServiceCallCompleted', payload: {service_call_id: 'original-call'}},
    ]};
    requireOriginalHistory(history, structuredClone(history), original);
    const changed = structuredClone(history);
    changed.events[1].payload.service_call_id = 'another-call';
    assert.throws(() => requireOriginalHistory(changed, structuredClone(changed), original));
    changed.events[1].payload.service_call_id = 'original-call';
    changed.run_id = 'another-run';
    assert.throws(() => requireOriginalHistory(changed, structuredClone(changed), original));
});
