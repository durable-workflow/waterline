import assert from 'node:assert/strict';
import test from 'node:test';

import {
    NAVIGATION_STATES,
    PRESENTATIONS,
    STATES,
    STREAM_RESULTS,
    VIEWPORTS,
    runDetailFixture,
    summarizeRunDetailReports,
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
