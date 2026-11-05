"""Pure contracts for a held, single-runner preparation/collection cell."""
from __future__ import annotations

import hashlib
import json
import re
from dataclasses import dataclass

SOURCE = '3d68d7d8954a0743104b318d03708515c7e3c530'
SOURCE_TREE = 'aadb0484b0330ed5bfaabde42245450cafa990c4'
LOCK = 'bc2014dd77b48074d41b9aded14a82cbab5c3676b59e36e5b2203aeb644e3506'
GRAPH = 'ac685314450fb8d939005d69b813a98d28f3571253b8bd91fa6640af0daef777'
FILES = 'b1a4a4edd0d83377371563e71c0fc7fa3dcbb5961b62e8c57601b3c5e5a3ddec'
PACKET_COMMIT = 'f5b6bc8e0353f3fabaa8445021b4e6e5cd848921'
PACKET_TREE = 'c341552e506cf512938e52d497fc082396bb0ffd'
WORKFLOW = '.github/workflows/service-image-smoke.yml'
NATIVE_WORKFLOW = '.github/workflows/service-image.yml'
HOSTED_BUILD_ARGUMENTS = (('recipe_sha256', 'WATERLINE_RECIPE_SHA256'),
                          ('source_manifest_sha256', 'WATERLINE_SOURCE_MANIFEST_SHA256'),
                          ('cache_manifest_sha256', 'WATERLINE_CACHE_MANIFEST_SHA256'),
                          ('context_sha256', 'WATERLINE_CONTEXT_SHA256'))
REPOSITORY = 'durable-workflow/waterline'
MAX_REPORT = 2 * 1024 * 1024
MAX_ARCHIVE = 4 * 1024 * 1024
MAX_NETWORK = 2 * 1024 ** 3
MAX_STORAGE = 8 * 1024 ** 3
EXECUTION_ENABLED = False


class Refusal(ValueError):
    """An identity or an admission boundary is unavailable."""


def require(condition, message):
    if not condition:
        raise Refusal(message)


def digest(raw):
    return hashlib.sha256(raw).hexdigest()


def canonical(value):
    return json.dumps(value, sort_keys=True, separators=(',', ':'), allow_nan=False).encode()


def sha(value):
    return isinstance(value, str) and re.fullmatch(r'[a-f0-9]{64}', value) is not None


def image_id(value):
    return isinstance(value, str) and value.startswith('sha256:') and sha(value[7:])


def positive(value):
    return type(value) is int and value > 0


def unique_keys(pairs):
    result = {}
    for key, value in pairs:
        require(key not in result, 'Duplicate JSON key')
        result[key] = value
    return result


def refuse_constant(_):
    raise Refusal('Nonfinite JSON')


def bounded_json(raw, limit=MAX_REPORT):
    require(isinstance(raw, bytes) and len(raw) <= limit, 'JSON byte bound')
    try:
        value = json.loads(raw, object_pairs_hook=unique_keys, parse_constant=refuse_constant)
    except (ValueError, RecursionError) as error:
        raise Refusal('Invalid JSON') from error
    check_json(value)
    return value


def check_json(value, depth=0):
    require(depth <= 32, 'JSON depth bound')
    if isinstance(value, str):
        require(len(value.encode()) <= 16384, 'JSON string bound')
    elif isinstance(value, dict):
        require(len(value) <= 4096, 'JSON object bound')
        for key, item in value.items():
            check_json(key, depth + 1)
            check_json(item, depth + 1)
    elif isinstance(value, list):
        require(len(value) <= 4096, 'JSON array bound')
        for item in value:
            check_json(item, depth + 1)


def exact(value, keys, message):
    require(isinstance(value, dict) and set(value) == set(keys), message)


def source_expectation(expected):
    require(expected.get('source') == SOURCE and expected.get('tree') == SOURCE_TREE
            and expected.get('lock') == LOCK, 'Independent application source mismatch')
    require(len(expected.get('graph', [])) == 79 and digest(canonical(expected['graph'])) == GRAPH,
            'Independent package graph mismatch')
    require(len(expected.get('files', {})) == 197 and digest(canonical(expected['files'])) == FILES,
            'Independent source file mapping mismatch')
    require(expected.get('php') == '8.3.36' and expected.get('int_size') == 8, 'PHP source contract mismatch')


def unbound_spec():
    """These are missing outputs, not fabricated candidate identities."""
    return {'source': SOURCE, 'platform': None, 'image_id': None, 'image_config_sha256': None,
            'browser_image_id': None, 'browser_config_sha256': None,
            'vendor_preparation_receipt_sha256': None,
            'packet_commit': PACKET_COMMIT, 'packet_tree': PACKET_TREE}


