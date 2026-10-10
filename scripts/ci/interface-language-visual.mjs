import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { createRequire } from 'node:module';
import { dashboardFixture, dashboardGeometry } from './dashboard-visual.mjs';
import { runDetailFixture } from './run-detail-visual.mjs';
import { auditContrast } from './workflow-list-dialog-visual.mjs';

const localeIndex = process.argv.indexOf('--locale');
const locale = localeIndex > 0 ? process.argv[localeIndex + 1] : 'es';
const localeCases = {
    de: { hour: 'eine Stunde', month: /feb/i, firstDate: '2026-02-01T12:00:00Z',
        count: '1.234.567', rate: '1,5', percent: '98,5%' },
    es: { hour: 'una hora', month: /ago/i, firstDate: '2026-08-01T12:00:00Z' },
    fr: { hour: 'une heure', month: /févr|fév/i, firstDate: '2026-02-01T12:00:00Z',
        count: '1\u202f234\u202f567', rate: '1,5', percent: '98,5%' },
    'pt-BR': { hour: 'uma hora', month: /fev/i, firstDate: '2026-02-01T12:00:00Z' },
    ja: { hour: '1時間', month: /2月/, firstDate: '2026-02-01T12:00:00Z',
        count: '1,234,567', rate: '1.5', percent: '98.5%' },
    'zh-Hans': { hour: '1 小时', month: /二月|2月/, firstDate: '2026-02-01T12:00:00Z',
        count: '1,234,567', rate: '1.5', percent: '98.5%' },
};
assert.ok(Object.hasOwn(localeCases, locale), 'Choose a locale: de, es, fr, ja, pt-BR or zh-Hans.');
const localeCase = localeCases[locale];
const catalog = JSON.parse(fs.readFileSync(new URL(`../../resources/lang/${locale}.json`, import.meta.url)));
const text = key => catalog[key];
const instance = 'waterline-visual-instance';
const run = 'waterline-visual-run';
const outputIndex = process.argv.indexOf('--output-dir');
assert.ok(outputIndex > 0 && process.argv[outputIndex + 1], '--output-dir is required.');
const output = path.resolve(process.argv[outputIndex + 1]);
const origins = [
    ['embedded', process.env.WATERLINE_LOCALIZED_EMBEDDED_URL],
    ['service', process.env.WATERLINE_LOCALIZED_SERVICE_URL],
];
assert.ok(origins.every(([, origin]) => origin), 'Both localized deployment URLs are required.');
fs.mkdirSync(output, { recursive: true });
const { chromium } = createRequire(path.join(process.cwd(), 'package.json'))('playwright');
const browser = await chromium.launch({ args: ['--no-sandbox'],
    ...(process.env.CHROMIUM_EXECUTABLE_PATH ? { executablePath: process.env.CHROMIUM_EXECUTABLE_PATH } : {}),
});
const reports = [];

async function login(page, url) {
    await page.goto(url, { waitUntil: 'networkidle' });
    if (/\/login(?:$|\?)/.test(page.url())) {
        await page.fill('input[name=email]', process.env.WATERLINE_VISUAL_EMAIL || 'demo@example.com');
        await page.fill('input[name=password]', process.env.WATERLINE_VISUAL_PASSWORD || 'password');
        await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle' }),
            page.click('button[type=submit], input[type=submit]')]);
        await page.goto(url, { waitUntil: 'networkidle' });
    }
    await page.waitForFunction(() => document.getElementById('waterline')?.dataset.waterlineMounted === 'true');
    assert.equal(await page.locator('html').getAttribute('lang'), locale);
    assert.equal(await page.locator('#waterline').evaluate(element =>
        JSON.parse(element.getAttribute('data-waterline-config')).locale), locale);
    await page.getByRole('link', { name: text('Skip to main content'), exact: true }).waitFor({ state: 'attached' });
}

async function snapshot(page, name, observations = {}) {
    assert.ok(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1),
        `${name}: the page must fit the viewport`);
    await page.screenshot({ path: path.join(output, `${name}.png`), fullPage: !name.endsWith('-dialog') });
    reports.push({ name, status: 'pass', ...observations });
}

