import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { createRequire } from 'node:module';
import { dashboardFixture } from './dashboard-visual.mjs';

function argument(name) {
    const index = process.argv.indexOf(name);
    assert.ok(index > 0 && process.argv[index + 1], `${name} is required.`);
    return process.argv[index + 1];
}

const output = path.resolve(argument('--output-dir'));
const origins = [['embedded', argument('--base-url')], ['service', argument('--service-base-url')]];
fs.mkdirSync(output, { recursive: true });
const { chromium } = createRequire(path.join(process.cwd(), 'package.json'))('playwright');
const browser = await chromium.launch({ args: ['--no-sandbox'],
    ...(process.env.CHROMIUM_EXECUTABLE_PATH ? { executablePath: process.env.CHROMIUM_EXECUTABLE_PATH } : {}),
});
const reports = [];
const originalDeadline = '2026-10-11T20:00:30.123456Z';

function callFixture(status, result = false) {
    return {
        id: 'original-call', namespace: 'orders', endpoint_name: 'original-endpoint',
        service_name: 'original-service', operation_name: 'original-operation',
        caller_namespace: 'orders', target_namespace: 'foreign', status,
        outcome: status, is_terminal: true, status_bucket: status, outcome_bucket: status,
        call_deadline_at: originalDeadline, accepted_at: '2026-10-11T20:00:00.123456Z',
        retry_attempt_count: 2, service_call_attempts: [{ attempt: 1, status: 'failed' }, { attempt: 2, status }],
        failure_type: status === 'failed' ? 'OriginalServiceFailure' : null,
        failure_message: status === 'failed' ? 'Original service failure message' : null,
        payloads_in_observer_namespace: true, output_available: status === 'completed', output: result,
        caller_link: { namespace: 'orders', workflow_instance_id: 'original-caller', workflow_run_id: 'original-caller-run', in_observer_namespace: true },
        linked_run_ref: { namespace: 'foreign', workflow_instance_id: 'foreign-target', workflow_run_id: 'foreign-run', in_observer_namespace: false },
    };
}

async function login(page, url) {
    await page.goto(url, { waitUntil: 'networkidle' });
    if (/\/login(?:$|\?)/.test(page.url())) {
        await page.fill('input[name=email]', process.env.WATERLINE_VISUAL_EMAIL || 'demo@example.com');
        await page.fill('input[name=password]', process.env.WATERLINE_VISUAL_PASSWORD || 'password');
        await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle' }), page.click('button[type=submit], input[type=submit]')]);
        await page.goto(url, { waitUntil: 'networkidle' });
    }
    await page.waitForFunction(() => document.getElementById('waterline')?.dataset.waterlineMounted === 'true');
}

async function capture(page, name) {
    assert.ok(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1), `${name}: page must fit the viewport`);
    await page.screenshot({ path: path.join(output, `${name}.png`), fullPage: true });
    reports.push({ name, status: 'pass' });
}

