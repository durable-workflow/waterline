"""Bounded host-only reads using the supported runner's existing GitHub identity."""
if __name__ == '__main__':
    raise SystemExit('HELD: no preparation provider request is admitted')

import time

import contract
from contract import MAX_ARCHIVE, REPOSITORY, bounded_json, positive, require


class Provider:
    """No publisher, retry loop, credential lookup or image transport.

    The host action supplies the artifact ID. Candidate roles never receive
    this reader or its host-only actions:read authority. GitHub, not a report,
    supplies the run, artifact and downloaded archive identities.
    """
    def __init__(self, evidence, deadline):
        self.evidence, self.deadline = evidence, deadline

    def api(self, path, limit):
        require(contract.EXECUTION_ENABLED, 'HELD: no provider access admitted')
        require(path.startswith('repos/' + REPOSITORY + '/actions/'), 'Unsupported provider authority')
        return self.evidence.command(['gh', 'api', '--hostname', 'github.com', path],
            min(self.deadline, time.monotonic() + 15), stdout_limit=limit, stderr_limit=4096)[0]

    def read(self, context, artifact_id):
        context.validate()
        require(positive(artifact_id), 'Provider artifact ID missing')
        prefix = 'repos/' + REPOSITORY + '/actions/'
        run = bounded_json(self.api(prefix + f'runs/{context.run_id}/attempts/{context.attempt}', 262144))
        artifact = bounded_json(self.api(prefix + f'artifacts/{artifact_id}', 262144))
        # Validate provider metadata before paying the bounded download cost.
        from binder import provider
        provider(run, artifact, context)
        archive = self.api(prefix + f'artifacts/{artifact_id}/zip', MAX_ARCHIVE)
        return run, artifact, archive
