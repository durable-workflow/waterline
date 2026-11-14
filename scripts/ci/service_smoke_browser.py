"""Unwired finite stdio controls for the existing published browser assertions."""

from __future__ import annotations

import base64
import json
import os
import selectors
import subprocess
import time

from service_smoke_io import kill_group, remaining_seconds
from service_smoke_browser_source import frozen_source
from waterline_release_identity import decode_json


class BrowserError(RuntimeError):
    """The owned browser process or its fixture protocol exceeded its boundary."""


def fixture_request(fixture, value: dict) -> dict:
    if not isinstance(value, dict) or set(value) != {"operation", "value"}:
        raise BrowserError("Invalid browser fixture command")
    operation, selected = value["operation"], value["value"]
    if operation == "mode" and isinstance(selected, dict):
        fixture.browser_mode(selected)
        return {"ok": True}
    if operation == "audit" and selected in {"writes.jsonl", "schedule-writes.jsonl"}:
        report = fixture.audit()
        return {"ok": True, "value": report["writes" if selected == "writes.jsonl" else "schedule_writes"]}
    raise BrowserError("Unselected browser fixture operation")


def handle_line(line: bytes, fixture, guard, completed):
    value = decode_json(line, "browser fixture request")
    if isinstance(value, dict) and set(value) == {"complete"} and isinstance(value["complete"], dict):
        if completed is not None:
            raise BrowserError("Duplicate browser completion")
        return b"", value["complete"]
    if completed is not None:
        raise BrowserError("Browser requested an effect after completion")
    guard()
    result = fixture_request(fixture, value)
    guard()
    raw = json.dumps(result, separators=(",", ":"), allow_nan=False).encode() + b"\n"
    if len(raw) > 8192:
        raise BrowserError("Browser fixture response exceeds its byte bound")
    return raw, completed


class RpcReader:
    def __init__(self, process, fixture, guard, deadline):
        self.process, self.fixture, self.guard, self.deadline = process, fixture, guard, deadline
        self.incoming, self.diagnostics, self.pending = bytearray(), bytearray(), b""
        self.total, self.commands, self.completed = 0, 0, None

    def write(self, stream, selector):
        self.pending = self.pending[os.write(stream.fileno(), self.pending):]
        if not self.pending:
            selector.unregister(stream)

    def protocol(self, raw: bytes, selector):
        self.total += len(raw)
        self.incoming.extend(raw)
        if self.total > 1024 * 1024 or len(self.incoming) > 65536:
            raise BrowserError("Browser protocol exceeds its byte bound")
        while b"\n" in self.incoming:
            line, _newline, rest = self.incoming.partition(b"\n")
            self.incoming = bytearray(rest)
            self.commands += 1
            if self.commands > 128 or self.pending:
                raise BrowserError("Too many or overlapping browser fixture requests")
            self.pending, self.completed = handle_line(bytes(line), self.fixture, self.guard, self.completed)
            if self.pending:
                selector.register(self.process.stdin, selectors.EVENT_WRITE)

    def read(self, stream, selector):
        raw = os.read(stream.fileno(), 65536)
        if not raw:
            selector.unregister(stream)
        elif stream is self.process.stderr:
            self.diagnostics.extend(raw)
            if len(self.diagnostics) > 65536:
                raise BrowserError("Browser diagnostics exceed their byte bound")
        else:
            self.protocol(raw, selector)

    def drain(self):
        with selectors.DefaultSelector() as selector:
            for stream in (self.process.stdin, self.process.stdout, self.process.stderr):
                os.set_blocking(stream.fileno(), False)
            selector.register(self.process.stdout, selectors.EVENT_READ)
            selector.register(self.process.stderr, selectors.EVENT_READ)
            while selector.get_map():
                for key, _mask in selector.select(min(remaining_seconds(self.deadline), 0.1)):
                    if key.fileobj is self.process.stdin:
                        self.write(key.fileobj, selector)
                    else:
                        self.read(key.fileobj, selector)
        while os.waitid(os.P_PID, self.process.pid, os.WEXITED | os.WNOHANG | os.WNOWAIT) is None:
            time.sleep(min(remaining_seconds(self.deadline), 0.005))
        kill_group(self.process)
        code = self.process.wait(timeout=5)
        self.guard()
        if code != 0 or self.incoming or self.pending or self.completed is None or self.completed.get("status") != "pass":
            raise BrowserError("Browser assertions did not complete successfully")
        return {"report": self.completed, "stderr": self.diagnostics.decode("utf-8", errors="strict"), "exit_code": code}


def rpc_process(command: list[str], fixture, guard, *, pass_fds=(), timeout: float = 180) -> dict:
    if not 0 < timeout <= 180 or not isinstance(command, list) or sum(len(value) for value in command) > 128 * 1024:
        raise BrowserError("Unselected browser process bound")
    guard()
    process = subprocess.Popen(command, stdin=subprocess.PIPE, stdout=subprocess.PIPE, stderr=subprocess.PIPE,
                               start_new_session=True, pass_fds=pass_fds,
                               env={"PATH": "/usr/bin:/bin", "LANG": "C.UTF-8", "LC_ALL": "C.UTF-8"})
    try:
        return RpcReader(process, fixture, guard, time.monotonic() + timeout).drain()
    finally:
        if process.returncode is None:
            kill_group(process)
            process.wait(timeout=5)
        for stream in (process.stdin, process.stdout, process.stderr):
            stream.close()


def browser_program(raw: bytes, *, kind: str, source: str, phase: str, base: str, output: str, dependency_root: str) -> str:
    if kind not in {"primary", "automatic"} or phase not in {"before", "cached", "cleared"} or not 0 < len(raw) <= 65536:
        raise BrowserError("Unselected existing browser assertion source or phase")
    selected = {"source": source, "phase": phase, "base": base, "output": output, "dependencyRoot": dependency_root,
                "fixedBrowserBudget": 0.8}
    encoded = "data:text/javascript;base64," + base64.b64encode(frozen_source(raw, kind)).decode()
    function = "runBrowserBudget" if kind == "primary" else "runAutoBudget"
    return """import readline from 'node:readline';
const input = readline.createInterface({input:process.stdin,crlfDelay:Infinity});
const replies = input[Symbol.asyncIterator]();
async function rpc(operation,value) {
    process.stdout.write(JSON.stringify({operation,value})+'\\n');
    const next = await replies.next();
    if (next.done) throw Error('Fixture channel closed');
    const answer = JSON.parse(next.value);
    if (answer.ok !== true) throw Error('Fixture control refused');
    return answer.value;
}
const fixture = {mode:value=>rpc('mode',value),audit:name=>rpc('audit',name)};
const selected = """ + json.dumps(selected, separators=(",", ":")) + ";\nconst assertions = await import(" + json.dumps(encoded) + ");\n" + (
        "try {\nconst report = await assertions." + function + "({...selected,fixture});\n"
        "process.stdout.write(JSON.stringify({complete:{...report,phase:selected.phase}})+'\\n');\n"
        "} finally { input.close(); process.stdin.destroy(); }\n"
    )
