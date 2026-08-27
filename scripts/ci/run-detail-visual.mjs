import { createRequire } from 'node:module';
import { execFileSync } from 'node:child_process';
import fs from 'node:fs';
import path from 'node:path';
import { pathToFileURL } from 'node:url';

export const VIEWPORTS = [
    { name: 'desktop', width: 1440, height: 900 },
    { name: 'intermediate', width: 768, height: 1024 },
    { name: 'mobile', width: 390, height: 844 },
    { name: 'short-height', width: 1280, height: 360 },
];

export const NAVIGATION_STATES = [
    { name: 'initial', fragment: null },
    { name: 'deep-section', fragment: 'workflowStreams' },
];

export const PRESENTATIONS = ['embedded', 'service'];

export const STREAM_RESULTS = ['populated', 'supported-empty', 'unavailable', 'degraded'];

export const DEEP_LINK_STABILITY = [
    {
        attempts: 3,
        state: 'embedded-populated-expanded',
        viewports: ['intermediate', 'short-height'],
    },
    {
        attempts: 3,
        state: 'service-populated-expanded',
        viewports: ['mobile'],
    },
];

export const STATES = PRESENTATIONS.flatMap((presentation) => [
    ...STREAM_RESULTS.map((result) => ({
        name: `${presentation}-${result}-expanded`,
        expanded: true,
        fixture: `${presentation}-${result}`,
        presentation,
        result,
    })),
    {
        name: `${presentation}-populated-collapsed`,
        expanded: false,
        fixture: `${presentation}-populated`,
        presentation,
        result: 'populated',
    },
]);

export function workflowStreamHeaderHierarchyFailures(viewportWidth, hierarchy) {
    if (viewportWidth > 768) {
        return [];
    }

    if (!hierarchy?.header || !hierarchy?.context || !hierarchy?.disclosure) {
        return ['Workflow Streams header hierarchy is incomplete'];
    }

    const failures = [];
    const minimumContextWidth = Math.min(240, hierarchy.header.width * 0.72);

    if (hierarchy.disclosure.top < hierarchy.context.bottom - 1) {
        failures.push('Workflow Streams disclosure compresses the heading context');
    }
    if (hierarchy.context.width < minimumContextWidth - 1) {
        failures.push('Workflow Streams heading context is too narrow');
    }
    if (hierarchy.disclosure.width > hierarchy.header.width + 1) {
        failures.push('Workflow Streams disclosure leaves its header');
    }

    return failures;
}

export function simultaneousNavigationFailures(composition) {
    if (!composition?.sidebar) {
        return ['responsive navigation is missing'];
    }

    const failures = [];

    if (composition.sidebar.scrollLeft > 1) {
        failures.push('responsive navigation did not return to its initial horizontal position');
    }

    for (const expected of ['Dashboard', 'Workers']) {
        const link = composition.links?.find(({ name }) => name === expected);

        if (!link?.inViewport) {
            failures.push(`${expected} is not visible in the simultaneous initial navigation composition`);
        }
    }

    return failures;
}

export function simultaneousTopbarFailures(composition) {
    if (!composition?.topbar || !composition?.actions) {
        return ['persistent top bar is missing'];
    }

    const failures = [];

    if (composition.actions.scrollLeft > 1) {
        failures.push('persistent top-bar actions did not return to their initial horizontal position');
    }
    if (composition.actions.scrollWidth > composition.actions.clientWidth + 1) {
        failures.push('persistent top-bar actions overflow their simultaneous composition');
    }

    for (const expected of ['Scope', 'Backend', 'Auto refresh', 'Theme']) {
        const item = composition.items?.find(({ name }) => name === expected);

        if (!item?.inViewport || !item?.inTopbar) {
            failures.push(`${expected} is not visible in the simultaneous persistent top-bar composition`);
        }
        if (item?.clipped) {
            failures.push(`${expected} is clipped in the simultaneous persistent top-bar composition`);
        }
    }

    return failures;
}

export function deepLinkedWorkflowStreamFailures(topbar, streamSection, viewportHeight) {
    if (!topbar || !streamSection) {
        return ['persistent chrome or Workflow Streams section is missing'];
    }

    if (streamSection.top < topbar.bottom - 1 || streamSection.top >= viewportHeight) {
        return ['deep-linked Workflow Streams section is hidden by persistent chrome'];
    }

    return [];
}

export function deepLinkStabilityAttempts(state, navigation, viewport) {
    if (navigation.name !== 'deep-section') {
        return 1;
    }

    return DEEP_LINK_STABILITY.find((qualification) => (
        state.name === qualification.state
        && qualification.viewports.includes(viewport.name)
    ))?.attempts ?? 1;
}

const INSTANCE_ID = 'waterline-visual-instance';
const RUN_ID = 'waterline-visual-run';

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

