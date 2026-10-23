// Exercises every Schedules row action in a real embedded host.
//
// Expects the rows seeded by scripts/ci/seed-schedule-visual.php in a
// cluster-wide Waterline: nightly-invoice-sync in default and tenant-b, and
// hourly-ledger in default. Each action must reach the namespace of the row
// it was taken from, and the page must show what the backend stored.
import assert from 'node:assert/strict';
import { createRequire } from 'node:module';
import fs from 'node:fs';
import path from 'node:path';

const VIEWPORTS = [
    { name: 'desktop', width: 1440, height: 900 },
    { name: 'tablet', width: 900, height: 900 },
    { name: 'mobile', width: 390, height: 844 },
];

function argumentValue(name, fallback = null) {
    const index = process.argv.indexOf(name);

    return index === -1 ? fallback : process.argv[index + 1];
}

// The job installs the pinned Playwright into the host directory it runs from.
function loadPlaywright() {
    return createRequire(path.join(process.cwd(), 'package.json'))('playwright');
}

const baseUrl = argumentValue('--base-url');
const outputDir = path.resolve(argumentValue('--output-dir', 'schedule-evidence'));
const email = argumentValue('--email', 'demo@example.com');
const password = argumentValue('--password', 'password');

assert.ok(baseUrl, '--base-url is required');
fs.mkdirSync(outputDir, { recursive: true });

const schedulesUrl = new URL('/waterline/schedules', baseUrl).href;
const apiUrl = new URL('/waterline/api/v2/schedules', baseUrl).href;
const evidence = { checks: [], requests: [], pageErrors: [] };

function record(name, details = {}) {
    evidence.checks.push({ name, ...details });
    console.log(`ok - ${name}`);
}

// Text as authored, ignoring CSS text-transform such as uppercase pills.
async function content(locator) {
    return (await locator.textContent()).replace(/\s+/g, ' ');
}

function row(page, namespace, scheduleId) {
    return page.locator(`tr[data-schedule-id="${scheduleId}"][data-schedule-namespace="${namespace}"]`);
}

async function listRows(page) {
    const response = await page.request.get(apiUrl, { headers: { Accept: 'application/json' } });
    assert.equal(response.status(), 200, 'schedule list');

    return (await response.json()).data;
}

async function stored(page, namespace, scheduleId) {
    const match = (await listRows(page)).find((item) => item.namespace === namespace && item.schedule_id === scheduleId);
    assert.ok(match, `${namespace}/${scheduleId} is listed`);

    return match;
}

async function openSchedules(page) {
    await page.goto(schedulesUrl, { waitUntil: 'networkidle', timeout: 30_000 });

    if (/\/login(?:$|\?)/.test(page.url())) {
        await page.fill('input[name=email]', email);
        await page.fill('input[name=password]', password);
        await Promise.all([
            page.waitForNavigation({ waitUntil: 'networkidle', timeout: 30_000 }).catch(() => {}),
            page.click('button[type=submit], input[type=submit]'),
        ]);
        await page.goto(schedulesUrl, { waitUntil: 'networkidle', timeout: 30_000 });
    }

    await page.waitForFunction(() => (
        document.getElementById('waterline')?.dataset.waterlineMounted === 'true'
    ), { timeout: 20_000 });
    await row(page, 'default', 'nightly-invoice-sync').waitFor({ state: 'visible', timeout: 20_000 });
}

function scheduleResponse(page, method, suffix, namespace) {
    return page.waitForResponse((response) => {
        const url = new URL(response.url());

        return response.request().method() === method
            && url.pathname.endsWith(`/waterline/api/v2/schedules/nightly-invoice-sync${suffix}`)
            && url.searchParams.get('namespace') === namespace;
    }, { timeout: 20_000 });
}

async function confirmDialog(page, buttonName) {
    const button = page.locator('.swal2-popup').getByRole('button', { name: buttonName, exact: true });
    await button.waitFor({ state: 'visible', timeout: 10_000 });
    await button.click();
    await page.locator('.swal2-popup').waitFor({ state: 'hidden', timeout: 10_000 }).catch(() => {});
}

