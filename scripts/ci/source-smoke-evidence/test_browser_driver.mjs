// Inert adapter controls only. Never load the frozen browser qualification.
import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import {stateFiles,readObject,boundedResponse,evaluateControls} from './browser-driver.mjs';

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
test('closed linker refuses unknown imports without launching a browser',async()=>{
    const root=fs.realpathSync(fs.mkdtempSync(path.join(os.tmpdir(),'waterline-vm-')));
    try {
        await assert.rejects(()=>evaluateControls('import "node:child_process";',root,{},{}));
        await evaluateControls('import fs from "node:fs";fs.writeFileSync("mode.json","{}");',root,{},{});
        assert.equal(fs.readFileSync(path.join(root,'mode.json'),'utf8'),'{}');
    } finally {fs.rmSync(root,{recursive:true,force:true});}
});
