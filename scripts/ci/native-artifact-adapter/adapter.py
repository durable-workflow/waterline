"""Unwired private-job input/output adapter. No operation is admitted."""

if __name__ == '__main__':
    raise SystemExit('HELD: private-context source proposal only; no operation or upload admitted')

from dataclasses import dataclass
from datetime import datetime, timedelta
import json
import re

import base

MAX_ARCHIVE = 1024 ** 3
MAX_DIAGNOSTIC = 1024 ** 2
MAX_RECEIPT = 3 * MAX_DIAGNOSTIC
MAX_PROVIDER_ZIP = MAX_ARCHIVE + 6 * MAX_DIAGNOSTIC
UPLOAD_ACTION = 'actions/upload-artifact@043fb46d1a93c77aae656e7c1c64a875d1fc6a0a'
RECEIPT_NAME = 'receipt.json'
ARCHIVE_NAME = 'image.oci.tar'
STOPS = ('native_inspector', 'native_retrieval', 'observation_qualification', 'server_failover',
         'candidate_runtime', 'installed_acceptance', 'publication', 'release', 'downstream_adoption')
EFFECTS = ('capture', 'preparation', 'build', 'upload', 'retention', 'cleanup', 'retrieval', 'publication')


def require(condition, message):
    if not condition:
        raise ValueError(message)


def exact(value, keys, message):
    require(type(value) is dict and set(value) == set(keys), message)


def sha(value):
    return type(value) is str and re.fullmatch('[a-f0-9]{64}', value) is not None


def commit(value):
    return type(value) is str and re.fullmatch('[a-f0-9]{40}', value) is not None


def positive(value):
    return type(value) is int and value > 0


def utc(value):
    require(type(value) is str and re.fullmatch(r'\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ', value),
            'UTC timestamp shape refused')
    return datetime.strptime(value, '%Y-%m-%dT%H:%M:%SZ')


def audience(value):
    require(type(value) is list and 0 < len(value) <= 256, 'Audience snapshot missing')
    identifiers = []
    for member in value:
        exact(member, ('id', 'login', 'permission'), 'Audience member shape')
        require(positive(member['id']) and type(member['login']) is str
                and re.fullmatch('[A-Za-z0-9-]{1,64}', member['login'])
                and member['permission'] in ('read', 'triage', 'write', 'maintain', 'admin'),
                'Audience member refused')
        identifiers.append(member['id'])
    require(identifiers == sorted(set(identifiers)), 'Audience is ambiguous or duplicated')


def origin(value):
    exact(value, ('repository', 'workflow', 'event', 'ref', 'head_sha', 'run_id', 'attempt',
                  'job_id', 'job_name', 'runner_binding', 'daemon_id', 'nonce'), 'Private job identity shape')
    repo, workflow = value['repository'], value['workflow']
    exact(repo, ('id', 'node_id', 'full_name', 'private', 'visibility'), 'Repository identity shape')
    require(positive(repo['id']) and type(repo['node_id']) is str
            and re.fullmatch('R_[A-Za-z0-9_-]{1,120}', repo['node_id'])
            and type(repo['full_name']) is str
            and re.fullmatch('[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+', repo['full_name'])
            and repo['private'] is True and repo['visibility'] == 'private', 'Private origin required')
    exact(workflow, ('path', 'commit', 'tree', 'sha256'), 'Workflow authority shape')
    require(type(workflow['path']) is str
            and re.fullmatch(r'\.github/workflows/[A-Za-z0-9_-]+\.yml', workflow['path'])
            and commit(workflow['commit']) and commit(workflow['tree']) and sha(workflow['sha256'])
            and value['head_sha'] == workflow['commit'], 'Workflow/head authority missing')
    require(value['event'] == 'workflow_dispatch' and type(value['ref']) is str
            and re.fullmatch(r'refs/heads/[A-Za-z0-9_-]+', value['ref'])
            and positive(value['run_id']) and value['attempt'] == 1 and type(value['attempt']) is int
            and positive(value['job_id']) and type(value['job_name']) is str
            and re.fullmatch('[A-Za-z0-9_-]{1,100}', value['job_name']) and sha(value['runner_binding'])
            and type(value['daemon_id']) is str and re.fullmatch('[A-Za-z0-9:-]{1,128}', value['daemon_id'])
            and type(value['nonce']) is str and re.fullmatch('[a-f0-9]{32}', value['nonce']),
            'Original private attempt required')