def require_complete_spec(spec):
    exact(spec, unbound_spec(), 'Runtime spec shape')
    require(spec['source'] == SOURCE and spec['packet_commit'] == PACKET_COMMIT
            and spec['packet_tree'] == PACKET_TREE, 'Runtime source authority mismatch')
    require(spec['platform'] in ('linux/amd64', 'linux/arm64'), 'Runtime platform UNBOUND')
    require(all(image_id(spec[key]) for key in ('image_id', 'browser_image_id')), 'Runtime image UNBOUND')
    require(all(sha(spec[key]) for key in ('image_config_sha256', 'browser_config_sha256',
                                         'vendor_preparation_receipt_sha256')), 'Runtime digest UNBOUND')


@dataclass(frozen=True)
class Context:
    preparation_commit: str
    preparation_tree: str
    run_id: int
    attempt: int
    daemon_id: str
    runner_binding: str
    nonce: str
    workflow: str = WORKFLOW

    def validate(self):
        require(self.workflow in (WORKFLOW, NATIVE_WORKFLOW), 'Unsupported source workflow')
        require(all(isinstance(item, str) and re.fullmatch(r'[a-f0-9]{40}', item) for item in
                    (self.preparation_commit, self.preparation_tree)), 'Preparation Git identity')
        require(positive(self.run_id) and positive(self.attempt), 'Provider context missing')
        require(isinstance(self.daemon_id, str) and 1 <= len(self.daemon_id) <= 128
                and re.fullmatch(r'[a-zA-Z0-9:-]+', self.daemon_id), 'Daemon identity missing')
        require(sha(self.runner_binding) and isinstance(self.nonce, str)
                and re.fullmatch(r'[a-f0-9]{32}', self.nonce), 'Runner binding missing')

    def report_context(self):
        self.validate()
        return {'preparation_commit': self.preparation_commit, 'preparation_tree': self.preparation_tree,
                'repository': REPOSITORY, 'workflow': self.workflow, 'run_id': self.run_id, 'attempt': self.attempt,
                'daemon_id': self.daemon_id, 'runner_binding': self.runner_binding, 'nonce': self.nonce}

    def artifact_name(self):
        return f'waterline-source-preparation-{self.run_id}-{self.attempt}-{self.nonce}'


@dataclass(frozen=True)
class CellBudget:
    started: float

    def preparation_deadline(self):
        return self.started + 1200

    def preparation_active_deadline(self):
        return self.started + 900

    def collection_deadline(self, now):
        require(now <= self.preparation_deadline(), 'Preparation phase expired')
        return min(now + 1200, self.started + 2400)

    def final_deadline(self):
        return self.started + 2700

    def allocation(self, now, guardian_ready):
        require(guardian_ready and now < self.preparation_active_deadline(), 'Guardian required before allocation')

    def resource_proof(self, proof):
        require(proof.get('cpus') == 2 and proof.get('memory') == 3 * 1024 ** 3
                and proof.get('pids') == 256 and positive(proof.get('jobs')) and proof['jobs'] <= 2
                and proof.get('scripts') is False and proof.get('plugins') is False
                and proof.get('request_concurrency') == 2 and proof.get('effective_trust_verified') is True,
                'Preparation resource/trust policy mismatch')
        require(type(proof.get('network_bytes')) is int and 0 <= proof['network_bytes'] <= MAX_NETWORK
                and type(proof.get('storage_bytes')) is int and 0 <= proof['storage_bytes'] <= MAX_STORAGE,
                'Preparation network/storage budget exceeded')


def unbound_admission():
    return {'platform': None, 'app_config': None, 'tool_config': None,
            'ca_sha256': None, 'preparation_tool_config_sha256': None, 'transport_policy_sha256': None}


def require_static_admission(admission):
    exact(admission, unbound_admission(), 'Static admission shape')
    require(admission['platform'] in ('linux/amd64', 'linux/arm64'), 'Static platform UNBOUND')
    require(isinstance(admission['app_config'], dict) and admission['app_config']
            and isinstance(admission['tool_config'], dict) and admission['tool_config'], 'Static config UNBOUND')
    require(sha(admission['ca_sha256']) and sha(admission['preparation_tool_config_sha256'])
            and sha(admission['transport_policy_sha256']), 'Static trust UNBOUND')


def same_cell(expected, observed):
    expected.validate()
    observed.validate()
    require(expected == observed, 'Cross-runner, cross-daemon or stale provider handoff')


def main():
    # No parsing, environment read, Git, Docker or provider request precedes this hold.
    raise Refusal('HELD: source-only preparation contracts; no cell is admitted')


if __name__ == '__main__':
    main()
