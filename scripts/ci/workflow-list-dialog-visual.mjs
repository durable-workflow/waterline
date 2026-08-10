import { createRequire } from 'node:module';
import { execFileSync } from 'node:child_process';
import fs from 'node:fs';
import path from 'node:path';
import { pathToFileURL } from 'node:url';

export const VIEWPORTS = [
    { name: 'desktop', width: 1440, height: 900 },
    { name: 'intermediate', width: 900, height: 768 },
    { name: 'mobile', width: 390, height: 844 },
    { name: 'short-height', width: 1280, height: 480 },
];

export const DIALOGS = [
    {
        name: 'filters',
        buttonName: 'Filters',
        title: 'Edit Filters',
        validation: true,
        requiredContrastCategories: ['title', 'label', 'help', 'notice', 'input', 'validation', 'action'],
    },
    {
        name: 'view-options',
        buttonName: 'View Options',
        title: 'View Options',
        validation: false,
        requiredContrastCategories: ['title', 'label', 'input', 'action'],
    },
];

function argumentValue(name, fallback = null) {
    const index = process.argv.indexOf(name);

    return index === -1 ? fallback : process.argv[index + 1];
}

function loadPlaywright() {
    const roots = [process.cwd()];

    try {
        roots.push(execFileSync('npm', ['root', '--global'], { encoding: 'utf8' }).trim());
    } catch {
        // The local project resolution below reports the actionable module error.
    }

    let lastError = null;

    for (const root of roots.filter(Boolean)) {
        try {
            const require = createRequire(path.join(root, 'package.json'));

            return require('playwright');
        } catch (error) {
            lastError = error;
        }
    }

    throw lastError;
}

async function maybeLogin(page, baseUrl, email, password) {
    await page.goto(new URL('/waterline/completed', baseUrl).href, {
        waitUntil: 'networkidle',
        timeout: 30_000,
    });

    if (!/\/login(?:$|\?)/.test(page.url())) {
        return;
    }

    await page.fill('input[name=email]', email);
    await page.fill('input[name=password]', password);
    await Promise.all([
        page.waitForNavigation({ waitUntil: 'networkidle', timeout: 30_000 }).catch(() => {}),
        page.click('button[type=submit], input[type=submit]'),
    ]);
    await page.goto(new URL('/waterline/completed', baseUrl).href, {
        waitUntil: 'networkidle',
        timeout: 30_000,
    });
}

async function waitForWorkflowList(page) {
    await page.waitForFunction(() => (
        document.getElementById('waterline')?.getAttribute('data-waterline-mounted') === 'true'
    ), { timeout: 20_000 });
    await page.getByRole('button', { name: 'View Options', exact: true }).waitFor({
        state: 'visible',
        timeout: 20_000,
    });
    await page.waitForFunction(() => {
        const stylesheet = document.getElementById('app-stylesheet');

        return stylesheet instanceof HTMLLinkElement
            && stylesheet.href.includes('/vendor/waterline/app-dark.css');
    }, { timeout: 10_000 });
}

async function triggerValidation(page) {
    const labels = page.locator('#waterline-filter-labels');
    const searchAttributes = page.locator('#waterline-filter-search-attributes');

    if (await labels.count()) {
        await labels.fill('invalid-label-filter');
    } else if (await searchAttributes.count()) {
        await searchAttributes.fill('invalid-search-attribute-filter');
    } else {
        throw new Error('The filter dialog has no structured metadata input for validation coverage.');
    }

    await page.locator('.waterline-dialog .swal2-confirm').click();
    await page.locator('.waterline-dialog .swal2-validation-message').waitFor({
        state: 'visible',
        timeout: 5_000,
    });
}

