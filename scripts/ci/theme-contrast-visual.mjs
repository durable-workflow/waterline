// Browser-computed text contrast for the waterline#214 surfaces in both themes.
//
// node scripts/ci/theme-contrast-visual.mjs --base-url URL --output-dir DIR
//     [--email demo@example.com] [--password password]
//
// In both themes at 1440x900 and 390x844, opens lists and run details for failed,
// completed and cancelled runs (bounded and full), plus Schedules. Every target of
// the issue's acceptance surfaces must reach 4.5:1:
// run-list id lines, table headers, card subtitles, run-detail muted metadata,
// sidebar labels (hidden at <= 1100px) and status badges. Backgrounds composite
// every ancestor background-color (opacity groups; an uncovered background image
// counts as its worst colour stop); WCAG 2.x luminance, ratios never rounded up.
import { createRequire } from 'node:module';
import fs from 'node:fs';
import path from 'node:path';
import { pathToFileURL } from 'node:url';

const THEMES = ['light', 'dark'];
const VIEWPORTS = [
    { name: 'desktop', width: 1440, height: 900 },
    { name: 'mobile', width: 390, height: 844 },
];
const SIDEBAR_HIDDEN_AT = 1100;
const CATEGORIES = {
    'run-list-id-lines': '.flow-row .flow-row__meta .flow-row__mono',
    'table-headers': '.wl-main table th',
    'card-subtitles': '.wl-main .card-header small, .wl-main .card-header .small, .wl-main .card-header .text-muted',
    'run-detail-muted': '.wl-flow-detail .text-muted, .wl-flow-detail__eyebrow, .wl-flow-detail__summary-body .col-md-2, .wl-flow-detail__headline-pill, .wl-flow-detail .workflow-stream-mobile-row dt',
    'sidebar-labels': '.wl-sidebar__label',
    'status-badges': '.wl-main .badge',
};
// Categories each page must render; all categories are checked wherever present.
const REQUIRED = {
    list: ['run-list-id-lines', 'table-headers', 'sidebar-labels'],
    detail: ['run-detail-muted', 'card-subtitles', 'sidebar-labels', 'status-badges'],
    'detail-full': ['run-detail-muted', 'card-subtitles', 'table-headers', 'sidebar-labels', 'status-badges'],
    schedules: ['table-headers', 'sidebar-labels'],
};

function argumentValue(name, fallback = null) {
    const index = process.argv.indexOf(name);
    return index === -1 ? fallback : process.argv[index + 1];
}

// Playwright comes from the working directory (the job installs it in Sample App).
function loadPlaywright() {
    const require = createRequire(path.join(process.cwd(), 'package.json'));
    return { playwright: require('playwright'), version: require('playwright/package.json').version };
}

async function maybeLogin(page, baseUrl, email, password) {
    await page.goto(new URL('/waterline/completed', baseUrl).href, { waitUntil: 'networkidle', timeout: 30_000 });
    if (!/\/login(?:$|\?)/.test(page.url())) return;
    await page.fill('input[name=email]', email);
    await page.fill('input[name=password]', password);
    await Promise.all([
        page.waitForNavigation({ waitUntil: 'networkidle', timeout: 30_000 }).catch(() => {}),
        page.click('button[type=submit], input[type=submit]'),
    ]);
}

async function findRuns(page, baseUrl) {
    const rows = {};
    for (const bucket of ['failed', 'completed', 'cancelled', 'running']) {
        const response = await page.request.get(new URL(`/waterline/api/flows/${bucket}`, baseUrl).href, {
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        });
        const body = response.ok() ? await response.json() : null;
        rows[bucket] = Array.isArray(body?.data) ? body.data : [];
    }
    return ['failed', 'completed', 'cancelled'].map((bucket) => {
        const run = rows[bucket].find((row) => (row.instance_id || row.workflow_instance_id) === `theme-contrast-${bucket}`);
        if (!run) throw new Error(`The seeded ${bucket} run is not visible; seed all three terminal outcomes before the contrast check.`);
        const instanceId = run.instance_id || run.workflow_instance_id;
        return {
            status: bucket,
            runId: run.run_id,
            listPath: `/waterline/${bucket}`,
            detailPath: `/waterline/flows/instances/${encodeURIComponent(instanceId)}/runs/${encodeURIComponent(run.run_id)}`,
            terminalEvent: { failed: 'WorkflowFailed', completed: 'WorkflowCompleted', cancelled: 'WorkflowCancelled' }[bucket],
        };
    });
}

