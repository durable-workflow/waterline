import assert from 'node:assert/strict';
import test from 'node:test';

import { isExact2xPrerelease } from '../../scripts/conformance/worker-status-version.mjs';

test('current 2.0 package prereleases are accepted as exact pins', () => {
  for (const version of [
    '2.0.0-alpha.1',
    '2.0.0-beta.1',
    '2.0.0-rc.1',
  ]) {
    assert.equal(isExact2xPrerelease(version), true, version);
  }
});

test('ranges, aliases, unpinned values, and versions outside the contract are rejected', () => {
  for (const version of [
    '',
    '*',
    '^2.0.0-beta.1',
    '2.0.x-dev',
    'dev-v2',
    '2.0.0',
    '2.0.1-beta.1',
    '1.0.0-rc.1',
    '2.0.0-preview.1',
    '2.0.0-beta',
    '2.0.0-beta.01',
    '2.0.0-beta.1 || 2.0.0',
  ]) {
    assert.equal(isExact2xPrerelease(version), false, version);
  }
});