try {
    for (const [presentation, origin] of origins) for (const theme of ['light', 'dark'])
        for (const width of [390, 1440]) {
            const context = await browser.newContext({ viewport: { width, height: 900 } });
            await context.addInitScript(value => localStorage.setItem('waterline-theme', value), theme);
            const page = await context.newPage();
            // A fast API response must not let charts measure the temporary
            // layout while the user's alternate theme stylesheet is loading.
            if (theme === 'light') await page.route('**/vendor/waterline/app.css*', async route => {
                await new Promise(resolve => setTimeout(resolve, 200));
                await route.continue();
            });
            const errors = [], writes = [];
            const prefix = `${presentation}-${locale}-${theme}-${width}`;
            let state = 'populated';
            page.on('pageerror', error => errors.push(error.message));
            await page.route('**/waterline/api/**', async route => {
                const request = route.request();
                if (request.method() !== 'GET') {
                    writes.push(`${request.method()} ${new URL(request.url()).pathname}`);
                    return route.abort();
                }
                const url = new URL(request.url());
                if (url.pathname.endsWith('/stats')) {
                    const fixture = dashboardFixture();
                    fixture.flows_per_minute = 1.5;
                    fixture.fleet_trends_series.timestamps = [localeCase.firstDate, '2026-09-15T12:00:00Z'];
                    return route.fulfill({ json: fixture });
                }
                if (url.pathname.endsWith('/preferences/run-detail')) return route.fulfill({ json: {
                    preferences: {}, effective_preferences: { tab: 'timeline' },
                } });
                if (url.pathname.endsWith(`/instances/${instance}/runs/${run}`)) {
                    if (state === 'error') return route.fulfill({ status: 503, json: { message: 'Synthetic backend interruption' } });
                    const fixture = runDetailFixture(`${presentation}-${state}`);
                    // Presentation fixtures permit opening dialogs. No command is
                    // confirmed, and the route above rejects every API write.
                    if (state === 'populated') for (const action of ['repair', 'cancel', 'terminate'])
                        fixture.actionability.actions[action] = { allowed: true, reason: null };
                    if (state === 'supported-empty') fixture.actionability.actions.archive = { allowed: true, reason: null };
                    return route.fulfill({ json: fixture });
                }
                return route.continue();
            });
            try {
                await login(page, new URL('/waterline/dashboard', origin).href);
                await page.locator('.wl-operator-metrics-grid').waitFor();
                assert.equal((await page.locator('.wl-operator-metric__value').last().textContent()).trim(), localeCase.hour);
                const running = page.locator('.wl-summary-card').filter({
                    has: page.getByText(text('Running now'), { exact: true }),
                });
                await running.locator('.wl-summary-card__label').waitFor();
                assert.equal((await running.locator('.wl-summary-card__value').textContent()).trim(), localeCase.count || '1.234.567',
                    'Counts must use the Waterline locale even in an English browser.');
                const rate = page.locator('.wl-summary-card').filter({
                    has: page.getByText(text('Flows per minute'), { exact: true }),
                });
                assert.equal((await rate.locator('.wl-summary-card__value').textContent()).trim(), localeCase.rate || '1,5');
                const expectedPercent = localeCase.percent || '98,5%';
                const passRate = page.locator('td').filter({ hasText: expectedPercent }).first();
                await passRate.waitFor();
                assert.equal((await passRate.textContent()).trim(), expectedPercent);
                await page.locator('.apexcharts-datalabel').filter({ hasText: expectedPercent }).first().waitFor();
                await page.locator('.apexcharts-xaxis text').filter({ hasText: localeCase.month }).first().waitFor();
                // Theme stylesheet loading and ApexCharts redraws are asynchronous.
                // Use the dashboard matrix's bounded convergence check.
                await page.waitForFunction(() => [...document.querySelectorAll('.apexcharts-canvas')].every(chart => {
                    const rect = chart.getBoundingClientRect(), card = chart.closest('.card').getBoundingClientRect();
                    return rect.left >= card.left - 2 && rect.right <= card.right + 2;
                }), null, { timeout: 3000 }).catch(() => {});
                const geometry = await dashboardGeometry(page);
                assert.deepEqual(geometry.failures, [], `${prefix}: dashboard text and charts must fit`);
                await snapshot(page, `${prefix}-dashboard`);

                for (state of ['populated', 'supported-empty', 'unavailable', 'degraded', 'error']) {
                    await login(page, new URL(`/waterline/flows/instances/${instance}/runs/${run}`, origin).href);
                    if (state === 'error') {
                        const expected = text('Request failed with HTTP {value1}: {value2}')
                            .replace('{value1}', '503').replace('{value2}', 'Synthetic backend interruption');
                        await page.getByText(expected, { exact: true }).waitFor();
                    } else {
                        await page.getByRole('heading', { name: 'App\\Workflows\\ResponsiveQualificationWorkflow', exact: true }).waitFor();
                        if (state === 'populated') {
                            const cascade = page.locator('section[aria-labelledby="cancellationCascadeTitle"]');
                            await cascade.getByText(text('Cancellation cascade'), { exact: true }).waitFor();
                            const content = await cascade.innerText();
                            for (const label of ['Root request', 'Original cleanup deadline', 'Cleanup worker recovery', 'Wait for cancellation completion'])
                                assert.ok(content.includes(text(label)), `${prefix}: ${label}`);
                            for (const original of ['visual-root-request-01M3ZZZZZZZZZZZZZZZZZZ', '2026-10-02T00:00:30Z',
                                'visual.php.parent', 'visual.python.child', 'rust.remote.work', 'Deployment maintenance'])
                                assert.ok(content.includes(original), `${prefix}: preserve ${original}`);
                            assert.ok((await page.locator('.wl-flow-detail').innerText()).includes('Import <failed> without exposing a trace.'));
                            assert.equal(await page.locator('failed').count(), 0, 'Exception text must not become markup.');
                        }
                        const actions = state === 'populated' ? ['repair', 'cancel', 'terminate']
                            : state === 'supported-empty' ? ['archive'] : [];
                        for (const action of actions) {
                            const label = action[0].toUpperCase() + action.slice(1);
                            await page.getByRole('button', { name: text(label), exact: true }).click();
                            const dialog = page.getByRole('dialog', { name: text(`${label} run?`), exact: true });
                            await dialog.waitFor();
                            await page.waitForFunction(() => {
                                const popup = document.querySelector('.swal2-popup');
                                return popup && getComputedStyle(popup).opacity === '1'
                                    && popup.getAnimations().every(animation => animation.playState !== 'running');
                            }, null, { timeout: 3000 });
                            if (action === 'cancel') assert.ok((await dialog.innerText()).includes(text(
                                'This immediately closes the current active run as Cancelled without cooperative cleanup. Previously completed external side effects are not undone.')));
                            if (action === 'terminate') assert.ok((await dialog.innerText()).includes(text(
                                'This forcibly closes the current active run as Terminated and stops cooperative cleanup. Previously completed external side effects are not undone.')));
                            const contrast = await auditContrast(page, ['title', 'body', 'action'], '.swal2-popup');
                            const bounds = await dialog.boundingBox();
                            assert.ok(bounds.x >= -1 && bounds.y >= -1 && bounds.x + bounds.width <= width + 1
                                && bounds.y + bounds.height <= 901, `${prefix}: confirmation must fit`);
                            await snapshot(page, `${prefix}-${action}-dialog`, { contrast });
                            await dialog.locator('.swal2-cancel').click();
                            await dialog.waitFor({ state: 'hidden' });
                        }
                    }
                    await snapshot(page, `${prefix}-${state}`);
                }
                assert.deepEqual(errors, [], `${prefix}: browser errors`);
                assert.deepEqual(writes, [], `${prefix}: qualification must not write to the backend`);
            } finally {
                await context.close();
            }
        }
    fs.writeFileSync(path.join(output, 'result.json'), JSON.stringify({ status: 'pass', locale, reports }, null, 2));
    console.log(JSON.stringify({ status: 'pass', locale, cases: reports.length }));
} finally {
    await browser.close();
}
