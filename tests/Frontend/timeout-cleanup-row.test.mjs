import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { parse } from '@vue/compiler-sfc';
import { compile, createSSRApp } from 'vue';
import { renderToString } from 'vue/server-renderer';
import { localizedState } from '../../resources/js/state-labels.mjs';

const component = readFileSync(new URL('../../resources/js/screens/flows/flow.vue', import.meta.url), 'utf8');
const english = JSON.parse(readFileSync(new URL('../../resources/lang/en.json', import.meta.url), 'utf8'));

function findCleanupRow(nodes) {
    for (const node of nodes) {
        if (node.props?.some((prop) => prop.name === 'if' && prop.exp?.content.includes('flow.timeout_cleanup.outcome'))) {
            return node.loc.source;
        }
        const child = findCleanupRow(node.children ?? []);
        if (child) return child;
    }
    return null;
}

const snippet = findCleanupRow(parse(component).descriptor.template.ast.children);
assert.ok(snippet);
const render = compile(snippet);

function renderCleanup(flow, messages = english) {
    const translate = (message) => messages[message] ?? message;
    return renderToString(createSSRApp({
        data: () => ({ flow }),
        methods: {
            $t: translate,
            stateLabel: (state) => localizedState(state, translate),
            hasDetailValue: (value) => value !== null && value !== undefined && value !== '',
            timestamp: (value) => value,
        },
        render,
    }));
}

test('the run header shows the recorded timeout cleanup state and deadline', async () => {
    const deadline = '2026-11-24T10:00:40+00:00';
    const running = await renderCleanup({ timeout_cleanup: { outcome: 'running', cleanup_deadline_at: deadline } });
    assert.match(running, /Timeout cleanup.*Running.*Cleanup deadline.*2026-11-24T10:00:40/s);

    const french = JSON.parse(readFileSync(new URL('../../resources/lang/fr.json', import.meta.url), 'utf8'));
    const closed = await renderCleanup({ timeout_cleanup: { outcome: 'preempted' } }, french);
    assert.match(closed, /Nettoyage après expiration du délai.*Interrompu/s);
    assert.doesNotMatch(closed, /Date limite du nettoyage/);
});

test('the timeout cleanup row is absent if the backend has no observation', async () => {
    assert.doesNotMatch(await renderCleanup({}), /<div\b|Timeout cleanup/);
    assert.doesNotMatch(await renderCleanup({ timeout_cleanup: {} }), /<div\b|Timeout cleanup/);
});
