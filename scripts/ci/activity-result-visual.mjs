import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { createRequire } from 'node:module';
import { waitForDialogReady } from './dialog-readiness.mjs';
import { runDetailFixture } from './run-detail-visual.mjs';

function argument(name) {
    const index = process.argv.indexOf(name);
    assert.ok(index > 0 && process.argv[index + 1], `${name} is required.`);
    return process.argv[index + 1];
}

const origin = argument('--service-base-url').replace(/\/$/, '');
const output = path.resolve(argument('--output-dir'));
fs.mkdirSync(output, { recursive: true });
const { chromium } = createRequire(path.join(process.cwd(), 'package.json'))('playwright');
const browser = await chromium.launch({ args: ['--no-sandbox'],
    ...(process.env.CHROMIUM_EXECUTABLE_PATH ? { executablePath: process.env.CHROMIUM_EXECUTABLE_PATH } : {}),
});
const identity = { instance_id: 'waterline-visual-instance', run_id: 'waterline-visual-run', activity_id: 'selected-activity' };
const resultPath = `/waterline/api/instances/${identity.instance_id}/runs/${identity.run_id}/activities/${identity.activity_id}/result`;
const rows = [];

try {
    for (const width of [390, 1440]) for (const theme of ['light', 'dark']) {
        const context = await browser.newContext({ viewport: { width, height: 844 } });
        await context.addInitScript(value => localStorage.setItem('waterline-theme', value), theme);
        const page = await context.newPage();
        let response = { ...identity, state: 'available', available: true, result: null };
        let status = 200;
        const resultReads = [], writes = [], errors = [];
        page.on('pageerror', error => errors.push(error.message));
        await page.route('**/waterline/api/**', async route => {
            const request = route.request(), url = new URL(request.url());
            if (request.method() !== 'GET') { writes.push(url.pathname); return route.abort(); }
            if (url.pathname === resultPath) {
                resultReads.push(url.pathname);
                return route.fulfill({ status, json: response });
            }
            if (url.pathname.endsWith('/preferences/run-detail')) return route.fulfill({ json: { preferences: {}, effective_preferences: { tab: 'timeline' } } });
            if (url.pathname === `/waterline/api/instances/${identity.instance_id}/runs/${identity.run_id}`) {
                const fixture = runDetailFixture('service-supported-empty');
                fixture.activities = [{ id: identity.activity_id, type: 'selected.activity', status: 'completed', attempts: [], retry_policy: null, queue: 'inspection',
                    history_authority: 'canonical', result: null }];
                return route.fulfill({ json: fixture });
            }
            return route.fulfill({ json: {} });
        });
        const url = `${origin}/waterline/flows/instances/${identity.instance_id}/runs/${identity.run_id}`;
        await page.goto(url, { waitUntil: 'networkidle' });
        if (/\/login(?:$|\?)/.test(page.url())) {
            await page.fill('input[name=email]', process.env.WATERLINE_VISUAL_EMAIL || 'demo@example.com');
            await page.fill('input[name=password]', process.env.WATERLINE_VISUAL_PASSWORD || 'password');
            await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle' }), page.click('button[type=submit], input[type=submit]')]);
            await page.goto(url, { waitUntil: 'networkidle' });
        }
        const view = page.locator('#collapseActivities').getByRole('button', { name: 'View', exact: true });
        await view.waitFor();
        assert.equal(resultReads.length, 0, 'Metadata listing must not load a result');
        for (const result of [null, false, { number: { $type: 'long', value: '9007199254740993' }, text: '日本語', nil: null }, { text: '日本語'.repeat(2048) }]) {
            response = { ...identity, state: 'available', available: true, result };
            status = 200;
            const before = resultReads.length;
            await view.focus();
            await page.keyboard.press('Enter');
            const dialog = page.getByRole('dialog');
            await waitForDialogReady(page, dialog);
            assert.equal(await dialog.locator('.swal2-html-container').innerText(), JSON.stringify(result, null, 2));
            assert.equal(resultReads.length, before + 1, 'One explicit selection makes one result request');
            const geometry = await dialog.evaluate(node => ({ top: node.getBoundingClientRect().top,
                bottom: node.getBoundingClientRect().bottom, width: node.getBoundingClientRect().width,
                viewportWidth: innerWidth, viewportHeight: innerHeight }));
            assert.ok(geometry.top >= 0 && geometry.bottom <= geometry.viewportHeight + 1 && geometry.width <= geometry.viewportWidth,
                `Result dialog fits viewport: ${JSON.stringify(geometry)}`);
            await dialog.getByRole('button', { name: 'Okay', exact: true }).click();
            await dialog.waitFor({ state: 'hidden' });
            assert.equal(await view.evaluate(node => document.activeElement === node), true, 'Dialog restores selection focus');
        }
        for (const [reason, expected, code] of [
            ['activity_result_sdk_unavailable', 'Update the PHP SDK', 200],
            ['activity_result_server_unavailable', 'Server does not support', 200],
            ['activity_result_decode_failed', 'could not be decoded', 422],
        ]) {
            response = { ...identity, state: 'unavailable', available: false, reason }; status = code;
            await view.click();
            const dialog = page.getByRole('dialog');
            await dialog.waitFor();
            assert.ok((await dialog.innerText()).includes(expected));
            await dialog.getByRole('button', { name: 'Okay', exact: true }).click();
        }
        response = { ...identity, state: 'pruned', available: false }; status = 200;
        await view.click();
        await page.getByRole('dialog').getByText(/Retention removed/).waitFor();
        await page.getByRole('dialog').getByRole('button', { name: 'Okay', exact: true }).click();
        response = { ...identity, run_id: 'other-run', state: 'available', available: true, result: 'must-not-display' };
        await view.click();
        const refusal = page.getByRole('dialog');
        await refusal.waitFor();
        assert.ok(!(await refusal.innerText()).includes('must-not-display'));
        assert.ok((await refusal.innerText()).includes('could not be loaded'));
        await refusal.getByRole('button', { name: 'Okay', exact: true }).click();
        assert.deepEqual(writes, []);
        assert.deepEqual(errors, []);
        rows.push({ width, theme, selected_reads: resultReads.length, status: 'pass' });
        await context.close();
    }
    fs.writeFileSync(path.join(output, 'activity-results.json'), JSON.stringify(rows, null, 2));
    console.log(`Activity result browser inspection: ${rows.length} desktop/mobile/theme profiles pass.`);
} finally {
    await browser.close();
}