async function openPage(page, url, theme, readySelector) {
    await page.goto(url, { waitUntil: 'networkidle', timeout: 30_000 });
    await page.waitForFunction((file) => {
        const link = document.getElementById('app-stylesheet');
        try {
            return Boolean(link && new URL(link.href).pathname.endsWith(file) && link.sheet.cssRules.length > 0);
        } catch {
            return false;
        }
    }, theme === 'light' ? '/app.css' : '/app-dark.css', { timeout: 15_000 });
    await page.locator(readySelector).first().waitFor({ state: 'visible', timeout: 15_000 });
    await settle(page);
}

async function settle(page) {
    await page.waitForLoadState('networkidle');
    await page.evaluate(async () => {
        const deadline = Date.now() + 3000;
        while (Date.now() < deadline && document.getAnimations().some((animation) => animation.playState === 'running')) {
            await new Promise((resolve) => setTimeout(resolve, 100));
        }
        window.scrollTo(0, 0);
    });
}

// Runs in the page. Returns every visible text target of each category.
function auditContrast(categories) {
    // Computed colours serialize as rgb()/rgba(), or color(srgb ...) after color-mix().
    const parse = (value) => {
        const wrapper = value.match(/^(rgba?|color)\((.*)\)$/);
        if (!wrapper) return value === 'transparent' ? { r: 0, g: 0, b: 0, a: 0 } : null;
        const channels = wrapper[2].replace(/^srgb\s+/, '').trim().split(/[ ,/]+/);
        if (channels.length !== 3 && channels.length !== 4) return null;
        const numbers = channels.map((channel, index) => Number(index === 3 ? channel.replace(/%$/, '') : channel));
        if (numbers.some((number) => !Number.isFinite(number))) return null;
        const scale = wrapper[1] === 'color' ? 255 : 1;
        let alpha = numbers[3] ?? 1;
        if (channels[3]?.endsWith('%')) alpha /= 100;
        return { r: numbers[0] * scale, g: numbers[1] * scale, b: numbers[2] * scale, a: alpha };
    };
    const over = (top, bottom) => {
        const a = top.a + bottom.a * (1 - top.a);
        const mix = (key) => (a === 0 ? 0 : (top[key] * top.a + bottom[key] * bottom.a * (1 - top.a)) / a);
        return { r: mix('r'), g: mix('g'), b: mix('b'), a };
    };
    const channel = (value) => {
        const v = Math.min(255, Math.max(0, value)) / 255;
        return v <= 0.04045 ? v / 12.92 : ((v + 0.055) / 1.055) ** 2.4;
    };
    const luminance = (c) => 0.2126 * channel(c.r) + 0.7152 * channel(c.g) + 0.0722 * channel(c.b);
    const contrast = (a, b) => (Math.max(luminance(a), luminance(b)) + 0.05) / (Math.min(luminance(a), luminance(b)) + 0.05);
    const hex = (c) => `#${[c.r, c.g, c.b].map((v) => Math.round(Math.min(255, Math.max(0, v))).toString(16).padStart(2, '0')).join('')}`;
    const ownText = (element) => [...element.childNodes].filter((node) => node.nodeType === 3).map((node) => node.nodeValue).join('').replace(/\s+/g, ' ').trim();
    const hasTextBox = (node) => {
        if (node.nodeType !== 3 || !node.nodeValue.trim()) return false;
        const range = document.createRange();
        range.selectNodeContents(node);
        return [...range.getClientRects()].some((rect) => rect.width > 1 && rect.height > 1);
    };
    const visible = (element) => {
        for (let node = element; node; node = node.parentElement) {
            const style = getComputedStyle(node);
            if (style.display === 'none' || style.visibility !== 'visible' || Number(style.opacity) === 0 || node.classList.contains('sr-only')) return false;
        }
        return [...element.childNodes].some(hasTextBox);
    };
    const describe = (element) => {
        const parts = [];
        for (let node = element; node && node !== document.body && parts.length < 3; node = node.parentElement) parts.unshift(node.tagName.toLowerCase() + [...node.classList].slice(0, 3).map((name) => `.${name}`).join(''));
        return parts.join(' > ');
    };
    // Composites the ancestor chain; `stop` replaces an uncovered background image.
    const render = (chain, text, stop) => {
        const stack = [{ canvas: { r: 255, g: 255, b: 255, a: 1 }, opacity: 1 }];
        for (const node of chain) {
            const style = getComputedStyle(node);
            if (Number(style.opacity) < 1) stack.push({ canvas: { r: 0, g: 0, b: 0, a: 0 }, opacity: Number(style.opacity) });
            const top = stack.at(-1);
            top.canvas = over(parse(style.backgroundColor) || { r: 0, g: 0, b: 0, a: 0 }, top.canvas);
            if (stop && node === stop.node) top.canvas = over(stop.color, top.canvas);
        }
        if (text) stack.at(-1).canvas = over(text, stack.at(-1).canvas);
        while (stack.length > 1) {
            const group = stack.pop();
            stack.at(-1).canvas = over({ ...group.canvas, a: group.canvas.a * group.opacity }, stack.at(-1).canvas);
        }
        return stack[0].canvas;
    };
    const measure = (element) => {
        const chain = [];
        for (let node = element; node; node = node.parentElement) chain.unshift(node);
        let imageNode = null;
        for (const node of chain) {
            const style = getComputedStyle(node);
            if ((parse(style.backgroundColor)?.a ?? 0) >= 1 && Number(style.opacity) === 1) imageNode = null;
            if (style.backgroundImage !== 'none') imageNode = node;
        }
        const style = getComputedStyle(element);
        const text = parse(style.color) || { r: 0, g: 0, b: 0, a: 1 };
        const stops = imageNode ? (getComputedStyle(imageNode).backgroundImage.match(/rgba?\([^)]*\)/g) || []).map(parse).filter(Boolean) : [];
        const worst = [null, ...stops].map((color) => {
            const stop = color ? { node: imageNode, color } : null;
            const fg = render(chain, text, stop);
            const bg = render(chain, null, stop);
            return { fg, bg, ratio: contrast(fg, bg) };
        }).sort((a, b) => a.ratio - b.ratio)[0];
        const size = Number.parseFloat(style.fontSize);
        const weight = Number.parseInt(style.fontWeight, 10) || 400;
        const minimum = 4.5;
        return {
            selector: describe(element), text: (ownText(element) || element.innerText || '').slice(0, 80),
            fg: hex(worst.fg), bg: hex(worst.bg), ratio: Math.floor(worst.ratio * 100) / 100, minimum,
            fontSize: size, fontWeight: weight, pass: worst.ratio >= minimum,
            ...(imageNode ? { backgroundImage: 'worst colour stop of an uncovered background image' } : {}),
        };
    };

    return Object.fromEntries(Object.entries(categories).map(([category, selector]) => {
        const targets = new Set();
        for (const element of document.querySelectorAll(selector)) {
            const candidates = ownText(element) ? [element] : [...element.querySelectorAll('*')].filter((node) => ownText(node));
            candidates.filter(visible).forEach((node) => targets.add(node));
        }
        return [category, [...targets].map(measure)];
    }));
}

