import assert from 'node:assert/strict';
import test from 'node:test';

import {
    NAVIGATION_STATES,
    PRESENTATIONS,
    STATES,
    STREAM_RESULTS,
    VIEWPORTS,
    runDetailFixture,
    simultaneousNavigationFailures,
    simultaneousTopbarFailures,
    summarizeRunDetailReports,
    workflowStreamHeaderHierarchyFailures,
} from '../../scripts/ci/run-detail-visual.mjs';

test('run-detail qualification covers presentation, result, navigation, and viewport contracts', () => {
    assert.deepEqual(VIEWPORTS, [
        { name: 'desktop', width: 1440, height: 900 },
        { name: 'intermediate', width: 768, height: 1024 },
        { name: 'mobile', width: 390, height: 844 },
        { name: 'short-height', width: 1280, height: 360 },
    ]);
    assert.deepEqual(NAVIGATION_STATES, [
        { name: 'initial', fragment: null },
        { name: 'deep-section', fragment: 'workflowStreams' },
    ]);
    assert.deepEqual(PRESENTATIONS, ['embedded', 'service']);
    assert.deepEqual(STREAM_RESULTS, ['populated', 'supported-empty', 'unavailable', 'degraded']);

    for (const presentation of PRESENTATIONS) {
        for (const result of STREAM_RESULTS) {
            assert.ok(STATES.some((state) => (
                state.presentation === presentation
                && state.result === result
                && state.expanded
            )));
        }
        assert.ok(STATES.some((state) => (
            state.presentation === presentation
            && state.result === 'populated'
            && !state.expanded
        )));
    }

    assert.equal(VIEWPORTS.length * NAVIGATION_STATES.length * STATES.length, 80);
});

test('run-detail fixtures exercise every material result in embedded and service presentations', () => {
    const fixture = runDetailFixture('embedded-populated');

    assert.equal(fixture.instance_id, 'waterline-visual-instance');
    assert.equal(fixture.run_id, 'waterline-visual-run');
    assert.equal(fixture.workflow_streams_mode, 'embedded');
    assert.equal(fixture.workflow_streams.length, 3);
    assert.deepEqual(
        fixture.workflow_streams.filter(({ stream_name }) => stream_name === 'orders')
            .map(({ direction }) => direction),
        ['inbound', 'outbound'],
    );
    assert.ok(fixture.workflow_streams.some(({ status }) => status === 'errored'));

    for (const presentation of PRESENTATIONS) {
        for (const result of STREAM_RESULTS) {
            const materialFixture = runDetailFixture(`${presentation}-${result}`);

            assert.equal(materialFixture.workflow_streams_mode, presentation);
            assert.equal(
                materialFixture.workflow_streams_state,
                ['populated', 'supported-empty'].includes(result) ? 'available' : result,
            );
            assert.equal(materialFixture.workflow_streams.length > 0, result === 'populated');
            assert.equal(
                typeof materialFixture.workflow_streams_unavailable_reason === 'string',
                ['unavailable', 'degraded'].includes(result),
            );
        }
    }
});

test('structured summaries retain each material run-detail result', () => {
    const reports = VIEWPORTS.flatMap((viewport) => NAVIGATION_STATES.flatMap((navigation) => (
        STATES.map((state) => ({
            state: state.name,
            streamState: state.fixture,
            presentation: state.presentation,
            result: state.result,
            navigation: navigation.name,
            viewport,
            screenshot: `${state.name}-${navigation.name}-${viewport.name}.png`,
            status: 'passed',
            failure: null,
        }))
    )));
    reports[7].status = 'failed';
    reports[7].failure = { name: 'Error', message: 'unreachable control' };

    const summary = summarizeRunDetailReports('http://sample.test', reports);

    assert.equal(summary.expectedCases, 80);
    assert.equal(summary.observedCases, 80);
    assert.equal(summary.passedCases, 79);
    assert.equal(summary.failedCases, 1);
    assert.deepEqual(summary.cases[7].failure, {
        name: 'Error',
        message: 'unreachable control',
    });
});

