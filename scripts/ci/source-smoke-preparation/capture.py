"""Held Composer quarantine and independently sealed observed-source custody."""

if __name__ == '__main__':
    raise SystemExit('HELD: quarantine/sealing source only; no archive or preparation admission')

import json
import re
import threading
from dataclasses import dataclass
from urllib.parse import urlsplit

import contract
import transport
from contract import (GRAPH, LOCK, SOURCE, SOURCE_TREE, canonical, digest, exact,
                      positive, require, same_cell, sha, source_expectation)
from custody import SourceAuthority
from proxy import ProxyLedger

MAX_POOL = 512 * 1024 ** 2
MANIFEST_SCHEMA = 'waterline.authenticated-source-observation/v1'


def clone(value):
    return json.loads(canonical(value))


def object_request(package):
    source, dist = package['source'], package['dist']
    reference = dist['reference']
    require(isinstance(reference, str) and re.fullmatch('[a-f0-9]{40}', reference)
            and source['reference'] == reference and source['type'] == 'git'
            and dist['type'] == 'zip' and dist['shasum'] in ('', None), 'Composer object source/ref changed')
    match = re.fullmatch(r'https://api\.github\.com/repos/([A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+)/zipball/'
                         + reference, dist['url'])
    require(match is not None, 'Composer canonical distribution authority changed')
    repository = match.group(1)
    require(source['url'] == 'https://github.com/' + repository + '.git', 'Composer source/dist repository mismatch')
    return dist['url'], 'https://codeload.github.com/' + repository + '/legacy.zip/' + reference


def validate_hop(hop, url, status, location, policy):
    exact(hop, ('url', 'status', 'location', 'tls'), 'Observed HTTP hop shape')
    require(hop['url'] == url and hop['status'] == status and hop['location'] == location,
            'Observed archive URL/redirect/status substitution')
    tls = hop['tls']
    exact(tls, ('host', 'address', 'port', 'policy_sha256', 'ca_sha256', 'peer_certificate_sha256',
                'tls_version', 'cipher', 'resolver'), 'Observed TLS identity shape')
    host = urlsplit(url).hostname
    require(tls['host'] == host and tls['address'] == policy.addresses[host] and tls['port'] == 443
            and tls['policy_sha256'] == policy.identity and tls['ca_sha256'] == policy.ca_sha256
            and sha(tls['peer_certificate_sha256']) and tls['tls_version'] in ('TLSv1.2', 'TLSv1.3')
            and tls['resolver'] == 'none-static-numeric-ip', 'Observed TLS trust/endpoint mismatch')
    cipher = tls['cipher']
    require(isinstance(cipher, list) and len(cipher) == 3 and type(cipher[2]) is int and cipher[2] >= 128
            and all(isinstance(value, str) and 0 < len(value) <= 128 for value in cipher[:2]),
            'Observed TLS cipher incomplete')


@dataclass(frozen=True)
class CapturedObject:
    body: bytes
    subject: bytes
    hops: bytes
    before: bytes
    after: bytes
    size: int
    sha256: str


