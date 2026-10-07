import assert from 'node:assert/strict';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { createRequire } from 'node:module';
import { execFileSync } from 'node:child_process';
import { pathToFileURL } from 'node:url';

export function dashboardFixture() {
    const timestamp = '2026-10-07T12:34:56.123456+00:00';
    const diagnostic = 'projection_checkpoint_missing_for_run_'.repeat(5);
    return {
        flows: 12345678, flows_past_hour: 1234567, flows_per_minute: 1234.5,
        exceptions_past_hour: 2345, failed_flows_past_week: 34567,
        operator_scope: { label: 'orders-'.repeat(12) },
        time_windows: { generated_at: timestamp },
        classification_scope: { available: true, label: 'All workflow types', options: [] },
        fleet_overview: { current: { running: 1234567, failed: 234567, completed: 3456789 }, trends: { hour: { completed: 1234567, failed: 234567 }, day: { completed: 2345678, failed: 345678 }, week: { completed: 3456789, failed: 456789 } } },
        fleet_trends_series: { timestamps: ['2026-10-06T12:00:00Z', '2026-10-07T12:00:00Z'], completed: [1234, 2345], failed: [12, 23] },
        workflow_type_health: [{ workflow_type: 'Company\\Orders\\' + 'FulfilOrder'.repeat(12), total_runs: 1234567, pass_rate: 98.5, median_duration_ms: 123456, error_count: 23456 }],
        needs_attention: { total_alerts: 1, has_critical: true, alerts: [{ type: 'projection', severity: 'warning', message: diagnostic, action: 'Inspect the run history and repair the missing projection.' }] },
        operator_metrics: {
            generated_at: timestamp,
            backend: { supported: false, database: { connection: 'workflow_postgresql_connection', driver: 'pgsql' }, cache: { store: 'workflow_cache_store', driver: 'redis' }, issues: [{ summary: diagnostic }] },
            backlog: { runnable_tasks: 1234567, unhealthy_tasks: 234567, delayed_tasks: 345678, leased_tasks: 456789, repair_needed_runs: 567890, claim_failed_runs: 678901, compatibility_blocked_runs: 789012 },
            tasks: { dispatch_overdue: 123456, lease_expired: 234567, max_ready_due_age_ms: 3600000, oldest_ready_due_at: timestamp, max_unhealthy_age_ms: 7200000, oldest_unhealthy_at: timestamp },
            runs: { waiting: 1234567, max_wait_age_ms: 3600000, oldest_wait_started_at: timestamp },
            workers: { active_workers: 1234, active_worker_scopes: 567, fleet: [{ worker_id: 'orders-worker-'.repeat(8), connection: 'workflow_postgresql', queue: 'orders-'.repeat(12), supported: [1, 2, 3], required: 3, recorded_at: timestamp, source: 'compatibility_heartbeat' }] },
            starts: { pending_runs: 123456, pending_commands: 234567, ready_tasks: 345678, max_pending_ms: 3600000 },
            projections: { run_summaries: { summaries: 1234567, runs: 2345678, missing: 123, orphaned: 234, stale: 345, max_missing_run_age_ms: 3600000, oldest_missing_run_started_at: timestamp } },
            structural_limits: { long_diagnostic_identifier: diagnostic },
        },
    };
}

// Check painted text against its own tile and every clipping ancestor. A page
// can have no horizontal scrollbar while a card silently hides its contents.
export async function dashboardGeometry(page) {
    return page.evaluate(() => {
        const failures = [];
        const walker = document.createTreeWalker(document.querySelector('.wl-app-shell'), NodeFilter.SHOW_TEXT);
        let node;
        while ((node = walker.nextNode())) {
            if (!node.textContent.trim()) continue;
            const element = node.parentElement;
            if (!element.closest('.wl-dashboard-view, .wl-topbar') || element.closest('svg, .apexcharts-canvas, .table-responsive, .wl-topbar__scope-value') || !element.checkVisibility({ checkOpacity: true, checkVisibilityCSS: true })) continue;
            const range = document.createRange();
            range.selectNodeContents(node);
            const rects = [...range.getClientRects()].filter(rect => rect.width && rect.height);
            const tile = element.closest('.wl-operator-metric, .wl-overview-tile, .wl-summary-card, .wl-topbar__button');
            for (let ancestor = element; ancestor; ancestor = ancestor.parentElement) {
                const style = getComputedStyle(ancestor);
                if (ancestor !== tile && !['hidden', 'clip'].includes(style.overflowX)) continue;
                const bounds = ancestor.getBoundingClientRect();
                if (rects.some(rect => rect.left < bounds.left - 2 || rect.right > bounds.right + 2)) {
                    failures.push({ text: node.textContent.trim(), container: ancestor.className, width: bounds.width });
                    break;
                }
            }
        }
        for (const control of document.querySelectorAll('.wl-topbar__actions > *, .wl-screen-hero__actions > *')) {
            const rect = control.getBoundingClientRect();
            if (rect.left < -1 || rect.right > innerWidth + 1) failures.push({ text: control.textContent.trim(), container: 'viewport' });
        }
        for (const chart of document.querySelectorAll('.apexcharts-canvas')) {
            const rect = chart.getBoundingClientRect(), card = chart.closest('.card').getBoundingClientRect();
            if (rect.left < card.left - 2 || rect.right > card.right + 2) failures.push({ container: 'chart', width: rect.width });
        }
        if (document.documentElement.scrollWidth > innerWidth + 1) failures.push({ container: 'document', width: document.documentElement.scrollWidth });
        return { viewport: { width: innerWidth, height: innerHeight, dpr: devicePixelRatio }, mainWidth: document.querySelector('.wl-main').clientWidth, failures };
    });
}

