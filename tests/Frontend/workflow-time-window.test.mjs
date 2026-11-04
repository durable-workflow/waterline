import assert from 'node:assert/strict';
import test from 'node:test';
import {timeWindowEditorState, timeWindowPayload, replaceTimeWindow} from '../../resources/js/workflow-time-window.mjs';

test('rolling editor reloads a duration and saves it without clock-dependent bounds', () => {
    const stored = {started_time: 'rolling', started_within_seconds: 86400};
    const state = timeWindowEditorState(stored);
    assert.deepEqual(state, {mode: 'rolling', duration: 1, unit: 'days', from: '', before: ''});
    assert.deepEqual(timeWindowPayload(state), stored);
    assert.deepEqual(timeWindowPayload({...state, duration: '2', unit: 'weeks'}), {started_time: 'rolling', started_within_seconds: 1209600});
});

test('fixed editor preserves microseconds and compares offsets without rounding to milliseconds', () => {
    const stored = {started_time: 'fixed', started_from: '2026-01-10T07:00:00.123456-05:00', started_before: '2026-01-10T12:00:00.123457Z'};
    assert.deepEqual(timeWindowPayload(timeWindowEditorState(stored)), stored);
    assert.throws(() => timeWindowPayload({...timeWindowEditorState(stored), before: '2026-01-10T12:00:00.123456Z'}));
});

test('switching modes replaces the whole saved time group and Any explicitly overrides a saved rolling view', () => {
    const previous = {namespace: 'orders', started_time: 'rolling', started_within_seconds: 86400};
    assert.deepEqual(replaceTimeWindow(previous, {started_time: 'any'}), {namespace: 'orders', started_time: 'any'});
    const fixed = timeWindowPayload({mode: 'fixed', from: '2026-01-01T00:00:00Z', before: '2026-01-02T00:00:00Z'});
    assert.deepEqual(replaceTimeWindow(previous, fixed), {namespace: 'orders', ...fixed});
});

test('invalid rolling durations and incomplete or local fixed bounds remain in the editor', () => {
    for (const duration of ['', '0', '-1', '1.5', '1e3', '999999999999999999']) {
        assert.throws(() => timeWindowPayload({mode: 'rolling', duration, unit: 'days'}));
    }
    for (const from of ['', 'now', '2026-01-01T00:00:00']) {
        assert.throws(() => timeWindowPayload({mode: 'fixed', from, before: '2026-01-02T00:00:00Z'}));
    }
});