async function auditContrast(page, requiredCategories) {
    const results = await page.locator('.waterline-dialog').evaluate((popup) => {
        const parseColor = (value) => {
            const match = value.match(/rgba?\(([^)]+)\)/);

            if (!match) {
                return null;
            }

            const channels = match[1].split(/[\s,/]+/).filter(Boolean).map(Number);

            return {
                r: channels[0],
                g: channels[1],
                b: channels[2],
                a: channels.length > 3 ? channels[3] : 1,
            };
        };
        const blend = (foreground, background) => ({
            r: foreground.r * foreground.a + background.r * (1 - foreground.a),
            g: foreground.g * foreground.a + background.g * (1 - foreground.a),
            b: foreground.b * foreground.a + background.b * (1 - foreground.a),
            a: 1,
        });
        const effectiveBackground = (element) => {
            let current = element;
            let background = { r: 255, g: 255, b: 255, a: 1 };
            const layers = [];

            while (current) {
                const color = parseColor(getComputedStyle(current).backgroundColor);

                if (color && color.a > 0) {
                    layers.push(color);
                }

                current = current.parentElement;
            }

            for (const layer of layers.reverse()) {
                background = blend(layer, background);
            }

            return background;
        };
        const luminance = (color) => {
            const channel = (value) => {
                const normalized = value / 255;

                return normalized <= 0.03928
                    ? normalized / 12.92
                    : ((normalized + 0.055) / 1.055) ** 2.4;
            };

            return 0.2126 * channel(color.r) + 0.7152 * channel(color.g) + 0.0722 * channel(color.b);
        };
        const contrast = (foreground, background) => {
            const foregroundLuminance = luminance(foreground);
            const backgroundLuminance = luminance(background);

            return (Math.max(foregroundLuminance, backgroundLuminance) + 0.05)
                / (Math.min(foregroundLuminance, backgroundLuminance) + 0.05);
        };
        const categories = [
            ['title', '.swal2-title'],
            ['label', 'label'],
            ['help', 'small.text-muted'],
            ['notice', '.card-bg-secondary'],
            ['input', '.swal2-input, .swal2-textarea, .swal2-select'],
            ['validation', '.swal2-validation-message'],
            ['action', '.swal2-actions button'],
        ];
        const audits = [];

        for (const [category, selector] of categories) {
            for (const element of popup.querySelectorAll(selector)) {
                const style = getComputedStyle(element);
                const rect = element.getBoundingClientRect();

                if (style.display === 'none' || style.visibility === 'hidden' || rect.width === 0 || rect.height === 0) {
                    continue;
                }

                const foreground = parseColor(style.color);
                const background = effectiveBackground(element);

                if (!foreground) {
                    audits.push({ category, selector, error: `Unsupported color ${style.color}` });
                    continue;
                }

                audits.push({
                    category,
                    selector,
                    text: (element.textContent || element.getAttribute('placeholder') || '').trim().slice(0, 120),
                    foreground: style.color,
                    background: `rgb(${Math.round(background.r)}, ${Math.round(background.g)}, ${Math.round(background.b)})`,
                    ratio: contrast(foreground, background),
                });

                if (element.matches('input[placeholder], textarea[placeholder]')) {
                    const placeholderStyle = getComputedStyle(element, '::placeholder');
                    const placeholder = parseColor(placeholderStyle.color);

                    if (placeholder) {
                        audits.push({
                            category: 'input',
                            selector: `${selector}::placeholder`,
                            text: element.getAttribute('placeholder')?.slice(0, 120) || '',
                            foreground: placeholderStyle.color,
                            background: `rgb(${Math.round(background.r)}, ${Math.round(background.g)}, ${Math.round(background.b)})`,
                            ratio: contrast(placeholder, background),
                        });
                    }
                }
            }
        }

        return audits;
    });

    for (const category of requiredCategories) {
        if (!results.some((result) => result.category === category)) {
            throw new Error(`Dialog contrast audit did not find the required ${category} surface.`);
        }
    }

    const failures = results.filter((result) => result.error || result.ratio < 4.5);

    if (failures.length > 0) {
        throw new Error(`Dialog text contrast fell below 4.5:1: ${JSON.stringify(failures)}`);
    }

    return results;
}

