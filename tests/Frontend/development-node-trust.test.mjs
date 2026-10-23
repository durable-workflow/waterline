import assert from 'node:assert/strict';
import { spawnSync } from 'node:child_process';
import fs from 'node:fs';
import { fileURLToPath } from 'node:url';
import { getCACertificates } from 'node:tls';
import test from 'node:test';
import { verifyNodeRuntime } from '../../.devcontainer/docker/verify-node-runtime.mjs';

const roots = getCACertificates('default');

test('development tooling refuses unsupported and pre-fix Node versions', () => {
    for (const version of ['18.20.8', '22.23.4', '24.21.9', '24.22.0', '26.12.1', '24.22.1-custom']) {
        assert.throws(() => verifyNodeRuntime(version, roots), /requires Node/);
    }
    assert.equal(verifyNodeRuntime('24.22.1', roots), roots.length);
    assert.equal(verifyNodeRuntime('24.23.0', roots), roots.length);
});

test('unavailable or invalid effective roots cannot qualify the runtime', () => {
    assert.throws(() => verifyNodeRuntime('24.22.1', []), /effective Node TLS roots/);
    assert.throws(() => verifyNodeRuntime('24.22.1', ['invalid certificate']));
});

test('raw Node refuses a blocked root reintroduced through NODE_EXTRA_CA_CERTS', () => {
    // Synthetic self-signed CA. Its signing key is not retained or used.
    const fixture = fileURLToPath(new URL('../Fixtures/blocked-node-ca.pem', import.meta.url));
    const blocked = fs.readFileSync(fixture, 'utf8');
    assert.throws(() => verifyNodeRuntime('24.22.1', [...roots, blocked]), /Blocked certificate/);
    const check = spawnSync(process.execPath, ['--input-type=module', '-e', `
        import {getCACertificates} from 'node:tls';
        import {verifyNodeRuntime} from './.devcontainer/docker/verify-node-runtime.mjs';
        verifyNodeRuntime('24.22.1', getCACertificates('default'));
    `], { encoding: 'utf8', env: { ...process.env, NODE_EXTRA_CA_CERTS: fixture } });
    assert.equal(check.status, 1);
    assert.match(check.stderr, /Blocked certificate/);
});
