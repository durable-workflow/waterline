"""Admission-bound Git objects and immutable in-memory/volume handoff source."""
from __future__ import annotations

import base64
import hashlib
import io
import json
import os
import select
import time
import re
import subprocess
import tarfile
from pathlib import Path

PACKET_PATH='scripts/ci/source-smoke-evidence'
PACKET_FILES=frozenset({
    'README.md','bootstrap.py','browser-driver.mjs','browser-loader.mjs',
    'browser-state-fs.mjs','browser-tool-module.mjs','cleanup.py','collector.php',
    'evidence.py','fixture.php','packet_snapshot.py','proxy.php',
    'read_state.py','runner.py','state.php','test_evidence.py','test_runner.py',
    'test_snapshot.py','test_browser_driver.mjs',
})
MAX_OBJECT=262144
MAX_SNAPSHOT=8*1024*1024


class SnapshotRefusal(ValueError):
    pass


def require(condition,message):
    if not condition:raise SnapshotRefusal(message)


def git_small(repository,*arguments,limit=MAX_OBJECT):
    process=subprocess.Popen(['git','--no-pager','-C',str(repository),*arguments],
                             stdout=subprocess.PIPE,stderr=subprocess.DEVNULL)
    deadline=time.monotonic()+5
    try:
        value=bytearray()
        while True:
            remaining=deadline-time.monotonic()
            require(remaining>0,'Git object read timeout')
            ready,_,_=select.select([process.stdout],[],[],remaining)
            require(bool(ready),'Git object read timeout')
            chunk=os.read(process.stdout.fileno(),min(65536,limit+1-len(value)))
            if not chunk:break
            value.extend(chunk)
            require(len(value)<=limit,'Git object output bound')
        require(process.wait(timeout=max(.01,deadline-time.monotonic()))==0,'Git object read failed')
        return bytes(value)
    finally:
        process.stdout.close()
        if process.poll() is None:
            process.kill();process.wait(timeout=2)


def identity(raw,mode):
    require(type(mode) is int and mode in (0o644,0o755),'Packet mode refused')
    return {'mode':mode,'size':len(raw),'sha256':hashlib.sha256(raw).hexdigest()}


def reviewed_packet(repository,commit,tree):
    require(re.fullmatch(r'[a-f0-9]{40}',commit) is not None and re.fullmatch(r'[a-f0-9]{40}',tree) is not None,
            'Packet admission commit/tree required')
    actual=git_small(repository,'rev-parse',commit+'^{tree}',limit=128).strip().decode()
    require(actual==tree,'Packet admission tree mismatch')
    entries=git_small(repository,'ls-tree','-r','-z',commit,'--',PACKET_PATH)
    selected={}
    for entry in entries.split(b'\0'):
        if not entry:continue
        metadata,path=entry.split(b'\t',1);mode,kind,object_id=metadata.split()
        relative=path.decode().removeprefix(PACKET_PATH+'/')
        require(relative in PACKET_FILES and relative not in selected,'Unexpected/duplicate packet member')
        require(kind==b'blob' and mode in (b'100644',b'100755'),'Non-regular packet Git member')
        size=int(git_small(repository,'cat-file','-s',object_id.decode(),limit=128))
        require(0<=size<=MAX_OBJECT,'Packet object size bound')
        raw=git_small(repository,'cat-file','blob',object_id.decode(),limit=size)
        require(len(raw)==size,'Packet Git object length mismatch')
        selected[relative]={'bytes':raw,'identity':identity(raw,int(mode,8)&0o777)}
    require(set(selected)==PACKET_FILES,'Missing packet member')
    return selected


def exact_manifest(files):
    return {name:entry['identity'] for name,entry in sorted(files.items())}


def snapshot_archive(files):
    """All data already comes from admitted Git objects, not live paths."""
    require(len(files)<=128,'Snapshot member count bound')
    manifest=exact_manifest(files)
    output=io.BytesIO()
    with tarfile.open(fileobj=output,mode='w:') as archive:
        for name,entry in sorted(files.items()):
            require(not name.startswith('/') and all(part not in ('','.','..') for part in name.split('/')),
                    'Unsafe snapshot member')
            raw=entry['bytes'];meta=entry['identity']
            require(identity(raw,meta['mode'])==meta,'Snapshot bytes/identity mismatch')
            info=tarfile.TarInfo(name);info.size=len(raw);info.mode=meta['mode'];info.mtime=0
            archive.addfile(info,io.BytesIO(raw))
    value=output.getvalue();require(len(value)<=MAX_SNAPSHOT,'Snapshot aggregate bound')
    return value,manifest


