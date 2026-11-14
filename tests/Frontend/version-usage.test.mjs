import assert from 'node:assert/strict';
import fs from 'node:fs';
import test from 'node:test';
import {parse} from '@vue/compiler-sfc';
import {usageStates, usageReasons, usageQuery, usageParams, validateUsagePage} from '../../resources/js/version-usage.mjs';

const draft = () => ({workflowType: 'Orders', changeId: 'carrier-api', minimum: '1', state: ''});
const query = () => usageQuery(draft(), null);
const page = (q = query()) => ({
    ...Object.fromEntries(Object.entries(q).filter(([key]) => key !== 'cursor')),
    schema_version: 1, consistency: 'live', through_run_id: 'run-3',
    counts: {total: 3, legacy: 1, current: 1, unknown: 1},
    runs: [
        {instance_id: 'order-1', run_id: 'run-1', status: 'waiting', state: 'legacy', recorded_version: 0, unknown_reason: null},
        {instance_id: 'order-2', run_id: 'run-2', status: 'running', state: 'current', recorded_version: 1, unknown_reason: null},
        {instance_id: 'order-3', run_id: 'run-3', status: 'pending', state: 'unknown', recorded_version: null, unknown_reason: 'marker_absent'},
    ], returned_count: 3, has_more: false, next_cursor: null,
});

test('queries preserve exact identities, support signed thresholds and omit only null values', () => {
    const q = usageQuery({...draft(), workflowType: ' Orders ', changeId: ' carrier-api ', minimum: '-2147483648'}, ' Tenant ');
    assert.equal(q.workflow_type, ' Orders ');
    assert.equal(q.change_id, ' carrier-api ');
    assert.equal(q.namespace, ' Tenant ');
    assert.equal(q.min_version, -2147483648);
    assert.equal(usageQuery({...draft(), minimum: '2147483647'}, '').min_version, 2147483647);
    assert.equal(usageQuery({...draft(), minimum: -2}, null).min_version, -2, 'Vue number inputs produce numbers after editing');
    assert.deepEqual(usageParams(query()), {workflow_type: 'Orders', change_id: 'carrier-api', min_version: 1, limit: 50});
    for (const minimum of ['', '1.5', '1e2', '2147483648', '-2147483649', 'true']) assert.throws(() => usageQuery({...draft(), minimum}, null));
    assert.throws(() => usageQuery({...draft(), changeId: '界'.repeat(342)}, null));
});

test('every unknown reason stays unknown, even with matching current definitions', () => {
    for (const reason of Object.keys(usageReasons)) {
        const p = page(); p.runs[2].unknown_reason = reason;
        assert.equal(validateUsagePage(p, query()).runs[2].state, 'unknown');
        assert.equal(p.runs[2].recorded_version, null);
    }
    for (const change of [
        p => p.runs[2].recorded_version = 1,
        p => p.runs[2].state = 'current',
        p => p.runs[2].unknown_reason = 'deployed_current',
        p => p.safe_to_remove = true,
        p => p.counts.unknown = false,
        p => p.namespace = 'foreign',
        p => p.runs[0].recorded_version = 1,
        p => p.runs[1].run_id = 'run-1',
    ]) {
        const p = page(); change(p); assert.throws(() => validateUsagePage(p, query()));
    }
});

test('a live continuation keeps the bound and refuses repeated or out-of-order rows', () => {
    const p = page(); p.runs = [p.runs[2]]; p.returned_count = 1;
    const continuation = {...query(), cursor: 'opaque'};
    assert.equal(validateUsagePage(p, continuation, {through: 'run-3', after: 'run-2'}), p);
    assert.throws(() => validateUsagePage(p, continuation, {through: 'run-4', after: 'run-2'}));
    assert.throws(() => validateUsagePage(p, continuation, {through: 'run-3', after: 'run-3'}));
    p.runs = []; p.returned_count = 0;
    p.counts = {total: 0, legacy: 0, current: 0, unknown: 0};
    assert.equal(validateUsagePage(p, continuation, {through: 'run-3', after: 'run-2'}), p);
});

test('first terminal pages are complete while empty later pages remain valid', () => {
    const p = page(); p.runs = []; p.returned_count = 0;
    assert.throws(() => validateUsagePage(p, query()));
    assert.equal(validateUsagePage(p, {...query(), cursor: 'opaque'}), p);
    const first = page(); first.runs = [first.runs[0]]; first.returned_count = 1; first.has_more = true; first.next_cursor = 'new';
    assert.throws(() => validateUsagePage(first, query()), 'A partial nonterminal page must not omit the rest of its limit');
    first.limit = 1;
    assert.equal(validateUsagePage(first, {...query(), limit: 1, cursor: 'old'}), first);
    assert.throws(() => validateUsagePage(first, {...query(), limit: 1, cursor: 'new'}));
});