def cleanup_policy(nonce):
    return {'owner_nonce': nonce, 'finally': True, 'direct_bound_ids': True,
            'discovery_only': False, 'independent_absence_required': True,
            'backstop': 'existing-private-job-timeout-and-runner-teardown',
            'job_timeout_seconds': 1800, 'deadline_extension': False,
            'collector_invoked': False, 'runtime_bindings': None}


def executor_policy(architecture):
    return {'kind': 'existing-private-repository-actions-job', 'builder': 'existing-buildx',
            'runner': {'amd64': 'ubuntu-24.04', 'arm64': 'ubuntu-24.04-arm'}[architecture],
            'max_parallel': 2, 'job_timeout_seconds': 1800, 'attempts': 1,
            'upload_action': UPLOAD_ACTION, 'stopped_task_replacement': False}


def runtime_identity(identity):
    return {'GITHUB_REPOSITORY_ID': str(identity['repository']['id']),
            'GITHUB_REPOSITORY': identity['repository']['full_name'],
            'GITHUB_RUN_ID': str(identity['run_id']), 'GITHUB_RUN_ATTEMPT': str(identity['attempt']),
            'GITHUB_SHA': identity['head_sha'], 'GITHUB_REF': identity['ref'],
            'GITHUB_EVENT_NAME': identity['event'], 'GITHUB_JOB': identity['job_name'],
            'GITHUB_WORKFLOW_SHA': identity['workflow']['commit'],
            'GITHUB_WORKFLOW_REF': identity['repository']['full_name'] + '/'
                + identity['workflow']['path'] + '@' + identity['ref']}


def token_route(identity):
    return {'mechanism': 'same-private-job-runtime', 'repository_id': identity['repository']['id'],
            'run_id': identity['run_id'], 'attempt': identity['attempt'],
            'transfer_hops': 0, 'extra_secret': False, 'broker': False}