try {
    for (const [presentation, origin] of origins) {
        for (const [width, theme] of [[390, 'light'], [1440, 'dark']]) {
            const context = await browser.newContext({ viewport: { width, height: 900 } });
            await context.addInitScript(value => localStorage.setItem('waterline-theme', value), theme);
            const page = await context.newPage();
            const errors = [], writes = [], requests = [];
            let fixture = callFixture('completed'), unsupported = false;
            page.on('pageerror', error => errors.push(error.message));
            await page.route('**/waterline/api/**', async route => {
                const request = route.request(), url = new URL(request.url());
                requests.push(url);
                if (request.method() !== 'GET') {
                    writes.push(`${request.method()} ${url.pathname}`);
                    return route.abort();
                }
                if (url.pathname.endsWith('/stats')) return route.fulfill({ json: dashboardFixture() });
                const suffix = url.pathname.split('/api/v2/services/')[1];
                if (!suffix) return route.fulfill({ json: {} });
                if (unsupported) return route.fulfill({ status: 501, json: {
                    message: 'The connected Server does not advertise catalog read contract version 1.',
                    reason: 'backend_capability_unavailable', required_contract_version: 1,
                } });
                if (suffix === 'calls/original-call') return route.fulfill({ json: fixture });
                if (suffix === 'endpoints/original-endpoint') return route.fulfill({ json: {
                    id: 'original-endpoint', endpoint_name: 'original-endpoint',
                    operations: [{ id: 'operation-1', operation_name: 'first-operation' }],
                    related_has_more: { operations: true, services: false },
                } });
                const rows = suffix === 'calls' ? [fixture]
                    : suffix === 'endpoints' ? [{ id: 'original-endpoint', endpoint_name: 'original-endpoint' }]
                    : [{ id: 'operation-1', operation_name: 'filtered-operation' }];
                return route.fulfill({ json: {
                    data: rows, current_page: Number(url.searchParams.get('page') || 1),
                    per_page: 50, total: suffix === 'operations' ? 101 : 1,
                    last_page: suffix === 'operations' ? 3 : 1,
                } });
            });

            const base = `${origin.replace(/\/$/, '')}/waterline/services`;
            for (const status of ['completed', 'failed', 'cancelled']) {
                fixture = callFixture(status);
                await login(page, `${base}?tab=calls`);
                await page.locator('.service-catalog-view tbody').getByRole('button', { name: 'View', exact: true }).click();
                const detail = page.locator('.service-catalog-view__detail');
                await detail.getByText('2026-10-11 20:00:30.123456', { exact: true }).waitFor();
                assert.ok((await detail.innerText()).includes('original-operation'));
                const target = detail.locator('.service-catalog-view__reference').filter({ hasText: 'Target Run' });
                assert.equal(await target.locator('a').count(), 0, 'Foreign target must remain opaque');
                if (status === 'completed') {
                    assert.equal(await detail.locator('section').filter({ has: page.getByRole('heading', { name: 'Terminal result', exact: true }) }).locator('pre').innerText(), 'false');
                } else if (status === 'failed') {
                    await detail.getByText('OriginalServiceFailure', { exact: true }).waitFor();
                    await detail.getByText('Original service failure message', { exact: true }).waitFor();
                }
                await capture(page, `${presentation}-${theme}-${width}-${status}`);
            }

            fixture = callFixture('completed');
            delete fixture.output_available;
            delete fixture.output;
            fixture.metadata = { nexus_result: { codec: 'avro', blob: 'recorded-result' } };
            await page.goto(`${base}?tab=calls`, { waitUntil: 'networkidle' });
            await page.locator('.service-catalog-view tbody').getByRole('button', { name: 'View', exact: true }).click();
            const unavailable = page.locator('.service-catalog-view__detail section').filter({
                has: page.getByRole('heading', { name: 'Terminal result', exact: true }),
            });
            await unavailable.getByText('Result preview unavailable.', { exact: true }).waitFor();
            assert.equal(await unavailable.getByText('No terminal result was recorded.', { exact: true }).count(), 0);
            await capture(page, `${presentation}-${theme}-${width}-recorded-preview-unavailable`);

            if (presentation === 'service' && width === 1440) {
                for (const value of [0, null]) {
                    fixture = callFixture('completed', value);
                    await page.goto(`${base}?tab=calls`, { waitUntil: 'networkidle' });
                    await page.locator('.service-catalog-view tbody').getByRole('button', { name: 'View', exact: true }).click();
                    const result = page.locator('.service-catalog-view__detail section').filter({ has: page.getByRole('heading', { name: 'Terminal result', exact: true }) }).locator('pre');
                    await result.waitFor();
                    assert.equal(await result.innerText(), JSON.stringify(value));
                }
                await page.goto(`${base}?tab=endpoints`, { waitUntil: 'networkidle' });
                await page.locator('.service-catalog-view tbody').getByRole('button', { name: 'View', exact: true }).click();
                await page.getByRole('button', { name: 'Browse operations', exact: true }).click();
                await page.getByText('filtered-operation', { exact: true }).waitFor();
                await Promise.all([
                    page.waitForResponse(response => {
                        const url = new URL(response.url());
                        return url.pathname.endsWith('/services/operations') && url.searchParams.get('page') === '2';
                    }),
                    page.getByRole('button', { name: 'Next', exact: true }).click(),
                ]);
                await page.waitForFunction(() => new URL(location.href).searchParams.get('page') === '2');
                assert.ok(requests.some(url => url.pathname.endsWith('/services/operations') && url.searchParams.get('endpoint_id') === 'original-endpoint' && url.searchParams.get('page') === '2'));
                await page.getByRole('button', { name: 'Clear', exact: true }).click();
                await page.waitForFunction(() => !new URL(location.href).searchParams.has('endpoint_id'));
                await capture(page, 'service-filtered-related-catalog');
                unsupported = true;
                await page.goto(`${base}?tab=calls`, { waitUntil: 'networkidle' });
                await page.getByText('The connected Server does not advertise catalog read contract version 1.', { exact: true }).waitFor();
                await capture(page, 'service-unsupported-backend');
            }
            assert.deepEqual(errors, [], 'No browser exceptions');
            assert.deepEqual(writes, [], 'Inspection must not issue writes');
            await context.close();
        }
    }
    fs.writeFileSync(path.join(output, 'service-catalog-results.json'), `${JSON.stringify({
        qualification: 'source-browser-presentation-fixtures', reports,
        scope: 'Browser rendering and navigation. Published API/backend acceptance is separate.',
    }, null, 2)}\n`);
    console.log(`Service catalog browser presentation: ${reports.length} cases passed.`);
} finally {
    await browser.close();
}
