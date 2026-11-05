// Inert adapter controls only. Never load the frozen browser qualification.
import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import {spawnSync} from 'node:child_process';
import {stateFiles,readObject,boundedResponse,controlHooks,originalInput} from './browser-driver.mjs';

test('state objects refuse oversized, linked and escaped inputs',()=>{
    const root=fs.realpathSync(fs.mkdtempSync(path.join(os.tmpdir(),'waterline-browser-')));
    try {
        const state=stateFiles(root);state.writeFileSync('mode.json','{}');
        assert.equal(state.readFileSync('mode.json','utf8'),'{}');
        assert.throws(()=>state.writeFileSync('../escape','x'));
        assert.throws(()=>state.writeFileSync('mode.json','x'.repeat(2097153)));
        fs.symlinkSync(path.join(root,'mode.json'),path.join(root,'writes.jsonl'));
        assert.throws(()=>state.readFileSync('writes.jsonl','utf8'));
        fs.linkSync(path.join(root,'mode.json'),path.join(root,'schedule-writes.jsonl'));
        assert.throws(()=>readObject(path.join(root,'mode.json'),2097152));
        assert.throws(()=>state.writeFileSync('mode.json','changed'));
        assert.equal(fs.readFileSync(path.join(root,'mode.json'),'utf8'),'{}');
    } finally {fs.rmSync(root,{recursive:true,force:true});}
});
test('response size is checked before body read or JSON decoding',async()=>{
    let reads=0;
    const response={headers:()=>({'content-length':'2097153'}),body:async()=>{reads++;return Buffer.from('{}');}};
    await assert.rejects(()=>boundedResponse(response));assert.equal(reads,0);
    response.headers=()=>({'content-length':'2'});
    assert.deepEqual(await boundedResponse(response),{});assert.equal(reads,1);
});
test('closed preload routing refuses unapproved entries and direct control imports',()=>{
    const entry=originalInput('/inputs/source/scripts/ci/request-budget-browser.mjs');
    assert.throws(()=>originalInput('/inputs/source/scripts/ci/../injected.mjs'));
    const hooks=controlHooks(entry.url);
    let delegated=0;
    const next=()=>{delegated++;return {url:'node:path'};};
    assert.throws(()=>hooks.resolve('node:child_process',{parentURL:entry.url},next));
    assert.equal(delegated,0);
    assert.ok(hooks.resolve('node:fs',{parentURL:entry.url},next).url.endsWith('/browser-state-fs.mjs'));
    assert.equal(delegated,0);
    hooks.resolve('node:path',{parentURL:entry.url},next);
    assert.equal(delegated,1);
});
test('normal Node preload applies the closed hooks before a synthetic entry loads',()=>{
    const root=fs.realpathSync(fs.mkdtempSync(path.join(os.tmpdir(),'waterline-preload-')));
    try {
        const entry=path.join(root,'entry.mjs');
        const preload=path.join(root,'preload.mjs');
        const driver=new URL('./browser-driver.mjs',import.meta.url).href;
        fs.writeFileSync(preload,`import {registerHooks} from 'node:module';import {controlHooks} from ${JSON.stringify(driver)};registerHooks(controlHooks(${JSON.stringify(new URL('file://'+entry).href)}));`);
        fs.writeFileSync(entry,"import assert from 'node:assert/strict';import path from 'node:path';assert.equal(path.basename('/fixture/check'),'check');");
        const allowed=spawnSync(process.execPath,['--import',preload,entry],{timeout:2000,encoding:'utf8'});
        assert.equal(allowed.status,0,allowed.stderr);
        fs.writeFileSync(entry,"import 'node:child_process';");
        const refused=spawnSync(process.execPath,['--import',preload,entry],{timeout:2000,encoding:'utf8'});
        assert.notEqual(refused.status,0);assert.match(refused.stderr,/Unapproved module import/);
    } finally {fs.rmSync(root,{recursive:true,force:true});}
});