def validate_task(selected, observed):
    """Compare protected selection with independent provider/host reader data.

    These inputs are not decoded from the candidate or sourced from public
    repository variables. This function verifies consistency, not provider
    authenticity; the future authenticated private readers remain UNBOUND.
    """
    exact(selected, ('schema', 'new_task', 'adapter_authority', 'origin', 'audience', 'architecture', 'build_environment',
                     'tools_sha256', 'parent_sha256', 'resources_sha256', 'transport_sha256',
                     'stop_map_sha256', 'executor', 'root', 'cleanup', 'retained_stops', 'effects'),
          'Protected selection shape')
    exact(observed, ('adapter_authority', 'origin', 'runtime_identity', 'token_route', 'audience', 'build_environment', 'docker_config',
                     'tools_sha256', 'parent_sha256', 'resources_sha256', 'transport_sha256', 'stop_map_sha256'),
          'Independent observation shape')
    require(selected['schema'] == 'waterline.private-build-source-input/v1'
            and selected['new_task'] == 'new-sealed-source-build-only', 'New task scope required')
    authority = selected['adapter_authority']
    exact(authority, ('commit', 'tree', 'manifest_sha256'), 'Adapter source authority shape')
    require(commit(authority['commit']) and commit(authority['tree']) and sha(authority['manifest_sha256'])
            and authority == observed['adapter_authority'], 'Independent adapter source authority changed')
    origin(selected['origin'])
    origin(observed['origin'])
    audience(selected['audience'])
    audience(observed['audience'])
    require(selected['origin'] == observed['origin'] and selected['audience'] == observed['audience'],
            'Changed private origin or audience')
    require(observed['runtime_identity'] == runtime_identity(selected['origin'])
            and base.canonical(observed['token_route']) == base.canonical(token_route(selected['origin'])),
            'Public job or transferred runtime-token route refused')
    require(selected['architecture'] in ('amd64', 'arm64'), 'Private native architecture missing')
    require(base.canonical(selected['executor']) == base.canonical(executor_policy(selected['architecture'])),
            'New private executor scope changed')
    require(type(selected['root']) is str and re.fullmatch(r'/[A-Za-z0-9_/-]{1,1024}', selected['root'])
            and '..' not in selected['root'].split('/') and '//' not in selected['root']
            and selected['root'].endswith('/' + selected['origin']['nonce']), 'Owned private root refused')
    expected_environment = {'PATH': '/usr/local/bin:/usr/bin:/bin', 'HOME': selected['root'] + '/home',
                            'DOCKER_CONFIG': selected['root'] + '/anonymous-docker'}
    require(selected['build_environment'] == expected_environment
            and observed['build_environment'] == expected_environment
            and observed['docker_config'] == b'{}\n', 'Build environment is not complete and secret-free')
    for name in ('tools_sha256', 'parent_sha256', 'resources_sha256', 'transport_sha256', 'stop_map_sha256'):
        require(sha(selected[name]) and selected[name] == observed[name], 'Static input missing or changed')
    require(base.canonical(selected['cleanup']) == base.canonical(cleanup_policy(selected['origin']['nonce'])),
            'Cleanup/backstop policy changed')
    exact(selected['retained_stops'], STOPS, 'Retained operator stop shape')
    require(all(value is True for value in selected['retained_stops'].values()), 'Retained operator stop changed')
    require(selected['effects'] == dict.fromkeys(EFFECTS), 'An actual effect was selected')
    return base.canonical(selected)


@dataclass(frozen=True)
class PrivateContext:
    """Real job identity and public source authority are separate namespaces."""
    identity: bytes
    adapter_authority: bytes
    preparation_commit: str = base.COMMIT
    preparation_tree: str = base.TREE

    def validate(self):
        require(type(self.identity) is bytes and len(self.identity) <= 16384, 'Private context byte bound')
        require(self.preparation_commit == base.COMMIT and self.preparation_tree == base.TREE,
                'Accepted public source authority changed')
        origin(json.loads(self.identity))
        require(type(self.adapter_authority) is bytes and len(self.adapter_authority) <= 512,
                'Adapter authority byte bound')

    def report_context(self):
        self.validate()
        return {'code_authority': {'commit': base.COMMIT, 'tree': base.TREE, 'manifest_sha256': base.MANIFEST},
                'adapter_authority': json.loads(self.adapter_authority), 'producer': json.loads(self.identity)}


@dataclass(frozen=True)
class Prepared:
    selection: bytes
    observation: bytes
    context: PrivateContext
    archive: bytes
    bindings: bytes
    source_manifest: bytes
    cache_manifest: bytes
    command: tuple


def private_context(selected, observed):
    """Preflight the independent inputs before a future capture is considered."""
    validate_task(selected, observed)
    return PrivateContext(base.canonical(selected['origin']), base.canonical(selected['adapter_authority']))


