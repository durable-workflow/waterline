import assert from 'node:assert/strict';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import test from 'node:test';
import {loadScheduleArtifacts} from '../../scripts/ci/schedule-refresh-artifacts.mjs';

function fixture(run) {
    const directory = fs.mkdtempSync(path.join(os.tmpdir(), 'waterline-schedule-artifacts-'));
    try { run(directory); } finally { fs.rmSync(directory, {recursive: true, force: true}); }
}

test('fixture HTTP paths select only the preloaded artifact set', () => fixture(directory => {
    fs.mkdirSync(path.join(directory, 'chunks'));
    fs.writeFileSync(path.join(directory, 'app.js'), 'app');
    fs.writeFileSync(path.join(directory, 'chunks', 'ui messages.js'), 'messages');
    fs.writeFileSync(path.join(directory, 'not-public.json'), 'not a served artifact');
    const artifacts = loadScheduleArtifacts(directory);
    assert.equal(artifacts.get('/assets/app.js').bytes.toString(), 'app');
    assert.equal(artifacts.get('/assets/chunks/ui%20messages.js').bytes.toString(), 'messages');
    for (const key of ['/assets/../app.js', '/assets/%2e%2e/app.js', '/assets/%2fapp.js', '/assets/not-public.json']) {
        assert.equal(artifacts.has(key), false);
    }
    fs.writeFileSync(path.join(directory, 'app.js'), 'changed after startup');
    assert.equal(artifacts.get('/assets/app.js').bytes.toString(), 'app', 'HTTP cannot reread changed filesystem paths');
}));

test('symlinks cannot admit outside artifact bytes or a directory escape', () => fixture(directory => {
    const publicDirectory = path.join(directory, 'public');
    fs.mkdirSync(publicDirectory);
    fs.writeFileSync(path.join(directory, 'outside.js'), 'synthetic outside marker');
    const link = path.join(publicDirectory, 'leak.js');
    fs.symlinkSync('../outside.js', link);
    assert.throws(() => loadScheduleArtifacts(publicDirectory), /artifact symlinks are refused/);
    fs.unlinkSync(link);
    fs.symlinkSync('..', path.join(publicDirectory, 'directory'));
    assert.throws(() => loadScheduleArtifacts(publicDirectory), /artifact symlinks are refused/);
    fs.symlinkSync('public', path.join(directory, 'root-link'));
    assert.throws(() => loadScheduleArtifacts(path.join(directory, 'root-link')), /artifact symlinks are refused/);
}));

test('artifact size is bounded before loading bytes', () => fixture(directory => {
    fs.writeFileSync(path.join(directory, 'app.js'), 'four');
    assert.throws(() => loadScheduleArtifacts(directory, 3), /memory budget exceeded/);
    assert.equal(loadScheduleArtifacts(directory, 4).get('/assets/app.js').bytes.length, 4);
}));