test('mobile header qualification rejects a disclosure that compresses its context', () => {
    const adjacent = {
        header: { top: 10, bottom: 180, width: 326 },
        context: { top: 26, bottom: 164, width: 109 },
        disclosure: { top: 26, bottom: 68, width: 207 },
    };
    const stacked = {
        header: { top: 10, bottom: 150, width: 326 },
        context: { top: 26, bottom: 82, width: 326 },
        disclosure: { top: 98, bottom: 134, width: 207 },
    };

    assert.deepEqual(workflowStreamHeaderHierarchyFailures(390, adjacent), [
        'Workflow Streams disclosure compresses the heading context',
        'Workflow Streams heading context is too narrow',
    ]);
    assert.deepEqual(workflowStreamHeaderHierarchyFailures(390, stacked), []);
    assert.deepEqual(workflowStreamHeaderHierarchyFailures(1440, adjacent), []);
});

test('simultaneous navigation qualification is independent of per-control reachability', () => {
    const individuallyReachableControls = [
        { target: 'Dashboard', inViewport: true, reachable: true },
        { target: 'Workers', inViewport: true, reachable: true },
    ];
    const compositionAfterControlScrolling = {
        sidebar: { scrollLeft: 318 },
        links: [
            { name: 'Dashboard', inViewport: false },
            { name: 'Workers', inViewport: false },
        ],
    };

    assert.ok(individuallyReachableControls.every(({ inViewport, reachable }) => inViewport && reachable));
    assert.deepEqual(simultaneousNavigationFailures(compositionAfterControlScrolling), [
        'responsive navigation did not return to its initial horizontal position',
        'Dashboard is not visible in the simultaneous initial navigation composition',
        'Workers is not visible in the simultaneous initial navigation composition',
    ]);
    assert.deepEqual(simultaneousNavigationFailures({
        sidebar: { scrollLeft: 0 },
        links: [
            { name: 'Dashboard', inViewport: true },
            { name: 'Workers', inViewport: true },
        ],
    }), []);
});

test('simultaneous top-bar qualification is independent of per-control reachability', () => {
    const individuallyReachableControls = [
        { target: 'Backend Standalone service', inViewport: true, reachable: true },
        { target: 'Light mode', inViewport: true, reachable: true },
    ];
    const clippedComposition = {
        topbar: { left: 0, right: 390, top: 0, bottom: 124 },
        actions: { scrollLeft: 184, scrollWidth: 564, clientWidth: 374 },
        items: [
            { name: 'Scope', inViewport: true, inTopbar: true, clipped: false },
            { name: 'Backend', inViewport: false, inTopbar: false, clipped: true },
            { name: 'Auto refresh', inViewport: false, inTopbar: false, clipped: false },
            { name: 'Theme', inViewport: false, inTopbar: false, clipped: false },
        ],
    };

    assert.ok(individuallyReachableControls.every(({ inViewport, reachable }) => inViewport && reachable));
    assert.deepEqual(simultaneousTopbarFailures(clippedComposition), [
        'persistent top-bar actions did not return to their initial horizontal position',
        'persistent top-bar actions overflow their simultaneous composition',
        'Backend is not visible in the simultaneous persistent top-bar composition',
        'Backend is clipped in the simultaneous persistent top-bar composition',
        'Auto refresh is not visible in the simultaneous persistent top-bar composition',
        'Theme is not visible in the simultaneous persistent top-bar composition',
    ]);
    assert.deepEqual(simultaneousTopbarFailures({
        topbar: { left: 0, right: 390, top: 0, bottom: 190 },
        actions: { scrollLeft: 0, scrollWidth: 374, clientWidth: 374 },
        items: ['Scope', 'Backend', 'Auto refresh', 'Theme'].map((name) => ({
            name,
            inViewport: true,
            inTopbar: true,
            clipped: false,
        })),
    }), []);
});