def prepare(primitives, selected, observed, context, sealed, ledger, cache_contents):
    """Retain one stdin tar using the accepted seal, COPY and tar primitives.

    No public Context is fabricated for the private run. No cache path is read
    after this snapshot. Neither this plan nor its command is executable here.
    """
    selection = validate_task(selected, observed)
    require(type(context) is PrivateContext and context.identity == base.canonical(selected['origin'])
            and context.adapter_authority == base.canonical(selected['adapter_authority']),
            'Private job context substitution')
    context.validate()
    require(type(sealed) is primitives.capture.SealedArchives and type(ledger) is primitives.proxy.ProxyLedger,
            'Independent observed-source custody required')
    retained, cache_raw = sealed.composer_cache(context, primitives.authority, ledger, cache_contents)
    cache = json.loads(cache_raw)
    archives = sealed.observation(context, primitives.authority, ledger)
    ledger.observation(context, primitives.authority)
    require(selected['transport_sha256'] == archives['manifest']['transport_policy_sha256'],
            'Sealed transport differs from independent private input')
    reader, _program = primitives.bootstrap.fixed_reader(primitives.repository)
    files = primitives.accepted.source_context_files(primitives.repository, reader)
    recipe = primitives.raw['docker/held-native.Dockerfile']
    original = reader.git_small(primitives.repository, 'show', primitives.contract.SOURCE + ':Dockerfile')
    recipe_sha = primitives.recipe.validate_hosted_recipe(original, recipe)
    require('Dockerfile' not in files and not any(p.startswith('.waterline-observed-source/') for p in files),
            'Trusted private build context collision')
    files['Dockerfile'] = recipe, 0o644
    files['.waterline-observed-source/manifest.json'] = cache_raw, 0o644
    files.update({'.waterline-observed-source/' + path: (raw, 0o644) for path, raw in retained})
    manifest = {path: {'kind': 'file', 'mode': mode, 'uid': 0, 'gid': 0,
                       'size': len(raw), 'sha256': base.digest(raw)} for path, (raw, mode) in files.items()}
    contents = {path: raw for path, (raw, _mode) in files.items()}
    budget = primitives.recipe.ArchiveBudget(544 * 1024 ** 2, 128 * 1024 ** 2, 528 * 1024 ** 2, 2048,
                                            profile=primitives.recipe.CONTEXT_PROFILE)
    snapshot = primitives.recipe.sealed_archive(primitives.recipe.canonical_archive(manifest, contents),
                                                manifest, budget)
    bindings = {'recipe_sha256': recipe_sha, 'source_manifest_sha256': cache['observed_source_manifest_sha256'],
                'cache_manifest_sha256': base.digest(cache_raw), 'context_sha256': snapshot['archive_sha256']}
    command = primitives.recipe.hosted_build_command(selected['architecture'], bindings,
                                                     selected['root'] + '/image.oci.tar')
    observed_bytes = base.canonical({**observed, 'docker_config': observed['docker_config'].decode()})
    return Prepared(selection, observed_bytes, context, snapshot['archive'], base.canonical(bindings),
                    base.canonical(archives['manifest']), cache_raw, tuple(command))


def checked_prepared(prepared):
    require(type(prepared) is Prepared and all(type(value) is bytes and len(value) <= 65536
            for value in (prepared.selection, prepared.observation, prepared.bindings)),
            'Retained private plan shape')
    selected, observed = json.loads(prepared.selection), json.loads(prepared.observation)
    require(base.canonical(observed) == prepared.observation, 'Retained observation encoding changed')
    observed['docker_config'] = observed['docker_config'].encode()
    require(validate_task(selected, observed) == prepared.selection, 'Retained selection changed')
    require(type(prepared.context) is PrivateContext
            and prepared.context.identity == base.canonical(selected['origin'])
            and prepared.context.adapter_authority == base.canonical(selected['adapter_authority']),
            'Retained private origin changed')
    prepared.context.validate()
    bindings = json.loads(prepared.bindings)
    require(type(prepared.source_manifest) is bytes and len(prepared.source_manifest) <= MAX_DIAGNOSTIC
            and type(prepared.cache_manifest) is bytes and len(prepared.cache_manifest) <= MAX_DIAGNOSTIC
            and base.digest(prepared.source_manifest) == bindings['source_manifest_sha256']
            and base.digest(prepared.cache_manifest) == bindings['cache_manifest_sha256'],
            'Original sealed input manifests changed')
    return selected