export function runDetailFixture(streamState = 'embedded-populated') {
    const match = /^(embedded|service)-(populated|supported-empty|unavailable|degraded)$/.exec(streamState);

    if (!match) {
        throw new Error(`Unknown run-detail Workflow Stream fixture: ${streamState}`);
    }

    const [, presentation, result] = match;
    const workflowStreams = result === 'populated'
        ? presentation === 'embedded'
            ? [
                {
                    stream_name: 'orders',
                    mode: 'embedded',
                    status: 'open',
                    last_offset: 25,
                    run_cursor_offset: 19,
                    offset_origin: 1,
                    total_items: 13,
                    pending_items: 5,
                    direction: 'inbound',
                    delivery: 'at-least-once',
                    error_reason: null,
                },
                {
                    stream_name: 'orders',
                    mode: 'embedded',
                    status: 'errored',
                    last_offset: 26,
                    run_cursor_offset: null,
                    offset_origin: 1,
                    total_items: 13,
                    pending_items: 0,
                    direction: 'outbound',
                    delivery: 'at-least-once',
                    error_reason: 'outbound_delivery_failed_after_retry_budget',
                },
                {
                    stream_name: 'audit-events-with-a-bounded-operator-visible-name',
                    mode: 'embedded',
                    status: 'errored',
                    last_offset: 8,
                    run_cursor_offset: 8,
                    offset_origin: 1,
                    total_items: 9,
                    pending_items: 1,
                    direction: 'inbound',
                    delivery: 'at-least-once',
                    error_reason: 'delivery_failed_after_retry_budget',
                },
            ]
            : [
                {
                    stream_name: 'orders',
                    mode: 'service',
                    status: 'open',
                    last_offset: 24,
                    run_cursor_offset: null,
                    offset_origin: 0,
                    total_items: 13,
                    pending_items: 5,
                    direction: 'workflow_output',
                    delivery: 'at-least-once',
                    error_reason: null,
                },
                {
                    stream_name: 'audit-events-with-a-bounded-operator-visible-name',
                    mode: 'service',
                    status: 'errored',
                    last_offset: 8,
                    run_cursor_offset: null,
                    offset_origin: 0,
                    total_items: 9,
                    pending_items: 1,
                    direction: 'workflow_output',
                    delivery: 'at-least-once',
                    error_reason: 'delivery_failed_after_retry_budget',
                },
            ]
        : [];
    const workflowStreamsState = result === 'supported-empty' || result === 'populated'
        ? 'available'
        : result;
    const unavailableReasons = {
        embedded: {
            unavailable: 'workflow_streams_schema_unavailable',
            degraded: 'workflow_streams_collection_failed',
        },
        service: {
            unavailable: 'workflow_streams_route_unsupported',
            degraded: 'workflow_streams_request_failed',
        },
    };

    return {
        id: RUN_ID,
        workflow_instance_id: INSTANCE_ID,
        instance_id: INSTANCE_ID,
        workflow_run_id: RUN_ID,
        run_id: RUN_ID,
        selected_run_id: RUN_ID,
        is_current_run: true,
        class: 'App\\Workflows\\ResponsiveQualificationWorkflow',
        workflow_type: 'sample.responsive-qualification',
        engine_source: presentation === 'service' ? 'service' : 'v2',
        engine_version: '2.0',
        status: 'running',
        status_bucket: 'running',
        namespace: 'default',
        queue: 'responsive-qualification',
        connection: presentation === 'service' ? 'standalone-server' : 'database',
        compatibility: 'v2',
        created_at: '2026-08-26T00:00:00Z',
        updated_at: '2026-08-26T00:01:00Z',
        closed_at: null,
        arguments: {
            order_id: 'responsive-qualification-order',
            purpose: 'Run-detail visual qualification',
        },
        output: null,
        chartData: [],
        exceptions: [],
        activities: [],
        logs: [],
        timeline: [],
        waits: [],
        tasks: [],
        commands: [],
        signals: [],
        updates: [],
        timers: [],
        linked_intakes: [],
        run_navigation: [],
        run_diagnostics: [],
        workflow_streams_mode: presentation,
        workflow_streams_state: workflowStreamsState,
        workflow_streams_available: workflowStreamsState === 'available',
        workflow_streams_unavailable_reason: unavailableReasons[presentation][result] || null,
        workflow_streams: workflowStreams,
        actionability: {
            actions: {
                query: { allowed: false, reason: 'workflow_definition_unavailable' },
                signal: { allowed: false, reason: 'waterline_read_only' },
                update: { allowed: false, reason: 'waterline_read_only' },
                repair: { allowed: false, reason: 'repair_not_needed' },
                cancel: { allowed: false, reason: 'waterline_read_only' },
                terminate: { allowed: false, reason: 'waterline_read_only' },
                archive: { allowed: false, reason: 'run_not_closed' },
            },
        },
        can_issue_terminal_commands: false,
    };
}

