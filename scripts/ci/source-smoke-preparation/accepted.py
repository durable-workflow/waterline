"""Read only the already accepted collector helper from immutable Git objects."""
import subprocess
import types

from contract import PACKET_COMMIT, PACKET_TREE, require


def accepted_evidence(repository):
    def git(*args):
        result = subprocess.run(['git', '-C', str(repository), *args], capture_output=True, timeout=5, check=True)
        require(len(result.stdout) <= 262144, 'Accepted Git object bound')
        return result.stdout
    require(git('rev-parse', PACKET_COMMIT + '^{tree}').strip().decode() == PACKET_TREE,
            'Accepted collector tree changed')
    name = PACKET_COMMIT + ':scripts/ci/source-smoke-evidence/evidence.py'
    require(int(git('cat-file', '-s', name)) <= 262144, 'Accepted evidence object bound')
    raw = git('show', name)
    module = types.ModuleType('accepted_source_evidence')
    module.__file__ = '/reviewed-packet/evidence.py'
    exec(compile(raw, module.__file__, 'exec'), module.__dict__)
    return module
