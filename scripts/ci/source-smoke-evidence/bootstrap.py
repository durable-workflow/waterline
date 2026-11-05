#!/usr/bin/env python3
"""Held launcher source; future launch is from its admitted Git blob via stdin.

Do not launch future executable packets by reading a mutable worktree script.
The independently authorized caller must bind this blob/commit/tree first.
"""
from __future__ import annotations

import sys
import re
import subprocess
import types

EXECUTION_ENABLED=False


class BootstrapRefusal(ValueError):
    pass


def reviewed_program(repository,commit,tree,spec,receipt_directory,destination):
    """Pure public-source preparation. It never launches the returned program."""
    if re.fullmatch(r'[a-f0-9]{40}',commit) is None or re.fullmatch(r'[a-f0-9]{40}',tree) is None:
        raise BootstrapRefusal('Admission commit/tree required')
    if spec.get('packet_commit')!=commit or spec.get('packet_tree')!=tree:
        raise BootstrapRefusal('Packet/launcher admission mismatch')
    actual=subprocess.check_output(['git','-C',str(repository),'rev-parse',commit+'^{tree}'],timeout=5).strip().decode()
    if actual!=tree:raise BootstrapRefusal('Admission tree mismatch')
    object_name=commit+':scripts/ci/source-smoke-evidence/packet_snapshot.py'
    size=int(subprocess.check_output(['git','-C',str(repository),'cat-file','-s',object_name],timeout=5))
    if not 0<size<=262144:raise BootstrapRefusal('Snapshot bootstrap object bound')
    raw=subprocess.check_output(['git','-C',str(repository),'cat-file','blob',object_name],timeout=5)
    if len(raw)!=size:raise BootstrapRefusal('Snapshot bootstrap object length mismatch')
    module=types.ModuleType('admitted_snapshot')
    module.__file__='/reviewed-git-object/packet_snapshot.py'
    exec(compile(raw,module.__file__,'exec'),module.__dict__)
    packet=module.reviewed_packet(repository,commit,tree)
    return module.host_program(packet,spec,repository,receipt_directory,destination)


def main():
    # Before any worktree import, repository read, snapshot or Docker operation.
    if not EXECUTION_ENABLED:
        raise SystemExit('HELD: reviewed immutable packet admission and execution are separate')
    raise SystemExit('HELD: no CLI execution path is admitted in this source proposal')


if __name__=='__main__':main()
