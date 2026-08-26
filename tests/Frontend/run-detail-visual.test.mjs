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
        { name: 'streams-expanded', expanded: true },
        { name: 'streams-collapsed', expanded: false },
    ]);
    assert.equal(VIEWPORTS.length * STATES.length, 8);
});

test('run-detail fixture exercises Workflow Streams and the deep section route', () => {
    const fixture = runDetailFixture();

    assert.equal(fixture.instance_id, 'waterline-visual-instance');
    assert.equal(fixture.run_id, 'waterline-visual-run');
    assert.equal(fixture.workflow_streams_mode, 'service');
    assert.equal(fixture.workflow_streams.length, 2);
    assert.ok(fixture.workflow_streams.some(({ status }) => status === 'errored'));
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

    assert.equal(summary.expectedCases, 8);
    assert.equal(summary.observedCases, 8);
    assert.equal(summary.passedCases, 7);
    assert.equal(summary.failedCases, 1);
    assert.deepEqual(summary.cases[7].failure, {
        name: 'Error',
        message: 'unreachable control',
    });
});
