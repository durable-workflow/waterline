import { test } from 'node:test';
import assert from 'node:assert/strict';
import { evaluate } from './npm-audit-allowlisted.mjs';

const pico = { url: 'https://github.com/advisories/GHSA-fc5q-39xc-vjpf', severity: 'high', title: 'ReDoS' };
const report = (vulnerabilities) => ({ vulnerabilities, metadata: { vulnerabilities: {} } });

test('accepts only the reviewed picomatch advisory and its transitive dependents', () => {
  const result = evaluate(report({
    picomatch: { severity: 'high', via: [pico] },
    fdir: { severity: 'high', via: ['picomatch'] },
  }));
  assert.deepEqual(result.failures, []);
  assert.equal(result.accepted.length, 1);
});

test('fails on any other moderate or higher advisory', () => {
  const result = evaluate(report({
    picomatch: { severity: 'high', via: [pico, { url: 'https://github.com/advisories/GHSA-xxxx-yyyy-zzzz', severity: 'moderate' }] },
  }));
  assert.equal(result.failures.length, 1);
});

test('binds the allowance to the exact package', () => {
  const result = evaluate(report({ other: { severity: 'high', via: [pico] } }));
  assert.equal(result.failures.length, 1);
});

test('ignores low and info advisories', () => {
  const result = evaluate(report({ x: { severity: 'low', via: [{ url: 'u', severity: 'low' }] } }));
  assert.deepEqual(result.failures, []);
});

test('fails when npm audit errored or produced no report', () => {
  assert.equal(evaluate({ error: { code: 'ENOLOCK' } }).failures.length, 1);
  assert.equal(evaluate({}).failures.length, 1);
  assert.equal(evaluate(null).failures.length, 1);
});