function localInput(date) {
    const pad = (value) => String(value).padStart(2, '0');

    return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}T${pad(date.getHours())}:${pad(date.getMinutes())}`;
}

async function assertRowsShowStoredState(page) {
    const rows = await listRows(page);
    assert.equal(rows.length, 3, 'three seeded schedules');

    for (const item of rows) {
        assert.equal(item.id, item.schedule_id, 'row id is the schedule ID');
        assert.ok(['default', 'tenant-b'].includes(item.namespace), 'row namespace');
    }

    const nightly = row(page, 'default', 'nightly-invoice-sync');
    const tenant = row(page, 'tenant-b', 'nightly-invoice-sync');
    const hourly = row(page, 'default', 'hourly-ledger');

    await assert.doesNotReject(nightly.getByText('Namespace default').waitFor({ timeout: 5_000 }));
    await assert.doesNotReject(tenant.getByText('Namespace tenant-b').waitFor({ timeout: 5_000 }));
    assert.match(await content(nightly), /Cron 0 2 \* \* \*/);
    assert.match(await content(nightly), /Timezone America\/Chicago/);
    assert.match(await content(tenant), /Cron 30 3 \* \* \*/);
    assert.match(await content(hourly), /Every 1 hr/);
    assert.match(await content(hourly), /Offset 5 min/);
    assert.doesNotMatch(await content(nightly), /Custom/);
    assert.match(await content(nightly), /Never/);
    record('rows show schedule IDs, namespaces, cadence and timezone from storage');
}

async function applyScheduleFilters(page, expectedStatus) {
    const response = page.waitForResponse((result) => (
        new URL(result.url()).pathname === new URL(apiUrl).pathname
        && result.request().method() === 'GET'
    ));
    await page.locator('.schedule-view__filters').getByRole('button', {name: 'Apply Filters', exact: true}).click();
    const result = await response;
    assert.equal(result.status(), expectedStatus, 'filtered list response');
    return result.json();
}

async function scheduleFilterCase(page, theme, viewport, workflowType, expectedRows) {
    await page.setViewportSize({width: viewport.width, height: viewport.height});
    await page.evaluate(value => localStorage.setItem('waterline-theme', value), theme);
    await openSchedules(page);
    const filters = page.locator('.schedule-view__filters');
    const type = filters.getByLabel('Workflow Type', {exact: true});
    const query = filters.getByLabel('Schedule visibility query', {exact: true});
    const status = filters.getByLabel('Status', {exact: true});
    await type.fill(workflowType);
    await status.selectOption('paused');
    assert.equal((await applyScheduleFilters(page, 200)).data.length, 0, 'status and type combine');
    await page.getByText('No schedules found', {exact: true}).waitFor();
    await status.selectOption('active');
    const matched = await applyScheduleFilters(page, 200);
    assert.deepEqual(matched.data.map(item => [item.namespace, item.schedule_id]).sort(), expectedRows);
    await page.waitForFunction(count => document.querySelectorAll('tr[data-schedule-id]').length === count, expectedRows.length);

    await query.fill('ScheduleId = "nightly-invoice-sync"');
    const unavailable = await applyScheduleFilters(page, 501);
    assert.equal(unavailable.reason, 'backend_capability_unavailable');
    assert.equal(unavailable.capability, 'schedule_visibility_query');
    await page.getByText('This embedded backend does not support schedule visibility queries. Workflow type and status filters remain available.', {exact: true}).waitFor();
    assert.equal(await query.inputValue(), 'ScheduleId = "nightly-invoice-sync"');
    assert.equal(await type.inputValue(), workflowType);
    assert.equal(await status.inputValue(), 'active');

    await query.fill('');
    await type.fill('');
    assert.equal((await applyScheduleFilters(page, 200)).data.length, 3, 'clearing filters recovers the list');
    await row(page, 'default', 'nightly-invoice-sync').waitFor();
    const overflow = await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
    assert.ok(overflow <= 1, `${theme}/${viewport.name}: filter form does not overflow (${overflow}px)`);
    await page.screenshot({path: path.join(outputDir, `schedule-filters-${theme}-${viewport.name}.png`), fullPage: true});
    record('type/status filters and explicit unsupported query recovery', {theme, viewport: viewport.name, expectedRows});
}

async function scheduleFilters(page) {
    const rows = await listRows(page);
    const workflowType = rows.find(item => typeof item.workflow_type === 'string' && item.workflow_type !== '')?.workflow_type;
    assert.ok(workflowType, 'seed includes a stored workflow type');
    const expectedRows = rows.filter(item => item.workflow_type === workflowType).map(item => [item.namespace, item.schedule_id]).sort();
    // One host and its current form are shared. Finish each real HTTP/UI
    // interaction before changing theme, viewport or applied filters.
    for (const theme of ['dark', 'light']) {
        for (const viewport of VIEWPORTS) {
            await scheduleFilterCase(page, theme, viewport, workflowType, expectedRows);
        }
    }
    await openSchedules(page);
}

async function pauseAndResumeTheTenantRow(page) {
    const tenant = row(page, 'tenant-b', 'nightly-invoice-sync');

    const paused = scheduleResponse(page, 'POST', '/pause', 'tenant-b');
    await tenant.getByRole('button', { name: 'Pause', exact: true }).click();
    assert.equal((await paused).status(), 200, 'pause response');
    await tenant.getByRole('button', { name: 'Resume', exact: true }).waitFor({ timeout: 10_000 });
    assert.equal((await stored(page, 'tenant-b', 'nightly-invoice-sync')).status, 'paused');
    assert.equal((await stored(page, 'default', 'nightly-invoice-sync')).status, 'active', 'other namespace unchanged');

    const resumed = scheduleResponse(page, 'POST', '/resume', 'tenant-b');
    await tenant.getByRole('button', { name: 'Resume', exact: true }).click();
    assert.equal((await resumed).status(), 200, 'resume response');
    await tenant.getByRole('button', { name: 'Pause', exact: true }).waitFor({ timeout: 10_000 });
    assert.equal((await stored(page, 'tenant-b', 'nightly-invoice-sync')).status, 'active');
    record('pause and resume change only the tenant-b row');
}

async function triggerTheDefaultRow(page) {
    const nightly = row(page, 'default', 'nightly-invoice-sync');

    await nightly.getByRole('button', { name: 'Trigger', exact: true }).click();
    const triggered = scheduleResponse(page, 'POST', '/trigger', 'default');
    await confirmDialog(page, 'Trigger now');
    const response = await triggered;
    assert.equal(response.status(), 200, 'trigger response');
    const body = await response.json();
    assert.equal(body.triggered, true, 'trigger started a workflow');
    assert.ok(body.workflow_id, 'trigger reports the workflow');
    await page.locator('.swal2-popup').getByText('Schedule triggered', { exact: true }).waitFor({ timeout: 10_000 });
    await confirmDialog(page, 'Okay');

    await nightly.getByText('Started', { exact: true }).waitFor({ timeout: 10_000 });
    assert.equal((await stored(page, 'default', 'nightly-invoice-sync')).last_outcome?.workflow_id, body.workflow_id);
    assert.equal((await stored(page, 'tenant-b', 'nightly-invoice-sync')).last_outcome, null, 'other namespace never fired');
    record('trigger starts the default row and shows its outcome', { workflow_id: body.workflow_id });
}

async function backfillTheDefaultRow(page) {
    const nightly = row(page, 'default', 'nightly-invoice-sync');
    const opener = nightly.getByRole('button', { name: 'Backfill', exact: true });

    await opener.click();
    const dialog = page.getByRole('dialog', { name: 'Backfill schedule' });
    await dialog.waitFor({ state: 'visible', timeout: 10_000 });

    const now = new Date();
    await dialog.getByLabel('From').fill(localInput(new Date(now.getTime() - 3 * 86_400_000)));
    await dialog.getByLabel('To').fill(localInput(new Date(now.getTime() - 3_600_000)));
    await dialog.getByLabel('Overlap Policy').selectOption('allow_all');

    const backfilled = scheduleResponse(page, 'POST', '/backfill', 'default');
    await dialog.getByRole('button', { name: 'Backfill', exact: true }).click();
    const response = await backfilled;
    assert.equal(response.status(), 200, 'backfill response');
    const body = await response.json();
    assert.ok(body.fires_attempted >= 2, 'backfill window contains schedule times');
    assert.equal(body.started_count, body.fires_attempted, 'allow_all starts every schedule time');
    await page.locator('.swal2-popup').getByText(/Schedule times: \d+\. Started: \d+/).waitFor({ timeout: 10_000 });
    await confirmDialog(page, 'Okay');
    record('backfill starts every schedule time in the window', { fires_attempted: body.fires_attempted });
}

async function closeWithEscapeRestoresFocus(page, dialog, opener) {
    await page.keyboard.press('Escape');
    await dialog.waitFor({ state: 'hidden', timeout: 10_000 });
    assert.equal(await opener.evaluate((element) => element === document.activeElement), true, 'focus returns to the row action');
}

async function historyAndDetailsForTheDefaultRow(page) {
    const nightly = row(page, 'default', 'nightly-invoice-sync');

    const historyOpener = nightly.getByRole('button', { name: 'History', exact: true });
    const history = scheduleResponse(page, 'GET', '/history', 'default');
    await historyOpener.click();
    assert.equal((await history).status(), 200, 'history response');
    const historyDialog = page.getByRole('dialog', { name: 'Schedule audit history' });
    await historyDialog.getByText('Schedule Triggered').first().waitFor({ timeout: 10_000 });
    await closeWithEscapeRestoresFocus(page, historyDialog, historyOpener);

    const detailOpener = nightly.getByRole('button', { name: 'Details', exact: true });
    const detail = scheduleResponse(page, 'GET', '', 'default');
    await detailOpener.click();
    assert.equal((await detail).status(), 200, 'detail response');
    const detailDialog = page.getByRole('dialog', { name: 'Schedule details' });
    await detailDialog.getByText('Recent fires', { exact: true }).waitFor({ timeout: 10_000 });
    assert.ok(await detailDialog.locator('table tbody tr').count() >= 2, 'recent fires list the trigger and backfill');
    assert.match(await content(detailDialog), /Namespace default/);
    assert.match(await content(detailDialog), /Latest workflow/);
    await closeWithEscapeRestoresFocus(page, detailDialog, detailOpener);
    record('history and details open for the default row, close on Escape and restore focus');
}

async function apiRefusesAmbiguousAndInvalidRequests(page) {
    const ambiguous = await page.request.post(`${apiUrl}/nightly-invoice-sync/pause`, { headers: { Accept: 'application/json' } });
    assert.equal(ambiguous.status(), 409, 'ambiguous schedule ID');
    assert.equal((await ambiguous.json()).reason, 'schedule_namespace_required');

    const invalid = await page.request.post(`${apiUrl}/nightly-invoice-sync/trigger?namespace=default`, {
        headers: { Accept: 'application/json' },
        data: { overlap_policy: 'allow' },
    });
    assert.equal(invalid.status(), 422, 'unknown overlap policy');
    assert.equal((await invalid.json()).reason, 'invalid_overlap_policy');

    assert.equal((await stored(page, 'default', 'nightly-invoice-sync')).status, 'active');
    assert.equal((await stored(page, 'tenant-b', 'nightly-invoice-sync')).status, 'active');
    record('ambiguous IDs and unknown overlap policies are refused without changes');
}

async function layoutHoldsAtEachWidth(page) {
    for (const viewport of VIEWPORTS) {
        await page.setViewportSize({ width: viewport.width, height: viewport.height });
        await openSchedules(page);
        const overflow = await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
        assert.ok(overflow <= 1, `${viewport.name}: page does not scroll horizontally (${overflow}px)`);
        await page.screenshot({ path: path.join(outputDir, `schedules-${viewport.name}.png`), fullPage: true });

        const opener = row(page, 'default', 'nightly-invoice-sync').getByRole('button', { name: 'Details', exact: true });
        await opener.scrollIntoViewIfNeeded();
        await opener.click();
        const dialog = page.getByRole('dialog', { name: 'Schedule details' });
        await dialog.getByText('Recent fires', { exact: true }).waitFor({ timeout: 10_000 });
        const box = await dialog.boundingBox();
        assert.ok(box && box.x >= 0 && box.x + box.width <= viewport.width + 1, `${viewport.name}: details dialog fits the width`);
        await page.screenshot({ path: path.join(outputDir, `schedule-details-${viewport.name}.png`) });
        await page.keyboard.press('Escape');
        await dialog.waitFor({ state: 'hidden', timeout: 10_000 });
    }
    record('list and details fit desktop, tablet and mobile widths');
}

const { chromium } = loadPlaywright();
const browser = await chromium.launch(process.env.CHROMIUM_EXECUTABLE_PATH ? { executablePath: process.env.CHROMIUM_EXECUTABLE_PATH } : {});
const context = await browser.newContext({ viewport: VIEWPORTS[0], timezoneId: 'UTC', locale: 'en-US' });
const page = await context.newPage();

page.on('pageerror', (error) => evidence.pageErrors.push(error.message));
page.on('response', (response) => {
    if (response.url().includes('/waterline/api/v2/schedules')) {
        evidence.requests.push({ method: response.request().method(), url: response.url(), status: response.status() });
    }
});

try {
    await openSchedules(page);
    await assertRowsShowStoredState(page);
    await scheduleFilters(page);
    await pauseAndResumeTheTenantRow(page);
    await triggerTheDefaultRow(page);
    await backfillTheDefaultRow(page);
    await historyAndDetailsForTheDefaultRow(page);
    await apiRefusesAmbiguousAndInvalidRequests(page);
    await layoutHoldsAtEachWidth(page);
    assert.deepEqual(evidence.pageErrors, [], 'no page errors');
} catch (error) {
    await page.screenshot({ path: path.join(outputDir, 'failure.png'), fullPage: true }).catch(() => {});
    evidence.failure = String(error?.stack || error);
    throw error;
} finally {
    fs.writeFileSync(path.join(outputDir, 'schedule-actions.json'), `${JSON.stringify(evidence, null, 2)}\n`);
    await browser.close();
}
