import assert from 'node:assert/strict';
import { EventEmitter } from 'node:events';
import test from 'node:test';

import {
  boundedDiagnostic,
  createInterruptionMonitor,
  runWithCleanup,
  waitForHttpReadiness,
} from '../../scripts/conformance/worker-status-runner-lifecycle.mjs';

test('structured runner diagnostics stay within their UTF-8 byte budget', () => {
  const diagnostic = boundedDiagnostic(`readiness failure: ${'🙂'.repeat(1_000)}`);

  assert.ok(Buffer.byteLength(diagnostic, 'utf8') <= 2_000);
  assert.match(diagnostic, /^readiness failure:/);
  assert.doesNotMatch(diagnostic, /�/);
});

function readyResponse() {
  return {
    ok: true,
    status: 200,
    text: async () => '{"status":"ok"}',
  };
}

test('an unresolved initial readiness request is bounded, retried, and cleaned up', async () => {
  let attempts = 0;
  let cleanupCalls = 0;
  const startedAt = Date.now();

  const observation = await runWithCleanup(
    () => waitForHttpReadiness({
      url: 'http://127.0.0.1:8080/waterline/api/v2/health',
      fetchImpl: async () => {
        attempts += 1;
        if (attempts === 1) return new Promise(() => {});
        return readyResponse();
      },
      attemptTimeoutMilliseconds: 25,
      overallTimeoutMilliseconds: 250,
      retryDelayMilliseconds: 5,
    }),
    async () => {
      cleanupCalls += 1;
    },
  );

  assert.equal(observation.status, 200);
  assert.equal(observation.attempts, 2);
  assert.equal(cleanupCalls, 1);
  assert.ok(Date.now() - startedAt < 2_000, 'readiness retry must remain bounded');
});

test('unresolved readiness exhaustion still reaches cleanup', async () => {
  let cleanupCalls = 0;
  const startedAt = Date.now();

  await assert.rejects(
    runWithCleanup(
      () => waitForHttpReadiness({
        url: 'http://127.0.0.1:8080/waterline/api/v2/health',
        fetchImpl: async () => new Promise(() => {}),
        attemptTimeoutMilliseconds: 20,
        overallTimeoutMilliseconds: 70,
        retryDelayMilliseconds: 5,
      }),
      async () => {
        cleanupCalls += 1;
      },
    ),
    /did not become ready after \d+ attempts/,
  );

  assert.equal(cleanupCalls, 1);
  assert.ok(Date.now() - startedAt < 2_000, 'readiness failure must remain bounded');
});

test('an interruption aborts unresolved readiness and still reaches cleanup', async () => {
  const processEvents = new EventEmitter();
  const interruption = createInterruptionMonitor(processEvents);
  let cleanupCalls = 0;

  setTimeout(() => processEvents.emit('SIGTERM'), 20);
  await assert.rejects(
    runWithCleanup(
      () => waitForHttpReadiness({
        url: 'http://127.0.0.1:8080/waterline/api/v2/health',
        fetchImpl: async () => new Promise(() => {}),
        attemptTimeoutMilliseconds: 1_000,
        overallTimeoutMilliseconds: 2_000,
        retryDelayMilliseconds: 5,
        signal: interruption.signal,
      }),
      async () => {
        cleanupCalls += 1;
      },
    ),
    /interrupted by SIGTERM/,
  );

  assert.equal(interruption.interruptedBy(), 'SIGTERM');
  assert.equal(cleanupCalls, 1);
  interruption.dispose();
});
