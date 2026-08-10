import assert from 'node:assert/strict';
import test from 'node:test';

import { createWaterlineDialogOptions } from '../../resources/js/dialogs.mjs';

function dialogFixture({ rootInert = false } = {}) {
    const attributes = new Set(rootInert ? ['inert'] : []);
    const root = {
        contains: () => false,
        hasAttribute: (name) => attributes.has(name),
        removeAttribute: (name) => attributes.delete(name),
        setAttribute: (name) => attributes.add(name),
    };
    const popupAttributes = new Map();
    const containerAttributes = new Map();
    const popup = {
        closest: () => ({
            setAttribute: (name, value) => containerAttributes.set(name, value),
        }),
        setAttribute: (name, value) => popupAttributes.set(name, value),
    };
    const ownerDocument = {
        getElementById: (id) => id === 'waterline' ? root : null,
    };

    return { attributes, containerAttributes, ownerDocument, popup, popupAttributes };
}

test('dark workflow-list dialogs expose one coherent modal contract', () => {
    const fixture = dialogFixture();
    const calls = [];
    const options = createWaterlineDialogOptions('dark', {
        title: 'Dialog title',
        customClass: {
            popup: 'caller-popup',
            confirmButton: 'caller-confirm',
        },
        didOpen: () => calls.push('opened'),
        willClose: () => calls.push('closed'),
    }, fixture.ownerDocument);

    assert.equal(options.background, '#181818');
    assert.equal(options.title, 'Dialog title');
    assert.equal(options.customClass.popup, 'waterline-dialog caller-popup');
    assert.equal(options.customClass.confirmButton, 'waterline-dialog__confirm caller-confirm');

    options.didOpen(fixture.popup);

    assert.equal(fixture.popupAttributes.get('role'), 'dialog');
    assert.equal(fixture.popupAttributes.get('aria-modal'), 'true');
    assert.equal(fixture.popupAttributes.get('data-waterline-dialog'), 'modal');
    assert.equal(fixture.containerAttributes.get('data-waterline-modal-backdrop'), 'intentional');
    assert.equal(fixture.attributes.has('inert'), true);
    assert.deepEqual(calls, ['opened']);

    options.willClose(fixture.popup);

    assert.equal(fixture.attributes.has('inert'), false);
    assert.deepEqual(calls, ['opened', 'closed']);
});

test('closing a dialog preserves an application root that was already inert', () => {
    const fixture = dialogFixture({ rootInert: true });
    const options = createWaterlineDialogOptions('light', {}, fixture.ownerDocument);

    assert.equal(options.background, '#ffffff');

    options.didOpen(fixture.popup);
    options.willClose(fixture.popup);

    assert.equal(fixture.attributes.has('inert'), true);
});
