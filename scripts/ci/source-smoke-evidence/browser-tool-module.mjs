import assert from 'node:assert/strict';
import {createRequire as toolRequire} from 'node:module';
import {boundedPlaywright} from './browser-driver.mjs';

export function createRequire(filename) {
    assert.equal(filename,'/state/package.json');
    return name => {
        assert.equal(name,'playwright');
        return boundedPlaywright(toolRequire('/tools/package.json')('playwright'));
    };
}
