import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';

const TYPES = {'.js': 'text/javascript', '.css': 'text/css', '.png': 'image/png'};

// A closed set of fixture artifacts, loaded before accepting any HTTP request.
export function loadScheduleArtifacts(directory, maximumBytes = 16 * 1024 * 1024) {
    const root = fs.lstatSync(directory);
    assert.ok(!root.isSymbolicLink(), 'artifact symlinks are refused');
    assert.ok(root.isDirectory(), 'artifact root must be a directory');
    const artifacts = new Map();
    let totalBytes = 0;
    function collect(current, prefix = '') {
        for (const entry of fs.readdirSync(current, {withFileTypes: true})) {
            const relative = path.join(prefix, entry.name);
            const file = path.join(current, entry.name);
            const metadata = fs.lstatSync(file);
            assert.ok(!metadata.isSymbolicLink(), 'artifact symlinks are refused');
            if (metadata.isDirectory()) { collect(file, relative); continue; }
            const type = TYPES[path.extname(entry.name)];
            if (!metadata.isFile() || !type) continue;
            totalBytes += metadata.size;
            assert.ok(totalBytes <= maximumBytes, 'fixture artifact memory budget exceeded');
            const bytes = fs.readFileSync(file);
            assert.equal(bytes.length, metadata.size, 'artifact changed during preload');
            artifacts.set('/assets/' + relative.split(path.sep).map(encodeURIComponent).join('/'), {type, bytes});
        }
    }
    collect(directory);
    return artifacts;
}