async function installFixtureRoutes(page, streamState) {
    await page.route('**/waterline/api/preferences/run-detail?**', async (route) => {
        await route.fulfill({
            status: 200,
            contentType: 'application/json',
            body: JSON.stringify({
                preferences: {},
                effective_preferences: { tab: 'timeline' },
            }),
        });
    });
    await page.route(
        `**/waterline/api/instances/${INSTANCE_ID}/runs/${RUN_ID}?**`,
        async (route) => {
            await route.fulfill({
                status: 200,
                contentType: 'application/json',
                body: JSON.stringify(runDetailFixture(streamState)),
            });
        },
    );
}

async function maybeLogin(page, targetUrl, email, password) {
    await page.goto(targetUrl, { waitUntil: 'networkidle', timeout: 30_000 });

    if (!/\/login(?:$|\?)/.test(page.url())) {
        return;
    }

    await page.fill('input[name=email]', email);
    await page.fill('input[name=password]', password);
    await Promise.all([
        page.waitForNavigation({ waitUntil: 'networkidle', timeout: 30_000 }).catch(() => {}),
        page.click('button[type=submit], input[type=submit]'),
    ]);
    await page.goto(targetUrl, { waitUntil: 'networkidle', timeout: 30_000 });
}

async function waitForRunDetail(page) {
    await page.waitForFunction(() => (
        document.getElementById('waterline')?.getAttribute('data-waterline-mounted') === 'true'
    ), { timeout: 20_000 });
    await page.getByRole('heading', { name: 'App\\Workflows\\ResponsiveQualificationWorkflow' }).waitFor({
        state: 'visible',
        timeout: 20_000,
    });
    await page.locator('#workflowStreams').waitFor({ state: 'visible', timeout: 20_000 });
    await page.waitForFunction(() => {
        const stylesheet = document.getElementById('app-stylesheet');

        return stylesheet instanceof HTMLLinkElement
            && stylesheet.href.includes('/vendor/waterline/app-dark.css');
    }, { timeout: 10_000 });
    await page.waitForFunction(() => {
        if (!window.location.hash) {
            return true;
        }

        const target = document.getElementById(decodeURIComponent(window.location.hash.slice(1)));

        return target?.dataset.waterlineFragmentPosition === 'settled';
    }, { timeout: 5_000 });
}

async function auditBootstrapIdentity(page, expectedPresentation) {
    const identity = await page.locator('#waterline').evaluate((element) => {
        const serialized = element.getAttribute('data-waterline-config');
        const bootstrap = serialized ? JSON.parse(serialized) : null;
        const backend = bootstrap?.backend;

        return backend ? {
            mode: backend.mode,
            label: backend.label,
            transport: backend.transport,
            namespace: backend.namespace,
            accessMode: backend.access_mode,
            readOnly: backend.read_only,
        } : null;
    });
    const expected = expectedPresentation === 'service'
        ? { label: 'Standalone service', transport: 'durable-workflow/sdk' }
        : { label: 'Embedded Laravel', transport: 'workflow-package' };

    if (
        identity?.mode !== expectedPresentation
        || identity?.label !== expected.label
        || identity?.transport !== expected.transport
    ) {
        throw new Error(
            `Run-detail bootstrap identity mismatch: expected ${expectedPresentation}, got ${JSON.stringify(identity)}`,
        );
    }

    return identity;
}

async function applyDisclosureState(page, state) {
    const toggle = page.locator('.wl-flow-detail__section-toggle');
    const body = page.locator('#collapseWorkflowStreams');

    if (!state.expanded) {
        await toggle.evaluate((control) => control.click());
    }

    await page.waitForFunction((expanded) => {
        const control = document.querySelector('.wl-flow-detail__section-toggle');
        const region = document.getElementById('collapseWorkflowStreams');
        const expectedText = expanded ? 'Collapse Workflow Streams' : 'Expand Workflow Streams';

        return control?.getAttribute('aria-expanded') === String(expanded)
            && control?.textContent?.trim() === expectedText
            && region?.classList.contains('show') === expanded;
    }, state.expanded);

    return {
        text: (await toggle.textContent()).trim(),
        ariaExpanded: await toggle.getAttribute('aria-expanded'),
        regionVisible: await body.isVisible(),
    };
}