async function auditModalGeometry(page, dialog, viewport) {
    const geometry = await page.locator('.waterline-dialog').evaluate((popup, expectedInternalScroll) => {
        const rect = popup.getBoundingClientRect();
        const body = popup.querySelector('.waterline-dialog__body');
        const appRoot = document.getElementById('waterline');
        const container = popup.closest('.swal2-container');
        const outsideControl = appRoot?.querySelector('button:not([disabled]), a[href], input:not([disabled]), select:not([disabled])');
        let outsideHit = null;

        if (outsideControl) {
            const outsideRect = outsideControl.getBoundingClientRect();
            const x = Math.min(window.innerWidth - 1, Math.max(0, outsideRect.left + outsideRect.width / 2));
            const y = Math.min(window.innerHeight - 1, Math.max(0, outsideRect.top + outsideRect.height / 2));
            const hit = document.elementFromPoint(x, y);

            outsideHit = {
                blockedByModal: hit !== outsideControl && !outsideControl.contains(hit),
                hitClass: hit?.className || hit?.tagName || null,
            };
        }

        const result = {
            popup: {
                left: rect.left,
                right: rect.right,
                top: rect.top,
                bottom: rect.bottom,
                clientHeight: popup.clientHeight,
                scrollHeight: popup.scrollHeight,
            },
            body: body ? {
                clientHeight: body.clientHeight,
                scrollHeight: body.scrollHeight,
                clientWidth: body.clientWidth,
                scrollWidth: body.scrollWidth,
                overflowY: getComputedStyle(body).overflowY,
            } : null,
            documentWidth: document.documentElement.scrollWidth,
            viewportWidth: window.innerWidth,
            viewportHeight: window.innerHeight,
            appRootInert: appRoot?.hasAttribute('inert') === true,
            backdropSemantics: container?.getAttribute('data-waterline-modal-backdrop'),
            dialogSemantics: popup.getAttribute('data-waterline-dialog'),
            role: popup.getAttribute('role'),
            ariaModal: popup.getAttribute('aria-modal'),
            activeElementInside: popup.contains(document.activeElement),
            outsideHit,
        };

        const failures = [];

        if (rect.left < -1 || rect.top < -1 || rect.right > window.innerWidth + 1 || rect.bottom > window.innerHeight + 1) {
            failures.push('popup leaves the viewport');
        }

        if (popup.scrollHeight > popup.clientHeight + 1) {
            failures.push('popup itself overflows instead of delegating to its body');
        }

        if (!body || body.scrollWidth > body.clientWidth + 1) {
            failures.push('dialog body has horizontal overflow');
        }

        if (body && body.scrollHeight > body.clientHeight + 1 && !['auto', 'scroll'].includes(getComputedStyle(body).overflowY)) {
            failures.push('dialog body cannot scroll overflowing content');
        }

        if (expectedInternalScroll && body && body.scrollHeight <= body.clientHeight + 1) {
            failures.push('short filter dialog did not exercise its internal scroll region');
        }

        if (document.documentElement.scrollWidth > window.innerWidth + 1) {
            failures.push('document has horizontal overflow');
        }

        if (!result.appRootInert || result.backdropSemantics !== 'intentional') {
            failures.push('background is not marked as intentionally inert');
        }

        if (result.dialogSemantics !== 'modal' || result.role !== 'dialog' || result.ariaModal !== 'true') {
            failures.push('live dialog modal semantics are incomplete');
        }

        if (!result.activeElementInside) {
            failures.push('focus is outside the open dialog');
        }

        if (outsideHit && !outsideHit.blockedByModal) {
            failures.push('an inert background control remains live through the backdrop');
        }

        return { ...result, failures };
    }, viewport.name === 'short-height');

    if (geometry.failures.length > 0) {
        throw new Error(`Dialog geometry failed: ${geometry.failures.join('; ')}`);
    }

    return geometry;
}

