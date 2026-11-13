// Exercises the compiled UI against a synthetic HTTP boundary. PHP/API and
// published-runtime acceptance establish the separate producer authority.
import assert from 'node:assert/strict';
import crypto from 'node:crypto';
import fs from 'node:fs';
import http from 'node:http';
import {createRequire} from 'node:module';
import path from 'node:path';
import {fileURLToPath} from 'node:url';

const root = fileURLToPath(new URL('../../', import.meta.url));
const require = createRequire(path.join(process.cwd(), 'package.json'));
const {chromium} = require('playwright');
const index = process.argv.indexOf('--output-dir');
const output = path.resolve(index === -1 ? 'schedule-refresh-evidence' : process.argv[index + 1]);
const assetsIndex = process.argv.indexOf('--assets-dir');
const assets = path.resolve(assetsIndex === -1 ? path.join(root, 'public') : process.argv[assetsIndex + 1]);
fs.mkdirSync(output, {recursive: true});
const report = {
    classification: 'compiled_ui_fixture',
    app_sha256: crypto.createHash('sha256').update(fs.readFileSync(path.join(assets, 'app.js'))).digest('hex'),
    node: process.version, playwright: require('playwright/package.json').version, cases: [], errors: [],
};
const states = new Map();
const escape = value => value.replace(/[&<>"']/g, character => ({'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[character]));
const server = http.createServer((request, response) => {
    const url = new URL(request.url, 'http://localhost');
    const name = request.headers.cookie?.match(/(?:^|;\s*)fixture=([^;]+)/)?.[1];
    const state = states.get(name);
    const json = (status, value) => {
        response.writeHead(status, {'Content-Type': 'application/json'});
        response.end(JSON.stringify(value));
    };
    if (url.pathname.startsWith('/assets/')) {
        const file = path.resolve(assets, url.pathname.slice('/assets/'.length));
        if (!file.startsWith(assets + path.sep) || !fs.existsSync(file) || !fs.statSync(file).isFile()) {
            response.writeHead(404).end(); return;
        }
        response.setHeader('Content-Type', file.endsWith('.js') ? 'text/javascript' : file.endsWith('.css') ? 'text/css' : 'image/png');
        fs.createReadStream(file).pipe(response); return;
    }
    if (!state) { json(400, {message: 'Unknown fixture'}); return; }
    if (request.method !== 'GET') {
        state.writes.push({method: request.method, path: url.pathname});
        json(405, {message: 'The refresh control does not accept writes'}); return;
    }
    if (url.pathname === '/waterline/api/preferences/schedules-list') { json(200, {}); return; }
    if (url.pathname === '/waterline/api/v2/schedules') {
        state.active++;
        state.maximumActive = Math.max(state.maximumActive, state.active);
        state.reads.push({at: Date.now(), params: Object.fromEntries(url.searchParams)});
        const page = Number(url.searchParams.get('page') || 1);
        const body = {data: [{
            schedule_id: 'nightly', namespace: 'orders', workflow_type: 'orders.nightly',
            status: state.status, spec: {cron_expressions: ['0 * * * *'], timezone: 'UTC'},
            last_outcome_state: 'known', buffered_actions_count: 0,
            last_outcome: {outcome: 'started', at: '2026-11-13T12:00:00.000001Z', reason: null},
            latest_workflow_instance_id: '01K9ZZEJF50000000000000001', current_run_state: 'known',
            current_run: {run_id: '01K9ZZEJF50000000000000002', status: state.runStatus,
                status_bucket: state.runStatus === 'failed' ? 'failed' : 'running', is_terminal: state.runStatus === 'failed'},
        }], current_page: page, last_page: 2, per_page: 50, total: 51,
            operator_scope: state.bootstrap.operator_scope, backend: state.bootstrap.backend, refresh_interval_ms: 3000,
            ...(state.mode === 'service' ? {page_start_token: page === 1 ? null : 'opaque-2', next_page_token: page === 1 ? 'opaque-2' : null} : {}),
        };
        const failure = state.failure;
        setTimeout(() => { state.active--; json(failure ? 503 : 200, failure ? {message: 'Fixture server unavailable'} : body); }, state.delay);
        return;
    }
    if (url.pathname === '/waterline/schedules') {
        response.setHeader('Content-Type', 'text/html; charset=utf-8');
        response.end(`<!doctype html><html lang="en"><head><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="csrf-token" content="synthetic"><link id="app-stylesheet" href="/assets/${state.theme === 'light' ? 'app.css' : 'app-dark.css'}" rel="stylesheet"><link href="/assets/components.css" rel="stylesheet"></head><body><div id="waterline" data-waterline-config="${escape(JSON.stringify(state.bootstrap))}" v-cloak></div><script type="module" src="/assets/app.js"></script></body></html>`);
        return;
    }
    response.writeHead(404).end();
});
await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
const base = `http://127.0.0.1:${server.address().port}`;
const browser = await chromium.launch({headless: true, args: ['--no-sandbox', '--disable-dev-shm-usage']});
report.browser = browser.version();

try {
    for (const mode of ['embedded', 'service']) {
        for (const [width, theme] of [[1440, 'dark'], [390, 'light']]) {
            const name = `${mode}-${width}`;
            const state = {mode, theme, status: 'active', runStatus: 'running', reads: [], writes: [], active: 0, maximumActive: 0, delay: 0, failure: false,
                bootstrap: {path: 'waterline', locale: 'en', app_name: 'Schedule refresh fixture', assets_current: true, maintenance: false,
                    operator_scope: {mode: 'namespace', namespace: 'orders'},
                    backend: {mode, namespace: 'orders', authentication: 'configured', access_mode: 'operator', capabilities: {schedule_visibility_query: true}},
                },
            };
            states.set(name, state);
            const context = await browser.newContext({viewport: {width, height: 900}});
            context.setDefaultTimeout(10000);
            await context.addCookies([{name: 'fixture', value: name, url: base}]);
            await context.addInitScript(theme => { localStorage.setItem('autoLoadsNewEntries', '1'); localStorage.setItem('waterline-theme', theme); }, theme);
            const page = await context.newPage();
            page.on('pageerror', error => report.errors.push(String(error)));
            await page.goto(base + '/waterline/schedules');
            const row = page.locator('tr[data-schedule-id="nightly"]');
            await row.waitFor();
            const filters = page.locator('.schedule-view__filters');
            await filters.getByLabel('Workflow Type', {exact: true}).fill('orders.nightly');
            await filters.getByRole('button', {name: 'Apply Filters', exact: true}).click();
            await page.locator('.schedule-view__pagination').getByRole('button', {name: 'Next', exact: true}).click();
            await page.waitForFunction(() => document.querySelector('.schedule-view__pagination .btn-primary')?.textContent.trim() === '2');
            await filters.getByLabel('Workflow Type', {exact: true}).fill('unapplied draft');
            await row.getByRole('button', {name: 'Backfill', exact: true}).click();
            const dialog = page.getByRole('dialog', {name: 'Backfill schedule', exact: true});
            await dialog.getByLabel('From', {exact: true}).fill('2026-11-01T00:00');
            await dialog.getByLabel('To', {exact: true}).fill('2026-11-02T00:00');
            const before = state.reads.length;
            state.status = 'paused';
            state.runStatus = 'failed';
            await row.locator('[data-row-action="resume"]').waitFor({state: 'attached', timeout: 7000});
            assert.match(await row.locator('[data-latest-run]').textContent(), /Failed/);
            assert.match(await row.locator('td[data-label="Last Result"]').textContent(), /Started/);
            assert.equal(await filters.getByLabel('Workflow Type', {exact: true}).inputValue(), 'unapplied draft');
            assert.equal(await dialog.getByLabel('From', {exact: true}).inputValue(), '2026-11-01T00:00');
            assert.equal(await dialog.getByLabel('To', {exact: true}).inputValue(), '2026-11-02T00:00');
            assert.equal(await dialog.isVisible(), true);
            for (const read of state.reads.slice(before)) {
                assert.equal(read.params.page, '2');
                assert.equal(read.params.workflow_type, 'orders.nightly');
                if (mode === 'service') assert.equal(read.params.page_token, 'opaque-2');
            }
            await page.screenshot({path: path.join(output, `${name}-${theme}.png`), fullPage: true});
            await page.keyboard.press('Escape');
            await dialog.waitFor({state: 'hidden'});
            state.status = 'active';
            await row.locator('[data-row-action="pause"]').waitFor({state: 'attached', timeout: 7000});
            await row.scrollIntoViewIfNeeded();
            assert.ok(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth + 1), 'latest-run display does not overflow the viewport');
            await row.screenshot({path: path.join(output, `${name}-${theme}-row.png`)});
            if (mode === 'service' && width === 1440) {
                const toggle = page.getByRole('button', {name: 'Auto refresh', exact: true});
                await toggle.click();
                const stoppedAt = state.reads.length;
                await page.waitForTimeout(3300);
                assert.equal(state.reads.length, stoppedAt, 'Auto refresh off issues no reads');
                const delayed = page.waitForRequest(request => new URL(request.url()).pathname === '/waterline/api/v2/schedules');
                state.delay = 4200;
                await toggle.click();
                await delayed;
                const delayedAt = state.reads.length;
                await page.waitForTimeout(3500);
                assert.equal(state.reads.length, delayedAt, 'slow reads do not overlap');
                assert.equal(state.maximumActive, 1);
                await page.waitForResponse(response => new URL(response.url()).pathname === '/waterline/api/v2/schedules');
                state.delay = 0;
                state.failure = true;
                await page.getByText('Fixture server unavailable', {exact: true}).waitFor({timeout: 7000});
                state.failure = false;
                await page.getByText('Fixture server unavailable', {exact: true}).waitFor({state: 'hidden', timeout: 7000});
            }
            assert.equal(state.maximumActive, 1);
            assert.deepEqual(state.writes, [], 'opening and preserving the form must not submit it');
            report.cases.push({mode, width, theme, reads: state.reads, maximum_active: state.maximumActive, form_preserved: true, dispatch: 'Started', latest_run: 'Failed'});
            await context.close();
        }
    }
    assert.deepEqual(report.errors, []);
    report.outcome = 'pass';
    console.log(`PASS: ${report.cases.length} compiled schedule refresh cases`);
} catch (error) {
    report.outcome = 'fail'; report.failure = String(error.stack || error); throw error;
} finally {
    fs.writeFileSync(path.join(output, 'schedule-refresh.json'), JSON.stringify(report, null, 2) + '\n');
    await browser.close();
    server.closeAllConnections();
    await new Promise(resolve => server.close(resolve));
}