function loadPlaywright() {
    for (const root of [process.cwd(), execFileSync('npm', ['root', '--global'], { encoding: 'utf8' }).trim()]) {
        try { return createRequire(path.join(root, 'package.json'))('playwright'); } catch { /* Try the next installed location. */ }
    }
    throw new Error('Install Playwright and Chromium before dashboard qualification.');
}

export async function runDashboardVisual({ baseUrl, serviceBaseUrl, outputDirectory, email = 'demo@example.com', password = 'password', widths = [1280, 1366, 1440, 1920], zooms = [1, 1.25, 1.5, 2] }) {
    assert.ok(serviceBaseUrl, 'Qualify both embedded and service presentations.');
    fs.mkdirSync(outputDirectory, { recursive: true });
    const scratch = fs.mkdtempSync(path.join(os.tmpdir(), 'waterline-dashboard-'));
    const extension = path.join(scratch, 'zoom');
    fs.mkdirSync(extension);
    fs.writeFileSync(path.join(extension, 'manifest.json'), JSON.stringify({ manifest_version: 3, name: 'Dashboard zoom qualification', version: '1.0', permissions: ['tabs'], background: { service_worker: 'worker.js' } }));
    fs.writeFileSync(path.join(extension, 'worker.js'), 'chrome.runtime.onInstalled.addListener(() => {});');
    const { chromium } = loadPlaywright();
    const context = await chromium.launchPersistentContext(path.join(scratch, 'profile'), {
        channel: 'chromium', headless: true, viewport: null,
        ...(process.env.CHROMIUM_EXECUTABLE_PATH ? { executablePath: process.env.CHROMIUM_EXECUTABLE_PATH } : {}),
        args: ['--no-sandbox', '--window-size=1440,900', `--disable-extensions-except=${extension}`, `--load-extension=${extension}`],
    });
    const reports = [];
    try {
        const worker = context.serviceWorkers()[0] || await context.waitForEvent('serviceworker', { timeout: 30000 });
        for (const [presentation, origin] of [['embedded', baseUrl], ['service', serviceBaseUrl]]) for (const locale of ['en', 'uk']) {
            const page = await context.newPage();
            const errors = [], writes = [];
            page.on('pageerror', error => errors.push(error.message));
            page.on('response', response => { if (response.status() >= 400) errors.push(`${response.status()} ${response.url()}`); });
            await page.route('**/waterline/api/**', async route => {
                if (route.request().method() !== 'GET') writes.push(route.request().method() + ' ' + route.request().url());
                if (new URL(route.request().url()).pathname.endsWith('/stats')) await route.fulfill({ json: dashboardFixture() });
                else await route.continue();
            });
            await page.route('**/waterline/dashboard', async route => {
                const response = await route.fetch({ maxRedirects: 0 });
                if (response.status() >= 300 && response.status() < 400) {
                    await route.fulfill({ response });
                    return;
                }
                let body = await response.text();
                body = body.replace(/data-waterline-config="([^"]+)"/, (_, encoded) => {
                    const config = JSON.parse(encoded.replaceAll('&quot;', '"').replaceAll('&amp;', '&'));
                    config.locale = locale;
                    config.app_name = 'Customer operations dashboard';
                    config.operator_scope = { ...config.operator_scope, mode: 'namespace', namespace: 'orders-'.repeat(12) };
                    return `data-waterline-config="${JSON.stringify(config).replaceAll('&', '&amp;').replaceAll('"', '&quot;').replaceAll('<', '&lt;')}"`;
                });
                await route.fulfill({ response, body });
            });
            const target = new URL('/waterline/dashboard', origin).href;
            await page.goto(target, { waitUntil: 'networkidle' });
            if (/\/login(?:$|\?)/.test(page.url())) {
                await page.fill('input[name=email]', email);
                await page.fill('input[name=password]', password);
                await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle' }), page.click('button[type=submit], input[type=submit]')]);
                await page.goto(target, { waitUntil: 'networkidle' });
            }
            await page.locator('.wl-operator-metrics-grid').waitFor();
            const tab = await worker.evaluate(async url => (await chrome.tabs.query({})).find(tab => tab.url === url), page.url());
            assert.ok(tab, 'The zoom extension must identify the actual dashboard tab.');
            const cdp = await context.newCDPSession(page);
            const { windowId } = await cdp.send('Browser.getWindowForTarget');
            for (const width of widths) for (const zoom of zooms) {
                const name = `${presentation}-${locale}-${width}-${Math.round(zoom * 100)}`;
                await cdp.send('Browser.setWindowBounds', { windowId, bounds: { width, height: 900, windowState: 'normal' } });
                await worker.evaluate(async ({ id, zoom }) => { await chrome.tabs.setZoomSettings(id, { mode: 'automatic', scope: 'per-tab' }); await chrome.tabs.setZoom(id, zoom); }, { id: tab.id, zoom });
                await page.waitForFunction(width => Math.abs(innerWidth - width) <= 2, width / zoom);
                await page.evaluate(() => new Promise(resolve => requestAnimationFrame(() => requestAnimationFrame(resolve))));
                // ApexCharts redraws asynchronously after resize. Bound that
                // settling period, then measure even if a chart stays too wide.
                await page.waitForFunction(() => [...document.querySelectorAll('.apexcharts-canvas')].every(chart => {
                    const rect = chart.getBoundingClientRect(), card = chart.closest('.card').getBoundingClientRect();
                    return rect.left >= card.left - 2 && rect.right <= card.right + 2;
                }), null, { timeout: 3000 }).catch(() => {});
                const geometry = await dashboardGeometry(page);
                const actualZoom = await worker.evaluate(id => chrome.tabs.getZoom(id), tab.id);
                assert.equal(actualZoom, zoom);
                if (!geometry.failures.length) {
                    for (const button of await page.locator('.wl-topbar__button').all()) {
                        await button.click();
                        await button.click();
                    }
                    const refreshed = page.waitForResponse(response => new URL(response.url()).pathname.endsWith('/stats'));
                    await page.locator('.wl-screen-hero__actions button').click();
                    await refreshed;
                    await page.locator('.wl-operator-metrics-grid').waitFor();
                    for (const table of await page.locator('.wl-dashboard-view .table-responsive').all()) {
                        assert.equal(await table.getAttribute('tabindex'), '0');
                        assert.ok(await table.getAttribute('aria-label'));
                        await table.focus();
                        assert.equal(await table.evaluate(element => document.activeElement === element), true);
                        const scroll = await table.evaluate(element => {
                            element.scrollLeft = element.scrollWidth;
                            const bounds = element.getBoundingClientRect();
                            const lastCell = element.querySelector('tr:last-child > :last-child').getBoundingClientRect();
                            return { rightEdgeReachable: lastCell.right <= bounds.right + 2, width: element.clientWidth, scrollWidth: element.scrollWidth };
                        });
                        assert.equal(scroll.rightEdgeReachable, true, 'The last table column must remain reachable.');
                        await table.evaluate(element => { element.scrollLeft = 0; });
                    }
                    const scope = page.locator('.wl-topbar__scope-value').first();
                    assert.equal(await scope.getAttribute('title'), await scope.textContent());
                    await page.locator('.wl-sidebar__link[href$="/dashboard"]').click();
                    await page.evaluate(() => scrollTo(0, document.documentElement.scrollHeight));
                    assert.equal(await page.evaluate(() => scrollY > 0), true, 'Vertical scrolling must reach the full dashboard.');
                    await page.evaluate(() => scrollTo(0, 0));
                }
                reports.push({ name, presentation, locale, windowWidth: width, zoom: actualZoom, ...geometry, errors: [...errors], writes: [...writes] });
                console.log(JSON.stringify(reports.at(-1)));
                if ((width === 1440 && zoom === 1) || zoom === 2) await page.screenshot({ path: path.join(outputDirectory, `${name}.png`), fullPage: true });
            }
            await page.close();
        }
    } finally {
        await context.close();
        fs.rmSync(scratch, { recursive: true, force: true });
    }
    fs.writeFileSync(path.join(outputDirectory, 'summary.json'), JSON.stringify({ boundary: 'Production UI with synthetic dashboard observations and Chromium page zoom', cases: reports }, null, 2));
    assert.equal(reports.length, 4 * widths.length * zooms.length);
    assert.deepEqual(reports.filter(report => report.failures.length || report.errors.length || report.writes.length), [], 'Dashboard content must remain visible and contained.');
}

if (process.argv[1] && pathToFileURL(path.resolve(process.argv[1])).href === import.meta.url) {
    const value = (name, fallback) => { const index = process.argv.indexOf(name); return index < 0 ? fallback : process.argv[index + 1]; };
    await runDashboardVisual({ baseUrl: value('--base-url', process.env.APP_URL), serviceBaseUrl: value('--service-base-url', process.env.WATERLINE_SERVICE_VISUAL_URL), outputDirectory: path.resolve(value('--output-dir', 'dashboard-evidence')), email: process.env.WATERLINE_VISUAL_EMAIL, password: process.env.WATERLINE_VISUAL_PASSWORD });
}