const source = fs.readFileSync(new URL('../../resources/js/components/VersionUsageView.vue', import.meta.url), 'utf8');
const script = parse(source).descriptor.script.content;
function fixture(namespace = null) {
    const requests = [];
    const http = {get: (url, options) => new Promise((resolve, reject) => requests.push({url, options, resolve, reject}))};
    const component = Function('http', 'usageStates', 'usageReasons', 'usageQuery', 'usageParams', 'validateUsagePage', 'Waterline',
        script.replace(/^import .*;$/gm, '').replace('export default', 'return'))(
        http, usageStates, usageReasons, usageQuery, usageParams, validateUsagePage, {basePath: '/waterline'});
    const state = {$root: {bootstrap: {operator_scope: {namespace}}}};
    Object.assign(state, component.data.call(state), component.methods);
    Object.assign(state.draft, draft());
    return {state, requests, unmount: () => component.unmounted.call(state)};
}

test('the page uses its scoped reader and links the exact instance/run', async () => {
    const {state, requests} = fixture('Orders ');
    const read = state.search();
    assert.equal(requests[0].url, '/waterline/api/v2/workflow-version-usage');
    assert.equal(requests[0].options.params.namespace, 'Orders ');
    const q = {...query(), namespace: 'Orders '};
    requests[0].resolve({data: page(q)}); await read;
    assert.deepEqual(state.page.counts, {total: 3, legacy: 1, current: 1, unknown: 1});
    assert.deepEqual(state.runRoute(state.page.runs[2]), {name: 'flow-detail-run', params: {instanceId: 'order-3', runId: 'run-3'}});
});

test('changing filters cancels the read and prevents stale success or failure from replacing it', async () => {
    for (const outcome of ['resolve', 'reject']) {
        const {state, requests} = fixture();
        const first = state.search();
        state.draft.workflowType = 'Other'; state.invalidate();
        assert.equal(requests[0].options.signal.aborted, true);
        const second = state.search();
        requests[1].resolve({data: page({...query(), workflow_type: 'Other'})}); await second;
        requests[0][outcome](outcome === 'resolve' ? {data: page()} : new Error('stale failure')); await first;
        assert.equal(state.page.workflow_type, 'Other');
        assert.equal(state.error, null);
    }
});

test('cursor refusals stay errors and Retry repeats the same opaque continuation', async () => {
    const {state, requests} = fixture();
    const first = state.search();
    const p = page();
    p.runs = Array.from({length: 50}, (_, index) => ({...p.runs[0], run_id: `run-${String(index + 1).padStart(3, '0')}`}));
    p.counts = {total: 51, legacy: 50, current: 0, unknown: 1};
    p.through_run_id = 'run-051'; p.returned_count = 50; p.has_more = true; p.next_cursor = ' opaque /+ = ';
    requests[0].resolve({data: p}); await first;
    const next = state.next();
    assert.equal(requests[1].options.params.cursor, ' opaque /+ = ');
    requests[1].reject({response: {status: 422}}); await next;
    assert.equal(state.page, null);
    assert.match(state.error, /cursor was refused/);
    const retry = state.retry();
    assert.deepEqual(requests[2].options.params, requests[1].options.params);
    requests[2].resolve({data: {...p, runs: [{...page().runs[2], run_id: 'run-051'}], returned_count: 1, has_more: false, next_cursor: null}}); await retry;
    assert.equal(state.page.runs[0].run_id, 'run-051');
    const restart = state.search();
    assert.equal(Object.hasOwn(requests[3].options.params, 'cursor'), false);
    requests[3].resolve({data: page()}); await restart;
});

test('failed, unavailable and malformed responses never become zero counts', async () => {
    for (const response of [{status: 501}, {status: 503}, {status: 401}, {status: 403}]) {
        const {state, requests} = fixture();
        const read = state.search(); requests[0].reject({response}); await read;
        assert.equal(state.page, null); assert.equal(typeof state.error, 'string');
    }
    const {state, requests, unmount} = fixture();
    const read = state.search(); requests[0].resolve({data: {counts: {total: 0}}}); await read;
    assert.equal(state.page, null); assert.match(state.error, /No counts/);
    const later = state.search(); unmount(); requests[1].resolve({data: page()}); await later;
    assert.equal(state.page, null); assert.equal(requests[1].options.signal.aborted, true);
});
