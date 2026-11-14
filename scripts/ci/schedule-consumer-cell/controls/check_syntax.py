"""Syntax only. PHP lint containers use captured IDs; no app executes."""
import ast
import hashlib
import importlib.util
import json
import os
from pathlib import Path
import secrets
import subprocess

SOURCE = Path(__file__).resolve().parent.parent
SNAPSHOT = SOURCE.parents[2]
def load(name, file):
    spec = importlib.util.spec_from_file_location(name, SOURCE / file)
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module
runner = load('syntax_runner', 'runner.py')
owner = load('syntax_ownership', 'owned-docker.py')
subjects, _ = runner.captured_inputs(json.loads((SNAPSHOT / 'inputs/packet.json').read_bytes()))
for name in ['SETUP', 'INVENTORY', 'WORKER_LOAD', 'SEED']:
    ast.parse(getattr(runner, name))
loader = runner.loader_program(subjects)
os.environ.clear(); os.environ.update({'PATH': os.defpath})
docker = ['/usr/bin/docker', '--host', 'unix:///var/run/docker.sock', '--config', '/proc/self/fd']
labels = {'dev.durable-workflow.fixture-owner': 'waterline', 'dev.durable-workflow.fixture-run': secrets.token_hex(12), 'dev.durable-workflow.fixture-role': 'packet-lint'}
flags = [piece for key, value in labels.items() for piece in ['--label', key + '=' + value]]
checks = {}
for name, source in [('effective-loader', loader), ('fingerprint', runner.APP_FINGERPRINT)]:
    identifier = subprocess.check_output(docker + ['create', '-i', '--name', 'waterline-packet-lint-' + name + '-' + labels['dev.durable-workflow.fixture-run'], *flags,
        '--init', '--cpus', '1', '--memory', '128m', '--pids-limit', '64', '--network', 'none', '--read-only', '--entrypoint', 'php',
        'sha256:5e6f71b4bc79c9a98a2c1f45dc2d003522a0fb440719a96546eac33f5a3487a1', '-l', '/dev/stdin'], text=True).strip()
    try:
        owner.inspect_owned(docker, identifier, labels)
        result = subprocess.run(docker + ['start', '-ai', identifier], input=('<?php\n' + source).encode(), capture_output=True, timeout=15)
        if result.returncode:
            raise RuntimeError('PHP syntax control failed')
        checks[name] = {'syntax': 'pass', 'sha256': hashlib.sha256(source.encode()).hexdigest()}
    finally:
        owner.remove_owned(docker, identifier, labels)
identifier = subprocess.check_output(docker + ['create', '-i', '--name', 'waterline-packet-lint-node-' + labels['dev.durable-workflow.fixture-run'], *flags,
    '--init', '--cpus', '1', '--memory', '128m', '--pids-limit', '64', '--network', 'none', '--read-only', '--entrypoint', 'node', runner.BROWSER, '--check'], text=True).strip()
try:
    owner.inspect_owned(docker, identifier, labels)
    result = subprocess.run(docker + ['start', '-ai', identifier], input=runner.TOOLS_LOAD.encode(), capture_output=True, timeout=10)
    if result.returncode:
        raise RuntimeError('Node tools-loader syntax failed')
finally:
    owner.remove_owned(docker, identifier, labels)
checks['embedded-python'] = {'syntax': 'pass', 'programs': 4}
checks['tools-loader'] = {'syntax': 'pass', 'sha256': hashlib.sha256(runner.TOOLS_LOAD.encode()).hexdigest()}
print(json.dumps(checks))
