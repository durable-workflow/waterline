import assert from 'node:assert/strict';
import test from 'node:test';
import { spawnSync } from 'node:child_process';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

import {
  isExact2xPrerelease,
  isExact2xRelease,
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

test('package pins accept exact stable 2.x releases and reject unsupported majors and aliases', () => {
  for (const version of ['2.5.12', '2.10.0', '2.10.2', '2.0.0-rc.5']) {
    assert.equal(isExact2xRelease(version), true, version);
  }
  for (const version of ['', '*', '^2.5.12', 'latest', '2.10.x-dev', '2.10.0-latest', '1.13.4', '3.0.0', '2.10.00']) {
    assert.equal(isExact2xRelease(version), false, version);
  }
});

function actualPreflight(workflow, waterline) {
  const resultDir = fs.mkdtempSync(path.join(os.tmpdir(), 'waterline-pin-preflight-'));
  try {
    const runner = fileURLToPath(new URL('../../scripts/conformance/worker-status-published-artifacts.mjs', import.meta.url));
    const process = spawnSync(globalThis.process.execPath, [runner], {
      encoding: 'utf8',
      timeout: 10000,
      env: {
        ...globalThis.process.env,
        PATH: '',
        RESULT_DIR: resultDir,
        DW_SERVER_VERSION: '2.6.1',
        DW_SERVER_IMAGE: 'durableworkflow/server:2.6.1',
        DW_CLI_VERSION: '2.2.3',
        DW_PHP_SDK_VERSION: '2.3.7',
        DW_WORKFLOW_PHP_VERSION: workflow,
        DW_WATERLINE_VERSION: waterline,
        DW_WATERLINE_HOST: 'waterline.example.test',
        DW_WATERLINE_WORKER_STATUS_SHARED_SERVER_STATE: '',
      },
    });
    assert.equal(process.error, undefined, process.error?.message);
    assert.equal(process.status, 1, process.stdout + process.stderr);
    return JSON.parse(fs.readFileSync(path.join(resultDir, 'waterline-worker-status-result.json'), 'utf8'));
  } finally {
    fs.rmSync(resultDir, {recursive: true, force: true});
  }
}

test('actual runner accepts stable and prerelease pins before its unavailable Docker boundary', () => {
  for (const [workflow, waterline] of [['2.5.12', '2.10.0'], ['2.0.0-rc.5', '2.0.0-rc.4']]) {
    const result = actualPreflight(workflow, waterline);
    assert.match(result.runner_error.message, /required command not found: docker/);
    assert.equal(result.runner_error.published_command_started, false);
    assert.doesNotMatch(result.runner_error.message, /must be an exact/);
  }
});

test('actual runner rejects invalid package pins before a dependency or runtime starts', () => {
  for (const [workflow, waterline] of [['^2.5.12', '2.10.0'], ['2.5.12', '2.10.0-latest'], ['1.13.4', '2.10.0']]) {
    const result = actualPreflight(workflow, waterline);
    assert.match(result.runner_error.message, /must be an exact 2.x release/);
    assert.equal(result.runner_error.published_command_started, false);
    assert.doesNotMatch(result.runner_error.message, /required command not found/);
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