def describe_output(primitives, prepared, observation):
    """Validate original supplied output/diagnostics; never build or upload."""
    require(type(prepared) is Prepared and type(prepared.archive) is bytes,
            'Retained private build inputs required')
    selected = checked_prepared(prepared)
    bindings = json.loads(prepared.bindings)
    expected_command = primitives.recipe.hosted_build_command(
        selected['architecture'], bindings, selected['root'] + '/image.oci.tar')
    require(prepared.context.identity == base.canonical(selected['origin'])
            and prepared.command == tuple(expected_command), 'Retained source plan changed')
    require(base.digest(prepared.archive) == bindings['context_sha256'], 'Immutable stdin context changed')
    exact(observation, ('origin', 'command', 'stdin_sha256', 'invocations', 'archive', 'stdout', 'stderr',
                        'exit_code', 'timed_out', 'oom_killed', 'started_at', 'completed_at'),
          'Original build observation shape')
    require(observation['origin'] == selected['origin'] and observation['command'] == prepared.command
            and observation['stdin_sha256'] == bindings['context_sha256']
            and type(observation['invocations']) is int and observation['invocations'] == 1,
            'Original single-export attempt changed')
    require(type(observation['exit_code']) is int and 0 <= observation['exit_code'] <= 255
            and type(observation['timed_out']) is bool and type(observation['oom_killed']) is bool,
            'Original build diagnostic types')
    start, end = utc(observation['started_at']), utc(observation['completed_at'])
    require(timedelta(0) <= end - start <= timedelta(seconds=1800), 'Original command clock refused')
    diagnostics = {}
    for name in ('stdout', 'stderr'):
        raw = observation[name]
        require(type(raw) is bytes and len(raw) <= MAX_DIAGNOSTIC, 'Original diagnostic byte bound')
        diagnostics[name] = {'size': len(raw), 'sha256': base.digest(raw)}
    archive = observation['archive']
    success = observation['exit_code'] == 0 and not observation['timed_out'] and not observation['oom_killed']
    require(archive is not None or not success, 'Successful builder omitted its original export')
    subject = None
    if archive is not None:
        require(type(archive) is bytes and 0 < len(archive) <= MAX_ARCHIVE, 'Original export size bound')
        subject = primitives.accepted.native_archive_subject(primitives.repository, archive,
                                                             selected['architecture'], bindings)
    receipt = {'schema': 'waterline.private-build-source-receipt/v1', 'execution_enabled': False,
               'qualified': False, 'execution_verified': False, 'provider_origin_verified': False,
               'publication_admitted': False, 'cleanup_verified': False,
               'context': prepared.context.report_context(), 'inputs': bindings,
               'selection_sha256': base.digest(prepared.selection),
               'runtime_observation_sha256': base.digest(prepared.observation),
               'observed_source_manifest': json.loads(prepared.source_manifest),
               'sealed_cache_manifest': json.loads(prepared.cache_manifest),
               'archive': subject, 'diagnostics': diagnostics,
               'exit_code': observation['exit_code'], 'timed_out': observation['timed_out'],
               'oom_killed': observation['oom_killed'], 'started_at': observation['started_at'],
               'completed_at': observation['completed_at'], 'cleanup': selected['cleanup'],
               'retained_stops': selected['retained_stops'], 'effects': selected['effects']}
    receipt_raw = base.canonical(receipt)
    require(len(receipt_raw) <= MAX_RECEIPT, 'Original source receipt byte bound')
    files = {RECEIPT_NAME: receipt_raw, 'stdout.log': observation['stdout'],
             'stderr.log': observation['stderr']}
    if archive is not None:
        files[ARCHIVE_NAME] = archive
    return receipt, files


