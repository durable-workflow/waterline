"""Compact host-observation producer. Candidate JSON supplies no authority."""
from dataclasses import dataclass

from contract import (CellBudget, Context, LOCK, SOURCE, canonical, digest, image_id,
                      require, sha, source_expectation, require_static_admission)
from recipe import validate_tool
from custody import SourceAuthority
from proxy import ProxyLedger


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
            'tool_config_sha256': host.preparation_tool_config_sha256,
            'scripts': False, 'plugins': False, 'request_concurrency': 2,
            'effective_trust_verified': True, 'candidate_autoload_network': False}
