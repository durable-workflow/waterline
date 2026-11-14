"""Unwired fixed fixture setup and control for native cache-phase checks."""

from __future__ import annotations

import base64
import hashlib
import json
import shlex
import time

from service_smoke_resources import OwnershipError
from waterline_release_identity import decode_json


FILES = {
    "protocol-server.php": "tests/Fixtures/service-mode-server.php",
    "server.php": "scripts/ci/request-budget-server.php",
}
CONTROL = b'''<?php
declare(strict_types=1);
$root = '/run/waterline';
foreach (['/run', $root] as $path) {
    $state = lstat($path);
    if (($state['mode'] & 0170000) !== 0040000) { throw new RuntimeException('Fixture root changed.'); }
}
if (posix_geteuid() !== 1000) { throw new RuntimeException('Wrong fixture UID.'); }
function fixtureFile(string $name): string {
    $path = '/run/waterline/'.$name;
    $state = lstat($path);
    if (($state['mode'] & 0170000) !== 0100000 || $state['nlink'] !== 1 || $state['size'] > 262144) {
        throw new RuntimeException('Invalid bounded fixture file.');
    }
    return $path;
}
function fixtureLines(string $name): array {
    $raw = file_get_contents(fixtureFile($name));
    return $raw === '' ? [] : explode("\n", rtrim($raw, "\n"));
}
$operation = $argv[1] ?? '';
$value = $argv[2] ?? '';
if ($operation === 'phase' && in_array($value, ['before', 'cached', 'cleared'], true)) {
    foreach (['reads.jsonl', 'writes.jsonl', 'protocol.log'] as $name) {
        file_put_contents(fixtureFile($name), '');
    }
    file_put_contents(fixtureFile('phase.txt'), $value);
    file_put_contents(fixtureFile('mode.json'), json_encode(['mode' => 'healthy'], JSON_THROW_ON_ERROR));
} elseif ($operation === 'mode' && in_array($value, ['healthy', 'backend-stall', 'write-stall'], true)) {
    file_put_contents(fixtureFile('mode.json'), json_encode(['mode' => $value], JSON_THROW_ON_ERROR));
} elseif ($operation === 'audit' && $value === '') {
    $writes = array_map(static fn ($line) => json_decode($line, true, flags: JSON_THROW_ON_ERROR), fixtureLines('writes.jsonl'));
    echo json_encode(['phase' => file_get_contents(fixtureFile('phase.txt')), 'writes' => $writes,
        'reads' => fixtureLines('reads.jsonl'), 'protocol' => fixtureLines('protocol.log')], JSON_THROW_ON_ERROR)."\n";
} else {
    throw new RuntimeException('Unselected fixture operation.');
}
'''


def startup(inputs: dict[str, bytes]) -> list[str]:
    if set(inputs) != set(FILES) or any(not isinstance(raw, bytes) or not 0 < len(raw) <= 65536 for raw in inputs.values()):
        raise OwnershipError("Incomplete or oversized fixed fixture source")
    statements = ["cd /run/waterline"]
    for name, raw in inputs.items():
        encoded = base64.b64encode(raw).decode()
        php = "file_put_contents(" + json.dumps(name) + ",base64_decode(" + json.dumps(encoded) + ",true));"
        statements.append("/usr/local/bin/php -n -r " + shlex.quote(php))
    statements += ["printf '%s' '{\"mode\":\"healthy\"}' > mode.json",
                   ": > reads.jsonl", ": > writes.jsonl", ": > protocol.log", ": > phase.txt",
                   "exec timeout -s TERM -k 5 600 /usr/local/bin/php -d error_log=/run/waterline/protocol.log -S 0.0.0.0:18081 /run/waterline/server.php"]
    return ["-eu", "-c", "\n".join(statements)]


class Fixture:
    def __init__(self, docker, role):
        if role.role != "fixture":
            raise OwnershipError("Expected the captured fixture role")
        self.docker, self.role = docker, role

    def command(self, operation: str, value: str = ""):
        result = self.role.command(self.docker, "exec", ["/usr/local/bin/php", "-n", "/dev/stdin", operation, value],
                                   options=("--interactive", "--user", "1000:1000"), data=CONTROL)
        if result.returncode != 0 or result.stderr:
            raise OwnershipError("Fixed fixture control failed")
        return result.stdout

    def phase(self, phase: str):
        self.command("phase", phase)

    def mode(self, mode: str):
        self.command("mode", mode)

    def audit(self) -> dict:
        return decode_json(self.command("audit"), "captured fixture audit")

    def drain(self, seconds: float):
        if seconds not in (2.3, 3.3):
            raise OwnershipError("Unselected fixture drain interval")
        self.role.inspect(self.docker)
        time.sleep(seconds)
        self.role.inspect(self.docker)


def source_hashes(inputs: dict[str, bytes]) -> dict:
    return {name: hashlib.sha256(raw).hexdigest() for name, raw in inputs.items()}
