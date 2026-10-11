import assert from 'node:assert/strict';
import fs from 'node:fs';
import test from 'node:test';

const source = fs.readFileSync(new URL('../../resources/js/components/ServiceCatalogView.vue', import.meta.url), 'utf8');
const script = source.match(/<script>([\s\S]*?)<\/script>/)[1];
const component = Function(script.replace(/import axios from 'axios';/, '').replace('export default', 'return'))();

test('call details retain the original microsecond deadline, attempt count and typed failure', () => {
    const state = {
        detailType: 'calls',
        detail: {call_deadline_at: '2026-10-11T20:00:01.123456Z', retry_attempt_count: 2,
            failure_type: 'ServiceCallTimedOut', accepted_at: '2026-10-11T20:00:00.123456Z'},
        $t: (value) => value,
        timestamp: component.methods.timestamp,
        formatLabel: component.methods.formatLabel,
        stateLabel: (value) => value,
    };
    const rows = component.computed.detailRows.call(state);
    assert.equal(rows.find((row) => row.key === 'deadline').value, '2026-10-11 20:00:01.123456');
    assert.equal(rows.find((row) => row.key === 'attempt_count').value, 2);
    assert.equal(rows.find((row) => row.key === 'failure_type').value, 'ServiceCallTimedOut');
    assert.equal(rows.find((row) => row.key === 'accepted_at').value, '2026-10-11 20:00:00.123456');
});

test('foreign references never become workflow routes', () => {
    const ref = {namespace: 'foreign', workflow_instance_id: 'original', workflow_run_id: 'run-1', in_observer_namespace: false};
    assert.equal(component.methods.referenceRoute(ref), null);
    assert.deepEqual(component.methods.referenceRoute({...ref, in_observer_namespace: true}), {
        name: 'flow-detail-run', params: {instanceId: 'original', runId: 'run-1'},
    });
});

test('related catalog browsing remains filtered across pages without sending unsupported filters', () => {
    const state = {activeTab: 'operations', $route: {query: {endpoint_id: 'endpoint-1', service_id: 'service-1', operation_id: 'ignored', namespace: 'foreign'}},
        stringQuery: component.methods.stringQuery};
    assert.deepEqual(component.methods.listParams.call(state, 2), {page: 2, endpoint_id: 'endpoint-1', service_id: 'service-1'});
    state.activeTab = 'endpoints';
    assert.deepEqual(component.methods.listParams.call(state, 3), {page: 3});
    const routes = [];
    component.methods.browseRelated.call({detailType: 'endpoints', detail: {id: 'original'},
        $route: {path: '/services'}, $router: {push: (route) => routes.push(route)}}, 'operations');
    assert.deepEqual(routes, [{path: '/services', query: {tab: 'operations', endpoint_id: 'original'}}]);
});

test('terminal scalar values keep their value in the display', () => {
    for (const value of [false, null, 0, '', ['done']]) {
        assert.equal(component.methods.prettyJson(value), JSON.stringify(value, null, 2));
    }
});