async function auditControlReachability(page) {
    const controls = page.locator('.waterline-dialog button:not([disabled]), .waterline-dialog input:not([disabled]), .waterline-dialog select:not([disabled]), .waterline-dialog textarea:not([disabled])');
    const count = await controls.count();
    const results = [];

    for (let index = 0; index < count; index += 1) {
        const control = controls.nth(index);

        if (!await control.isVisible()) {
            continue;
        }

        await control.scrollIntoViewIfNeeded();
        const result = await control.evaluate((element) => {
            const rect = element.getBoundingClientRect();
            const x = rect.left + rect.width / 2;
            const y = rect.top + rect.height / 2;
            const hit = document.elementFromPoint(x, y);
            const style = getComputedStyle(element);
            const canvas = document.createElement('canvas');
            const context = canvas.getContext('2d');
            let clipped = element.matches('button')
                && (element.scrollWidth > element.clientWidth + 1 || element.scrollHeight > element.clientHeight + 1);

            const textInput = element.matches('input:not([type]), input[type="email"], input[type="number"], input[type="password"], input[type="search"], input[type="tel"], input[type="text"], input[type="url"]');

            if (context && (textInput || element.matches('select'))) {
                const text = element.matches('select')
                    ? element.selectedOptions?.[0]?.textContent?.trim() || ''
                    : element.value || element.getAttribute('placeholder') || '';
                const padding = (Number.parseFloat(style.paddingLeft) || 0)
                    + (Number.parseFloat(style.paddingRight) || 0);
                const selectAllowance = element.matches('select') && style.appearance !== 'none' ? 24 : 0;
                const availableWidth = Math.max(0, element.clientWidth - padding - selectAllowance);
                const letterSpacing = Number.parseFloat(style.letterSpacing) || 0;

                context.font = style.font || `${style.fontWeight} ${style.fontSize} ${style.fontFamily}`;
                clipped = clipped || context.measureText(text).width
                    + Math.max(0, text.length - 1) * letterSpacing > availableWidth + 1;
            }

            return {
                id: element.id || null,
                className: element.className,
                text: (element.textContent || element.getAttribute('aria-label') || '').trim().slice(0, 120),
                clipped,
                inViewport: rect.left >= 0
                    && rect.top >= 0
                    && rect.right <= window.innerWidth
                    && rect.bottom <= window.innerHeight,
                reachable: hit === element || element.contains(hit),
            };
        });

        results.push(result);
    }

    if (results.length === 0) {
        throw new Error('Dialog reachability audit found no live controls.');
    }

    const failures = results.filter((result) => result.clipped || !result.inViewport || !result.reachable);

    if (failures.length > 0) {
        throw new Error(`Dialog controls are clipped or unreachable: ${JSON.stringify(failures)}`);
    }

    for (const action of ['.swal2-confirm', '.swal2-cancel']) {
        const result = results.find((entry) => String(entry.className).includes(action.slice(1)));

        if (!result) {
            throw new Error(`Dialog is missing the reachable ${action} action.`);
        }
    }

    return results;
}

async function auditCheckboxAffordances(page) {
    const liveCheckbox = page.locator('.waterline-dialog .waterline-column-option:not(:disabled)').first();

    if (await liveCheckbox.count() === 0) {
        throw new Error('View Options has no interactive column checkbox.');
    }

    if (await liveCheckbox.isChecked()) {
        await liveCheckbox.uncheck();
    }

    const audit = await page.locator('.waterline-dialog').evaluate((popup) => {
        const parseColor = (value) => {
            const match = value.match(/rgba?\(([^)]+)\)/);

            if (!match) {
                return null;
            }

            const channels = match[1].split(/[\s,/]+/).filter(Boolean).map(Number);

            return {
                r: channels[0],
                g: channels[1],
                b: channels[2],
                a: channels.length > 3 ? channels[3] : 1,
            };
        };
        const blend = (foreground, background) => ({
            r: foreground.r * foreground.a + background.r * (1 - foreground.a),
            g: foreground.g * foreground.a + background.g * (1 - foreground.a),
            b: foreground.b * foreground.a + background.b * (1 - foreground.a),
            a: 1,
        });
        const luminance = (color) => {
            const channel = (value) => {
                const normalized = value / 255;

                return normalized <= 0.03928
                    ? normalized / 12.92
                    : ((normalized + 0.055) / 1.055) ** 2.4;
            };

            return 0.2126 * channel(color.r) + 0.7152 * channel(color.g) + 0.0722 * channel(color.b);
        };
        const contrast = (first, second) => (
            (Math.max(luminance(first), luminance(second)) + 0.05)
            / (Math.min(luminance(first), luminance(second)) + 0.05)
        );
        const popupBackground = parseColor(getComputedStyle(popup).backgroundColor);
        const checkboxes = Array.from(popup.querySelectorAll('.waterline-column-option')).map((checkbox) => {
            const style = getComputedStyle(checkbox);
            const markStyle = getComputedStyle(checkbox, '::before');
            const rect = checkbox.getBoundingClientRect();
            const border = parseColor(style.borderTopColor);
            const fill = parseColor(style.backgroundColor);
            const mark = parseColor(markStyle.borderBottomColor);

            return {
                id: checkbox.id,
                checked: checkbox.checked,
                disabled: checkbox.disabled,
                appearance: style.appearance,
                width: rect.width,
                height: rect.height,
                borderContrast: border && popupBackground ? contrast(border, popupBackground) : 0,
                fillContrast: fill && popupBackground ? contrast(fill, popupBackground) : 0,
                markContrast: mark && fill ? contrast(mark, blend(fill, popupBackground)) : 0,
                markOpacity: Number.parseFloat(markStyle.opacity),
            };
        });
        const failures = [];

        if (!checkboxes.some((checkbox) => checkbox.checked)) {
            failures.push('no checked column state is rendered');
        }

        if (!checkboxes.some((checkbox) => !checkbox.checked)) {
            failures.push('no unchecked column state is rendered');
        }

        for (const checkbox of checkboxes) {
            if (checkbox.appearance !== 'none') {
                failures.push(`${checkbox.id} still depends on a platform checkbox glyph`);
            }

            if (checkbox.width < 16 || checkbox.height < 16) {
                failures.push(`${checkbox.id} is smaller than its visible 16px affordance`);
            }

            if (checkbox.borderContrast < 3) {
                failures.push(`${checkbox.id} border contrast is ${checkbox.borderContrast.toFixed(2)}:1`);
            }

            if (checkbox.checked && checkbox.fillContrast < 3) {
                failures.push(`${checkbox.id} checked fill contrast is ${checkbox.fillContrast.toFixed(2)}:1`);
            }

            if (checkbox.checked && (checkbox.markOpacity < 1 || checkbox.markContrast < 3)) {
                failures.push(`${checkbox.id} check mark is not visibly distinguishable`);
            }

            if (!checkbox.checked && checkbox.markOpacity !== 0) {
                failures.push(`${checkbox.id} renders a check mark while unchecked`);
            }
        }

        return { checkboxes, failures };
    });

    if (audit.failures.length > 0) {
        throw new Error(`Column checkbox affordances failed: ${audit.failures.join('; ')}`);
    }

    return audit.checkboxes;
}

