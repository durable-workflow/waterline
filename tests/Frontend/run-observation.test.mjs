import assert from 'node:assert/strict'
import fs from 'node:fs'
import vm from 'node:vm'
import test from 'node:test'
import { appendRunHistoryPage } from '../../resources/js/run-history.mjs'
import { createWaterlineI18n } from '../../resources/js/localization.mjs'

const source = fs.readFileSync(new URL('../../resources/js/screens/flows/flow.vue', import.meta.url), 'utf8')
const script = source.match(/<script[^>]*>([\s\S]*?)<\/script>/)[1]
    .replace(/^import .*$/gm, '').replace('export default', 'globalThis.component =')

function loadComponent(backend = 'embedded') {
    const sandbox = {
        Waterline: { basePath: '/waterline', backend: { mode: backend } },
        TimelineEventRenderer: {}, SearchAttributeRenderer: {}, CancellationCascadeView: {},
        appendRunHistoryPage,
    }
    vm.runInNewContext(script, sandbox)
    return sandbox.component
}

function detail(overrides = {}, backend = 'embedded') {
    const component = loadComponent(backend)
    const context = {
        $t: createWaterlineI18n('en').global.t,
        flow: {}, historyLimit: 200, historyPageSize: 200, historyPageRequest: 0,
        series: [{ data: [] }, { data: [] }],
        $route: { name: 'flow-detail-run', params: { instanceId: 'order', runId: 'run' }, query: {} },
        ...overrides,
    }
    for (const [name, method] of Object.entries(component.methods)) {
        if (!(name in context)) context[name] = method.bind(context)
    }
    return context
}

const run = { instance_id: 'order', selected_run_id: 'run', run_id: 'run', read_mode: 'bounded', engine_source: 'v2' }

test('localized chart tooltips preserve diagnostic data as escaped text', () => {
    const context = detail({ $t: createWaterlineI18n('uk').global.t })
    const chart = loadComponent().data.call(context).chartOptions
    const event = { type: '<img src=x onerror=alert(1)>', x: '<script>alert(2)</script>', y: [10, 20], diagnostic_only: true }
    const before = structuredClone(event)
    const html = chart.tooltip.custom({ seriesIndex: 0, dataPointIndex: 0, w: { globals: { initialSeries: [{ data: [event] }] } } })
    assert.ok(html.includes('<b>Час</b>: 10ms'))
    assert.ok(html.includes('лише для діагностики'))
    assert.ok(html.includes('&lt;img'))
    assert.ok(html.includes('&lt;script&gt;'))
    assert.ok(!html.includes('<img') && !html.includes('<script>'))
    assert.deepEqual(event, before)
})

test('initial embedded and service selections stay bounded, while full inspection remains explicit', () => {
    const embedded = detail()
    const endpoint = '/waterline/api/instances/order/runs/run'
    assert.equal(embedded.withHistoryLimit(endpoint), endpoint + '?history_limit=200&observation=bounded')
    assert.equal(embedded.withHistoryLimit(endpoint + '?history_page_token=opaque'),
        endpoint + '?history_page_token=opaque&history_limit=200&observation=bounded')
    assert.equal(embedded.withHistoryLimit(endpoint + '?observation=complete'),
        endpoint + '?observation=complete&history_limit=200')
    assert.equal(detail({}, 'service').withHistoryLimit(endpoint), endpoint + '?history_limit=200&observation=bounded')
    assert.equal(embedded.withHistoryLimit('/waterline/api/flows/legacy'), '/waterline/api/flows/legacy?history_limit=200')
})

test('bounded observation never grants command authority and exports the selected run even when the stored current pointer changes', () => {
    const context = detail({ flow: { ...run, is_current_run: true, can_query: true, can_repair: true } })
    assert.equal(context.canAction('query', true), false)
    assert.equal(context.canAction('repair'), false)
    assert.equal(context.historyExportEndpoint(), '/waterline/api/instances/order/runs/run/history-export')
})

test('explicit deep inspection bookmarks remain usable while failure-event bookmarks preserve bounded reads', async () => {
    const requests = []
    const context = detail({ fetchFlow: async (path) => requests.push(path) })
    context.$route.hash = '#workflowStreams'
    await context.loadCanonicalFlow('order', 'run')
    assert.equal(requests.at(-1), '/waterline/api/instances/order/runs/run?observation=complete')
    context.$route.hash = '#history-event-1002'
    context.$route.query.history_page_token = 'opaque-failure'
    await context.loadCanonicalFlow('order', 'run')
    assert.equal(requests.at(-1), '/waterline/api/instances/order/runs/run?history_page_token=opaque-failure')
    assert.ok(context.withHistoryLimit(requests.at(-1)).endsWith('&observation=bounded'))
})

test('related child outcomes and wait timing render independently of the completed parent', () => {
    const context = detail({ flow: {
        ...run, status: 'completed',
        current_waits: [{ id: 'wait', kind: 'activity_retry', reason: 'Retry scheduled', state: 'planned', next_scheduled_resume_at: '2026-10-07T00:00:00Z' }],
        relationships: { children: { returned_count: 2, has_more: true, relationships: [
            { link_id: 'one', instance_id: 'child-one', run_id: 'failed-child', status: 'failed' },
            { link_id: 'two', instance_id: 'child-two', run_id: 'running-child', status: 'running' },
        ] } },
    } })
    assert.equal(context.lineageEntries().map(({ status }) => status).join(','), 'failed,running')
    assert.equal(context.relationshipWindowSummary(), '2 children shown, more available in full details')
    assert.equal(context.waitRows()[0].current_summary.state, 'planned')
    assert.equal(context.waitRows()[0].current_summary.next_scheduled_resume_at, '2026-10-07T00:00:00Z')
    assert.equal(context.flow.status, 'completed')
})

test('history pages retain earlier events and full inspection remains explicit on subsequent loads', async () => {
    const requests = []
    const context = detail({
        flow: { ...run, timeline: [{ sequence: 1 }], history_next_page_token: 'opaque-original-boundary' },
        $http: { get: async (path) => {
            requests.push(path)
            return { data: { ...run, timeline: [{ sequence: 2 }], history_next_page_token: null } }
        } },
    })
    assert.equal(await context.loadOlderHistory(), true)
    assert.equal(context.flow.timeline.map(({ sequence }) => sequence).join(','), '1,2')
    assert.ok(requests[0].includes('history_page_token=opaque-original-boundary'))
    assert.ok(requests[0].endsWith('&observation=bounded'))
    context.fetchFlow = async (path) => requests.push(path)
    await context.loadCompleteDetails()
    assert.equal(requests.at(-1), '/waterline/api/instances/order/runs/run?observation=complete')
    context.flow = { ...run, read_mode: undefined, timeline_total_count: 500 }
    await context.loadOlderHistory()
    assert.equal(requests.at(-1), '/waterline/api/instances/order/runs/run?observation=complete')
})

test('late initial selection responses cannot overwrite the newly selected run', async () => {
    const pending = []
    const context = detail({
        $http: { get: (path) => new Promise((resolve) => pending.push({ path, resolve })) },
        resetHistoryVirtualWindow() {}, scrollToRouteHash() {},
    })
    const first = context.fetchFlow('/waterline/api/instances/order/runs/old')
    const second = context.fetchFlow('/waterline/api/instances/order/runs/new')
    pending[1].resolve({ data: { ...run, selected_run_id: 'new' } })
    assert.equal(await second, true)
    pending[0].resolve({ data: { ...run, selected_run_id: 'old' } })
    assert.equal(await first, false)
    assert.equal(context.flow.selected_run_id, 'new')
    assert.equal(context.ready, true)
})
