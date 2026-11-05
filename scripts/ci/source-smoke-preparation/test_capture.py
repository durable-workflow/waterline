"""Synthetic response transcripts only. No socket, archive or preparation run."""
import copy
import dataclasses
import json
import pathlib
import subprocess
import sys
import unittest
from unittest import mock
from urllib.parse import urlsplit

sys.path.insert(0, str(pathlib.Path(__file__).parent))
import accepted
import capture
import contract as c
import custody
import proxy
import transport

BODY = b'synthetic archive bytes, not a zip'


def synthetic_policy():
    ca = b'synthetic CA bytes; never loaded by the inert transport'
    addresses = {'api.github.com': '1.1.1.1', 'codeload.github.com': '8.8.8.8'}
    expected = c.digest(c.canonical(transport.policy_description(c.digest(ca), addresses)))
    return transport.TransportPolicy(ca, c.digest(ca), addresses, expected)


def synthetic_request(url, policy, ledger, limit):
    ledger.write(lambda raw: len(raw), b'synthetic TLS request')
    ledger.read(lambda bound: b'synthetic TLS response')
    parsed = urlsplit(url)
    location = None
    status = 200
    if parsed.hostname == 'api.github.com':
        repository, reference = parsed.path.removeprefix('/repos/').split('/zipball/')
        location, status = f'https://codeload.github.com/{repository}/legacy.zip/{reference}', 302
    observed = {'url': url, 'status': status, 'location': location,
        'tls': {'host': parsed.hostname, 'address': policy.addresses[parsed.hostname], 'port': 443,
                'policy_sha256': policy.identity, 'ca_sha256': policy.ca_sha256,
                'peer_certificate_sha256': 'a' * 64, 'tls_version': 'TLSv1.3',
                'cipher': ['TLS_AES_256_GCM_SHA384', 'TLSv1.3', 256], 'resolver': 'none-static-numeric-ip'}}
    return (b'' if status == 302 else BODY), observed


def captured_fixture(context, authority, expected, ledger, release=True):
    policy = synthetic_policy()
    pool = capture.Quarantine(context, authority, expected, policy, ledger, object_limit=64, pool_limit=4096)
    reader = capture.ArchiveReader(context, authority, expected, policy, ledger)
    with mock.patch.object(c, 'EXECUTION_ENABLED', True), mock.patch.object(transport, 'request', synthetic_request):
        for name in sorted(expected['graph']):
            pool.capture(name)
        pool.freeze()
        sealed = reader.seal(pool)
        if release:
            for name in sorted(expected['graph']):
                sealed.release(name, len)
    return policy, pool, reader, sealed


class CaptureTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.repository = pathlib.Path(__file__).resolve().parents[3]
        def git(*args):
            return subprocess.check_output(['git', '-C', str(cls.repository), *args], timeout=5)
        commit = git('rev-parse', 'HEAD').strip().decode()
        tree = git('rev-parse', 'HEAD^{tree}').strip().decode()
        cls.authority = custody.SourceAuthority(git, commit, tree, custody.snapshot(git, commit, tree)[2])
        cls.context = c.Context(commit, tree, 301, 1, 'synthetic-daemon', '3' * 64, '4' * 32)
        cls.expected = accepted.accepted_evidence(cls.repository).expected_from_git(cls.repository)

    def ledger(self):
        return proxy.ProxyLedger(self.context, self.authority, deadline=200, clock=lambda: 100)

    def test_exact_79_graph_is_derived_without_request_substitution(self):
        for package in self.expected['graph'].values():
            canonical_url, redirect = capture.object_request(package)
            self.assertEqual(canonical_url, package['dist']['url'])
            self.assertTrue(redirect.endswith('/' + package['source']['reference']))
        original = next(iter(self.expected['graph'].values()))
        for mutate in [lambda value: value['dist'].update(reference='a' * 40),
                       lambda value: value['dist'].update(url='https://example.com/replacement'),
                       lambda value: value['source'].update(url='https://github.com/other/repo.git')]:
            changed = copy.deepcopy(original)
            mutate(changed)
            self.assertRaises(c.Refusal, capture.object_request, changed)

    def test_quarantine_release_and_observed_provenance_are_distinct(self):
        ledger = self.ledger()
        policy, pool, reader, sealed = captured_fixture(self.context, self.authority, self.expected, ledger, False)
        self.assertEqual(ledger.checkpoint()['network_bytes'], 79 * 2 * (21 + 22))
        self.assertRaises(c.Refusal, sealed.observation, self.context, self.authority, ledger)
        with mock.patch.object(c, 'EXECUTION_ENABLED', True):
            for name in sorted(self.expected['graph']):
                sealed.release(name, len)
        ledger.seal()
        observed = sealed.observation(self.context, self.authority, ledger)
        manifest = observed['manifest']
        self.assertEqual(manifest['schema'], capture.MANIFEST_SCHEMA)
        self.assertEqual(manifest['provenance'], 'authenticated-source-observation')
        self.assertIs(manifest['upstream_published_checksum'], False)
        self.assertEqual(manifest['transport_policy_sha256'], policy.identity)
        self.assertEqual(observed['released_objects'], 79)
        self.assertEqual(observed['released_bytes'], 79 * len(BODY))
        self.assertEqual(observed['manifest_sha256'], c.digest(c.canonical(manifest)))

    def test_incomplete_unknown_duplicate_and_changed_graph_refuse_before_request(self):
        for name in ('unknown/package', sorted(self.expected['graph'])[1]):
            ledger = self.ledger()
            pool = capture.Quarantine(self.context, self.authority, self.expected, synthetic_policy(), ledger)
            with mock.patch.object(c, 'EXECUTION_ENABLED', True), mock.patch.object(transport, 'request') as request:
                self.assertRaises(c.Refusal, pool.capture, name)
                request.assert_not_called()
        ledger = self.ledger()
        pool = capture.Quarantine(self.context, self.authority, self.expected, synthetic_policy(), ledger)
        first = sorted(self.expected['graph'])[0]
        with mock.patch.object(c, 'EXECUTION_ENABLED', True), mock.patch.object(transport, 'request', synthetic_request):
            pool.capture(first)
            self.assertRaises(c.Refusal, pool.freeze)
            self.assertRaises(c.Refusal, pool.capture, first)
        pool = capture.Quarantine(self.context, self.authority, self.expected, synthetic_policy(), self.ledger())
        pool.requests[first] = ('https://api.github.com/replacement', 'https://codeload.github.com/replacement')
        with mock.patch.object(c, 'EXECUTION_ENABLED', True), mock.patch.object(transport, 'request') as request:
            self.assertRaises(c.Refusal, pool.capture, first)
            request.assert_not_called()

    def test_object_pool_and_uncertain_response_limits_do_not_release_bytes(self):
        for object_limit, pool_limit in ((len(BODY) - 1, 4096), (len(BODY), len(BODY))):
            ledger = self.ledger()
            pool = capture.Quarantine(self.context, self.authority, self.expected, synthetic_policy(), ledger,
                                      object_limit=object_limit, pool_limit=pool_limit)
            names = sorted(self.expected['graph'])
            with mock.patch.object(c, 'EXECUTION_ENABLED', True), mock.patch.object(transport, 'request', synthetic_request):
                if object_limit >= len(BODY):
                    pool.capture(names[0])
                    name = names[1]
                else:
                    name = names[0]
                self.assertRaises(c.Refusal, pool.capture, name)
                self.assertRaises(c.Refusal, pool.freeze)
        pool = capture.Quarantine(self.context, self.authority, self.expected, synthetic_policy(), self.ledger())
        with mock.patch.object(c, 'EXECUTION_ENABLED', True), mock.patch.object(transport, 'request', side_effect=OSError):
            self.assertRaises(OSError, pool.capture, sorted(self.expected['graph'])[0])
            self.assertRaises(c.Refusal, pool.freeze)

    def test_independent_reader_rejects_changed_bytes_hops_and_counter_splicing(self):
        for field, changed in [('body', b'changed'), ('subject', b'{}'), ('hops', b'[]'), ('before', b'{}')]:
            ledger = self.ledger()
            policy, pool, _, _ = captured_fixture(self.context, self.authority, self.expected, ledger, False)
            first = sorted(pool.graph)[0]
            pool._objects[first] = dataclasses.replace(pool._objects[first], **{field: changed})
            reader = capture.ArchiveReader(self.context, self.authority, self.expected, policy, ledger)
            with mock.patch.object(c, 'EXECUTION_ENABLED', True):
                self.assertRaises((c.Refusal, ValueError), reader.seal, pool)

    def test_reader_rejects_cross_cell_policy_and_fresh_zero_ledger(self):
        ledger = self.ledger()
        policy, pool, _, _ = captured_fixture(self.context, self.authority, self.expected, ledger, False)
        wrong = dataclasses.replace(self.context, nonce='5' * 32)
        for context, alternative in [(wrong, ledger), (self.context, self.ledger())]:
            reader = capture.ArchiveReader(context, self.authority, self.expected, policy, alternative)
            with mock.patch.object(c, 'EXECUTION_ENABLED', True):
                self.assertRaises(c.Refusal, reader.seal, pool)

    def test_manifest_splicing_and_duplicate_release_refuse_before_writer(self):
        for mutate in (False, True):
            ledger = self.ledger()
            _, pool, reader, sealed = captured_fixture(self.context, self.authority, self.expected, ledger, False)
            first = sorted(pool.graph)[0]
            writer = mock.Mock(side_effect=len)
            with mock.patch.object(c, 'EXECUTION_ENABLED', True):
                if mutate:
                    value = json.loads(sealed._raw)
                    value['provenance'] = 'upstream-published-checksum'
                    sealed._raw = c.canonical(value)
                else:
                    sealed.release(first, len)
                self.assertRaises(c.Refusal, sealed.release, first, writer)
                writer.assert_not_called()

    def test_literal_holds_precede_capture_seal_release_and_cli_effects(self):
        ledger = self.ledger()
        pool = capture.Quarantine(self.context, self.authority, self.expected, synthetic_policy(), ledger)
        with mock.patch.object(transport, 'request') as request:
            self.assertRaisesRegex(c.Refusal, 'HELD', pool.capture, sorted(pool.graph)[0])
            self.assertRaisesRegex(c.Refusal, 'HELD', pool.freeze)
            request.assert_not_called()
        result = subprocess.run([sys.executable, '-I', str(pathlib.Path(capture.__file__)), '--enable'],
                                capture_output=True, timeout=5)
        self.assertNotEqual(result.returncode, 0)
        self.assertIn(b'HELD: quarantine/sealing source only', result.stderr)


if __name__ == '__main__':
    unittest.main()