async function auditFocusTrap(page) {
    const visited = [];

    const keys = [
        ...Array.from({ length: 12 }, () => 'Tab'),
        ...Array.from({ length: 12 }, () => 'Shift+Tab'),
    ];

    for (const [index, key] of keys.entries()) {
        await page.keyboard.press(key);
        const focus = await page.locator('.waterline-dialog').evaluate((popup) => ({
            inside: popup.contains(document.activeElement),
            target: document.activeElement?.id || document.activeElement?.className || document.activeElement?.tagName || null,
        }));

        visited.push({ direction: key, target: focus.target });

        if (!focus.inside) {
            throw new Error(`Focus escaped the dialog after ${index + 1} Tab presses.`);
        }
    }

    return visited;
}

export function summarizeDialogReports(baseUrl, reports) {
    return {
        schema: 'durable-workflow.waterline.dialog-visual-summary.v1',
        baseUrl,
        expectedCases: VIEWPORTS.length * DIALOGS.length,
        observedCases: reports.length,
        passedCases: reports.filter((report) => report.status === 'passed').length,
        failedCases: reports.filter((report) => report.status === 'failed').length,
        cases: reports.map((report) => ({
            dialog: report.dialog,
            state: report.state,
            viewport: report.viewport,
            screenshot: report.screenshot,
            status: report.status,
            failure: report.failure,
        })),
    };
}