class Quarantine:
    """Trusted host memory only. No constructor accepts a candidate report.

    One capture at a time keeps pool reservations and raw-counter ranges exact.
    This is below the existing two-request ceiling. No extraction/installation
    or preparation writer can obtain data from an unfinished capture.
    """
    def __init__(self, context, authority, expected, policy, ledger,
                 object_limit=transport.MAX_BODY, pool_limit=MAX_POOL):
        require(type(authority) is SourceAuthority and type(policy) is transport.TransportPolicy
                and type(ledger) is ProxyLedger, 'Independent quarantine authority required')
        self.identity = authority.identity(context)
        source_expectation(expected)
        policy.validate()
        require(positive(object_limit) and object_limit <= transport.MAX_BODY
                and positive(pool_limit) and object_limit <= pool_limit <= MAX_POOL, 'Quarantine caps UNBOUND')
        self.context, self.authority, self.policy, self.ledger = context, authority, policy, ledger
        self.graph = clone(expected['graph'])
        self.requests = {name: object_request(package) for name, package in self.graph.items()}
        self.object_limit, self.pool_limit = object_limit, pool_limit
        self.origin = canonical(ledger.checkpoint())
        self._objects, self._total = {}, 0
        self._failed = self._frozen = False
        self._lock = threading.Lock()

    def capture(self, name):
        require(contract.EXECUTION_ENABLED, 'HELD: no quarantine capture admitted')
        with self._lock:
            try:
                require(not self._failed and not self._frozen, 'Quarantine failed or frozen')
                require(digest(canonical(self.graph)) == GRAPH, 'Quarantine package graph changed')
                names = sorted(self.requests)
                require(len(self._objects) < len(names) and name == names[len(self._objects)],
                        'Unknown/duplicate/out-of-order Composer object')
                allowance = min(self.object_limit, self.pool_limit - self._total)
                require(allowance > 0, 'Quarantine pool allowance exhausted')
                self.policy.validate()
                before = self.ledger.checkpoint()
                previous = self._objects[names[len(self._objects) - 1]].after if self._objects else self.origin
                require(canonical(before) == previous, 'Unexpected transport effect outside capture sequence')
                canonical_url, redirect_url = self.requests[name]
                require((canonical_url, redirect_url) == object_request(self.graph[name]),
                        'Quarantine request authority changed')
                _, first = transport.request(canonical_url, self.policy, self.ledger, allowance)
                validate_hop(first, canonical_url, 302, redirect_url, self.policy)
                raw, second = transport.request(redirect_url, self.policy, self.ledger, allowance)
                validate_hop(second, redirect_url, 200, None, self.policy)
                require(type(raw) is bytes and 0 < len(raw) <= allowance, 'Quarantine object byte bound')
                captured = CapturedObject(raw, canonical(self.graph[name]), canonical([first, second]),
                    canonical(before), canonical(self.ledger.checkpoint()), len(raw), digest(raw))
                self._objects[name] = captured
                self._total += len(raw)
            except BaseException:
                self._failed = True
                raise

    def freeze(self):
        require(contract.EXECUTION_ENABLED, 'HELD: no quarantine freeze admitted')
        with self._lock:
            require(not self._failed and not self._frozen and set(self._objects) == set(self.graph),
                    'Incomplete/failed quarantine cannot freeze')
            self._frozen = True

    def snapshot(self):
        with self._lock:
            require(self._frozen and not self._failed, 'Quarantine is not complete and frozen')
            self.policy.validate()
            return dict(self._objects)


def read_object(name, item, pool, previous):
    require(type(item) is CapturedObject and type(item.body) is bytes
            and item.subject == canonical(pool.graph[name]), 'Independent object subject/byte custody mismatch')
    require(0 < len(item.body) <= pool.object_limit and len(item.body) == item.size
            and digest(item.body) == item.sha256, 'Independent quarantine bytes changed')
    hops = json.loads(item.hops)
    require(isinstance(hops, list) and len(hops) == 2, 'Archive redirect count changed')
    canonical_url, redirect_url = object_request(pool.graph[name])
    validate_hop(hops[0], canonical_url, 302, redirect_url, pool.policy)
    validate_hop(hops[1], redirect_url, 200, None, pool.policy)
    before, after = json.loads(item.before), json.loads(item.after)
    keys = ('network_read_bytes', 'network_write_bytes', 'network_bytes', 'operations', 'network_remaining')
    for cursor in (before, after):
        exact(cursor, keys, 'Transport counter cursor shape')
        require(all(type(value) is int and value >= 0 for value in cursor.values())
                and cursor['network_bytes'] == cursor['network_read_bytes'] + cursor['network_write_bytes']
                and cursor['network_bytes'] + cursor['network_remaining'] == pool.ledger.network_limit,
                'Transport counter cursor invalid')
    require(previous is None or before == previous, 'Manifest transport range splicing')
    require(all(after[key] >= before[key] for key in keys if key != 'network_remaining')
            and after['operations'] > before['operations'] and after['network_bytes'] > before['network_bytes']
            and after['network_remaining'] < before['network_remaining'], 'Unobserved or regressed transport')
    return {'subject': clone(pool.graph[name]), 'size': len(item.body), 'sha256': digest(item.body),
            'hops': hops, 'before': before, 'after': after}