async function auditCase(page, { theme, viewport, name, required }) {
    const results = await page.evaluate(auditContrast, CATEGORIES);
    const failures = [];
    const counts = {};

    for (const [category, targets] of Object.entries(results)) {
        const failing = targets.filter((target) => !target.pass);
        counts[category] = { targets: targets.length, failing: failing.length, min: targets.length ? Math.min(...targets.map((t) => t.ratio)) : null };
        failing.forEach((target) => failures.push({ category, ...target }));
    }
    for (const category of required) {
        if (category === 'sidebar-labels' && viewport.width <= SIDEBAR_HIDDEN_AT) continue;
        if (!counts[category].targets) failures.push({ category, missing: true, message: `no visible ${category} target` });
    }

    return { theme, viewport: viewport.name, page: name, url: page.url(), status: failures.length ? 'failed' : 'passed', counts, failures };
}

export async function runThemeContrastVisual({ baseUrl, outputDirectory, email = 'demo@example.com', password = 'password' }) {
    fs.mkdirSync(outputDirectory, { recursive: true });
    const { playwright, version } = loadPlaywright();
    const launchOptions = { args: ['--no-sandbox'] };
    if (process.env.CHROMIUM_EXECUTABLE_PATH) launchOptions.executablePath = process.env.CHROMIUM_EXECUTABLE_PATH;
    const browser = await playwright.chromium.launch(launchOptions);
    const cases = [];
    let runs = null;

    try {
        for (const theme of THEMES) {
            for (const viewport of VIEWPORTS) {
                const context = await browser.newContext({
                    viewport: { width: viewport.width, height: viewport.height },
                    deviceScaleFactor: 1, isMobile: viewport.name === 'mobile', hasTouch: viewport.name === 'mobile',
                    colorScheme: theme, reducedMotion: 'reduce',
                });
                await context.addInitScript((value) => localStorage.setItem('waterline-theme', value), theme);
                const page = await context.newPage();
                const failed = (name, error) => cases.push({ theme, viewport: viewport.name, page: name, url: page.url(), status: 'failed', failures: [{ category: 'page', message: String(error?.message || error) }] });
                const visit = async (name, pathname, ready, prepare = null) => {
                    try {
                        await openPage(page, new URL(pathname, baseUrl).href, theme, ready);
                        if (prepare) await prepare();
                        cases.push(await auditCase(page, { theme, viewport, name, required: REQUIRED[name.split(':')[0]] }));
                        await page.screenshot({ path: path.join(outputDirectory, `${theme}-${viewport.name}-${name.replace(':', '-')}.png`), fullPage: true });
                    } catch (error) {
                        failed(name, error);
                    }
                };

                try {
                    await maybeLogin(page, baseUrl, email, password);
                    runs ||= await findRuns(page, baseUrl);
                    for (const run of runs) {
                        const terminalBadge = () => page.locator('.timeline-event-label .badge').filter({ hasText: new RegExp(`^${run.terminalEvent}$`) }).waitFor({ state: 'visible', timeout: 15_000 });
                        await visit(`list:${run.status}`, run.listPath, '.flow-row');
                        await visit(`detail:${run.status}`, run.detailPath, '.wl-flow-detail__status', terminalBadge);
                        // Full inspection must preserve the same terminal event's badge.
                        await visit(`detail-full:${run.status}`, run.detailPath, '.wl-flow-detail__status', async () => {
                            const inspect = page.getByRole('button', { name: 'Inspect full details' });
                            if (await inspect.count()) {
                                await inspect.first().click();
                                await page.locator('#collapseArguments').waitFor({ state: 'attached', timeout: 15_000 });
                            }
                            await terminalBadge();
                            await settle(page);
                        });
                    }
                    await visit('schedules', '/waterline/schedules', '.wl-main table tbody tr');
                } catch (error) {
                    failed('setup', error);
                } finally {
                    await context.close();
                }
            }
        }
    } finally {
        await browser.close();
    }

    const failures = cases.flatMap((entry) => entry.failures.map((failure) => ({ theme: entry.theme, viewport: entry.viewport, page: entry.page, ...failure })));
    const report = {
        generatedAt: new Date().toISOString(), baseUrl, playwright: version, runs,
        rules: 'WCAG 2.x relative luminance; every named acceptance surface requires 4.5:1; ratios truncated, never rounded up',
        passedCases: cases.filter((entry) => entry.status === 'passed').length,
        failedCases: cases.filter((entry) => entry.status === 'failed').length,
        failures, cases,
    };
    fs.writeFileSync(path.join(outputDirectory, 'theme-contrast-report.json'), `${JSON.stringify(report, null, 2)}\n`);

    return report;
}

