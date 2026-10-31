import assert from 'node:assert/strict';
import {after, test} from 'node:test';
import {createRequire} from 'node:module';
import path from 'node:path';
import {inspectDashboardTables} from '../../scripts/ci/dashboard-visual.mjs';

const {chromium} = createRequire(path.join(process.cwd(), 'package.json'))('playwright');
const browser = await chromium.launch({args: ['--no-sandbox']});
after(() => browser.close());

async function fixture(count = 4) {
    const page = await browser.newPage();
    const labels = ['Current status', 'Recent trends', 'Workflow type health', 'Worker compatibility fleet'];
    await page.setContent(`<style>.table-responsive {width:100px;overflow:auto} table {width:600px}</style>
        <main class="wl-dashboard-view">${labels.slice(0, count).map(label =>
            `<div class="table-responsive" role="region" tabindex="0" aria-label="${label}"><table><tr><td>First</td><td>Last</td></tr></table></div>`
        ).join('')}</main>`);
    return page;
}

test('all four labelled dashboard tables remain focusable and scrollable', async () => {
    const page = await fixture();
    try {
        await inspectDashboardTables(page, 'stable');
    } finally {
        await page.close();
    }
});

test('a table keeps its identity when another region moves during focus', async () => {
    const page = await fixture();
    try {
        await page.getByRole('region', {name: 'Current status', exact: true}).evaluate(element => {
            element.addEventListener('focus', () => {
                const root = element.parentElement;
                root.prepend(root.lastElementChild);
            }, {once: true});
        });
        await inspectDashboardTables(page, 'reordered');
    } finally {
        await page.close();
    }
});

test('a missing fixture table is refused rather than reducing coverage', async () => {
    const page = await fixture(3);
    try {
        await assert.rejects(inspectDashboardTables(page, 'missing'), /four dashboard tables/);
    } finally {
        await page.close();
    }
});

test('table observation waits for a replacement theme stylesheet to load', async () => {
    const page = await fixture();
    try {
        await page.route('http://theme.test/*.css', async route => {
            await new Promise(resolve => setTimeout(resolve, 1500));
            await route.fulfill({contentType: 'text/css', body: '.table-responsive {width:100px;overflow:auto} table {width:600px}'});
        });
        await page.evaluate(() => {
            for (const cell of document.querySelectorAll('td:first-child')) cell.textContent = 'WorkflowType'.repeat(40);
            document.querySelector('style').remove();
            const link = document.createElement('link');
            link.id = 'app-stylesheet';
            link.rel = 'stylesheet';
            link.href = 'http://theme.test/theme.css';
            document.head.append(link);
        });
        assert.equal(await page.locator('#app-stylesheet').evaluate(link => link.sheet === null), true);
        await inspectDashboardTables(page, 'theme-loading');
        assert.equal(await page.locator('#app-stylesheet').evaluate(link => link.sheet !== null), true);
        const alternateRequested = page.waitForRequest('http://theme.test/alternate.css');
        await page.locator('#app-stylesheet').evaluate(link => { link.href = 'http://theme.test/alternate.css'; });
        await alternateRequested;
        await page.locator('#app-stylesheet').evaluate(link => { link.href = 'http://theme.test/theme.css'; });
        assert.equal(await page.locator('#app-stylesheet').evaluate(link => [...document.styleSheets].includes(link.sheet)), false);
        await inspectDashboardTables(page, 'cached-theme-loading');
        assert.equal(await page.locator('#app-stylesheet').evaluate(link => [...document.styleSheets].includes(link.sheet)), true);
    } finally {
        await page.close();
    }
});

test('a failed theme cannot pass the table reachability check', async () => {
    const page = await fixture();
    try {
        await page.route('http://theme.test/missing.css', route => route.abort());
        await page.evaluate(() => {
            for (const cell of document.querySelectorAll('td:first-child')) cell.textContent = 'WorkflowType'.repeat(40);
            document.querySelector('style').remove();
            const link = document.createElement('link');
            link.id = 'app-stylesheet';
            link.rel = 'stylesheet';
            link.href = 'http://theme.test/missing.css';
            document.head.append(link);
        });
        await assert.rejects(inspectDashboardTables(page, 'theme-failed'), /last table column must remain reachable|Timeout/);
    } finally {
        await page.close();
    }
});
