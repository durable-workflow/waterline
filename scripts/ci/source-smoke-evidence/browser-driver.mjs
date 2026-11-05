// Disabled source-only adapter. Execute the unchanged, Git-bound M1 controls
// with explicit bounded I/O and a closed builtin/tool-module linker.
import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';

const MAX_JSON = 2097152;
export const ORIGINAL_HASHES = Object.freeze({
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

export function boundedPlaywright(playwright) {
    return Object.freeze({chromium:{launch: async options => {
        const browser = await playwright.chromium.launch(options);
        return new Proxy(browser,{get(target,key) {
            if (key === 'newPage') return async options => responsePage(await target.newPage(options));
            const value = Reflect.get(target,key);
            return typeof value === 'function' ? value.bind(target) : value;
        }});
    }}});
}

export function controlHooks(entryURL) {
    const redirects = new Map([
        ['node:fs',new URL('./browser-state-fs.mjs',import.meta.url).href],
        ['node:module',new URL('./browser-tool-module.mjs',import.meta.url).href],
    ]);
    return {resolve(specifier,context,nextResolve) {
        if (context.parentURL !== entryURL) return nextResolve(specifier,context);
        if (redirects.has(specifier)) return {url:redirects.get(specifier),shortCircuit:true};
        assert.ok(['node:assert/strict','node:path'].includes(specifier),'Unapproved module import');
        return nextResolve(specifier,context);
    }};
}

export function originalInput(filename) {
    if (filename === '/inputs/source/scripts/ci/request-budget-browser.mjs') {
        return {filename:'/inputs/source/scripts/ci/request-budget-browser.mjs',
            url:'file:///inputs/source/scripts/ci/request-budget-browser.mjs',sha256:ORIGINAL_HASHES.browser};
    }
    assert.equal(filename,'/inputs/source/scripts/ci/request-budget-auto.mjs','Unapproved browser control');
    return {filename:'/inputs/source/scripts/ci/request-budget-auto.mjs',
        url:'file:///inputs/source/scripts/ci/request-budget-auto.mjs',sha256:ORIGINAL_HASHES.auto};
}
