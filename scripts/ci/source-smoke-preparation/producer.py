"""Compact host-observation producer. Candidate JSON supplies no authority."""
from dataclasses import dataclass
import json

from contract import (CellBudget, Context, LOCK, SOURCE, SOURCE_TREE, GRAPH, NATIVE_WORKFLOW, canonical, digest, image_id,
                      require, sha, source_expectation, require_static_admission)
from recipe import validate_tool
from custody import SourceAuthority
from proxy import ProxyLedger
from capture import SealedArchives
from accepted import native_archive_subject, source_context_files
from recipe import CONTEXT_PROFILE, ArchiveBudget, canonical_archive, sealed_archive, validate_hosted_recipe
from bootstrap import fixed_reader


@dataclass(frozen=True)
class HostObservation:
    """Independent host observations, never decoded from the candidate report.

    The future supported wrapper must compose these through reviewed readers
    on this live daemon. ApplicationReader supplies the held application side;
    browser, build, trust and budget readers remain separate required source.
    This producer never promotes decoded candidate JSON into an observation.
    """
    context: Context
    app: dict
    tool: dict
    installed_raw: bytes
    files: dict
    classes: dict
    php: str
    int_size: int
    actual_tool: dict
    policy: dict
    ca_sha256: str
    preparation_tool_config_sha256: str
    network_removed_before_autoload: bool
    proxy: ProxyLedger
    archives: SealedArchives


@dataclass(frozen=True)
class HostedBuildInputs:
    """Independent host inputs; never decoded from a candidate report."""
    context: Context
    authority: SourceAuthority
    archives: SealedArchives
    proxy: ProxyLedger
    cache_contents: dict
    archive: bytes
    architecture: str
    recipe: bytes


def hosted_inputs(build, repository):
    """Form one immutable context from independent Git and archive authority."""
    require(type(build) is HostedBuildInputs and type(build.authority) is SourceAuthority
            and type(build.archives) is SealedArchives and type(build.context) is Context
            and type(build.proxy) is ProxyLedger and type(build.recipe) is bytes,
            'Independent hosted build inputs required')
    context = build.context
    require(context.workflow == NATIVE_WORKFLOW, 'Wrong hosted builder workflow')
    preparation = build.authority.identity(context)
    retained, cache_raw = build.archives.composer_cache(context, build.authority, build.proxy, build.cache_contents)
    cache = json.loads(cache_raw)
    network = build.proxy.observation(context, build.authority)
    reader, _program = fixed_reader(repository)
    def git_file(commit, path):
        return reader.git_small(repository, 'show', commit + ':' + path, limit=262144)
    original = git_file(SOURCE, 'Dockerfile')
    recipe_sha256 = validate_hosted_recipe(original, build.recipe)
    workflow = git_file(context.preparation_commit, NATIVE_WORKFLOW)
    files = source_context_files(repository, reader)
    require('Dockerfile' not in files and not any(name.startswith('.waterline-observed-source/') for name in files),
            'M1 source collides with trusted context inputs')
    files['Dockerfile'] = (build.recipe, 0o644)
    files['.waterline-observed-source/manifest.json'] = (cache_raw, 0o644)
    files.update({'.waterline-observed-source/' + path: (raw, 0o644) for path, raw in retained})
    manifest = {name: {'kind': 'file', 'mode': mode, 'uid': 0, 'gid': 0,
                       'size': len(raw), 'sha256': digest(raw)} for name, (raw, mode) in files.items()}
    contents = {name: raw for name, (raw, _mode) in files.items()}
    budget = ArchiveBudget(544 * 1024 ** 2, 128 * 1024 ** 2, 528 * 1024 ** 2, 2048,
                           profile=CONTEXT_PROFILE)
    snapshot = sealed_archive(canonical_archive(manifest, contents), manifest, budget)
    bindings = {'recipe_sha256': recipe_sha256,
                'source_manifest_sha256': cache['observed_source_manifest_sha256'],
                'cache_manifest_sha256': digest(cache_raw), 'context_sha256': snapshot['archive_sha256']}
    return {'snapshot': snapshot, 'bindings': bindings, 'preparation': preparation,
            'workflow_sha256': digest(workflow), 'source_capture_transport': network,
            'cache_objects': len(retained)}


def hosted_artifact(build, repository):
    """Bind supplied bytes; no CLI, workflow or lifecycle invokes this source.

    Tests supply inert OCI fixtures. Native execution and publication acceptance
    remain separate; a config digest is never renamed as a daemon observation.
    """
    inputs = hosted_inputs(build, repository)
    subject = native_archive_subject(repository, build.archive, build.architecture, inputs['bindings'])
    return {'schema': 'waterline.build-only-native-artifact/v1', 'qualified': False,
            'execution_verified': False, 'source': SOURCE, 'tree': SOURCE_TREE,
            'lock_sha256': LOCK, 'graph_sha256': GRAPH,
            'context': build.context.report_context(), 'preparation': inputs['preparation'],
            'workflow_sha256': inputs['workflow_sha256'], 'inputs': inputs['bindings'], 'archive': subject,
            'source_capture_transport': inputs['source_capture_transport'],
            'cache_objects': inputs['cache_objects'], 'vendor_php_preflight': 'required-before-candidate-execution',
            'provider_origin_verified': False, 'publication_admitted': False}


