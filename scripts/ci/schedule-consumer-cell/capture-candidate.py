"""Capture only regular candidate package leaves from one exact Git commit."""
import base64
import hashlib
import json
import pathlib
import re
import subprocess

ROOTS = {'app', 'config', 'database', 'public', 'resources', 'routes'}


def capture(repository, commit):
    if re.fullmatch(r'[0-9a-f]{40}', commit) is None:
        raise ValueError('exact commit required')
    def git(*args):
        return subprocess.check_output(['git', '-C', str(repository), *args])
    if git('rev-parse', commit + '^{commit}').decode().strip() != commit:
        raise ValueError('commit identity differs')
    tree = git('rev-parse', commit + '^{tree}').decode().strip()
    records = git('ls-tree', '-rz', '--full-tree', commit, *sorted(ROOTS))
    leaves = []
    total = 0
    for entry in records.split(b'\0'):
        if not entry:
            continue
        metadata, encoded_path = entry.split(b'\t', 1)
        mode, kind, oid = metadata.decode('ascii').split()
        name = encoded_path.decode('utf-8')
        path = pathlib.PurePosixPath(name)
        if (mode not in {'100644', '100755'} or kind != 'blob'
            or path.is_absolute() or path.parts[0] not in ROOTS
            or any(part in {'', '.', '..'} for part in path.parts)
            or path.as_posix() != name or '\\' in name or len(name.encode()) > 512):
            raise ValueError('unexpected candidate leaf')
        size = int(git('cat-file', '-s', oid))
        if size > 16 * 1024 * 1024 - total:
            raise ValueError('candidate capture byte budget exceeded before read')
        data = git('cat-file', 'blob', oid)
        if len(data) != size:
            raise ValueError('Git blob size differs')
        total += len(data)
        if total > 16 * 1024 * 1024 or len(leaves) >= 1000:
            raise ValueError('candidate capture budget exceeded')
        leaves.append({'path': name, 'git_blob': oid, 'git_mode': mode,
                       'sha256': hashlib.sha256(data).hexdigest(), 'size': len(data),
                       'base64': base64.b64encode(data).decode('ascii')})
    if {pathlib.PurePosixPath(x['path']).parts[0] for x in leaves} != ROOTS:
        raise ValueError('missing package root')
    leaves.sort(key=lambda x: x['path'])
    manifest = [{k: v for k, v in item.items() if k != 'base64'} for item in leaves]
    canonical = json.dumps(manifest, separators=(',', ':'), sort_keys=True).encode()
    return {'classification': 'source-overlay-candidate', 'commit': commit, 'tree': tree,
            'manifest_sha256': hashlib.sha256(canonical).hexdigest(),
            'bytes': total, 'leaves': leaves}
