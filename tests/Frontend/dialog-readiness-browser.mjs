import assert from 'node:assert/strict';
import { after, test } from 'node:test';
import { createRequire } from 'node:module';
import path from 'node:path';
import { waitForDialogReady } from '../../scripts/ci/dialog-readiness.mjs';

const { chromium } = createRequire(path.join(process.cwd(), 'package.json'))('playwright');
const browser = await chromium.launch({ args: ['--no-sandbox'] });
after(() => browser.close());

test('an attached but transparent popup with no animation is not ready', async () => {
    const page = await browser.newPage();
    try {
        await page.setContent('<div role="dialog" style="opacity:0">Result</div>');
        await assert.rejects(waitForDialogReady(page, page.getByRole('dialog'), 150), /Timeout/);
    } finally {
        await page.close();
    }
});

test('a visible popup is ready only after its actual animation finishes', async () => {
    const page = await browser.newPage();
    try {
        await page.setContent('<div role="dialog">Result</div>');
        await page.getByRole('dialog').evaluate(node => {
            node.animate([{ transform: 'scale(0.7)' }, { transform: 'scale(1)' }], { duration: 250 });
        });
        await waitForDialogReady(page, page.getByRole('dialog'));
        assert.equal(await page.getByRole('dialog').evaluate(node => (
            getComputedStyle(node).opacity === '1'
            && node.getAnimations().every(animation => animation.playState !== 'running')
        )), true);
    } finally {
        await page.close();
    }
});

test('a visible popup without animation is ready', async () => {
    const page = await browser.newPage({ reducedMotion: 'reduce' });
    try {
        await page.setContent('<div role="dialog">Result</div>');
        await waitForDialogReady(page, page.getByRole('dialog'));
    } finally {
        await page.close();
    }
});

test('readiness does not reposition or conceal a persistent viewport overflow', async () => {
    const page = await browser.newPage();
    try {
        await page.setContent('<div role="dialog" style="position:fixed;top:-10px">Result</div>');
        await waitForDialogReady(page, page.getByRole('dialog'));
        assert.equal(await page.getByRole('dialog').evaluate(node => node.getBoundingClientRect().top), -10);
    } finally {
        await page.close();
    }
});
