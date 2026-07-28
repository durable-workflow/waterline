import assert from 'node:assert/strict';
import test from 'node:test';

import { parseBootstrapConfig, readBootstrapConfig } from '../../resources/js/bootstrap-config.mjs';

const validConfig = {
    path: '/organizations/acme/namespaces/orders/waterline/',
    operator_scope: { mode: 'namespace', namespace: 'orders' },
    backend: { mode: 'service' },
    app_name: 'Managed Waterline',
    assets_current: true,
    maintenance: false,
};

test('normalizes a validated managed-route configuration', () => {
    assert.deepEqual(parseBootstrapConfig(JSON.stringify(validConfig)), {
        ...validConfig,
        path: 'organizations/acme/namespaces/orders/waterline',
        basePath: '/organizations/acme/namespaces/orders/waterline',
    });
});

test('returns null for missing or malformed configuration', () => {
    assert.equal(parseBootstrapConfig(null), null);
    assert.equal(parseBootstrapConfig(''), null);
    assert.equal(parseBootstrapConfig('{not-json'), null);
    assert.equal(parseBootstrapConfig('[]'), null);
    assert.equal(parseBootstrapConfig(JSON.stringify({ ...validConfig, path: null })), null);
    assert.equal(parseBootstrapConfig(JSON.stringify({ ...validConfig, backend: [] })), null);
    assert.equal(parseBootstrapConfig(JSON.stringify({ ...validConfig, assets_current: 'yes' })), null);
});

test('reads configuration from the mount element without dereferencing absent data', () => {
    assert.equal(readBootstrapConfig(null), null);
    assert.equal(readBootstrapConfig({}), null);
    assert.equal(readBootstrapConfig({ getAttribute: () => null }), null);
    assert.equal(readBootstrapConfig({
        getAttribute: (name) => name === 'data-waterline-config'
            ? JSON.stringify(validConfig)
            : null,
    }).basePath, '/organizations/acme/namespaces/orders/waterline');
});
