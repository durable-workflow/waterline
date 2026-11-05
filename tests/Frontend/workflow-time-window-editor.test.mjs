import assert from 'node:assert/strict';
import fs from 'node:fs';
import test from 'node:test';
import {parse} from '@vue/compiler-sfc';
import * as windowControls from '../../resources/js/workflow-time-window.mjs';
import {createWaterlineI18n, messages} from '../../resources/js/localization.mjs';

const source = fs.readFileSync(new URL('../../resources/js/screens/flows/index.vue', import.meta.url), 'utf8');
const script = parse(source).descriptor.script.content.replace(/^[ \t]*import.*$/gm, '').replace('export default', 'return');
const component = Function('FlowRow', ...Object.keys(windowControls), script)({}, ...Object.values(windowControls));
const fields = Object.fromEntries(windowControls.TIME_WINDOW_FIELDS.map(field => [field, {filterable: true, group: 'started_time'}]));

function state(locale = 'en') {
    return {...component.methods, $t: createWaterlineI18n(locale).global.t, visibilityFieldDefinition: name => fields[name]};
}

test('the production editor exposes labelled rolling and fixed controls in every shipped locale', () => {
    for (const locale of Object.keys(messages)) {
        const view = state(locale);
        const html = view.timeWindowEditorHtml({started_time: 'rolling', started_within_seconds: 86400});
        assert.ok(html.includes(view.escapeHtml(view.$t('Rolling window'))), locale);
        for (const id of ['started-time', 'duration', 'duration-unit', 'started-from', 'started-before']) {
            assert.ok(html.includes(`for="waterline-filter-${id}"`), `${locale}/${id}`);
            assert.ok(html.includes(`id="waterline-filter-${id}"`), `${locale}/${id}`);
        }
        assert.match(html, /aria-describedby="waterline-fixed-time-help"/);
        assert.match(html, /data-waterline-time-mode="fixed" hidden/);
    }
});

test('switching the real editor mode hides and disables irrelevant controls', () => {
    const groups = ['rolling', 'fixed'].map(mode => ({dataset: {waterlineTimeMode: mode}, inputs: [{disabled: false}],
        querySelectorAll() { return this.inputs; }}));
    let changed;
    const mode = {value: 'rolling', addEventListener(name, callback) { assert.equal(name, 'change'); changed = callback; }};
    state().bindTimeWindowEditor({querySelector: () => mode, querySelectorAll: () => groups});
    assert.deepEqual(groups.map(group => [group.hidden, group.inputs[0].disabled]), [[false, false], [true, true]]);
    mode.value = 'fixed'; changed();
    assert.deepEqual(groups.map(group => [group.hidden, group.inputs[0].disabled]), [[true, true], [false, false]]);
    mode.value = 'any'; changed();
    assert.ok(groups.every(group => group.hidden && group.inputs[0].disabled));
});

test('the real saved-view merge replaces all prior time fields together', () => {
    const view = state();
    const stored = {workflow_type: 'orders', started_time: 'rolling', started_within_seconds: 86400};
    assert.deepEqual(view.mergeFilterPayloads(stored, {started_time: 'any'}), {workflow_type: 'orders', started_time: 'any'});
    const html = view.timeWindowEditorHtml({started_time: 'fixed', started_from: '"><script>alert(1)</script>', started_before: ''});
    assert.ok(!html.includes('<script>'));
    assert.ok(html.includes('&lt;script&gt;'));
});
