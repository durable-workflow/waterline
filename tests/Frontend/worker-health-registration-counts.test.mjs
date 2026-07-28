import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import test from 'node:test';
import { fileURLToPath } from 'node:url';

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

test('worker registration summary separates returned active and stale registrations', () => {
    const component = componentOptions();
    const context = {
        healthData: {
            operator_metrics: {
                workers: {
                    registration_count: 2,
                    active_registration_count: 0,
                    stale_registration_count: 2,
                },
            },
        },
        workers: [],
    };

    context.staleRegistrationCount = component.computed.staleRegistrationCount.call(context);
    context.registrationCount = component.computed.registrationCount.call(context);
    context.activeRegistrationCount = component.computed.activeRegistrationCount.call(context);

    assert.equal(context.registrationCount, 2);
    assert.equal(context.activeRegistrationCount, 0);
    assert.equal(context.staleRegistrationCount, 2);
    assert.equal(component.computed.registrationSummary.call(context), '0 active; 2 stale.');
});

test('worker registration summary does not double count stale rows returned in the roster', () => {
    const component = componentOptions();
    const context = {
        healthData: {
            operator_metrics: {
                workers: {
                    stale_registration_count: 1,
                },
            },
        },
        workers: [
            {worker_id: 'worker-active', status: 'active'},
            {worker_id: 'worker-stale', status: 'stale'},
        ],
    };

    context.staleRegistrationCount = component.computed.staleRegistrationCount.call(context);
    context.registrationCount = component.computed.registrationCount.call(context);
    context.activeRegistrationCount = component.computed.activeRegistrationCount.call(context);

    assert.equal(context.registrationCount, 2);
    assert.equal(context.activeRegistrationCount, 1);
    assert.equal(context.staleRegistrationCount, 1);
    assert.equal(component.computed.registrationSummary.call(context), '1 active; 1 stale.');
});
