import assert from 'node:assert/strict';
import test from 'node:test';

import {
    DIALOGS,
    VIEWPORTS,
    summarizeDialogReports,
} from '../../scripts/ci/workflow-list-dialog-visual.mjs';

test('pre-merge dialog qualification defines the required eight cases', () => {
    assert.deepEqual(VIEWPORTS, [
        { name: 'desktop', width: 1440, height: 900 },
        { name: 'intermediate', width: 900, height: 768 },
        { name: 'mobile', width: 390, height: 844 },
        { name: 'short-height', width: 1280, height: 480 },
    ]);
    assert.deepEqual(
        DIALOGS.map(({ name, title, validation }) => ({ name, title, validation })),
        [
            { name: 'filters', title: 'Edit Filters', validation: true },
            { name: 'view-options', title: 'View Options', validation: false },
        ],
    );
    assert.equal(VIEWPORTS.length * DIALOGS.length, 8);
});

test('each material dialog state retains its readability audit categories', () => {
    const filters = DIALOGS.find(({ name }) => name === 'filters');
    const viewOptions = DIALOGS.find(({ name }) => name === 'view-options');

    assert.deepEqual(
        filters.requiredContrastCategories,
        ['title', 'label', 'help', 'notice', 'input', 'validation', 'action'],
    );
    assert.deepEqual(
        viewOptions.requiredContrastCategories,
        ['title', 'label', 'input', 'action'],
    );
});

test('structured summaries retain every passing and failing material state', () => {
    const reports = VIEWPORTS.flatMap((viewport) => DIALOGS.map((dialog) => ({
        dialog: dialog.name,
        state: dialog.validation ? 'filter-validation' : 'checked-and-unchecked-columns',
        viewport,
        screenshot: `${dialog.name}-${viewport.name}.png`,
        status: 'passed',
        failure: null,
    })));
    reports[0].status = 'failed';
    reports[0].failure = { name: 'Error', message: 'contrast failure' };

    const summary = summarizeDialogReports('http://sample.test', reports);

    assert.equal(summary.expectedCases, 8);
    assert.equal(summary.observedCases, 8);
    assert.equal(summary.passedCases, 7);
    assert.equal(summary.failedCases, 1);
    assert.deepEqual(summary.cases[0].failure, {
        name: 'Error',
        message: 'contrast failure',
    });
});
