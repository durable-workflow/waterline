// Finite actual-application observer. The parent performs all external writes.
import assert from 'node:assert/strict';
import {createRequire} from 'node:module';
import readline from 'node:readline';
const require = createRequire('/opt/waterline-browser/package.json');
const {chromium} = require('playwright');
const input = readline.createInterface({input: process.stdin});
const lines = input[Symbol.asyncIterator]();
async function command(phase) {
    let timer;
    const {value, done} = await Promise.race([lines.next(), new Promise((_, reject) => { timer = setTimeout(() => reject(new Error('parent command deadline')), 15000); })]).finally(() => clearTimeout(timer));
    assert.equal(done, false, 'parent command missing');
    assert.ok(Buffer.byteLength(value) < 2048);
    const data = JSON.parse(value);
    assert.equal(data.phase, phase);
    return data;
}
function emit(value) { process.stdout.write(JSON.stringify(value) + '\n'); }
const selected = await command('start');
const base = new URL(selected.url);
assert.equal(base.protocol, 'http:');
assert.equal(base.hostname, '127.0.0.1');
assert.ok(Number(base.port) >= 1024 && Number(base.port) <= 65535);
assert.equal(base.pathname, '/');
assert.equal(base.username + base.password + base.search + base.hash, '');
assert.match(selected.schedule_id, /^waterline-[a-z0-9-]{1,80}$/);
assert.equal(selected.workflow_type, 'waterline.schedule-controlled-failure');
const browser = await chromium.launch({headless: true, args: ['--no-sandbox', '--disable-dev-shm-usage']});
const report = {classification: 'source-overlay-candidate-browser', errors: [], list_reads: [], form_writes: [], navigations: 0};
try {
    const context = await browser.newContext({viewport: {width: 1440, height: 1000}});
    context.setDefaultTimeout(12000);
    await context.addInitScript(() => {
        localStorage.setItem('autoLoadsNewEntries', '1');
        localStorage.setItem('waterline-theme', 'dark');
    });
    const page = await context.newPage();
    page.on('pageerror', error => report.errors.push(String(error)));
    page.on('framenavigated', frame => { if (frame === page.mainFrame()) report.navigations++; });
    const activeReads = new Set();
    page.on('requestfinished', request => activeReads.delete(request));
    page.on('requestfailed', request => { if (activeReads.delete(request)) report.errors.push('Schedule read failed'); });
    page.on('request', request => {
        const path = new URL(request.url()).pathname;
        if (path === '/waterline/api/v2/schedules') { activeReads.add(request); report.list_reads.push({at: Date.now(), url: request.url()}); }
        if (request.method() !== 'GET' && path.startsWith('/waterline/api/v2/schedules')) report.form_writes.push(path);
    });
    await page.goto(new URL('waterline/schedules', base).href);
    const filters = page.locator('.schedule-view__filters');
    await filters.getByLabel('Workflow Type', {exact: true}).fill(selected.workflow_type);
    await filters.getByRole('button', {name: 'Apply Filters', exact: true}).click();
    await page.locator('.schedule-view__pagination').getByRole('button', {name: 'Next', exact: true}).click();
    await page.waitForFunction(() => document.querySelector('.schedule-view__pagination .btn-primary')?.textContent.trim() === '2');
    const row = page.locator(`tr[data-schedule-id="${selected.schedule_id}"]`);
    await row.waitFor();
    assert.match(await row.locator('[data-latest-run]').textContent(), /(?:Running|Waiting)/);
    assert.match(await row.locator('td[data-label="Last Result"]').textContent(), /Started/);
    await filters.getByLabel('Workflow Type', {exact: true}).fill('unapplied draft');
    await row.getByRole('button', {name: 'Backfill', exact: true}).click();
    const dialog = page.getByRole('dialog', {name: 'Backfill schedule', exact: true});
    await dialog.getByLabel('From', {exact: true}).fill('2026-11-01T00:00');
    await dialog.getByLabel('To', {exact: true}).fill('2026-11-02T00:00');
    emit({phase: 'ready_for_external_pause', schedule_id: selected.schedule_id});
    await command('paused');
    await row.locator('[data-row-action="resume"]').waitFor({state: 'attached', timeout: 10000});
    assert.match(await row.locator('[data-latest-run]').textContent(), /(?:Running|Waiting)/);
    emit({phase: 'paused_visible'});
    await command('resumed_and_released');
    await row.locator('[data-row-action="pause"]').waitFor({state: 'attached', timeout: 10000});
    await page.waitForFunction(id => document.querySelector(`tr[data-schedule-id="${id}"] [data-latest-run]`)?.textContent.includes('Failed'), selected.schedule_id, {timeout: 12000});
    assert.match(await row.locator('td[data-label="Last Result"]').textContent(), /Started/);
    assert.equal(await filters.getByLabel('Workflow Type', {exact: true}).inputValue(), 'unapplied draft');
    assert.equal(await dialog.getByLabel('From', {exact: true}).inputValue(), '2026-11-01T00:00');
    assert.equal(await dialog.getByLabel('To', {exact: true}).inputValue(), '2026-11-02T00:00');
    assert.equal(await dialog.isVisible(), true);
    assert.equal(report.navigations, 1, 'the view must not reload');
    assert.deepEqual(report.form_writes, []);
    assert.deepEqual(report.errors, []);
    emit({phase: 'screenshot', name: 'desktop-preserved-dialog', base64: (await page.screenshot({fullPage: true})).toString('base64')});
    await page.keyboard.press('Escape');
    await dialog.waitFor({state: 'hidden'});
    await page.setViewportSize({width: 390, height: 1000});
    await row.scrollIntoViewIfNeeded();
    assert.ok(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth + 1));
    emit({phase: 'screenshot', name: 'mobile-result-row', base64: (await row.screenshot()).toString('base64')});
    await page.getByRole('button', {name: 'Auto refresh', exact: true}).click();
    const deadline = Date.now() + 5000;
    while (activeReads.size > 0) { assert.ok(Date.now() < deadline, 'last read did not settle'); await page.waitForTimeout(50); }
    assert.deepEqual(report.errors, []);
    report.outcome = 'pass';
    report.latest_run = 'Failed'; report.recorded_dispatch = 'Started'; report.form_preserved = true;
    emit({phase: 'complete', report});
    await context.close();
} finally {
    await browser.close();
    input.close();
}
