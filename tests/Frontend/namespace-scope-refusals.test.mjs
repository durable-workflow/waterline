import assert from 'node:assert/strict';
import fs from 'node:fs';
import test from 'node:test';
import { createSSRApp } from 'vue';
import { renderToString } from '@vue/server-renderer';
import { createWaterlineI18n } from '../../resources/js/localization.mjs';
import Base from '../../resources/js/base.js';
import { namespaceScopeRefusals } from '../../resources/js/namespace-scope-refusals.mjs';
import { operatorPermission } from '../../resources/js/operator-permissions.mjs';
import * as dispatch from '../../resources/js/activity-dispatch.mjs';

const source = fs.readFileSync(new URL('../../resources/js/components/WorkerHealth.vue', import.meta.url), 'utf8');

// The component's own sections, cut at their fixed SFC delimiters.
function section(open, close) {
    const start = source.indexOf(open) + open.length;
    return source.slice(start, source.indexOf(close, start));
}

const script = section('<script>\n', '\n</script>');
const template = section('<template>\n', '\n</template>\n\n<script>');
const component = Function('axios', 'Swal', 'namespaceScopeRefusals', 'escapeDialogHtml', 'showDialogValidationText', 'operatorPermission', 'dispatch',
    script.replace(/^import .*;$/gm, '').replace('export default', 'return'))({}, {}, namespaceScopeRefusals, String, () => {}, operatorPermission, dispatch);

const XSS = '<img src=x onerror=alert(1)>';

function refusalCheck(overrides = {}) {
    return {
        name: 'namespace_scope_refusals',
        status: 'warning',
        category: 'correctness',
        message: 'This app refused work in namespaces outside its allowlist in the last 15 minutes.',
        data: {
            total: 9,
            by_path: { job: 7, repair: 2, timeout: 0, schedule_tick: 0 },
            namespaces: [
                { namespace: 'billing', path: 'job', count: 7, last_seen_at: '2026-11-13T10:14:00.000000Z' },
                { namespace: 'reports', path: 'repair', count: 2, last_seen_at: '2026-11-13T10:12:00.000000Z' },
            ],
            window_seconds: 900,
            window_started_at: '2026-11-13T10:00:00.000000Z',
            truncated: false,
            available: true,
            scope: { all: false, namespaces: ['orders'] },
            ...overrides,
        },
    };
}

async function render(checks, locale = 'en') {
    const healthData = { status: 'warning', checks, backend: { mode: 'embedded' } };
    return renderToString(createSSRApp({
        ...component,
        template,
        data() {
            return { ...component.data.call(this), loading: false, healthData, workers: [] };
        },
    }).mixin(Base).use(createWaterlineI18n(locale)));
}

test('a warning check lists refusals by namespace and path', async () => {
    const refusals = namespaceScopeRefusals(refusalCheck());
    assert.equal(refusals.total, 9);
    assert.deepEqual(refusals.byPath.map((entry) => [entry.path, entry.count]), [['job', 7], ['repair', 2]]);
    assert.deepEqual(refusals.rows.map((row) => [row.namespace, row.path, row.count]), [['billing', 'job', 7], ['reports', 'repair', 2]]);
    assert.equal(refusals.windowMinutes, 15);
    assert.deepEqual(refusals.servedNamespaces, ['orders']);

    const html = await render([refusalCheck()]);
    assert.match(html, /data-check-detail="namespace_scope_refusals"/);
    assert.match(html, /Queued job: 7/);
    assert.match(html, /Repair pass: 2/);
    assert.doesNotMatch(html, /Timeout pass:/);
    assert.match(html, /<td class="worker-health__refusal-namespace">billing<\/td><td>Queued job<\/td><td>7<\/td>/);
    assert.match(html, /<td class="worker-health__refusal-namespace">reports<\/td><td>Repair pass<\/td><td>2<\/td>/);
    assert.match(html, /in the last 15 minutes/);
    assert.match(html, /This app serves: orders\. Add each namespace it should serve to DW_V2_NAMESPACES/);
    assert.doesNotMatch(html, /More namespace and path pairs/);
});

test('an ok check, another check or a check without data shows no table', async () => {
    assert.equal(namespaceScopeRefusals({ ...refusalCheck(), status: 'ok' }), null);
    assert.equal(namespaceScopeRefusals({ ...refusalCheck(), name: 'worker_compatibility' }), null);
    assert.equal(namespaceScopeRefusals({ name: 'namespace_scope_refusals', status: 'warning', message: 'older Workflow' }), null);

    const html = await render([
        { ...refusalCheck(), status: 'ok', message: 'No work outside this app\'s namespaces was refused in the last 15 minutes.' },
        { name: 'worker_compatibility', status: 'warning', category: 'correctness', message: 'x', data: refusalCheck().data },
    ]);
    assert.match(html, /No work outside this app/);
    assert.doesNotMatch(html, /data-check-detail/);
    assert.doesNotMatch(html, /billing/);
});

test('truncation, an unreadable cache, unknown paths and an all-namespace scope are explained', async () => {
    const check = refusalCheck({
        by_path: { job: 1, future_path: 3 },
        namespaces: [
            { namespace: 'billing', path: 'future_path', count: 3, last_seen_at: null },
            { namespace: '', path: 'job', count: 1 },
            { namespace: 'zero', path: 'job', count: 0 },
            'not a row',
        ],
        truncated: true,
        available: false,
        scope: { all: true, namespaces: null },
    });
    const refusals = namespaceScopeRefusals(check);
    assert.deepEqual(refusals.byPath.map((entry) => [entry.path, entry.label]), [['job', 'Queued job'], ['future_path', null]]);
    assert.deepEqual(refusals.rows.map((row) => row.namespace), ['billing']);
    assert.equal(refusals.servedNamespaces, null);

    const html = await render([check]);
    assert.match(html, /future_path: 3/);
    assert.match(html, /<td>future_path<\/td><td>3<\/td><td>—<\/td>/);
    assert.match(html, /More namespace and path pairs were refused than are listed/);
    assert.match(html, /could not be read from the cache/);
    assert.doesNotMatch(html, /This app serves/);
});

test('namespace names, paths and the check message render as text, never HTML', async () => {
    const check = refusalCheck({
        by_path: { [XSS]: 1 },
        namespaces: [{ namespace: XSS, path: XSS, count: 1, last_seen_at: XSS }],
        scope: { all: false, namespaces: [XSS] },
    });
    check.message = XSS;

    const html = await render([check]);
    assert.doesNotMatch(html, /<img/);
    assert.ok(html.includes('&lt;img src=x onerror=alert(1)&gt;'));
});

test('the table is translated', async () => {
    const html = await render([refusalCheck()], 'uk');
    assert.match(html, /Завдання в черзі: 7/);
    assert.match(html, /Простір імен/);
    assert.match(html, /Цей застосунок обслуговує: orders/);
});

test('redacted refusals show the counts and say why the names are hidden', async () => {
    const check = refusalCheck({ namespaces: [], scope: { all: false, namespaces: null }, namespaces_redacted: true });
    const refusals = namespaceScopeRefusals(check);
    assert.equal(refusals.redacted, true);
    assert.deepEqual(refusals.rows, []);

    const html = await render([check]);
    assert.match(html, /Queued job: 7/);
    assert.match(html, /Namespace names are hidden because this Waterline is open without sign-in/);
    assert.doesNotMatch(html, /<table/);
    assert.doesNotMatch(html, /This app serves/);
});
