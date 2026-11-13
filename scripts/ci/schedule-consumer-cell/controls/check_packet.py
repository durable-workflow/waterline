"""Validate the retained snapshot only; no Docker or application execution."""
import base64
import hashlib
import importlib.util
import json
from pathlib import Path

SOURCE = Path(__file__).resolve().parent.parent
SNAPSHOT = SOURCE.parents[2]
spec = importlib.util.spec_from_file_location('frozen_packet_control', SOURCE / 'runner.py')
runner = importlib.util.module_from_spec(spec)
spec.loader.exec_module(runner)
raw = (SNAPSHOT / 'inputs/packet.json').read_bytes()
packet = json.loads(raw)
subjects, runtime = runner.captured_inputs(packet)
checks = 1
for name in subjects:
    changed = json.loads(raw)
    changed['subjects'][name] = base64.b64encode(subjects[name] + b'changed').decode()
    try:
        runner.captured_inputs(changed)
    except ValueError:
        checks += 1
    else:
        raise AssertionError('changed subject accepted')
for field, replacement in [('index', 'sha256:' + '0' * 64), ('amd64', 'sha256:' + '0' * 64), ('source', '0' * 40), ('version', '3.9.2')]:
    changed = json.loads(raw)
    changed['runtime'][field] = replacement
    try:
        runner.captured_inputs(changed)
    except ValueError:
        checks += 1
    else:
        raise AssertionError('changed runtime accepted')
loader = runner.loader_program(subjects)
assert loader.index('declare(strict_types=1);') < loader.index('$expectedPayloadSha256=') < loader.index('$input =')
print(json.dumps({'outcome': 'pass', 'checks': checks, 'packet_sha256': hashlib.sha256(raw).hexdigest(),
                  'packet_bytes': len(raw), 'runner_sha256': hashlib.sha256((SOURCE / 'runner.py').read_bytes()).hexdigest(),
                  'effective_loader_program_sha256': hashlib.sha256(loader.encode()).hexdigest()}))
