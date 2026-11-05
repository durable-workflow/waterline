#!/usr/bin/env python3
"""Literal source hold. No arguments, imports or environment can enable a cell."""

if __name__ == '__main__':
    raise SystemExit('HELD: preparation/receipt/tool source only; no cell allocation or input selection')

import base64
import hashlib
import json
import re
import subprocess
import sys
import types

SNAPSHOT_COMMIT = 'f5b6bc8e0353f3fabaa8445021b4e6e5cd848921'
SNAPSHOT_SHA256 = 'd8693a6207c20d59458217d8a23be788f48e1f6cb59c7562cab24a32f8d5cca9'
CONTRACT = 'contract'
CUSTODY = 'custody'
BOOTSTRAP_MODULES = (CONTRACT, 'bootstrap', CUSTODY)
MODULES = (*BOOTSTRAP_MODULES, 'guardian', 'accepted', 'recipe', 'producer', 'binder', 'provider', 'cell')
SOURCE_PREFIX = 'scripts/ci/source-smoke-preparation/'


def require(condition, message):
    if not condition:
        raise ValueError(message)


def fixed_reader(repository):
    """Bootstrap only the already accepted, fixed bounded Git reader."""
    name = SNAPSHOT_COMMIT + ':scripts/ci/source-smoke-evidence/packet_snapshot.py'
    def git(*args):
        return subprocess.check_output(['git', '-C', str(repository), *args], timeout=5)
    size = int(git('cat-file', '-s', name))
    require(0 < size <= 262144, 'Fixed snapshot reader size bound')
    raw = git('show', name)
    require(len(raw) == size and hashlib.sha256(raw).hexdigest() == SNAPSHOT_SHA256,
            'Fixed snapshot reader identity mismatch')
    module = types.ModuleType('fixed_preparation_snapshot')
    module.__file__ = '/reviewed-packet/packet_snapshot.py'
    exec(compile(raw, module.__file__, 'exec'), module.__dict__)
    return module, raw


def load(name, raw):
    module = types.ModuleType(name)
    module.__file__ = '/reviewed-preparation/' + name + '.py'
    sys.modules[name] = module
    exec(compile(raw, module.__file__, 'exec'), module.__dict__)
    return module


def source_packet(git, commit):
    """Bound and hash every regular member before importing a new helper."""
    listing = git('ls-tree', '-rz', commit, '--', SOURCE_PREFIX)
    require(len(listing) <= 65536, 'Preparation listing byte bound')
    sources, records, total = {}, {}, 0
    for entry in listing.split(b'\0'):
        if not entry:
            continue
        metadata, path = entry.decode().split('\t', 1)
        mode, kind, oid = metadata.split()
        relative = path.removeprefix(SOURCE_PREFIX)
        require(path.startswith(SOURCE_PREFIX) and re.fullmatch(r'[a-zA-Z0-9_.-]+', relative)
                and relative not in sources and len(sources) < 128 and re.fullmatch(r'[a-f0-9]{40}', oid)
                and mode == '100644' and kind == 'blob', 'Preparation non-regular/extra object')
        size = int(git('cat-file', '-s', oid))
        require(0 <= size <= 262144, 'Preparation object byte bound')
        raw = git('cat-file', 'blob', oid)
        total += len(raw)
        require(len(raw) == size and total <= 2 * 1024 * 1024, 'Preparation packet byte bound')
        sources[relative] = raw
        records[relative] = {'mode': 0o100644, 'oid': oid, 'sha256': hashlib.sha256(raw).hexdigest(), 'size': size}
    manifest = hashlib.sha256(json.dumps(records, sort_keys=True, separators=(',', ':'), allow_nan=False).encode()).hexdigest()
    return sources, records, manifest