def upload_proposal(prepared, receipt, files):
    """Describe the existing upload primitive in the same private job only.

    A future owner must still supply authenticated origin, upload/expiry and
    cleanup observations. This function never sees a runtime token.
    """
    selected = checked_prepared(prepared)
    required = {RECEIPT_NAME, 'stdout.log', 'stderr.log'}
    require(set(files) in (required, required | {ARCHIVE_NAME})
            and all(type(raw) is bytes for raw in files.values()), 'Private upload member set')
    require(files[RECEIPT_NAME] == base.canonical(receipt)
            and len(files[RECEIPT_NAME]) <= MAX_RECEIPT
            and receipt['context'] == prepared.context.report_context()
            and receipt['inputs'] == json.loads(prepared.bindings)
            and receipt['selection_sha256'] == base.digest(prepared.selection)
            and receipt['runtime_observation_sha256'] == base.digest(prepared.observation)
            and base.canonical(receipt['observed_source_manifest']) == prepared.source_manifest
            and base.canonical(receipt['sealed_cache_manifest']) == prepared.cache_manifest
            and receipt['schema'] == 'waterline.private-build-source-receipt/v1'
            and receipt['cleanup'] == selected['cleanup']
            and receipt['retained_stops'] == selected['retained_stops']
            and receipt['effects'] == selected['effects'], 'Upload receipt substitution')
    require(all(receipt[name] is False for name in ('execution_enabled', 'qualified', 'execution_verified',
            'provider_origin_verified', 'publication_admitted', 'cleanup_verified')), 'Upload promoted a source receipt')
    for name in ('stdout', 'stderr'):
        raw = files[name + '.log']
        require(len(raw) <= MAX_DIAGNOSTIC and receipt['diagnostics'][name]
                == {'size': len(raw), 'sha256': base.digest(raw)}, 'Original upload diagnostics changed')
    subject = receipt['archive']
    require((ARCHIVE_NAME in files) == (subject is not None), 'Original export member changed')
    if subject is not None:
        raw = files[ARCHIVE_NAME]
        require(0 < len(raw) <= MAX_ARCHIVE and len(raw) == subject['size']
                and base.digest(raw) == subject['sha256'], 'Original upload export changed')
    name = 'waterline-build-only-' + selected['architecture'] + '-' + str(selected['origin']['run_id'])
    name += '-1-' + selected['origin']['nonce']
    return {'uses': UPLOAD_ACTION, 'execution_enabled': False, 'origin': selected['origin'],
            'token_source': 'same-repository-job-runtime-only', 'cross_repository_transfer': False,
            'with': {'name': name, 'path': '\n'.join(selected['root'] + '/' + name for name in sorted(files)),
                     'retention-days': 1, 'compression-level': 0, 'if-no-files-found': 'error',
                     'overwrite': False, 'include-hidden-files': False},
            'members': {name: {'size': len(raw), 'sha256': base.digest(raw)} for name, raw in sorted(files.items())},
            'upload_receipt': None, 'provider_zip_digest': None, 'created_at': None, 'expires_at': None,
            'audience': selected['audience'], 'cleanup': selected['cleanup']}


def validate_retention(proposal, observed):
    """Pure original-provider contract; it performs no retrieval or mutation."""
    selected_origin, selected_audience = proposal['origin'], proposal['audience']
    origin(selected_origin)
    audience(selected_audience)
    require(proposal['uses'] == UPLOAD_ACTION and proposal['execution_enabled'] is False
            and proposal['token_source'] == 'same-repository-job-runtime-only'
            and proposal['cross_repository_transfer'] is False, 'Public/private token route refused')
    exact(observed, ('origin', 'audience', 'artifact_id', 'name', 'size', 'zip_sha256',
                     'created_at', 'expires_at', 'retention_days', 'expired', 'overwrite'),
          'Private retention observation shape')
    require(observed['origin'] == selected_origin and observed['audience'] == selected_audience,
            'Retention origin/audience substitution')
    require(observed['name'] == proposal['with']['name'], 'Original artifact namespace changed')
    require(positive(observed['artifact_id']) and positive(observed['size'])
            and observed['size'] <= MAX_PROVIDER_ZIP and sha(observed['zip_sha256'])
            and observed['retention_days'] == 1 and type(observed['retention_days']) is int
            and observed['expired'] is False and observed['overwrite'] is False,
            'Private retention bound refused')
    require(utc(observed['expires_at']) - utc(observed['created_at']) == timedelta(days=1),
            'Original one-day expiry changed')
    return False  # Consistency is not authenticated provider or cleanup proof.


def execute(*_arguments, **_keywords):
    raise ValueError('HELD: private adapter is unwired source; all effects remain unselected')