if (process.argv[1] && import.meta.url === pathToFileURL(path.resolve(process.argv[1])).href) {
    const outputDirectory = argumentValue('--output-dir', 'theme-contrast-evidence');
    const report = await runThemeContrastVisual({
        baseUrl: argumentValue('--base-url', process.env.APP_URL || 'http://127.0.0.1:8000'),
        outputDirectory,
        email: argumentValue('--email', process.env.WATERLINE_VISUAL_EMAIL || 'demo@example.com'),
        password: argumentValue('--password', process.env.WATERLINE_VISUAL_PASSWORD || 'password'),
    });

    for (const entry of report.cases) {
        const counts = Object.entries(entry.counts || {}).filter(([, count]) => count.targets).map(([category, count]) => `${category} ${count.targets} min ${count.min.toFixed(2)}`);
        console.log(`${entry.status === 'passed' ? 'PASS' : 'FAIL'} ${entry.theme} ${entry.viewport} ${entry.page}: ${counts.join(', ') || entry.url}`);
    }
    for (const f of report.failures) {
        const detail = f.message || `${f.ratio.toFixed(2)} < ${f.minimum} ${f.fg} on ${f.bg} ${f.fontSize}px/${f.fontWeight} "${f.text}" (${f.selector})`;
        console.log(`  ${f.theme} ${f.viewport} ${f.page} ${f.category}: ${detail}`);
    }
    console.log(`${report.passedCases} passed, ${report.failedCases} failed; report: ${path.join(outputDirectory, 'theme-contrast-report.json')}`);
    process.exitCode = report.failedCases > 0 ? 1 : 0;
}
