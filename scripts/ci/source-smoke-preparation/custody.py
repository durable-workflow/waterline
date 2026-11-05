"""Exact Git-object custody for the held preparation packet. No launcher."""
import re
import stat

from contract import canonical, digest, require

PREFIX = 'scripts/ci/source-smoke-preparation/'
MEMBERS = {'README.md', 'accepted.py', 'binder.py', 'bootstrap.py', 'browser-tool-lock.json',
           'cell.py', 'contract.py', 'custody.py', 'guardian.py', 'preparation.Dockerfile',
           'producer.py', 'provider.py', 'recipe.py', 'test_guardian.py', 'test_preparation.py',
           'test_custody.py', 'test_provider.py'}
MAX_MEMBER = 262144
MAX_PACKET = 2 * 1024 * 1024


def snapshot(git, commit, tree):
    """The caller supplies an independently admitted preparation commit/tree.

    The Git adapter is a bounded host command reader, never the candidate.
    Every byte is retained in the return value; no later worktree import is
    authorized. This function neither evaluates nor executes the packet.
    """
    require(all(isinstance(value, str) and re.fullmatch(r'[a-f0-9]{40}', value)
                for value in (commit, tree)), 'Preparation custody Git identity')
    require(git('rev-parse', commit + '^{tree}').strip().decode() == tree, 'Preparation tree mismatch')
    listing = git('ls-tree', '-rz', commit, '--', PREFIX)
    require(len(listing) <= 65536, 'Preparation listing byte bound')
    records, raw, total = {}, {}, 0
    for entry in listing.split(b'\0'):
        if not entry:
            continue
        metadata, name = entry.decode().split('\t', 1)
        mode, kind, oid = metadata.split()
        relative = name.removeprefix(PREFIX)
        require(name.startswith(PREFIX) and relative in MEMBERS and relative not in raw
                and mode == '100644' and kind == 'blob', 'Preparation non-regular/extra object')
        require(0 <= int(git('cat-file', '-s', oid)) <= MAX_MEMBER, 'Preparation object byte bound')
        content = git('cat-file', 'blob', oid)
        total += len(content)
        require(len(content) <= MAX_MEMBER and total <= MAX_PACKET, 'Preparation packet byte bound')
        raw[relative] = content
        records[relative] = {'mode': stat.S_IFREG | 0o644, 'oid': oid, 'sha256': digest(content), 'size': len(content)}
    require(set(raw) == MEMBERS, 'Preparation object absent')
    return raw, records, digest(canonical(records))
