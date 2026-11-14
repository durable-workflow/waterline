// Source UI check against synthetic HTTP fixtures. This does not qualify an installed backend.
import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import {createRequire} from 'node:module';
import {auditContrast} from './theme-contrast-visual.mjs';

function argument(name, fallback) {
    const index = process.argv.indexOf(name);
    return index < 0 ? fallback : process.argv[index + 1];
}

const assets = path.resolve(argument('--assets-dir', 'public'));
const output = path.resolve(argument('--output-dir', 'version-usage-browser-output'));
const tools = path.resolve(argument('--browser-tools', '.'));
const {chromium} = createRequire(path.join(tools, 'package.json'))('playwright');
const browser = await chromium.launch({args: ['--no-sandbox'],
    ...(process.env.CHROMIUM_EXECUTABLE_PATH ? {executablePath: process.env.CHROMIUM_EXECUTABLE_PATH} : {}),
});
fs.mkdirSync(output, {recursive: true});
const reports = [];
const origin = 'http://waterline.invalid';
const catalogs = new URL('../../resources/lang/', import.meta.url);

function observation(url, paginated) {
    const get = name => url.searchParams.get(name);
    const minimum = Number(get('min_version'));
    let runs = ['legacy', 'current', 'unknown'].map((state, index) => ({
        instance_id: index === 2 ? 'long-instance-'.repeat(25) : `order-${index + 1}`,
        run_id: `run-${index + 1}`, status: ['waiting', 'running', 'pending'][index], state,
        recorded_version: index === 2 ? null : minimum - (index === 0 ? 1 : 0),
        unknown_reason: index === 2 ? 'marker_absent' : null,
    }));
    if (paginated) {
        runs = [runs[0], ...Array.from({length: 49}, () => ({...runs[1]})), runs[2]]
            .map((run, index) => ({...run, run_id: `run-${String(index + 1).padStart(3, '0')}`}));
    }
    const counts = {total: runs.length, legacy: 1, current: runs.length - 2, unknown: 1};
    runs = runs.filter(run => get('state') === null || run.state === get('state'));
    const continuation = get('cursor') !== null;
    const visible = paginated && !continuation ? runs.slice(0, 50) : continuation ? runs.slice(50) : runs;
    return {
        schema_version: 1, namespace: get('namespace'), workflow_type: get('workflow_type'), change_id: get('change_id'),
        min_version: minimum, state: get('state'), consistency: 'live', through_run_id: paginated ? 'run-051' : 'run-3',
        counts, runs: visible, limit: 50,
        returned_count: visible.length, has_more: paginated && !continuation && runs.length > 50,
        next_cursor: paginated && !continuation && runs.length > 50 ? ' opaque /+ = ' : null,
    };
}

