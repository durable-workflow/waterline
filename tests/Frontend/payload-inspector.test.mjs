import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import test from 'node:test';
import { fileURLToPath } from 'node:url';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const componentPath = path.join(root, 'resources/js/components/PayloadInspector.vue');

function componentOptions() {
    const source = fs.readFileSync(componentPath, 'utf8');
    const script = source.match(/<script>([\s\S]*?)<\/script>/)?.[1];

    assert.ok(script, 'PayloadInspector must expose an options script.');

    return Function(script.replace('export default', 'return'))();
}

test('unsupported v2 codec envelopes remain raw diagnostics', () => {
    const component = componentOptions();
    const state = {
        decoded: null,
        rawValue: null,
    };

    component.methods.decodeEnvelope.call(state, {
        codec: 'unsupported-codec',
        blob: 'opaque-payload',
    });

    assert.equal(state.decoded, null);
    assert.equal(state.rawValue, 'opaque-payload');
});
