import assert from 'node:assert/strict';
import fs from 'node:fs';
import test from 'node:test';
import { createSSRApp } from 'vue';
import { renderToString } from '@vue/server-renderer';
import { createWaterlineI18n } from '../../resources/js/localization.mjs';
import Base from '../../resources/js/base.js';

const source = fs.readFileSync(new URL('../../resources/js/components/WorkerHealth.vue', import.meta.url), 'utf8');
const script = source.match(/<script>([\s\S]*?)<\/script>/)[1];
const component = Function(script.replace(/^import .*;$/gm, '').replace('export default', 'return'))();
const template = source.match(/<template>([\s\S]*?)<\/template>\s*<script>/)[1];

function observation(runtime) {
    return { worker_id: 'orders-worker', connection: 'redis', queue: 'orders', supported: ['orders-build-v1'], supports_required: true, recorded_at: new Date().toISOString(), source: 'database', runtime };
}

function context(locale = 'en') {
    const value = { $t: createWaterlineI18n(locale).global.t };
    for (const [name, method] of Object.entries(component.methods)) value[name] = method.bind(value);
    return value;
}

test('compatibility observations preserve reported runtimes without changing freshness or compatibility', () => {
    const methods = context();
    for (const runtime of ['php', 'python', 'rust']) {
        const entry = observation(runtime);
        const worker = methods.workersFromSnapshot({ operator_metrics: { workers: { fleet: [entry] } } })[0];
        assert.equal(worker.runtime, runtime);
        assert.equal(worker.status, 'active');
        assert.equal(worker.last_heartbeat_at, entry.recorded_at);
        assert.equal(worker.task_queue, 'redis:orders');
        assert.deepEqual(worker.supported_compatibility, entry.supported);
        assert.equal(methods.workerRuntimeDescription(worker), runtime);
    }
});

test('missing runtime stays unavailable for both backend modes and is never inferred as PHP', () => {
    const methods = context();
    for (const mode of ['embedded', 'service']) for (const runtime of [undefined, null, '', ' ', 42, {}]) {
        const worker = methods.workersFromSnapshot({ backend: { mode }, operator_metrics: { workers: { fleet: [observation(runtime)] } } })[0];
        assert.equal(worker.runtime, null);
        assert.equal(worker.status, 'active');
        assert.equal(methods.workerRuntimeLabel(worker), 'Not reported');
        assert.match(methods.workerRuntimeDescription(worker), /Compatibility heartbeats/);
    }
});

test('registered worker observations retain backend runtime and liveness authority', () => {
    const methods = context();
    const worker = { worker_id: 'python-sdk-worker', runtime: 'python', status: 'stale' };
    const selected = methods.workersFromSnapshot({ operator_metrics: { workers: { registrations: [worker], fleet: [observation('php')] } } });
    assert.deepEqual(selected, [worker]);
    assert.equal(selected[0], worker);
    assert.equal(methods.workerRuntimeLabel(worker), 'python');
    assert.equal(methods.workerRuntimeLabel({}), 'Not reported');
    assert.equal(methods.workerRuntimeDescription({}), 'This worker observation does not include runtime metadata.');
});

test('English and Ukrainian rosters explain absent runtime independently of an active heartbeat', async () => {
    for (const mode of ['embedded', 'service']) for (const locale of ['en', 'uk']) {
        const methods = context(locale);
        const healthData = { backend: { mode }, operator_metrics: { workers: { fleet: [observation(undefined), { ...observation('rust'), worker_id: 'rust-worker' }] } } };
        const workers = methods.workersFromSnapshot(healthData);
        const html = await renderToString(createSSRApp({
            ...component, template,
            data() { return { ...component.data.call(this), loading: false, healthData, workers, effectiveOperatorPreferences: { columns: ['worker_id', 'runtime', 'heartbeat', 'status', 'compatibility'] } }; },
        }).mixin(Base).use(createWaterlineI18n(locale)));
        assert.ok(html.includes(methods.$t('Worker runtime')));
        assert.ok(html.includes(methods.$t('Not reported')));
        assert.ok(html.includes(methods.$t('Compatibility heartbeats report freshness and compatibility, but do not identify the worker runtime.')));
        assert.ok(html.includes('rust'));
        assert.doesNotMatch(html, />unknown<|>UNKNOWN</);
        assert.equal(workers[0].status, 'active');
        assert.equal(workers[1].runtime, 'rust');
    }
});
