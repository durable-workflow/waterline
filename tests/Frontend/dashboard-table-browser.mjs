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
