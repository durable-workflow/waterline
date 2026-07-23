import assert from 'node:assert/strict';
import test from 'node:test';

import {
  isExact2xPrerelease,
  isExactSemverRelease,
} from '../../scripts/conformance/worker-status-version.mjs';

test('server release pins accept exact alpha, beta, rc, and stable identities', () => {
  for (const version of [
    '2.0.0-alpha.1',
    '2.0.0-beta.4',
    '2.0.0-rc.2',
    '2.0.0',
    '1.13.4',
  ]) {
    assert.equal(isExactSemverRelease(version), true, version);
  }
});

test('server release pins reject ranges, aliases, malformed values, and unpinned inputs', () => {
  for (const version of [
    '',
    '*',
    '^2.0.0-beta.4',
    'latest',
    'current',
    'dev-v2',
    '2.0.0-latest',
    '2.0.0-snapshot.4',
    '2.0',
    '2.0.x',
    '2.0.0-beta.01',
    '2.0.0-beta..3',
    'v2.0.0-beta.4',
    '2.0.0-beta.4 || 2.0.0',
  ]) {
    assert.equal(isExactSemverRelease(version), false, version);
  }
});

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