async function auditContrast(page, state) {
    const streamDataSelectors = state.result === 'populated'
        ? [
            '.workflow-stream-section tbody td',
            '.workflow-stream-mobile-row dd',
            '.workflow-stream-section .text-muted',
            '.workflow-stream-section .text-danger',
        ]
        : ['.workflow-stream-notice'];
    const categories = {
        title: ['.wl-flow-detail__title'],
        sectionTitle: ['.workflow-stream-section h5'],
        help: ['.workflow-stream-section .small.text-muted'],
        streamData: state.expanded ? streamDataSelectors : [],
        disclosure: ['.wl-flow-detail__section-toggle'],
        navigation: ['.wl-sidebar__link'],
        persistentAction: ['.wl-topbar__button'],
    };

    const audit = await page.evaluate((selectorsByCategory) => {
        const parseColor = (value) => {
            const match = value.match(/rgba?\(([^)]+)\)/);

            if (match) {
                const channels = match[1].split(/[\s,/]+/).filter(Boolean).map(Number);

                return {
                    r: channels[0],
                    g: channels[1],
                    b: channels[2],
                    a: channels.length > 3 ? channels[3] : 1,
                };
            }

            const srgb = value.match(/^color\(srgb\s+([^)]+)\)$/);

            if (srgb) {
                const [channelsPart, alphaPart] = srgb[1].split('/').map((part) => part.trim());
                const channels = channelsPart.split(/\s+/).map(Number);

                return {
                    r: channels[0] * 255,
                    g: channels[1] * 255,
                    b: channels[2] * 255,
                    a: alphaPart === undefined ? 1 : Number(alphaPart),
                };
            }

            return null;
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
        const visible = (element) => {
            const style = getComputedStyle(element);
            const rect = element.getBoundingClientRect();

            return style.display !== 'none'
                && style.visibility !== 'hidden'
                && Number(style.opacity) > 0
                && rect.width > 0
                && rect.height > 0;
        };
        const background = (element) => {
            const layers = [];
            let current = element;

            while (current) {
                const color = parseColor(getComputedStyle(current).backgroundColor);

                if (color && color.a > 0) {
                    layers.push(color);
                    if (color.a >= 1) {
                        break;
                    }
                }
                current = current.parentElement;
            }

            let result = layers.pop() || { r: 255, g: 255, b: 255, a: 1 };

            while (layers.length) {
                result = blend(layers.pop(), result);
            }

            return result;
        };
        const results = [];
        const failures = [];

        for (const [category, selectors] of Object.entries(selectorsByCategory)) {
            const elements = selectors.flatMap((selector) => Array.from(document.querySelectorAll(selector)))
                .filter(visible);

            if (selectors.length > 0 && elements.length === 0) {
                failures.push(`${category} has no visible audit target`);
                continue;
            }

            for (const element of elements) {
                const style = getComputedStyle(element);
                const foreground = parseColor(style.color);
                const canvas = background(element);

                if (!foreground || !canvas) {
                    failures.push(`${category} has an unparseable color`);
                    continue;
                }

                const ratio = contrast(blend(foreground, canvas), canvas);
                const fontSize = Number.parseFloat(style.fontSize);
                const fontWeight = Number.parseInt(style.fontWeight, 10) || 400;
                const large = fontSize >= 24 || (fontSize >= 18.66 && fontWeight >= 700);
                const threshold = large ? 3 : 4.5;
                const result = {
                    category,
                    text: (element.textContent || '').trim().replace(/\s+/g, ' ').slice(0, 160),
                    ratio,
                    threshold,
                };

                results.push(result);
                if (ratio + 0.01 < threshold) {
                    failures.push(`${category} "${result.text}" contrast is ${ratio.toFixed(2)}:1`);
                }
            }
        }

        return { results, failures };
    }, categories);

    if (audit.failures.length > 0) {
        throw new Error(`Run-detail contrast failed: ${audit.failures.join('; ')}`);
    }

    return audit.results;
}

async function auditControls(page) {
    const controls = page.locator('#waterline a[href], #waterline button:not([disabled]), #waterline input:not([disabled]), #waterline select:not([disabled]), #waterline textarea:not([disabled]), #waterline [tabindex]:not([tabindex="-1"])');
    const count = await controls.count();
    const results = [];
    const scrollSnapshot = await page.evaluateHandle(() => {
        const candidates = [document.scrollingElement, ...document.querySelectorAll('*')]
            .filter((element) => element instanceof Element);

        return Array.from(new Set(candidates))
            .filter((element) => (
                element === document.scrollingElement
                || element.scrollWidth > element.clientWidth + 1
                || element.scrollHeight > element.clientHeight + 1
            ))
            .map((element) => ({
                element,
                left: element.scrollLeft,
                top: element.scrollTop,
            }));
    });

    try {
        for (let index = 0; index < count; index += 1) {
            const control = controls.nth(index);

            if (!await control.isVisible()) {
                continue;
            }

            await control.scrollIntoViewIfNeeded();
            await page.waitForTimeout(20);
            const result = await control.evaluate((element) => {
                const rect = element.getBoundingClientRect();
                const topbar = document.querySelector('.wl-topbar')?.getBoundingClientRect();
                const x = rect.left + rect.width / 2;
                const y = rect.top + rect.height / 2;
                const hit = x >= 0 && x <= window.innerWidth && y >= 0 && y <= window.innerHeight
                    ? document.elementFromPoint(x, y)
                    : null;
                const style = getComputedStyle(element);
                const clipped = element.scrollWidth > element.clientWidth + 1
                    || element.scrollHeight > element.clientHeight + 1;
                const coveredByChrome = topbar
                    ? rect.top < topbar.bottom - 1 && !element.closest('.wl-topbar')
                    : false;

                return {
                    target: element.id || element.getAttribute('aria-label') || (element.textContent || '').trim().replace(/\s+/g, ' ').slice(0, 120),
                    clipped,
                    coveredByChrome,
                    inViewport: rect.left >= -1
                        && rect.top >= -1
                        && rect.right <= window.innerWidth + 1
                        && rect.bottom <= window.innerHeight + 1,
                    reachable: hit === element || element.contains(hit),
                };
            });

            results.push(result);
        }
    } finally {
        await scrollSnapshot.evaluate((entries) => {
            for (const { element, left, top } of entries) {
                element.scrollLeft = left;
                element.scrollTop = top;
            }
        });
        await scrollSnapshot.dispose();
        await page.evaluate(() => new Promise((resolve) => {
            requestAnimationFrame(() => requestAnimationFrame(resolve));
        }));
    }

    if (results.length === 0) {
        throw new Error('Run-detail reachability audit found no visible controls.');
    }

    return results;
}