def image(value, platform, app, expected_config):
    require(isinstance(value, dict) and image_id(value.get('Id')), 'Local image identity missing')
    require(value.get('Os') == 'linux' and 'linux/' + str(value.get('Architecture')) == platform,
            'Local image platform mismatch')
    config = value.get('Config')
    require(isinstance(config, dict) and config == expected_config, 'Independent image config mismatch')
    if app:
        labels = config.get('Labels') or {}
        require(labels.get('org.opencontainers.image.revision') == SOURCE
                and labels.get('dev.durable-workflow.release.tag') == '2.15.1', 'Application image source mismatch')
    return {'id': value['Id'], 'config_sha256': digest(canonical(config)), 'platform': platform}


def checked_tool(lock, observed):
    validate_tool(lock, complete=True)
    expected = {'node': lock['node'], 'python': lock['python'], 'packages': lock['packages'],
                'browsers_source_sha256': lock['browsers_source_sha256'], 'browsers': lock['browsers'],
                'base_image_id': lock['unbound_static_inputs']['base_image_id'],
                'base_config_sha256': lock['unbound_static_inputs']['base_config_sha256'],
                'platform': lock['unbound_static_inputs']['platform'],
                'library_inventory': lock['unbound_static_inputs']['library_inventory'],
                'browser_binary_hashes': lock['unbound_static_inputs']['browser_binary_hashes']}
    require(observed == expected, 'Independent browser tool closure mismatch')
    return digest(canonical(expected))


def produce(host, expected, tool_lock, evidence, admission, authority):
    """Only host facts are serialized; runtime output can never amend expected."""
    require(isinstance(host, HostObservation), 'Independent host observation required')
    host.context.validate()
    require(isinstance(authority, SourceAuthority), 'Independent preparation custody authority required')
    preparation = authority.identity(host.context)
    source_expectation(expected)
    require_static_admission(admission)
    require(host.network_removed_before_autoload is True, 'Candidate autoload retains network authority')
    CellBudget(0).resource_proof(host.policy)
    require(isinstance(host.proxy, ProxyLedger), 'Independent completed proxy ledger required')
    network = host.proxy.observation(host.context, authority)
    require(host.policy['network_bytes'] == network['network_bytes'], 'Proxy counter and host policy differ')
    require(type(host.archives) is SealedArchives, 'Independent sealed archive manifest required')
    archives = host.archives.observation(host.context, authority, host.proxy)
    require(archives['manifest']['transport_policy_sha256'] == admission['transport_policy_sha256']
            and archives['manifest']['transport_policy']['ca_sha256'] == admission['ca_sha256'],
            'Archive observation transport admission mismatch')
    require(sha(host.ca_sha256) and sha(host.preparation_tool_config_sha256), 'Preparation trust identity missing')
    require(host.ca_sha256 == admission['ca_sha256']
            and host.preparation_tool_config_sha256 == admission['preparation_tool_config_sha256'],
            'Independent preparation trust mismatch')
    require(host.files == expected['files'] and host.classes == expected['classes']
            and host.php == expected['php'] and host.int_size == 8, 'Independent application files/PHP mismatch')
    raw = evidence.bounded_json(host.installed_raw, limit=2 * 1024 * 1024)
    require(isinstance(raw, dict) and raw.get('dev') is False
            and raw.get('dev-package-names') == [], 'Installed metadata is not production-only')
    graph = evidence.graph(raw.get('packages'))
    require(graph == expected['graph'], 'Installed source/dist authorities differ')
    platform = tool_lock['unbound_static_inputs']['platform']
    require(platform == admission['platform'], 'Static application/tool platform mismatch')
    tool_closure = checked_tool(tool_lock, host.actual_tool)
    app = image(host.app, platform, True, admission['app_config'])
    tool = image(host.tool, platform, False, admission['tool_config'])
    require((host.tool['Config'].get('Labels') or {}).get('dev.durable-workflow.tool-closure')
            == digest(canonical(tool_lock)), 'Tool image not bound to static closure')
    return {'schema': 'waterline.preparation-report/v1', 'context': host.context.report_context(),
            'preparation': preparation,
            'source': SOURCE, 'lock': LOCK, 'graph': graph, 'files_sha256': digest(canonical(host.files)),
            'installed_json_sha256': digest(host.installed_raw), 'app': app, 'tool': tool,
            'browser_closure_sha256': tool_closure, 'ca_sha256': host.ca_sha256,
            'network': network,
            'archives': archives,
            'tool_config_sha256': host.preparation_tool_config_sha256,
            'scripts': False, 'plugins': False, 'request_concurrency': 2,
            'effective_trust_verified': True, 'candidate_autoload_network': False}