class ArchiveReader:
    def __init__(self, context, authority, expected, policy, ledger):
        require(type(authority) is SourceAuthority and type(policy) is transport.TransportPolicy
                and type(ledger) is ProxyLedger, 'Independent archive reader authority required')
        self.identity = authority.identity(context)
        source_expectation(expected)
        self.context, self.authority, self.policy, self.ledger = context, authority, policy, ledger
        self.graph = clone(expected['graph'])
        self._sealed, self._manifest_sha256 = None, None

    def seal(self, pool):
        require(contract.EXECUTION_ENABLED, 'HELD: no archive sealing operation admitted')
        require(self._sealed is None and type(pool) is Quarantine and pool.ledger is self.ledger and pool.graph == self.graph
                and pool.identity == self.identity and pool.policy.identity == self.policy.identity,
                'Independent reader/capture source or transport mismatch')
        same_cell(self.context, pool.context)
        objects, observed, previous = pool.snapshot(), {}, json.loads(pool.origin)
        require(set(objects) == set(self.graph), 'Missing/extra observed object')
        for name in sorted(objects):
            observed[name] = read_object(name, objects[name], pool, previous)
            previous = observed[name]['after']
        total = sum(item['size'] for item in observed.values())
        require(total == pool._total and total <= pool.pool_limit and previous == self.ledger.checkpoint(),
                'Independent quarantine pool/counter mismatch')
        manifest = {'schema': MANIFEST_SCHEMA, 'provenance': 'authenticated-source-observation',
                    'upstream_published_checksum': False, 'source': SOURCE, 'tree': SOURCE_TREE,
                    'lock_sha256': LOCK, 'graph_sha256': GRAPH, 'context': self.context.report_context(),
                    'preparation': dict(self.identity), 'transport_policy': self.policy.description(),
                    'transport_policy_sha256': self.policy.identity,
                    'capture_source_sha256': digest(self.authority._raw['capture.py']),
                    'transport_source_sha256': digest(self.authority._raw['transport.py']),
                    'object_limit': pool.object_limit, 'pool_limit': pool.pool_limit, 'total_bytes': total,
                    'objects': observed}
        raw = canonical(manifest)
        require(len(raw) <= 1024 ** 2, 'Observation manifest byte bound')
        self._manifest_sha256 = digest(raw)
        self._sealed = SealedArchives(self, pool, raw, objects)
        return self._sealed


class SealedArchives:
    def __init__(self, reader, pool, raw, objects):
        self._reader, self._pool, self._raw, self._objects = reader, pool, raw, objects
        self._released, self._failed = set(), False
        self._lock = threading.Lock()

    def release(self, name, write):
        require(contract.EXECUTION_ENABLED, 'HELD: no archive preparation release admitted')
        with self._lock:
            try:
                require(self._reader._sealed is self and digest(self._raw) == self._reader._manifest_sha256
                        and not self._failed and name in self._objects and name not in self._released,
                        'Unknown/duplicate/failed archive release')
                expected = json.loads(self._raw)['objects'][name]
                self._pool.ledger.admit(self._objects[name].body, expected['size'], expected['sha256'], write)
                self._released.add(name)
            except BaseException:
                self._failed = True
                raise

    def observation(self, context, authority, ledger):
        with self._lock:
            same_cell(self._reader.context, context)
            require(not self._failed and type(authority) is SourceAuthority
                    and authority.identity(context) == self._reader.identity and ledger is self._pool.ledger
                    and self._released == set(self._reader.graph) and self._reader._sealed is self
                    and digest(self._raw) == self._reader._manifest_sha256, 'Archive custody/release is incomplete')
            self._pool.snapshot()
            return {'manifest_sha256': digest(self._raw), 'manifest': json.loads(self._raw),
                    'released_objects': len(self._released),
                    'released_bytes': sum(item.size for item in self._objects.values())}
