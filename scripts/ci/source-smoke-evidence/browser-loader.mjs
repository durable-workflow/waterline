// Preload only. Node's normal module loader executes the unchanged sealed file.
import assert from 'node:assert/strict';
import {createHash} from 'node:crypto';
import {registerHooks} from 'node:module';
import {controlHooks,originalInput,readObject} from './browser-driver.mjs';

const input=originalInput(process.argv[1]);
const raw=readObject(input.filename,131072);
assert.equal(createHash('sha256').update(raw).digest('hex'),input.sha256);
registerHooks(controlHooks(input.url));