def verify_archive(raw,manifest):
    """Inert authority control, also used before handing bytes to the tool role."""
    require(isinstance(raw,bytes) and len(raw)<=MAX_SNAPSHOT,'Snapshot archive bound')
    seen=set()
    with tarfile.open(fileobj=io.BytesIO(raw),mode='r:') as archive:
        for member in archive:
            require(member.name in manifest and member.name not in seen,'Extra/duplicate snapshot member')
            require(member.isfile() and member.mode in (0o644,0o755),'Non-regular snapshot member')
            expected=manifest[member.name]
            require(member.size==expected['size'] and member.size<=MAX_OBJECT,'Snapshot object size mismatch')
            body=archive.extractfile(member).read(MAX_OBJECT+1)
            require(identity(body,member.mode)==expected,'Snapshot object identity mismatch')
            seen.add(member.name)
    require(seen==set(manifest),'Missing snapshot member')


def validate_packet(packet):
    require(set(packet)==PACKET_FILES,'Exact reviewed packet allowlist required')
    for entry in packet.values():
        require(isinstance(entry['bytes'],bytes) and len(entry['bytes'])<=MAX_OBJECT
                and identity(entry['bytes'],entry['identity']['mode'])==entry['identity'],
                'Packet bytes/identity mismatch')


def host_program(packet,spec,repository,receipt,evidence):
    """Verified Git bytes become immutable process input, never reopened files."""
    validate_packet(packet)
    manifest=exact_manifest(packet)
    sources={name:base64.b64encode(entry['bytes']).decode() for name,entry in packet.items()}
    admitted=json.dumps({'sources':sources,'manifest':manifest,'spec':spec,
                         'repository':str(repository),'receipt':str(receipt),'evidence':str(evidence)},sort_keys=True)
    require(len(admitted.encode())<=MAX_SNAPSHOT,'Host program input bound')
    # -I removes cwd/PYTHONPATH imports. Only these exact modules enter sys.modules.
    return ('''import base64,json,sys,types
payload=json.loads('''+repr(admitted)+''')
for name in ('evidence','packet_snapshot','runner'):
    module=types.ModuleType(name)
    module.__file__='/reviewed-packet/scripts/ci/source-smoke-evidence/'+name+'.py'
    sys.modules[name]=module
    raw=base64.b64decode(payload['sources'][name+'.py'],validate=True)
    exec(compile(raw,module.__file__,'exec'),module.__dict__)
packet={name:{'bytes':base64.b64decode(raw,validate=True),'identity':payload['manifest'][name]}
        for name,raw in payload['sources'].items()}
sys.modules['runner'].execute_reviewed(payload['spec'],payload['repository'],payload['receipt'],payload['evidence'],packet)
''').encode()


# Runs only in an independently admitted trusted tool image, never the app.
# No live host mounts are provided; stdin is a bounded immutable tar payload.
SEAL_PROGRAM='''import hashlib,io,json,os,pathlib,sys,tarfile
manifest=json.loads(sys.argv[1]);raw=sys.stdin.buffer.read(8388609)
if len(raw)>8388608:raise SystemExit(1)
seen=set();root=pathlib.Path('/sealed')
with tarfile.open(fileobj=io.BytesIO(raw),mode='r:') as archive:
    for item in archive:
        if item.name not in manifest or item.name in seen or not item.isfile():raise SystemExit(1)
        expected=manifest[item.name]
        if item.size!=expected['size'] or item.size>262144 or item.mode!=expected['mode']:raise SystemExit(1)
        content=archive.extractfile(item).read(262145)
        if len(content)!=item.size or hashlib.sha256(content).hexdigest()!=expected['sha256']:raise SystemExit(1)
        target=root/item.name;target.parent.mkdir(parents=True,exist_ok=True)
        fd=os.open(target,os.O_CREAT|os.O_EXCL|os.O_WRONLY|os.O_NOFOLLOW,item.mode)
        with os.fdopen(fd,'wb') as stream:stream.write(content);stream.flush();os.fsync(stream.fileno())
        os.chmod(target,item.mode);seen.add(item.name)
if seen!=set(manifest):raise SystemExit(1)
print(json.dumps(manifest,sort_keys=True,separators=(',',':')))
'''
