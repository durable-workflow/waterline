"""Exact Git-object custody for the held preparation packet. No launcher."""
import re

import bootstrap

from contract import Refusal, canonical, digest, require, sha

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
    try:
        raw, records, manifest = bootstrap.source_packet(git, commit)
    except ValueError as error:
        raise Refusal('Preparation custody refused') from error
    require(set(raw) == MEMBERS, 'Preparation object absent')
    return raw, records, manifest


class SourceAuthority:
    """Independent static admission and actual Git custody, not report context.

    The immutable host caller supplies the reviewed commit/tree/manifest. The
    host Git reader proves their relationship and retains all source bytes.
    Neither a candidate report nor HostObservation can supply this authority.
    """
    def __init__(self, git, commit, tree, admitted_manifest_sha256):
        require(sha(admitted_manifest_sha256), 'Admitted preparation manifest missing')
        raw, records, manifest_sha256 = snapshot(git, commit, tree)
        require(manifest_sha256 == admitted_manifest_sha256, 'Admitted preparation manifest mismatch')
        self._identity = {'commit': commit, 'tree': tree, 'manifest_sha256': manifest_sha256}
        self._raw, self._records = raw, records

    def identity(self, context):
        context.validate()
        require(context.preparation_commit == self._identity['commit']
                and context.preparation_tree == self._identity['tree'], 'Preparation source custody mismatch')
        require(set(self._raw) == MEMBERS and digest(canonical(self._records)) == self._identity['manifest_sha256'],
                'Preparation source custody changed')
        require(all(digest(raw) == self._records[name]['sha256'] and len(raw) == self._records[name]['size']
                    for name, raw in self._raw.items()), 'Preparation source bytes changed')
        return dict(self._identity)
