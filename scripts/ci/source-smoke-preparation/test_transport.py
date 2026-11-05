"""Inert HTTP/TLS state transcripts. No live resolver, socket or TLS session."""
import io
import http.client
import pathlib
import ssl
import subprocess
import sys
import unittest
from unittest import mock

sys.path.insert(0, str(pathlib.Path(__file__).parent))
import contract as c
import proxy
import transport
import test_capture as fixtures

synthetic_policy = fixtures.synthetic_policy

URL = 'https://api.github.com/repos/example/repo/zipball/' + 'a' * 40


class FakeChannel:
    def __init__(self, wire, ledger, policy):
        self.wire, self.ledger, self.policy = io.BytesIO(wire), ledger, policy
        self.sent, self.closed, self.aborted = [], False, False

    def read(self, maximum):
        return self.ledger.read(self.wire.read, maximum)

    def write(self, raw):
        self.sent.append(raw)
        self.ledger.write(len, raw)

    def identity(self):
        return {'host': 'api.github.com', 'address': self.policy.addresses['api.github.com'], 'port': 443,
                'policy_sha256': self.policy.identity, 'ca_sha256': self.policy.ca_sha256,
                'peer_certificate_sha256': 'a' * 64, 'tls_version': 'TLSv1.3',
                'cipher': ['TLS_AES_256_GCM_SHA384', 'TLSv1.3', 256], 'resolver': 'none-static-numeric-ip'}

    def close(self):
        self.ledger.write(len, b'synthetic close notify')
        self.closed = True

    def abort(self):
        self.aborted = True


class TransportTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        fixtures.CaptureTests.setUpClass()
        cls.context, cls.authority = fixtures.CaptureTests.context, fixtures.CaptureTests.authority

    def ledger(self):
        return proxy.ProxyLedger(self.context, self.authority, deadline=200, clock=lambda: 100)

    def request(self, wire, limit=64):
        policy, ledger = synthetic_policy(), self.ledger()
        channel = FakeChannel(wire, ledger, policy)
        with mock.patch.object(c, 'EXECUTION_ENABLED', True), mock.patch.object(transport, 'TLSChannel', return_value=channel):
            result = transport.request(URL, policy, ledger, limit)
        return result, channel, ledger

    def test_standard_parser_handles_complete_fixed_and_chunked_bodies(self):
        bodies = [b'Content-Length: 3\r\n\r\nabc', b'Transfer-Encoding: chunked\r\n\r\n3\r\nabc\r\n0\r\n\r\n']
        for body in bodies:
            wire = b'HTTP/1.1 200 OK\r\n' + body
            result, channel, ledger = self.request(wire)
            self.assertEqual(result[0], b'abc')
            self.assertEqual(result[1]['status'], 200)
            self.assertTrue(channel.closed)
            self.assertFalse(channel.aborted)
            request = channel.sent[0]
            self.assertTrue(request.startswith(b'GET /repos/example/repo/zipball/'))
            self.assertIn(b'Accept-Encoding: identity\r\n', request)
            self.assertNotIn(b'Authorization:', request)
            self.assertNotIn(b'CONNECT ', request)
            self.assertEqual(ledger.checkpoint()['network_bytes'], len(wire) + len(request) + len(b'synthetic close notify'))

    def test_redirect_is_observed_without_automatic_follow_or_substitution(self):
        wire = b'HTTP/1.1 302 Found\r\nLocation: https://codeload.github.com/exact\r\nContent-Length: 0\r\n\r\n'
        result, channel, _ = self.request(wire)
        self.assertEqual(result[1]['location'], 'https://codeload.github.com/exact')
        self.assertEqual(len(channel.sent), 1)

    def test_http10_fixed_chunked_and_redirect_responses_refuse(self):
        responses = [b'HTTP/1.0 200 OK\r\nContent-Length: 3\r\n\r\nabc',
                     b'HTTP/1.0 200 OK\r\nTransfer-Encoding: chunked\r\n\r\n3\r\nabc\r\n0\r\n\r\n',
                     b'HTTP/1.0 302 Found\r\nLocation: https://codeload.github.com/exact\r\nContent-Length: 0\r\n\r\n']
        for wire in responses:
            with self.subTest(response=wire.split(b'\r\n')[0]):
                self.assertRaises(c.Refusal, self.request, wire)

    def test_encoding_ambiguous_lengths_oversize_status_and_incomplete_body_refuse(self):
        bodies = [b'Content-Encoding: gzip\r\nContent-Length: 3\r\n\r\nabc',
                  b'Content-Length: 3\r\nContent-Length: 4\r\n\r\nabc',
                  b'Content-Length: 3\r\nTransfer-Encoding: chunked\r\n\r\nabc',
                  b'Content-Length: 999\r\n\r\nabc', b'Content-Length: -1\r\n\r\nabc',
                  b'Content-Length: 4\r\n\r\nabc', b'Transfer-Encoding: gzip\r\n\r\nabc']
        for body in bodies:
            with self.subTest(body=body[:40]):
                self.assertRaises((c.Refusal, http.client.HTTPException), self.request, b'HTTP/1.1 200 OK\r\n' + body)
        self.assertRaises(c.Refusal, self.request, b'HTTP/1.1 403 Forbidden\r\nContent-Length: 0\r\n\r\n')

    def test_unknown_length_at_limit_refuses_without_one_byte_overread(self):
        self.assertRaises(c.Refusal, self.request, b'HTTP/1.1 200 OK\r\n\r\nabcd', 4)
        self.assertEqual(self.request(b'HTTP/1.1 200 OK\r\n\r\nabc', 4)[0][0], b'abc')

    def test_aggregate_headers_are_bounded_before_body_capture(self):
        wire = b'HTTP/1.1 200 OK\r\nX-A: ' + b'a' * 32760 + b'\r\nX-B: ' + b'b' * 32760 + b'\r\n\r\n'
        self.assertRaises(c.Refusal, self.request, wire)

    def test_bad_request_authorities_refuse_before_channel_creation(self):
        for url in ('http://api.github.com/path', 'https://example.com/path',
                    'https://user@api.github.com/path', 'https://api.github.com:443/path',
                    'https://api.github.com/path?replace=1', 'https://api.github.com/path#fragment',
                    'https://api.github.com/path\r\nInjected: yes'):
            with mock.patch.object(c, 'EXECUTION_ENABLED', True), mock.patch.object(transport, 'TLSChannel') as channel:
                self.assertRaises(c.Refusal, transport.request, url, synthetic_policy(), self.ledger(), 64)
                channel.assert_not_called()

    def test_policy_has_exact_ca_and_numeric_public_ip_closure(self):
        policy = synthetic_policy()
        with mock.patch.object(transport.ssl, 'SSLContext') as factory:
            context = policy.context()
            factory.assert_called_once_with(ssl.PROTOCOL_TLS_CLIENT)
            context.load_verify_locations.assert_called_once_with(cadata=policy.ca_pem.decode())
            context.load_default_certs.assert_not_called()
            self.assertEqual(context.verify_mode, ssl.CERT_REQUIRED)
            self.assertIs(context.check_hostname, True)
        for address in ('127.0.0.1', '10.1.2.3', '169.254.169.254', '::1', 'github.com'):
            self.assertRaises((c.Refusal, ValueError), transport.TransportPolicy,
                policy.ca_pem, policy.ca_sha256, {'api.github.com': address, 'codeload.github.com': '8.8.8.8'}, policy.identity)
        for admitted in (None, 'f' * 64):
            self.assertRaises(c.Refusal, transport.TransportPolicy,
                              policy.ca_pem, policy.ca_sha256, policy.addresses, admitted)
        policy.addresses['api.github.com'] = '8.8.4.4'
        self.assertRaises(c.Refusal, policy.validate)

    def test_memory_bio_ciphertext_and_partial_raw_writes_use_shared_ledger(self):
        channel = object.__new__(transport.TLSChannel)
        channel.ledger, channel.incoming, channel.outgoing = self.ledger(), ssl.MemoryBIO(), ssl.MemoryBIO()
        channel.outgoing.write(b'client hello ciphertext')
        channel.timeout = mock.Mock()
        received = b'server hello ciphertext'
        sent = []
        channel.socket = mock.Mock()
        channel.socket.send.side_effect = lambda raw: sent.append(raw[:2]) or len(raw[:2])
        channel.socket.recv.return_value = received
        action = mock.Mock(side_effect=[ssl.SSLWantReadError(), 'handshaked'])
        self.assertEqual(channel.drive(action), 'handshaked')
        self.assertEqual(b''.join(sent), b'client hello ciphertext')
        self.assertEqual(channel.incoming.read(), received)
        self.assertEqual(channel.ledger.checkpoint()['network_bytes'], len(received) + sum(map(len, sent)))

    def test_observed_identity_rejects_actual_peer_or_verifier_policy_drift(self):
        for drift in ('peer', 'hostname', 'verification'):
            with self.subTest(drift=drift):
                channel = object.__new__(transport.TLSChannel)
                channel.host, channel.policy = 'api.github.com', synthetic_policy()
                channel.socket = mock.Mock()
                channel.socket.getpeername.return_value = ('1.1.1.1', 443)
                channel.tls = mock.Mock()
                channel.tls.server_hostname = channel.host
                channel.tls.getpeercert.return_value = b'synthetic certificate'
                channel.tls.version.return_value = 'TLSv1.3'
                channel.tls.selected_alpn_protocol.return_value = 'http/1.1'
                channel.tls.cipher.return_value = ('TLS_AES_256_GCM_SHA384', 'TLSv1.3', 256)
                context = channel.tls.context
                context.verify_mode, context.check_hostname = ssl.CERT_REQUIRED, True
                context.minimum_version, context.maximum_version = ssl.TLSVersion.TLSv1_2, ssl.TLSVersion.TLSv1_3
                self.assertEqual(channel.identity()['address'], '1.1.1.1')
                if drift == 'peer':
                    channel.socket.getpeername.return_value = ('8.8.8.8', 443)
                elif drift == 'hostname':
                    context.check_hostname = False
                else:
                    context.verify_mode = ssl.CERT_NONE
                self.assertRaises(c.Refusal, channel.identity)

    def test_literal_hold_precedes_socket_and_request_effects(self):
        with mock.patch.object(transport.socket, 'socket') as socket:
            self.assertRaisesRegex(c.Refusal, 'HELD', transport.TLSChannel, None, None, None)
            self.assertRaisesRegex(c.Refusal, 'HELD', transport.request, None, None, None, None)
            socket.assert_not_called()
        result = subprocess.run([sys.executable, '-I', str(pathlib.Path(transport.__file__)), '--enable'],
                                capture_output=True, timeout=5)
        self.assertNotEqual(result.returncode, 0)
        self.assertIn(b'HELD: authenticated-source transport', result.stderr)


if __name__ == '__main__':
    unittest.main()
