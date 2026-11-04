import assert from 'node:assert/strict';
import fs from 'node:fs';
import test from 'node:test';
import {parse} from '@vue/compiler-sfc';

const source = fs.readFileSync(new URL('../../resources/js/components/ScheduleView.vue', import.meta.url), 'utf8');
const script = parse(source).descriptor.script.content;

function fixture({mode = 'embedded', autoRefresh = true} = {}) {
    const requests = [];
    const axios = {get: (url, options) => new Promise((resolve, reject) => requests.push({url, options, resolve, reject}))};
    const component = Function('axios', script.replace(/^import[\s\S]*?from ['"][^'"]+['"];$/gm, '').replace('export default', 'return'))(axios);
    const state = {
        ...component.data(), ...component.methods, serviceMode: mode === 'service',
        $t: value => value, $root: {autoLoadsNewEntries: autoRefresh},
        resolvedApiEndpoint: () => '/waterline/api/v2/schedules',
    };
    state.unmount = component.unmounted.bind(state);
    return {state, requests};
}

const page = (status, outcome) => ({data: {
    data: [{schedule_id: 'nightly', status, last_result: {outcome}}],
    current_page: 2, last_page: 3, per_page: 50, total: 120,
}});

test('an auto refresh shows an outside pause and new outcome while filters, page and open dialogs stay', async () => {
    const {state, requests} = fixture();
    Object.assign(state, {appliedStatus: 'active', appliedWorkflowType: 'orders.nightly', currentPage: 2});
    const first = state.loadData();
    requests[0].resolve(page('active', 'started'));
    await first;
    Object.assign(state, {showDetail: true, detailSchedule: state.schedules[0], showBackfill: true, backfillFrom: '2026-11-01T00:00'});

    assert.equal(state.refreshPeriodically(), true);
    assert.deepEqual(requests[1].options.params, requests[0].options.params);
    assert.equal(state.loading, false, 'rows stay on screen during a refresh');
    requests[1].resolve(page('paused', 'failed_to_dispatch'));
    await Promise.resolve();
    await Promise.resolve();

    assert.equal(state.schedules[0].status, 'paused');
    assert.equal(state.schedules[0].last_result.outcome, 'failed_to_dispatch');
    assert.equal(state.currentPage, 2);
    assert.equal(state.appliedWorkflowType, 'orders.nightly');
    assert.equal(state.showDetail, true);
    assert.equal(state.showBackfill, true);
    assert.equal(state.backfillFrom, '2026-11-01T00:00');
});

test('a refresh never overlaps a read or a pending row action and honors Auto refresh', async () => {
    const {state, requests} = fixture();
    const first = state.loadData();
    assert.equal(state.refreshPeriodically(), false, 'first load still in flight');
    requests[0].resolve(page('active', 'started'));
    await first;

    assert.equal(state.refreshPeriodically(), true);
    assert.equal(state.refreshPeriodically(), false, 'previous refresh still in flight');
    assert.equal(requests.length, 2);
    requests[1].resolve(page('active', 'started'));
    await Promise.resolve();
    await Promise.resolve();

    state.pendingRowKeys = {nightly: true};
    assert.equal(state.refreshPeriodically(), false);
    state.pendingRowKeys = {nightly: false};
    state.$root.autoLoadsNewEntries = false;
    assert.equal(state.refreshPeriodically(), false);
    assert.equal(requests.length, 2);
});

test('a failed refresh stays visible and the next one recovers', async () => {
    const {state, requests} = fixture();
    const first = state.loadData();
    requests[0].resolve(page('active', 'started'));
    await first;
    state.refreshPeriodically();
    requests[1].reject({response: {data: {message: 'Server unavailable'}}});
    await new Promise(resolve => setImmediate(resolve));
    assert.equal(state.error, 'Server unavailable');
    assert.equal(state.schedules[0].schedule_id, 'nightly', 'rows from the last good read stay');
    state.refreshPeriodically();
    requests[2].resolve(page('paused', 'started'));
    await new Promise(resolve => setImmediate(resolve));
    assert.equal(state.error, null);
    assert.equal(state.schedules[0].status, 'paused');
});

test('embedded mode refreshes on the list cadence, service mode more slowly, and unmount stops it', () => {
    const timers = [];
    const cleared = [];
    const original = {set: globalThis.setInterval, clear: globalThis.clearInterval};
    globalThis.setInterval = (callback, milliseconds) => (timers.push({callback, milliseconds}), timers.length);
    globalThis.clearInterval = id => cleared.push(id);
    try {
        const embedded = fixture().state;
        embedded.startPeriodicRefresh();
        embedded.startPeriodicRefresh();
        const service = fixture({mode: 'service'}).state;
        service.startPeriodicRefresh();
        assert.deepEqual(timers.map(timer => timer.milliseconds), [3000, 30000]);
        embedded.unmount();
        assert.deepEqual(cleared, [1]);
        assert.equal(embedded.refreshTimer, null);
        embedded.startPeriodicRefresh();
        assert.equal(timers.length, 2, 'no timer starts after unmount');
    } finally {
        globalThis.setInterval = original.set;
        globalThis.clearInterval = original.clear;
    }
});
