import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import test from 'node:test';
import { fileURLToPath } from 'node:url';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const frontendRoot = path.join(root, 'resources/js');

function frontendSources(directory) {
    return fs.readdirSync(directory, { withFileTypes: true }).flatMap((entry) => {
        const sourcePath = path.join(directory, entry.name);

        if (entry.isDirectory()) {
            return frontendSources(sourcePath);
        }

        return /\.(?:js|mjs|vue)$/.test(entry.name) ? [sourcePath] : [];
    });
}

test('package frontend source leaves style attributes to bounded runtime writes', () => {
    const violations = frontendSources(frontendRoot).flatMap((sourcePath) => {
        const source = fs.readFileSync(sourcePath, 'utf8');

        return source.split('\n').flatMap((line, index) =>
            /\sstyle\s*=/.test(line)
                ? [`${path.relative(root, sourcePath)}:${index + 1}`]
                : []
        );
    });

    assert.deepEqual(violations, []);
});
