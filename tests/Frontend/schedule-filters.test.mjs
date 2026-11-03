import assert from 'node:assert/strict';
import fs from 'node:fs';
import test from 'node:test';
import {createSSRApp} from 'vue';
import {renderToString} from 'vue/server-renderer';

const source = fs.readFileSync(new URL('../../resources/js/components/ScheduleView.vue', import.meta.url), 'utf8');
const script = source.match(/<script>([\s\S]*?)<\/script>/)[1];

function fixture(mode = 'service') {
    const requests = [];
    const axios = {get: (url, options) => new Promise((resolve, reject) => requests.push({url, options, resolve, reject}))};
    const component = Function('axios', script.replace(/^import[\s\S]*?from ['"][^'"]+['"];$/gm, '').replace('export default', 'return'))(axios);
    const state = {
        ...component.data(), ...component.methods, serviceMode: mode === 'service',
        $t: value => value, resolvedApiEndpoint: () => '/waterline/api/v2/schedules',
    };
    return {state, requests};
}

const response = id => ({data: {data: [{schedule_id: id}], current_page: 1, last_page: 3, per_page: 50, total: 120}});

test('schedule filters apply together, reset the page and persist across pagination', async () => {
    const {state, requests} = fixture();
    Object.assign(state, {currentPage: 3, statusFilter: 'paused', workflowTypeFilter: ' orders.process ', visibilityQueryFilter: ' Note = "nightly AND urgent" '});
    const load = state.applyFilters();
    assert.equal(state.currentPage, 1);
    assert.deepEqual(requests[0].options.params, {page: 1, status: 'paused', workflow_type: 'orders.process', query: 'Note = "nightly AND urgent"'});
    requests[0].resolve(response('matching'));
    await load;
    state.currentPage = 2;
    state.statusFilter = 'active';
    state.workflowTypeFilter = 'unapplied edit';
    const next = state.loadData();
    assert.deepEqual(requests[1].options.params, {...requests[0].options.params, page: 2});
    requests[1].resolve(response('second-page'));
    await next;
});

test('empty applied filters are omitted and old filter results cannot replace a new selection', async () => {
    for (const result of ['resolve', 'reject']) {
        const {state, requests} = fixture();
        const old = state.loadData();
        state.workflowTypeFilter = 'orders.process';
        const latest = state.applyFilters();
        requests[1].resolve(response('latest'));
        await latest;
        requests[0][result](result === 'resolve' ? response('old') : {response: {data: {message: 'old error'}}});
        await old;
        assert.equal(state.schedules[0].schedule_id, 'latest');
        assert.equal(state.error, null);
        assert.equal(state.refreshing, false);
        state.workflowTypeFilter = '  ';
        state.visibilityQueryFilter = '';
        const cleared = state.applyFilters();
        assert.deepEqual(requests[2].options.params, {page: 1});
        requests[2].resolve(response('unfiltered'));
        await cleared;
    }
});

test('a refused query preserves entered values and an open dialog for explicit read recovery', async () => {
    const {state, requests} = fixture();
    Object.assign(state, {workflowTypeFilter: 'orders.process', visibilityQueryFilter: 'Status > "active"', showBackfill: true, backfillFrom: '2026-10-23T00:00:00Z'});
    const load = state.applyFilters();
    requests[0].reject({response: {data: {reason: 'unsupported_schedule_visibility_predicate', message: 'Only equality predicates joined by AND are supported.'}}});
    await load;
    assert.equal(state.error, 'Only equality predicates joined by AND are supported.');
    assert.equal(state.visibilityQueryFilter, 'Status > "active"');
    assert.equal(state.workflowTypeFilter, 'orders.process');
    assert.equal(state.showBackfill, true);
    assert.equal(state.backfillFrom, '2026-10-23T00:00:00Z');
    const retried = state.loadData();
    assert.deepEqual(requests[1].options.params, requests[0].options.params);
    requests[1].resolve(response('recovered'));
    await retried;
    assert.equal(state.error, null);
});

const filterForm = source.match(/<form class="schedule-view__filters[\s\S]*?<\/form>/)[0];

async function renderFilters(mode) {
    const app = createSSRApp({
        template: filterForm,
        data: () => ({statusFilter: '', workflowTypeFilter: '', visibilityQueryFilter: ''}),
        computed: {serviceMode: () => mode === 'service'},
        methods: {applyFilters() {}},
    });
    app.config.globalProperties.$t = value => value;
    return renderToString(app);
}

test('the visibility query field and its parameter are service-only', async () => {
    const service = await renderFilters('service');
    assert.match(service, /id="waterline-schedule-query"/);
    assert.match(service, /id="waterline-schedule-type"/);
    assert.doesNotMatch(service, /value="deleted"/);
    assert.doesNotMatch(service, /schedule-view__filters--embedded/);

    const embedded = await renderFilters('embedded');
    assert.doesNotMatch(embedded, /waterline-schedule-query|Schedule visibility query/);
    assert.match(embedded, /id="waterline-schedule-type"/);
    assert.match(embedded, /value="deleted"/);
    assert.match(embedded, /schedule-view__filters--embedded/);

    const {state, requests} = fixture('embedded');
    Object.assign(state, {workflowTypeFilter: 'orders.process', visibilityQueryFilter: 'ScheduleId = "nightly"'});
    const load = state.applyFilters();
    assert.deepEqual(requests[0].options.params, {page: 1, workflow_type: 'orders.process'});
    requests[0].resolve(response('embedded'));
    await load;
});