async function auditSimultaneousNavigation(page) {
    return page.evaluate(() => {
        const sidebar = document.querySelector('.wl-sidebar');
        const sidebarRect = sidebar?.getBoundingClientRect() || null;
        const links = Array.from(document.querySelectorAll('.wl-sidebar__link'))
            .filter((link) => ['Dashboard', 'Workers'].includes((link.textContent || '').trim()))
            .map((link) => {
                const rect = link.getBoundingClientRect();

                return {
                    name: (link.textContent || '').trim(),
                    left: rect.left,
                    right: rect.right,
                    inViewport: rect.left >= -1
                        && rect.right <= window.innerWidth + 1
                        && (!sidebarRect || (
                            rect.left >= sidebarRect.left - 1
                            && rect.right <= sidebarRect.right + 1
                        )),
                };
            });

        return {
            sidebar: sidebar && sidebarRect ? {
                left: sidebarRect.left,
                right: sidebarRect.right,
                scrollLeft: sidebar.scrollLeft,
                scrollWidth: sidebar.scrollWidth,
                clientWidth: sidebar.clientWidth,
            } : null,
            links,
        };
    });
}

async function auditSimultaneousTopbar(page) {
    return page.evaluate(() => {
        const topbar = document.querySelector('.wl-topbar');
        const actions = document.querySelector('.wl-topbar__actions');
        const topbarRect = topbar?.getBoundingClientRect() || null;
        const items = [
            ...Array.from(document.querySelectorAll('.wl-topbar__scope')).map((element) => ({
                element,
                name: element.querySelector('.wl-topbar__scope-label')?.textContent?.trim() || 'Scope',
            })),
            ...Array.from(document.querySelectorAll('.wl-topbar__button')).map((element, index) => ({
                element,
                name: index === 0 ? 'Auto refresh' : 'Theme',
            })),
        ].map(({ element, name }) => {
            const rect = element.getBoundingClientRect();

            return {
                name,
                text: (element.textContent || '').trim().replace(/\s+/g, ' '),
                left: rect.left,
                right: rect.right,
                top: rect.top,
                bottom: rect.bottom,
                clipped: element.scrollWidth > element.clientWidth + 1
                    || element.scrollHeight > element.clientHeight + 1,
                inViewport: rect.left >= -1
                    && rect.top >= -1
                    && rect.right <= window.innerWidth + 1
                    && rect.bottom <= window.innerHeight + 1,
                inTopbar: !topbarRect || (
                    rect.left >= topbarRect.left - 1
                    && rect.top >= topbarRect.top - 1
                    && rect.right <= topbarRect.right + 1
                    && rect.bottom <= topbarRect.bottom + 1
                ),
            };
        });

        return {
            topbar: topbar && topbarRect ? {
                left: topbarRect.left,
                right: topbarRect.right,
                top: topbarRect.top,
                bottom: topbarRect.bottom,
            } : null,
            actions: actions ? {
                scrollLeft: actions.scrollLeft,
                scrollWidth: actions.scrollWidth,
                clientWidth: actions.clientWidth,
            } : null,
            items,
        };
    });
}

