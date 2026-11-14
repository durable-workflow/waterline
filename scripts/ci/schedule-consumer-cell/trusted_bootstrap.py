"""Render a literal first-code program for independent review; never execute it.

Its parameters are independently reviewed identities, not fixture self-report.
The exact rendered bytes/hash must be fixed in the review and actual invocation.
"""
import re
from pathlib import Path


def render(commit, repository, packet_path, runner_sha256, packet_sha256):
    if re.fullmatch('[0-9a-f]{40}', commit) is None or any(
        re.fullmatch('[0-9a-f]{64}', value) is None for value in [runner_sha256, packet_sha256]
    ):
        raise ValueError('independently reviewed exact identities required')
    if not Path(repository).is_absolute() or not Path(packet_path).is_absolute():
        raise ValueError('literal absolute input roles required')
    constants = repr({'commit': commit, 'repository': str(repository), 'packet': str(packet_path),
                      'runner_sha256': runner_sha256, 'packet_sha256': packet_sha256})
    return 'SELECTED = ' + constants + '\n' + BODY


BODY = r'''import hashlib, io, os, stat, subprocess, sys
relative = 'scripts/ci/schedule-consumer-cell/runner.py'
expected = SELECTED
source = subprocess.check_output(
    ['/usr/bin/git', '-C', expected['repository'], 'show', expected['commit'] + ':' + relative],
    env={'PATH': os.defpath}, timeout=10,
)
if len(source) > 512 * 1024 or hashlib.sha256(source).hexdigest() != expected['runner_sha256']:
    raise ValueError('reviewed Git runner bytes differ')
handles = []

def shape(info):
    return (info.st_dev, info.st_ino, info.st_mode, info.st_uid, info.st_gid,
            info.st_nlink, info.st_size, info.st_mtime_ns, info.st_ctime_ns)

def capture(path, maximum, checksum):
    descriptor = os.open(path, os.O_RDONLY | os.O_NOFOLLOW | os.O_CLOEXEC)
    handles.append(descriptor)
    info = os.fstat(descriptor)
    if not stat.S_ISREG(info.st_mode) or info.st_uid != os.getuid() or info.st_nlink != 1 or info.st_mode & 0o022 or info.st_size > maximum:
        raise ValueError('input is not an owned regular immutable role')
    data = os.pread(descriptor, maximum + 1, 0)
    if len(data) != info.st_size or hashlib.sha256(data).hexdigest() != checksum:
        raise ValueError('input bytes differ before first instruction')
    item = (path, descriptor, shape(info), maximum, checksum)
    return item, data

try:
    runner, working_bytes = capture(expected['repository'] + '/' + relative, 512 * 1024, expected['runner_sha256'])
    packet, packet_bytes = capture(expected['packet'], 64 * 1024 * 1024, expected['packet_sha256'])
    if working_bytes != source:
        raise ValueError('working runner differs from captured Git bytes')
    def recheck_inputs():
        for path, descriptor, original, maximum, checksum in [runner, packet]:
            if shape(os.fstat(descriptor)) != original or shape(os.stat(path, follow_symlinks=False)) != original:
                raise ValueError('held bootstrap input was substituted')
    recheck_inputs()
    sys.stdin = io.TextIOWrapper(io.BytesIO(packet_bytes), encoding='utf-8')
    sys.argv = ['captured-waterline-schedule-cell.py']
    scope = {'__name__': '__main__', '__file__': 'captured-waterline-schedule-cell.py',
             '__waterline_recheck_inputs__': recheck_inputs,
             '__waterline_bootstrap_identity__': expected}
    # Execute only retained Git bytes; no executable pathname is reopened.
    exec(compile(source, scope['__file__'], 'exec'), scope)
finally:
    for descriptor in reversed(handles):
        os.close(descriptor)
'''
