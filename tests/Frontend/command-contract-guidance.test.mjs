import assert from 'node:assert/strict';
import fs from 'node:fs';
import test from 'node:test';
import { parse } from '@vue/compiler-dom';
import { createSSRApp } from 'vue';
import { renderToString } from 'vue/server-renderer';
import { createWaterlineI18n } from '../../resources/js/localization.mjs';

const source = fs.readFileSync(new URL('../../resources/js/screens/flows/flow.vue', import.meta.url), 'utf8');
const template = source.match(/<template>([\s\S]*?)<\/template>\s*<script/)[1];
const rows = [];
function inspect(node) {
    if (node.type === 1 && node.props.some((prop) => prop.name === 'if'
        && prop.exp?.content === 'hasDetailValue(flow.declared_contract_source)')
        && node.loc.source.includes('$t("Contract Source")')) rows.push(node.loc.source);
    for (const child of node.children ?? []) inspect(child);
}
inspect(parse(template));
assert.equal(rows.length, 1);

async function render(flow, locale = 'en') {
    const before = structuredClone(flow);
    const app = createSSRApp({
        template: rows[0],
        data: () => ({ flow }),
        methods: {
            hasDetailValue: (value) => value !== undefined && value !== null && value !== '',
            contractSourceLabel: (value) => value,
        },
    });
    app.use(createWaterlineI18n(locale));
    const html = await renderToString(app);
    assert.deepEqual(flow, before, 'Rendering must preserve durable contract data');
    return html;
}

const missing = {
    engine_source: 'service', declared_contract_source: 'unavailable',
    declared_contract_backfill_needed: true, declared_contract_backfill_available: false,
    declared_signal_targets: [], declared_query_targets: [], declared_update_targets: [],
    workflow_definition_fingerprint: 'original-worker-fingerprint',
};

test('missing service metadata gives SDK provenance/recovery guidance without asserting a missing PHP class or worker', async () => {
    for (const status of ['completed', 'running']) {
        const html = await render({ ...missing, status });
        assert.ok(html.includes('Command declarations are missing for this service run.'));
        assert.ok(html.includes('current worker registrations'));
        assert.ok(html.includes('before starting the workflow'));
        assert.ok(!html.includes('php artisan'));
        assert.ok(!html.includes('can no longer resolve the workflow definition'));
        assert.ok(!html.includes('query or update execution still stays blocked'));
        assert.ok(!html.includes('No active worker'));
    }
});

test('complete empty and named SDK contracts never become a missing-contract warning', async () => {
    for (const targets of [[], [{ name: 'total', parameters: [] }]]) {
        const html = await render({ ...missing, declared_contract_source: 'durable_history',
            declared_contract_backfill_needed: false, declared_query_targets: targets });
        assert.ok(html.includes('durable_history'));
        assert.ok(!html.includes('Command declarations are missing'));
        assert.ok(!html.includes('before starting the workflow'));
        assert.ok(!html.includes('php artisan'));
    }
});

test('worker compatibility alone cannot create a missing-contract warning', async () => {
    const html = await render({ ...missing, declared_contract_source: 'durable_history',
        declared_contract_backfill_needed: false, compatibility_status: 'unsupported',
        compatibility_supported_in_fleet: false });
    assert.ok(!html.includes('Command declarations are missing'));
    assert.ok(!html.includes('can no longer resolve the workflow definition'));
});

test('embedded missing and explicitly backfillable PHP definitions preserve their supported guidance', async () => {
    const unavailable = await render({ ...missing, engine_source: 'v2' });
    assert.ok(unavailable.includes('can no longer resolve the workflow definition'));
    assert.ok(!unavailable.includes('For new SDK runs'));
    for (const engine of ['v2', 'service']) {
        const available = await render({ ...missing, engine_source: engine,
            declared_contract_backfill_available: true });
        assert.ok(available.includes('workflow:v2:backfill-command-contracts --dry-run'));
        assert.ok(!available.includes('For new SDK runs'));
    }
});

test('service guidance is localized in Ukrainian', async () => {
    const html = await render(missing, 'uk');
    assert.ok(html.includes('Для цього сервісного запуску бракує декларацій команд.'));
    assert.ok(!html.includes('Command declarations are missing'));
    assert.ok(!html.includes('поточна збірка вже не може визначити робочий процес'));
});