async function auditGeometry(page, viewport, navigation) {
    const geometry = await page.evaluate((navigationName) => {
        const visible = (element) => {
            const style = getComputedStyle(element);
            const rect = element.getBoundingClientRect();

            return style.display !== 'none'
                && style.visibility !== 'hidden'
                && Number(style.opacity) > 0
                && rect.width > 0
                && rect.height > 0;
        };
        const floatingElements = Array.from(document.querySelectorAll('body *'))
            .filter((element) => {
                const position = getComputedStyle(element).position;

                return visible(element) && ['fixed', 'sticky'].includes(position);
            });
        const overlappingFloatingElements = [];

        for (let firstIndex = 0; firstIndex < floatingElements.length; firstIndex += 1) {
            const first = floatingElements[firstIndex];
            const firstRect = first.getBoundingClientRect();

            for (let secondIndex = firstIndex + 1; secondIndex < floatingElements.length; secondIndex += 1) {
                const second = floatingElements[secondIndex];

                if (first.contains(second) || second.contains(first)) {
                    continue;
                }

                const secondRect = second.getBoundingClientRect();
                const width = Math.min(firstRect.right, secondRect.right)
                    - Math.max(firstRect.left, secondRect.left);
                const height = Math.min(firstRect.bottom, secondRect.bottom)
                    - Math.max(firstRect.top, secondRect.top);

                if (width <= 1 || height <= 1) {
                    continue;
                }

                overlappingFloatingElements.push({
                    first: first.className || first.tagName.toLowerCase(),
                    second: second.className || second.tagName.toLowerCase(),
                    width,
                    height,
                });
            }
        }
        const topbar = document.querySelector('.wl-topbar')?.getBoundingClientRect() || null;
        const sidebar = document.querySelector('.wl-sidebar');
        const sidebarRect = sidebar?.getBoundingClientRect() || null;
        const streamSection = document.getElementById('workflowStreams')?.getBoundingClientRect() || null;
        const streamHeaderElement = document.querySelector('#workflowStreams > .card-header');
        const streamHeaderContext = streamHeaderElement?.firstElementChild || null;
        const streamDisclosure = streamHeaderElement?.querySelector('.wl-flow-detail__section-toggle') || null;
        const streamHeaderRect = streamHeaderElement?.getBoundingClientRect() || null;
        const streamContextRect = streamHeaderContext?.getBoundingClientRect() || null;
        const streamDisclosureRect = streamDisclosure?.getBoundingClientRect() || null;
        const summaryCard = document.querySelector('.wl-flow-detail__summary-card')?.getBoundingClientRect() || null;
        const main = document.querySelector('.wl-main')?.getBoundingClientRect() || null;
        const cards = Array.from(document.querySelectorAll('.wl-main .card'))
            .filter((card) => getComputedStyle(card).display !== 'none')
            .map((card) => {
                const rect = card.getBoundingClientRect();

                return {
                    id: card.id || card.querySelector('h1, h5, h6')?.textContent?.trim() || 'card',
                    left: rect.left,
                    right: rect.right,
                };
            });
        const failures = [];

        if (document.documentElement.scrollWidth > window.innerWidth + 1) {
            failures.push('document has horizontal overflow');
        }

        if (!main || main.left < -1 || main.right > window.innerWidth + 1) {
            failures.push('run-detail main content leaves the viewport');
        }

        for (const card of cards) {
            if (card.left < -1 || card.right > window.innerWidth + 1) {
                failures.push(`${card.id} leaves the viewport horizontally`);
            }
        }

        if (!topbar || !summaryCard || !streamSection) {
            failures.push('persistent chrome or run-detail content is missing');
        } else if (navigationName !== 'deep-section' && (summaryCard.top < topbar.bottom - 1 || summaryCard.top >= window.innerHeight)) {
            failures.push('initial run summary is hidden by persistent chrome');
        }

        if (window.innerWidth > 1100) {
            const overflowY = sidebar ? getComputedStyle(sidebar).overflowY : null;

            if (!sidebarRect || sidebarRect.top < (topbar?.bottom || 0) - 1 || sidebarRect.bottom > window.innerHeight + 1) {
                failures.push('sticky desktop navigation leaves the compact viewport');
            }
            if (sidebar && sidebar.scrollHeight > sidebar.clientHeight + 1 && !['auto', 'scroll'].includes(overflowY)) {
                failures.push('sticky desktop navigation cannot scroll to its lower controls');
            }
        }

        return {
            viewport: { width: window.innerWidth, height: window.innerHeight },
            document: {
                clientWidth: document.documentElement.clientWidth,
                scrollWidth: document.documentElement.scrollWidth,
                scrollHeight: document.documentElement.scrollHeight,
                scrollY: window.scrollY,
            },
            topbar: topbar ? { top: topbar.top, bottom: topbar.bottom } : null,
            sidebar: sidebarRect ? {
                top: sidebarRect.top,
                bottom: sidebarRect.bottom,
                clientHeight: sidebar.clientHeight,
                scrollHeight: sidebar.scrollHeight,
                overflowY: getComputedStyle(sidebar).overflowY,
            } : null,
            streamSection: streamSection ? { top: streamSection.top, bottom: streamSection.bottom } : null,
            workflowStreamHeader: {
                header: streamHeaderRect ? {
                    top: streamHeaderRect.top,
                    bottom: streamHeaderRect.bottom,
                    width: streamHeaderRect.width,
                } : null,
                context: streamContextRect ? {
                    top: streamContextRect.top,
                    bottom: streamContextRect.bottom,
                    width: streamContextRect.width,
                } : null,
                disclosure: streamDisclosureRect ? {
                    top: streamDisclosureRect.top,
                    bottom: streamDisclosureRect.bottom,
                    width: streamDisclosureRect.width,
                } : null,
            },
            summaryCard: summaryCard ? { top: summaryCard.top, bottom: summaryCard.bottom } : null,
            cards,
            overlapping_floating_elements: overlappingFloatingElements,
            failures,
        };
    }, navigation.name);
    const controls = await auditControls(page);
    const simultaneousNavigation = await auditSimultaneousNavigation(page);
    const simultaneousTopbar = await auditSimultaneousTopbar(page);

    if (navigation.name === 'deep-section') {
        geometry.failures.push(...deepLinkedWorkflowStreamFailures(
            geometry.topbar,
            geometry.streamSection,
            viewport.height,
        ));
    }

    geometry.simultaneous_navigation = simultaneousNavigation;
    geometry.simultaneous_topbar = simultaneousTopbar;
    geometry.unreachable_controls = controls.filter((control) => !control.inViewport || !control.reachable || control.coveredByChrome);
    geometry.clipped_controls = controls.filter((control) => control.clipped);

    for (const failure of workflowStreamHeaderHierarchyFailures(viewport.width, geometry.workflowStreamHeader)) {
        geometry.failures.push(failure);
    }
    for (const failure of simultaneousNavigationFailures(simultaneousNavigation)) {
        geometry.failures.push(failure);
    }
    for (const failure of simultaneousTopbarFailures(simultaneousTopbar)) {
        geometry.failures.push(failure);
    }

    if (geometry.unreachable_controls.length > 0) {
        geometry.failures.push(`unreachable controls: ${JSON.stringify(geometry.unreachable_controls)}`);
    }
    if (geometry.clipped_controls.length > 0) {
        geometry.failures.push(`clipped controls: ${JSON.stringify(geometry.clipped_controls)}`);
    }
    if (geometry.overlapping_floating_elements.length > 0) {
        geometry.failures.push(`floating elements overlap: ${JSON.stringify(geometry.overlapping_floating_elements)}`);
    }
    if (geometry.viewport.width !== viewport.width || geometry.viewport.height !== viewport.height) {
        geometry.failures.push('browser viewport does not match the requested evidence viewport');
    }

    return { geometry, controls };
}

