"""Unwired ordinary wrapping of the unchanged browser assertion programs.

Both the original Git blob and the actual transformed module have literal
subjects. No held adapter, VM hook, collector or fallback path is imported.
"""

import hashlib


ORIGINAL = {
    "primary": "6e40c0211104700fe6da345f7194b7683211f991b7ee0ee200692a05e7da2b1c",
    "automatic": "2d0649d8d5d26a848425c38023fd67926a775de905e9c8c9484e531f1c556121",
}
COMPILED = {
    "primary": "66830e03aa579b884c9a88fcee8f1dab3ec38baa5905cc0719043cd523dac4ec",
    "automatic": "5e258805d90be6f95d53652662670782067f612b83d66459e843b7039fb5a4ce",
}
IMPORTS = """import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import {createRequire} from 'node:module';
"""


class BrowserSourceError(RuntimeError):
    """Original or actual callable bytes differ from their literal subjects."""


def replace_selected(body: str, before: str, after: str, count: int = 1) -> str:
    if body.count(before) != count:
        raise BrowserSourceError("Ordinary browser source boundary changed")
    return body.replace(before, after)


def transform_primary(body: str) -> tuple[str, str]:
    body = replace_selected(body, "    const base = 'http://127.0.0.1:8080';\n", "")
    for selected in ("mode(scenario);", "mode('write-stall');", "mode('browser-stall',fixture);",
                     "mode('healthy',fixture);", "mode('healthy',scheduleFixture);"):
        body = replace_selected(body, selected, "await " + selected)
    body = replace_selected(body, "mode('healthy');", "await mode('healthy');", 2)
    for name in ("writes.jsonl", "schedule-writes.jsonl"):
        before = "fs.readFileSync('" + name + "','utf8').trim().split('\\n').map(line=>JSON.parse(line))"
        body = replace_selected(body, before, "await fixture.audit('" + name + "')")
    header = """export async function runBrowserBudget({fixture,base,output,dependencyRoot,source}) {
const {chromium} = createRequire(path.join(dependencyRoot,'package.json'))('playwright');
fs.mkdirSync(output,{recursive:true});
const browserBudget = () => 0.8;
const mode = (value,extra={}) => fixture.mode({mode:value,service_budget:0.2,browser_budget:0.8,...extra});
const browser = await chromium.launch({args:['--no-sandbox']});
const report = {source,status:'running',checks:[],errors:[]};
"""
    return header, body


def transform_automatic(body: str) -> tuple[str, str]:
    for selected in ("mode('browser-stall');", "mode('healthy');"):
        body = replace_selected(body, selected, "await " + selected)
    body = replace_selected(body, "await page.goto('http://127.0.0.1:8080/waterline/running');",
                            "await page.goto(base+'/waterline/running');")
    header = """export async function runAutoBudget({fixture,base,output,dependencyRoot,source}) {
const {chromium} = createRequire(path.join(dependencyRoot,'package.json'))('playwright');
fs.mkdirSync(output,{recursive:true});
const mode = value => fixture.mode({mode:value,service_budget:0.2,browser_budget:3.5,browser_stall_microseconds:5_000_000});
const browser = await chromium.launch({args:['--no-sandbox']});
const report = {source,status:'running',browserBudget:3.5,requests:[],errors:[]};
"""
    return header, body


def transformed_source(raw: bytes, kind: str) -> bytes:
    if kind not in ORIGINAL or not isinstance(raw, bytes) or hashlib.sha256(raw).hexdigest() != ORIGINAL[kind]:
        raise BrowserSourceError("Original ordinary browser subject differs")
    source = raw.decode("utf-8", errors="strict")
    body = source[source.index("try {\n"):]
    header, body = transform_primary(body) if kind == "primary" else transform_automatic(body)
    body = replace_selected(body, "} finally {", "    report.status = 'pass';\n} catch (error) {\n"
                            "    report.status = 'nonpass';\n    report.failure = String(error);\n    throw error;\n} finally {")
    return (IMPORTS + header + body + "return report;\n}\n").encode()


def frozen_source(raw: bytes, kind: str) -> bytes:
    compiled = transformed_source(raw, kind)
    if hashlib.sha256(compiled).hexdigest() != COMPILED[kind]:
        raise BrowserSourceError("Actual callable browser bytes differ from their reviewed subject")
    return compiled
