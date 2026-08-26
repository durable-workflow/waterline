import assert from 'node:assert/strict';
import test from 'node:test';

import {
    STATES,
    VIEWPORTS,
    runDetailFixture,
    summarizeRunDetailReports,
} from '../../scripts/ci/run-detail-visual.mjs';

test('run-detail qualification covers both disclosure states at every responsive viewport', () => {
    assert.deepEqual(VIEWPORTS, [
        { name: 'desktop', width: 1440, height: 900 },
        { name: 'intermediate', width: 900, height: 768 },
        { name: 'mobile', width: 390, height: 844 },
        { name: 'short-height', width: 1280, height: 480 },
    ]);
    assert.deepEqual(STATES, [
        { name: 'streams-expanded', expanded: true, fixture: 'embedded-mixed' },
        { name: 'streams-collapsed', expanded: false, fixture: 'embedded-mixed' },
        { name: 'service-supported-empty', expanded: true, fixture: 'service-supported-empty' },
        { name: 'service-unavailable', expanded: true, fixture: 'service-unavailable' },
        { name: 'embedded-degraded', expanded: true, fixture: 'embedded-degraded' },
    ]);
    assert.equal(VIEWPORTS.length * STATES.length, 20);
});

test('run-detail fixtures exercise mixed, supported-empty, unavailable, and degraded streams', () => {
    const fixture = runDetailFixture('embedded-mixed');

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

    const supportedEmpty = runDetailFixture('service-supported-empty');
    assert.equal(supportedEmpty.workflow_streams_state, 'available');
    assert.deepEqual(supportedEmpty.workflow_streams, []);

    const unavailable = runDetailFixture('service-unavailable');
    assert.equal(unavailable.workflow_streams_state, 'unavailable');
    assert.equal(typeof unavailable.workflow_streams_unavailable_reason, 'string');

    const degraded = runDetailFixture('embedded-degraded');
    assert.equal(degraded.workflow_streams_state, 'degraded');
    assert.equal(typeof degraded.workflow_streams_unavailable_reason, 'string');
});

test('structured summaries retain each material run-detail result', () => {
    const reports = VIEWPORTS.flatMap((viewport) => STATES.map((state) => ({
        state: state.name,
        viewport,
        screenshot: `${state.name}-${viewport.name}.png`,
        status: 'passed',
        failure: null,
    })));
    reports[7].status = 'failed';
    reports[7].failure = { name: 'Error', message: 'unreachable control' };

    const summary = summarizeRunDetailReports('http://sample.test', reports);

    assert.equal(summary.expectedCases, 20);
    assert.equal(summary.observedCases, 20);
    assert.equal(summary.passedCases, 19);
    assert.equal(summary.failedCases, 1);
    assert.deepEqual(summary.cases[7].failure, {
        name: 'Error',
        message: 'unreachable control',
    });
});
