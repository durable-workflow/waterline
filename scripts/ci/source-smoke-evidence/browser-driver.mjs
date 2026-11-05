// Disabled source-only adapter. Execute the unchanged, Git-bound M1 controls
// with explicit bounded I/O and a closed builtin/tool-module linker.
import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import vm from 'node:vm';
import {createHash} from 'node:crypto';
import {createRequire} from 'node:module';
import {fileURLToPath} from 'node:url';

const MAX_JSON = 2097152;
const ORIGINAL_HASHES = Object.freeze({
    browser:'6e40c0211104700fe6da345f7194b7683211f991b7ee0ee200692a05e7da2b1c',
    auto:'2d0649d8d5d26a848425c38023fd67926a775de905e9c8c9484e531f1c556121',
});

export function readObject(filename, limit) {
    const before = fs.lstatSync(filename);
    assert.ok(before.isFile() && !before.isSymbolicLink() && before.nlink === 1 && before.size <= limit);
    const descriptor = fs.openSync(filename,fs.constants.O_RDONLY|fs.constants.O_NOFOLLOW|fs.constants.O_NONBLOCK);
    try {
        const opened = fs.fstatSync(descriptor);
        const bytes = Buffer.alloc(before.size+1);
        let length = 0;
        let available = fs.readSync(descriptor,bytes,0,bytes.length,null);
        while (available > 0) {
            length += available;
            assert.ok(length <= before.size);
            available = fs.readSync(descriptor,bytes,length,bytes.length-length,null);
        }
        const closed = fs.fstatSync(descriptor);
        const after = fs.lstatSync(filename);
        for (const field of ['dev','ino','mode','nlink','size','mtimeMs','ctimeMs']) {
            assert.equal(before[field],opened[field]);
            assert.equal(before[field],closed[field]);
            assert.equal(before[field],after[field]);
        }
        assert.equal(length,before.size);
        return bytes.subarray(0,length);
    } finally { fs.closeSync(descriptor); }
}

export function stateFiles(root) {
    assert.equal(fs.realpathSync(root),root);
    const selected = name => {
        assert.ok(typeof name === 'string' && /^(?:mode\.json(?:\.tmp)?|writes\.jsonl|schedule-writes\.jsonl|request-budget-evidence(?:\/browser(?:-auto)?\.json)?)$/.test(name));
        const target = path.join(root,name);
        if (name.includes('/')) {
            assert.ok(!fs.lstatSync(path.dirname(target)).isSymbolicLink());
        }
        return target;
    };
    return Object.freeze({
        mkdirSync(name,options) { return fs.mkdirSync(selected(name),options); },
        renameSync(before,after) { return fs.renameSync(selected(before),selected(after)); },
        readFileSync(name,encoding) {
            assert.equal(encoding,'utf8');
            return new TextDecoder('utf-8',{fatal:true}).decode(readObject(selected(name),MAX_JSON));
        },
        writeFileSync(name,value) {
            assert.ok(typeof value === 'string' && Buffer.byteLength(value) <= MAX_JSON);
            const target = selected(name);
            const descriptor = fs.openSync(target,fs.constants.O_WRONLY|fs.constants.O_CREAT|fs.constants.O_NONBLOCK|fs.constants.O_NOFOLLOW,0o600);
            try {
                const info = fs.fstatSync(descriptor);
                assert.ok(info.isFile() && info.nlink === 1);
                fs.ftruncateSync(descriptor,0);
                fs.writeFileSync(descriptor,value);
            } finally { fs.closeSync(descriptor); }
        },
    });
}

export async function boundedResponse(response) {
    const length = Number(response.headers()['content-length']);
    assert.ok(Number.isInteger(length) && length >= 0 && length <= MAX_JSON);
    const raw = await response.body();
    assert.equal(raw.length,length);
    return JSON.parse(new TextDecoder('utf-8',{fatal:true}).decode(raw));
}

function boundedBrowserResponse(response) {
    return new Proxy(response,{get(original,property) {
        if (property === 'json') return () => boundedResponse(original);
        const value = Reflect.get(original,property);
        return typeof value === 'function' ? value.bind(original) : value;
    }});
}

function responseHandler(target,listeners,event,handler) {
    if (event !== 'response') return target.on(event,handler);
    const wrapped = response => handler(boundedBrowserResponse(response));
    listeners.set(handler,wrapped);
    return target.on(event,wrapped);
}

function responsePage(page) {
    const listeners = new Map();
    return new Proxy(page, {get(target,key) {
        if (key === 'on') return (event,handler) => responseHandler(target,listeners,event,handler);
        if (key === 'off') return (event,handler) => target.off(event,listeners.get(handler)||handler);
        const value = Reflect.get(target,key);
        return typeof value === 'function' ? value.bind(target) : value;
    }});
}

function boundedPlaywright(playwright) {
    return Object.freeze({chromium:{launch: async options => {
        const browser = await playwright.chromium.launch(options);
        return new Proxy(browser,{get(target,key) {
            if (key === 'newPage') return async options => responsePage(await target.newPage(options));
            const value = Reflect.get(target,key);
            return typeof value === 'function' ? value.bind(target) : value;
        }});
    }}});
}

export async function evaluateControls(source, stateRoot, playwright, environment) {
    const context = vm.createContext({console,URL,Buffer,setTimeout,clearTimeout,
        process:Object.freeze({cwd:()=>stateRoot,env:Object.freeze({...environment})})});
    const modules = new Map([
        ['node:assert/strict',assert],['node:fs',stateFiles(stateRoot)],['node:path',path],
        ['node:module',{createRequire: filename => {
            assert.equal(filename,path.join(stateRoot,'package.json'));
            return name => { assert.equal(name,'playwright'); return boundedPlaywright(playwright); };
        }}],
    ]);
    const module = new vm.SourceTextModule(source,{context});
    await module.link(specifier => {
        assert.ok(modules.has(specifier),'Unapproved module import');
        const value = modules.get(specifier);
        const exports = ['default',...Object.keys(value).filter(key=>key!=='default')];
        return new vm.SyntheticModule(exports,function () {
            this.setExport('default',value);
            for (const key of exports.slice(1)) this.setExport(key,value[key]);
        },{context});
    });
    await module.evaluate();
}

if (process.argv[1] && path.resolve(process.argv[1]) === fileURLToPath(import.meta.url)) {
    const variant = process.argv[2];
    assert.ok(Object.hasOwn(ORIGINAL_HASHES,variant),'Unapproved browser control');
    const original = variant === 'browser'
        ? '/inputs/source/scripts/ci/request-budget-browser.mjs'
        : '/inputs/source/scripts/ci/request-budget-auto.mjs';
    const raw = readObject(original,131072);
    assert.equal(createHash('sha256').update(raw).digest('hex'),ORIGINAL_HASHES[variant]);
    const playwright = createRequire('/tools/package.json')('playwright');
    await evaluateControls(new TextDecoder('utf-8',{fatal:true}).decode(raw),'/state',playwright,{
        EXPECTED_CANDIDATE_SOURCE:process.env.EXPECTED_CANDIDATE_SOURCE,
        FIXED_BROWSER_BUDGET_SECONDS:process.env.FIXED_BROWSER_BUDGET_SECONDS,
    });
}