try {
    for (const locale of ['en', 'de', 'es', 'fr', 'ja', 'pt-BR', 'uk', 'zh-Hans']) {
        const catalog = JSON.parse(fs.readFileSync(new URL(`${locale}.json`, catalogs), 'utf8'));
        for (const theme of ['light', 'dark']) {
            const mode = theme === 'light' ? 'embedded' : 'service';
            const context = await browser.newContext({viewport: {width: 1440, height: 1000}});
            await context.addInitScript(value => localStorage.setItem('waterline-theme', value), theme);
            const page = await context.newPage();
            const errors = [], requests = [];
            let refusal = null, paginated = false;
            page.on('pageerror', error => errors.push(error.message));
            const config = {
                path: 'waterline', locale, app_name: 'Version usage source fixture', assets_current: true, maintenance: false,
                operator_scope: {mode: mode === 'service' ? 'namespace' : 'cluster', namespace: mode === 'service' ? 'orders' : null, label: 'orders'},
                backend: {mode, namespace: 'orders', authentication: 'configured', access_mode: 'read_only', capabilities: {}},
            };
            await context.route('**/*', async route => {
                const request = route.request(), url = new URL(request.url());
                assert.equal(url.origin, origin, 'No external requests are part of this fixture');
                assert.equal(request.method(), 'GET', 'Version inspection is read-only');
                if (url.pathname === '/waterline/api/v2/workflow-version-usage') {
                    requests.push(url);
                    return refusal ? route.fulfill({status: refusal, json: {reason: 'synthetic_read_refusal'}})
                        : route.fulfill({json: observation(url, paginated)});
                }
                if (url.pathname.startsWith('/vendor/waterline/')) {
                    const file = path.resolve(assets, url.pathname.slice('/vendor/waterline/'.length));
                    assert.ok(file.startsWith(assets + path.sep));
                    return route.fulfill({path: file, contentType: file.endsWith('.css') ? 'text/css' : 'text/javascript'});
                }
                assert.equal(url.pathname, '/waterline/version-usage');
                const serialized = JSON.stringify(config).replaceAll('&', '&amp;').replaceAll("'", '&#39;');
                return route.fulfill({contentType: 'text/html', body: `<!doctype html><html lang="${locale}"><head><meta name="viewport" content="width=device-width, initial-scale=1"><link id="app-stylesheet" rel="stylesheet" href="/vendor/waterline/app${theme === 'dark' ? '-dark' : ''}.css"><link rel="stylesheet" href="/vendor/waterline/components.css"></head><body><div id="waterline" data-waterline-config='${serialized}'></div><script type="module" src="/vendor/waterline/app.js"></script></body></html>`});
            });
            await page.goto(`${origin}/waterline/version-usage`);
            await page.waitForFunction(() => document.querySelector('#waterline')?.dataset.waterlineMounted === 'true');
            await page.getByRole('heading', {name: catalog['Version usage'], exact: true}).waitFor();
            const inputs = page.locator('.usage-filters input');
            assert.equal(await inputs.nth(0).isEditable(), mode === 'embedded');
            if (mode === 'embedded') await inputs.nth(0).fill(' Orders ');
            await inputs.nth(1).fill(' Orders ');
            await inputs.nth(2).fill(' carrier-api ');
            await inputs.nth(3).fill('3');
            await inputs.nth(3).press('Enter');
            await page.locator('.usage-counts').waitFor();
            assert.equal(requests.at(-1).searchParams.get('min_version'), '3', 'Edited numeric v-model reaches the read');
            assert.equal(requests.at(-1).searchParams.get('namespace'), mode === 'embedded' ? ' Orders ' : 'orders');
            assert.equal(requests.at(-1).searchParams.get('change_id'), ' carrier-api ');
            assert.equal(await page.locator('.usage-count--unknown dd').innerText(), '1');
            await page.getByText(catalog['No version marker recorded'], {exact: true}).waitFor();
            assert.equal(await page.locator('tbody a').last().getAttribute('href'), `/waterline/flows/instances/${'long-instance-'.repeat(25)}/runs/run-3`);
            for (const [width, zoom] of [[1440, 1], [390, 1], [820, 2]]) {
                await page.setViewportSize({width, height: 1000});
                await page.evaluate(value => {document.body.style.zoom = value;}, zoom);
                assert.ok(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1), `${locale}/${theme}/${width}/${zoom}: no document overflow`);
                const table = page.getByRole('region', {name: catalog['Open runs'], exact: true});
                await table.focus();
                assert.equal(await table.evaluate(element => element === document.activeElement), true);
                await table.evaluate(element => {element.scrollLeft = element.scrollWidth;});
                assert.ok(await table.evaluate(element => element.querySelector('tr:last-child td:last-child').getBoundingClientRect().right <= element.getBoundingClientRect().right + 2));
                await table.evaluate(element => {element.scrollLeft = 0;});
                const contrast = await page.evaluate(auditContrast, {labels: '.version-usage label', inputs: '.version-usage .form-control', body: '.version-usage p', counts: '.usage-counts dt, .usage-counts dd', table: '.version-usage th, .version-usage td', buttons: '.version-usage button:not(:disabled)'});
                for (const [category, targets] of Object.entries(contrast)) {
                    assert.ok(targets.length > 0, `${category} must be observed`);
                    assert.deepEqual(targets.filter(target => !target.pass), [], `${locale}/${theme}: ${category} contrast`);
                }
                reports.push({locale, theme, mode, width, zoom, minimumContrast: Math.min(...Object.values(contrast).flat().map(target => target.ratio))});
                if (locale === 'en' || locale === 'de') await page.screenshot({path: path.join(output, `${locale}-${theme}-${width}-${zoom}.png`), fullPage: true});
            }
            await page.evaluate(() => {document.body.style.zoom = 1;});
            await page.setViewportSize({width: 1440, height: 1000});
            if (locale === 'en') {
                paginated = true;
                await page.locator('button[type=submit]').click();
                await page.getByRole('button', {name: catalog['Next page'], exact: true}).click();
                await page.getByText(catalog['No version marker recorded'], {exact: true}).waitFor();
                assert.equal(requests.at(-1).searchParams.get('cursor'), ' opaque /+ = ');
                for (const status of [501, 422, 503]) {
                    refusal = status;
                    await page.locator('button[type=submit]').click();
                    await page.getByRole('alert').waitFor();
                    assert.equal(await page.locator('.usage-counts').count(), 0);
                }
                refusal = null;
                await page.getByRole('button', {name: catalog.Retry ?? 'Retry', exact: true}).click();
                await page.locator('.usage-counts').waitFor();
            }
            assert.deepEqual(errors, []);
            await context.close();
        }
    }
    fs.writeFileSync(path.join(output, 'report.json'), JSON.stringify({kind: 'synthetic-source-ui', cases: reports}, null, 2) + '\n');
    console.log(`${reports.length} version-usage locale/theme/reflow/contrast cases passed, with keyboard, paging and refusal recovery.`);
} finally {
    await browser.close();
}