export async function runWorkflowListDialogVisual({
    baseUrl,
    outputDirectory,
    email = 'demo@example.com',
    password = 'password',
    chromium = loadPlaywright().chromium,
}) {
    fs.mkdirSync(outputDirectory, { recursive: true });

    const launchOptions = { args: ['--no-sandbox'] };

    if (process.env.CHROMIUM_EXECUTABLE_PATH) {
        launchOptions.executablePath = process.env.CHROMIUM_EXECUTABLE_PATH;
    }

    const browser = await chromium.launch(launchOptions);
    const reports = [];

    try {
        for (const viewport of VIEWPORTS) {
            for (const dialog of DIALOGS) {
                const context = await browser.newContext({
                    viewport: { width: viewport.width, height: viewport.height },
                    deviceScaleFactor: 1,
                });
                await context.addInitScript(() => localStorage.setItem('waterline-theme', 'dark'));
                const page = await context.newPage();
                const consoleErrors = [];
                const name = `${dialog.name}-${viewport.name}`;
                const screenshot = `${name}.png`;
                let openedDialog = false;
                let geometry = null;
                let contrast = [];
                let focus = [];
                let controls = [];
                let checkboxes = [];
                let failure = null;

                page.on('pageerror', (error) => consoleErrors.push(`pageerror: ${error.message}`));
                page.on('console', (message) => {
                    if (message.type() === 'error') {
                        consoleErrors.push(`console: ${message.text()}`);
                    }
                });

                try {
                    await maybeLogin(page, baseUrl, email, password);
                    await waitForWorkflowList(page);
                    await page.getByRole('button', { name: dialog.buttonName, exact: true }).click();
                    await page.getByRole('dialog', { name: dialog.title, exact: true }).waitFor({
                        state: 'visible',
                        timeout: 10_000,
                    });
                    openedDialog = true;

                    if (dialog.validation) {
                        await triggerValidation(page);
                    }

                    await page.waitForTimeout(400);

                    geometry = await auditModalGeometry(page, dialog, viewport);
                    contrast = await auditContrast(page, dialog.requiredContrastCategories);
                    focus = await auditFocusTrap(page);
                    controls = await auditControlReachability(page);
                    checkboxes = dialog.name === 'view-options'
                        ? await auditCheckboxAffordances(page)
                        : [];

                    await page.locator('.waterline-dialog__body').evaluate((element) => {
                        element.scrollTop = 0;
                    });

                    if (consoleErrors.length > 0) {
                        throw new Error(`Dialog emitted browser errors: ${consoleErrors.join(' | ')}`);
                    }
                } catch (error) {
                    failure = {
                        name: error instanceof Error ? error.name : 'Error',
                        message: error instanceof Error ? error.message : String(error),
                        stack: error instanceof Error ? error.stack : null,
                    };
                } finally {
                    try {
                        await page.screenshot({
                            path: path.join(outputDirectory, screenshot),
                            fullPage: false,
                        });
                    } catch (error) {
                        const screenshotFailure = error instanceof Error ? error.message : String(error);

                        if (failure) {
                            failure.screenshot = screenshotFailure;
                        } else {
                            failure = {
                                name: 'ScreenshotError',
                                message: screenshotFailure,
                                stack: error instanceof Error ? error.stack : null,
                            };
                        }
                    }

                    const report = {
                        schema: 'durable-workflow.waterline.dialog-visual.v1',
                        status: failure ? 'failed' : 'passed',
                        dialog: dialog.name,
                        state: dialog.validation ? 'filter-validation' : 'checked-and-unchecked-columns',
                        viewport,
                        url: page.url(),
                        screenshot,
                        openedDialog,
                        consoleErrors,
                        contrast,
                        geometry,
                        controls,
                        checkboxes,
                        focus,
                        failure,
                    };

                    fs.writeFileSync(
                        path.join(outputDirectory, `${name}.json`),
                        `${JSON.stringify(report, null, 2)}\n`,
                    );
                    reports.push(report);
                    console.log(
                        `DIALOG_VISUAL ${dialog.name} ${viewport.name} ${failure ? 'FAIL' : 'PASS'}`,
                    );
                    await context.close();
                }
            }
        }
    } finally {
        await browser.close();
    }

    const summary = summarizeDialogReports(baseUrl, reports);

    fs.writeFileSync(
        path.join(outputDirectory, 'summary.json'),
        `${JSON.stringify(summary, null, 2)}\n`,
    );

    if (summary.observedCases !== summary.expectedCases || summary.failedCases > 0) {
        throw new Error(
            `Expected ${summary.expectedCases} passing dialog cases; `
            + `observed ${summary.observedCases} with ${summary.failedCases} failures.`,
        );
    }

    return summary;
}

const invokedPath = process.argv[1] ? pathToFileURL(path.resolve(process.argv[1])).href : null;

if (invokedPath === import.meta.url) {
    await runWorkflowListDialogVisual({
        baseUrl: argumentValue('--base-url', process.env.APP_URL || 'http://127.0.0.1:8000'),
        outputDirectory: path.resolve(argumentValue('--output-dir', process.env.OUTPUT_DIR || 'dialog-evidence')),
        email: argumentValue('--email', process.env.WATERLINE_VISUAL_EMAIL || 'demo@example.com'),
        password: argumentValue('--password', process.env.WATERLINE_VISUAL_PASSWORD || 'password'),
    });
}