def reviewed_program(repository, commit, tree, admitted_manifest, context):
    """Prepare private immutable process input; never launch the returned code.

    A future caller must itself obtain this bootstrap from its independently
    admitted Git blob. No mutable worktree module is imported here.
    """
    require(all(isinstance(value, str) and re.fullmatch(r'[a-f0-9]{40}', value)
                for value in (commit, tree)), 'Preparation launcher Git identity')
    reader, snapshot_raw = fixed_reader(repository)
    def git(*args):
        return reader.git_small(repository, *args)
    require(git('rev-parse', commit + '^{tree}').strip().decode() == tree, 'Preparation launcher tree mismatch')
    sources, _records, manifest = source_packet(git, commit)
    require(manifest == admitted_manifest, 'Preparation launcher manifest mismatch')
    previous = {name: sys.modules.get(name) for name in BOOTSTRAP_MODULES}
    try:
        for name in BOOTSTRAP_MODULES:
            load(name, sources[name + '.py'])
        authority = sys.modules[CUSTODY].SourceAuthority(git, commit, tree, admitted_manifest)
        identity = authority.identity(context)
        payload = {'identity': identity, 'repository': str(repository),
                   'context': {key: getattr(context, key) for key in context.__dataclass_fields__},
                   'sources': {name: base64.b64encode(raw).decode() for name, raw in authority._raw.items()},
                   'records': authority._records,
                   'snapshot_reader': base64.b64encode(snapshot_raw).decode()}
    finally:
        for name, original in previous.items():
            if original is None:
                sys.modules.pop(name, None)
            else:
                sys.modules[name] = original
    encoded = json.dumps(payload, sort_keys=True, separators=(',', ':'))
    require(len(encoded.encode()) <= 2 * 1024 * 1024, 'Immutable preparation launcher byte bound')
    return (PROGRAM.replace('PAYLOAD_JSON', repr(encoded))
            .replace('FIXED_SNAPSHOT_SHA', repr(SNAPSHOT_SHA256))
            .replace('CLOSED_MODULES', repr(MODULES))).encode()


PROGRAM = '''import base64,hashlib,json,sys,types
payload=json.loads(PAYLOAD_JSON)
records=payload['records']
canonical=lambda value:json.dumps(value,sort_keys=True,separators=(',',':'),allow_nan=False).encode()
if hashlib.sha256(canonical(records)).hexdigest()!=payload['identity']['manifest_sha256']:
 raise SystemExit('Preparation sealed manifest mismatch')
raw={name:base64.b64decode(value,validate=True) for name,value in payload['sources'].items()}
if set(raw)!=set(records):raise SystemExit('Preparation sealed member set mismatch')
for name,value in raw.items():
 if len(value)!=records[name]['size'] or hashlib.sha256(value).hexdigest()!=records[name]['sha256']:
  raise SystemExit('Preparation sealed source bytes mismatch')
snapshot=base64.b64decode(payload['snapshot_reader'],validate=True)
if hashlib.sha256(snapshot).hexdigest()!=FIXED_SNAPSHOT_SHA:
 raise SystemExit('Preparation fixed reader mismatch')
fixed=types.ModuleType('fixed_preparation_snapshot');fixed.__file__='/reviewed-packet/packet_snapshot.py'
exec(compile(snapshot,fixed.__file__,'exec'),fixed.__dict__)
for name in CLOSED_MODULES:
 module=types.ModuleType(name);module.__file__='/reviewed-preparation/'+name+'.py'
 sys.modules[name]=module
 exec(compile(raw[name+'.py'],module.__file__,'exec'),module.__dict__)
def git(*arguments):return fixed.git_small(payload['repository'],*arguments)
identity=payload['identity']
authority=sys.modules['custody'].SourceAuthority(git,identity['commit'],identity['tree'],identity['manifest_sha256'])
context=sys.modules['contract'].Context(**payload['context'])
authority.identity(context)
sys.modules['cell'].execute_cell(authority=authority,context=context,repository=payload['repository'])
'''


def main():
    raise SystemExit('HELD: no preparation or collection admission')
