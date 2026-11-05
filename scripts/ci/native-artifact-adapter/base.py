"""Load the accepted recipe primitives from retained, verified Git objects."""

from contextlib import contextmanager
import hashlib
import json
import re
import subprocess
import sys
import types

COMMIT = 'a04d0680d740722577ebe53c294d0c76d26bd6f8'
TREE = 'aa24f3d900408925799261a369b5554381c6060c'
MANIFEST = '2a81084227dd3629bde18373f1e29504858de6251eea2750ea30401debaa7332'
PREPARATION = '48683d03ba15586e28a06e5a5a2c69d0503134536b6d8c4fda9ec1412d7cde0b'
PREFIX = 'scripts/ci/source-smoke-preparation/'
EXTRA = ('docker/held-native.Dockerfile', 'docker/held-native-buildkit.toml',
         'release/held-native-recipe.json', 'release/held-native-recipe.md')
MODULES = ('contract', 'bootstrap', 'custody', 'guardian', 'accepted', 'recipe',
           'proxy', 'transport', 'capture', 'producer', 'binder', 'provider', 'cell')


def canonical(value):
    return json.dumps(value, sort_keys=True, separators=(',', ':'), allow_nan=False).encode()


def digest(raw):
    return hashlib.sha256(raw).hexdigest()


def git_reader(repository):
    def git(*arguments):
        result = subprocess.run(['git', '-C', str(repository), *arguments],
                                capture_output=True, check=True, timeout=5)
        if len(result.stdout) > 262144:
            raise ValueError('Accepted Git object bound')
        return result.stdout
    return git


def snapshot(git):
    if git('rev-parse', COMMIT + '^{tree}').strip().decode() != TREE:
        raise ValueError('Accepted recipe tree changed')
    raw, records = {}, {}
    listing = git('ls-tree', '-rz', COMMIT, '--', PREFIX, *EXTRA)
    for entry in listing.split(b'\0'):
        if not entry:
            continue
        metadata, path = entry.decode().split('\t')
        mode, kind, oid = metadata.split()
        if (mode != '100644' or kind != 'blob' or path in raw
                or not re.fullmatch('[a-f0-9]{40}', oid)
                or len(raw) >= 27 or int(git('cat-file', '-s', oid)) > 262144):
            raise ValueError('Accepted recipe member refused')
        value = git('cat-file', 'blob', oid)
        raw[path] = value
        records[path] = {'mode': 0o100644, 'oid': oid, 'size': len(value), 'sha256': digest(value)}
    if len(raw) != 27 or sum(map(len, raw.values())) != 268914 or digest(canonical(records)) != MANIFEST:
        raise ValueError('Accepted complete recipe packet changed')
    return raw


@contextmanager
def primitives(repository):
    """Only local Git reads. No provider, environment, Docker or lifecycle call.

    Restore the module registry after this explicit use. The accepted module
    objects and their classes retain their own immutable globals.
    """
    git = git_reader(repository)
    raw = snapshot(git)
    previous = {name: sys.modules.get(name) for name in MODULES}
    loaded = {}
    try:
        for name in MODULES:
            module = types.ModuleType(name)
            module.__file__ = '/accepted-recipe/' + name + '.py'
            sys.modules[name] = module
            exec(compile(raw[PREFIX + name + '.py'], module.__file__, 'exec'), module.__dict__)
            loaded[name] = module
        authority = loaded['custody'].SourceAuthority(git, COMMIT, TREE, PREPARATION)
        yield types.SimpleNamespace(**loaded, authority=authority, raw=raw, repository=repository)
    finally:
        for name, original in previous.items():
            if original is None:
                sys.modules.pop(name, None)
            else:
                sys.modules[name] = original
