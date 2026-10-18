import assert from 'node:assert/strict';
import test from 'node:test';
import {parseExactJson, requireNotFoundReply, requireOriginalCall, requireOriginalHistory} from '../../scripts/ci/service-catalog-cli-published.mjs';

test('CLI evidence preserves integer changes that ordinary JSON decoding collapses', () => {
    const original = '{"long":9223372036854775807}';
    const changed = '{"long":9223372036854775806}';
    assert.deepEqual(JSON.parse(original), JSON.parse(changed));
    assert.notDeepEqual(parseExactJson(original), parseExactJson(changed));
    assert.deepEqual(parseExactJson('{"n":null,"text":"λ雪"}'), {n: null, text: 'λ雪'});
});

test('a failed request mentioning 404 or a refusal in another namespace is not a canonical not-found result', () => {
    const reply = (status, statusCode, namespace) => ({status, stdout: JSON.stringify({
        exit_code: status, status_code: statusCode, namespace, error: 'lookup 404',
    })});
    requireNotFoundReply(reply(5, 404, '017'), '017');
    assert.throws(() => requireNotFoundReply(reply(6, 500, '017'), '017'));
    assert.throws(() => requireNotFoundReply(reply(5, 404, '17'), '017'));
});

test('matching CLI/API call details cannot substitute another original target or alter typed results', () => {
    const original = {id: 'call', namespace: 'shared', caller_namespace: 'tenant-a', caller_workflow_instance_id: 'caller',
        caller_workflow_run_id: 'caller-run', target_namespace: 'shared', linked_workflow_instance_id: 'target',
        linked_workflow_run_id: 'original-target', status: 'completed', call_deadline_at: '2026-10-18T03:00:00.123456Z'};
    const detail = {...original, service_call_attempts: [{attempt: 1}], retry_attempt_count: 1,
        result_envelope: {codec: 'avro', blob: 'original'}};
    requireOriginalCall(detail, structuredClone(detail), original);
    const changed = {...detail, linked_workflow_run_id: 'another-target'};
    assert.throws(() => requireOriginalCall(changed, structuredClone(changed), original));
    assert.throws(() => requireOriginalCall({...detail, result_envelope: {codec: 'avro', blob: 'changed'}}, detail, original));
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
