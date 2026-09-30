import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import test from 'node:test';
import { fileURLToPath } from 'node:url';
import { createSSRApp } from 'vue';
import { renderToString } from '@vue/server-renderer';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const componentPath = path.join(root, 'resources/js/components/WorkerHealth.vue');

function componentOptions() {
    const source = fs.readFileSync(componentPath, 'utf8');
    const script = source.match(/<script>([\s\S]*?)<\/script>/)?.[1];

    assert.ok(script, 'WorkerHealth must expose an options script.');

    const executable = script
        .replace(/^import .*;$/gm, '')
        .replace('export default', 'return');

    return Function(executable)();
}

function healthSnapshot(backend, registrations, staleRegistrations) {
    return {
        backend: {mode: backend},
        operator_metrics: {
            workers: {
                registration_count: registrations.length + staleRegistrations.length,
                active_registration_count: registrations.length,
                stale_registration_count: staleRegistrations.length,
                registrations,
                stale_registrations: staleRegistrations,
            },
        },
    };
}

function renderedState(component, healthData) {
    const workers = component.methods.workersFromSnapshot.call({}, healthData);
    const context = {
        healthData,
        workers,
    };

    context.staleRegistrationCount = component.computed.staleRegistrationCount.call(context);
    context.registrationCount = component.computed.registrationCount.call(context);
    context.activeRegistrationCount = component.computed.activeRegistrationCount.call(context);

    return {
        workerIds: workers.map((worker) => worker.worker_id),
        registrationCount: context.registrationCount,
        activeRegistrationCount: context.activeRegistrationCount,
        staleRegistrationCount: context.staleRegistrationCount,
        registrationSummary: component.computed.registrationSummary.call(context),
        totalLeases: component.computed.totalLeases.call(context),
    };
}

test('embedded and service snapshots render and aggregate equivalent worker fleets identically', () => {
    const component = componentOptions();
    const active = [
        {worker_id: 'worker-active-1', status: 'active', current_leases: 2},
        {worker_id: 'worker-active-2', status: 'active', current_leases: 3},
    ];
    const stale = [
        {worker_id: 'worker-stale', status: 'stale', current_leases: 13},
    ];
    const scenarios = {
        'active-only': [active, []],
        'stale-only': [[], stale],
        mixed: [active, stale],
    };

    for (const [name, [activeRoster, staleRoster]] of Object.entries(scenarios)) {
        const embedded = renderedState(
            component,
            healthSnapshot('embedded', activeRoster, staleRoster),
        );
        const service = renderedState(
            component,
            healthSnapshot('service', activeRoster, staleRoster),
        );

        assert.deepEqual(service, embedded, name);
        assert.deepEqual(embedded.workerIds, activeRoster.map((worker) => worker.worker_id), name);
        assert.equal(embedded.registrationCount, activeRoster.length + staleRoster.length, name);
        assert.equal(embedded.activeRegistrationCount, activeRoster.length, name);
        assert.equal(embedded.staleRegistrationCount, staleRoster.length, name);
        assert.equal(
            embedded.registrationSummary,
            `${activeRoster.length} active; ${staleRoster.length} stale.`,
            name,
        );
        assert.equal(
            embedded.totalLeases,
            activeRoster.reduce((total, worker) => total + worker.current_leases, 0),
            name,
        );
    }
});

test('an empty fleet distinguishes stale registrations from a first worker setup', async () => {
    const source = fs.readFileSync(componentPath, 'utf8');
    const template = source.match(/<template>([\s\S]*)<\/template>\s*<script>/)?.[1];
    assert.ok(template, 'WorkerHealth must expose its rendered template.');

    for (const backend of ['embedded', 'service']) {
        for (const stale of [[], [{worker_id: 'worker-stale', status: 'stale'}]]) {
            const component = componentOptions();
            const healthData = healthSnapshot(backend, [], stale);
            const html = await renderToString(createSSRApp({
                ...component,
                template,
                data() {
                    return {
                        ...component.data(),
                        loading: false,
                        healthData,
                        workers: [],
                    };
                },
            }));

            if (stale.length > 0) {
                assert.match(html, /No active workers/);
                assert.match(html, /workers are stale/);
                assert.match(html, /fresh heartbeat/);
                assert.doesNotMatch(html, /No workers registered/);
                assert.doesNotMatch(html, /not observed any worker registrations/);
            } else {
                assert.match(html, /No workers registered/);
                assert.match(html, /not observed any worker registrations/);
                assert.doesNotMatch(html, /No active workers/);
            }
        }
    }
});