export function summarizeRunDetailReports(baseUrl, reports) {
    return {
        schema: 'durable-workflow.waterline.run-detail-visual-summary.v1',
        baseUrl,
        expectedCases: VIEWPORTS.length * NAVIGATION_STATES.length * STATES.length,
        observedCases: reports.length,
        passedCases: reports.filter((report) => report.status === 'passed').length,
        failedCases: reports.filter((report) => report.status === 'failed').length,
        cases: reports.map((report) => ({
            state: report.state,
            streamState: report.streamState,
            presentation: report.presentation,
            result: report.result,
            navigation: report.navigation,
            bootstrap: report.bootstrap,
            viewport: report.viewport,
            screenshot: report.screenshot,
            status: report.status,
            failure: report.failure,
            deepLinkStability: report.deepLinkStability,
        })),
    };
}

export async function runRunDetailVisual({
    baseUrl,
    serviceBaseUrl,
    outputDirectory,
    email = 'demo@example.com',
    password = 'password',
    chromium = loadPlaywright().chromium,
}) {
    if (!baseUrl || !serviceBaseUrl) {
        throw new Error('Both embedded and service run-detail base URLs are required.');
    }

    const baseUrls = {
        embedded: new URL(baseUrl).href,
        service: new URL(serviceBaseUrl).href,
    };
    fs.mkdirSync(outputDirectory, { recursive: true });

    const launchOptions = { args: ['--no-sandbox'] };

    if (process.env.CHROMIUM_EXECUTABLE_PATH) {
        launchOptions.executablePath = process.env.CHROMIUM_EXECUTABLE_PATH;
    }

    const browser = await chromium.launch(launchOptions);
    const reports = [];
    const evidenceCases = VIEWPORTS.flatMap((viewport) => NAVIGATION_STATES.flatMap((navigation) => (
        STATES.map((state) => ({ navigation, state, viewport }))
    )));

    try {
        for (const { navigation, state, viewport } of evidenceCases) {
            const context = await browser.newContext({
                viewport: { width: viewport.width, height: viewport.height },
                deviceScaleFactor: 1,
            });
            await context.addInitScript(() => localStorage.setItem('waterline-theme', 'dark'));
            const page = await context.newPage();
            const browserErrors = [];
            const requestFailures = [];
            const errorResponses = [];
            const name = `${state.name}-${navigation.name}-${viewport.name}`;
            const screenshot = `${name}.png`;
            let disclosure = null;
            let bootstrap = null;
            let geometry = null;
            let controls = [];
            let contrast = [];
            const deepLinkStability = [];
            let failure = null;

            page.on('pageerror', (error) => browserErrors.push(`pageerror: ${error.message}`));
            page.on('console', (message) => {
                if (message.type() === 'error') {
                    browserErrors.push(`console: ${message.text()}`);
                }
            });
            page.on('requestfailed', (request) => {
                requestFailures.push(`${request.method()} ${request.url()} ${request.failure()?.errorText || ''}`.trim());
            });
            page.on('response', (response) => {
                if (response.status() >= 400) {
                    errorResponses.push(`${response.status()} ${response.request().method()} ${response.url()}`);
                }
            });

            try {
                await installFixtureRoutes(page, state.fixture);
                const targetUrl = new URL(
                    `/waterline/flows/instances/${INSTANCE_ID}/runs/${RUN_ID}${navigation.fragment ? `#${navigation.fragment}` : ''}`,
                    baseUrls[state.presentation],
                ).href;
                await maybeLogin(page, targetUrl, email, password);
                await waitForRunDetail(page);
                const attempts = deepLinkStabilityAttempts(state, navigation, viewport);

                for (let attempt = 1; attempt <= attempts; attempt += 1) {
                    if (attempt > 1) {
                        await page.goto(targetUrl, { waitUntil: 'networkidle', timeout: 30_000 });
                        await waitForRunDetail(page);
                    }

                    bootstrap = await auditBootstrapIdentity(page, state.presentation);
                    disclosure = await applyDisclosureState(page, state);
                    contrast = await auditContrast(page, state);
                    ({ geometry, controls } = await auditGeometry(page, viewport, navigation));
                    await page.evaluate((scrollY) => window.scrollTo(0, scrollY), geometry.document.scrollY);

                    if (attempts > 1) {
                        deepLinkStability.push({
                            attempt,
                            scrollY: geometry.document.scrollY,
                            topbarBottom: geometry.topbar?.bottom ?? null,
                            workflowStreamsTop: geometry.streamSection?.top ?? null,
                            failures: geometry.failures,
                        });
                    }

                    if (geometry.failures.length > 0) {
                        throw new Error(`Run-detail geometry failed: ${geometry.failures.join('; ')}`);
                    }
                }

                if (browserErrors.length > 0 || requestFailures.length > 0 || errorResponses.length > 0) {
                    throw new Error([
                        ...browserErrors,
                        ...requestFailures.map((item) => `requestfailed: ${item}`),
                        ...errorResponses.map((item) => `response: ${item}`),
                    ].join(' | '));
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
                    schema: 'durable-workflow.waterline.run-detail-visual.v1',
                    status: failure ? 'failed' : 'passed',
                    surface: 'run-detail',
                    state: state.name,
                    streamState: state.fixture,
                    presentation: state.presentation,
                    result: state.result,
                    navigation: navigation.name,
                    bootstrap,
                    viewport,
                    url: page.url(),
                    screenshot,
                    disclosure,
                    browserErrors,
                    requestFailures,
                    errorResponses,
                    contrast,
                    geometry,
                    controls,
                    deepLinkStability,
                    failure,
                };

                fs.writeFileSync(
                    path.join(outputDirectory, `${name}.json`),
                    `${JSON.stringify(report, null, 2)}\n`,
                );
                reports.push(report);
                console.log(
                    `RUN_DETAIL_VISUAL ${state.name} ${navigation.name} ${viewport.name} ${failure ? 'FAIL' : 'PASS'}`,
                );
                await context.close();
            }
        }
    } finally {
        await browser.close();
    }

    const summary = summarizeRunDetailReports(baseUrls.embedded, reports);
    summary.baseUrls = baseUrls;

    fs.writeFileSync(
        path.join(outputDirectory, 'summary.json'),
        `${JSON.stringify(summary, null, 2)}\n`,
    );

    if (summary.observedCases !== summary.expectedCases || summary.failedCases > 0) {
        throw new Error(
            `Expected ${summary.expectedCases} passing run-detail cases; `
            + `observed ${summary.observedCases} with ${summary.failedCases} failures.`,
        );
    }

    return summary;
}

const invokedPath = process.argv[1] ? pathToFileURL(path.resolve(process.argv[1])).href : null;

if (invokedPath === import.meta.url) {
    await runRunDetailVisual({
        baseUrl: argumentValue('--base-url', process.env.APP_URL || 'http://127.0.0.1:8000'),
        serviceBaseUrl: argumentValue('--service-base-url', process.env.WATERLINE_SERVICE_VISUAL_URL),
        outputDirectory: path.resolve(argumentValue('--output-dir', process.env.OUTPUT_DIR || 'run-detail-evidence')),
        email: argumentValue('--email', process.env.WATERLINE_VISUAL_EMAIL || 'demo@example.com'),
        password: argumentValue('--password', process.env.WATERLINE_VISUAL_PASSWORD || 'password'),
    });
}
