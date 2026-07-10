import assert from 'node:assert/strict';
import test from 'node:test';

import {
  publishedHttpUrl,
  validatedPublishedHost,
  waterlinePublishedTopology,
} from '../../scripts/conformance/worker-status-network.mjs';

test('a Docker gateway drives both externally reachable Waterline URLs', () => {
  assert.deepEqual(
    waterlinePublishedTopology('172.24.0.1', 32_868, 'waterline-sibling'),
    {
      externalHostUrl: 'http://172.24.0.1:32868',
      appUrl: 'http://172.24.0.1:32868',
      containerNetworkUrl: 'http://waterline-sibling:8000',
    },
  );
});

test('real host execution retains loopback when no override is supplied', () => {
  assert.equal(validatedPublishedHost(undefined), '127.0.0.1');
  assert.equal(publishedHttpUrl('', 8_000), 'http://127.0.0.1:8000');
});

test('DNS and IPv6 hosts are normalized into valid URL authorities', () => {
  assert.equal(publishedHttpUrl('HOST.DOCKER.INTERNAL', 8_000), 'http://host.docker.internal:8000');
  assert.equal(publishedHttpUrl('[fd00::1]', 8_000), 'http://[fd00::1]:8000');
});

test('schemes, ports, paths, credentials, whitespace, and malformed addresses are rejected', () => {
  for (const invalid of [
    'http://172.17.0.1',
    '172.17.0.1:8000',
    '172.17.0.1/path',
    'user@host',
    'docker host',
    '172.17.0.999',
    '[fd00::1]:8000',
  ]) {
    assert.throws(() => validatedPublishedHost(invalid), /DW_WATERLINE_HOST/);
  }
});
