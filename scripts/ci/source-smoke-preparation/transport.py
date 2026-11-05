"""Held raw-metered HTTPS source. No default CA, IP, resolver or credential."""

if __name__ == '__main__':
    raise SystemExit('HELD: authenticated-source transport needs separate cell admission')

import http.client
import io
import ipaddress
import socket
import ssl
import time
from urllib.parse import urlsplit

import contract
from contract import canonical, digest, exact, positive, require, sha
from proxy import CHUNK, ProxyLedger

HOSTS = ('api.github.com', 'codeload.github.com')
HEADER_BYTES = 65536
MAX_BODY = 128 * 1024 ** 2


def policy_description(ca_sha256, addresses):
    return {'ca_sha256': ca_sha256, 'addresses': dict(addresses), 'port': 443,
            'tls_versions': ['TLSv1.2', 'TLSv1.3'], 'verify_peer': True, 'verify_hostname': True,
            'resolver': 'none-static-numeric-ip', 'credentials': False, 'http': '1.1'}


class TransportPolicy:
    def __init__(self, ca_pem, ca_sha256, addresses, admitted_policy_sha256):
        require(type(ca_pem) is bytes and 0 < len(ca_pem) <= 1024 ** 2
                and sha(ca_sha256) and digest(ca_pem) == ca_sha256, 'Transport CA UNBOUND or changed')
        exact(addresses, HOSTS, 'Transport address closure UNBOUND')
        for address in addresses.values():
            require(isinstance(address, str) and '%' not in address, 'Transport numeric address required')
            parsed = ipaddress.ip_address(address)
            require(parsed.is_global and str(parsed) == address, 'Transport address is not canonical public IP')
        self.ca_pem, self.ca_sha256 = ca_pem, ca_sha256
        self.addresses = dict(addresses)
        self.identity = digest(canonical(self.description()))
        require(sha(admitted_policy_sha256) and self.identity == admitted_policy_sha256,
                'Independent transport policy admission UNBOUND or changed')
        self._admitted_sha256 = admitted_policy_sha256

    def description(self):
        return policy_description(self.ca_sha256, self.addresses)

    def validate(self):
        require(digest(self.ca_pem) == self.ca_sha256
                and digest(canonical(self.description())) == self.identity == self._admitted_sha256,
                'Transport policy changed')

    def context(self):
        self.validate()
        context = ssl.SSLContext(ssl.PROTOCOL_TLS_CLIENT)
        context.minimum_version, context.maximum_version = ssl.TLSVersion.TLSv1_2, ssl.TLSVersion.TLSv1_3
        context.verify_mode, context.check_hostname = ssl.CERT_REQUIRED, True
        context.load_verify_locations(cadata=self.ca_pem.decode('ascii'))
        context.set_alpn_protocols(['http/1.1'])
        return context


class TLSChannel:
    """SSLObject never owns a socket; every ciphertext effect uses the ledger."""
    def __init__(self, host, policy, ledger):
        require(contract.EXECUTION_ENABLED, 'HELD: no transport connection admitted')
        require(type(policy) is TransportPolicy and type(ledger) is ProxyLedger and host in HOSTS,
                'Transport host/policy/ledger mismatch')
        policy.validate()
        require(ledger.checkpoint()['network_remaining'] > 0, 'No transport allowance before connection')
        self.host, self.policy, self.ledger = host, policy, ledger
        self.incoming, self.outgoing = ssl.MemoryBIO(), ssl.MemoryBIO()
        self.tls = policy.context().wrap_bio(self.incoming, self.outgoing, server_hostname=host)
        address = ipaddress.ip_address(policy.addresses[host])
        self.socket = socket.socket(socket.AF_INET if address.version == 4 else socket.AF_INET6, socket.SOCK_STREAM)
        try:
            self.timeout()
            self.socket.connect((str(address), 443))
            self.drive(self.tls.do_handshake)
        except BaseException:
            self.socket.close()
            raise

    def timeout(self):
        remaining = self.ledger.deadline - time.monotonic()
        require(remaining > 0, 'Transport deadline expired')
        self.socket.settimeout(min(3, remaining))

    def send_raw(self, raw):
        self.timeout()
        return self.socket.send(raw)

    def receive_raw(self, maximum):
        self.timeout()
        return self.socket.recv(maximum)

    def drain(self):
        while self.outgoing.pending:
            raw = self.outgoing.read(min(CHUNK, self.outgoing.pending))
            offset = 0
            while offset < len(raw):
                offset += self.ledger.write(self.send_raw, raw[offset:])

    def drive(self, action):
        for _ in range(256):
            try:
                result = action()
                self.drain()
                return result
            except ssl.SSLWantReadError:
                self.drain()
                raw = self.ledger.read(self.receive_raw)
                require(raw, 'Incomplete TLS transport')
                self.incoming.write(raw)
            except ssl.SSLWantWriteError:
                self.drain()
        raise contract.Refusal('TLS protocol step bound exceeded')

    def write(self, raw):
        offset = 0
        while offset < len(raw):
            written = self.drive(lambda start=offset: self.tls.write(raw[start:start + CHUNK]))
            require(type(written) is int and written > 0, 'TLS plaintext write stalled')
            offset += written

    def read(self, maximum):
        return self.drive(lambda: self.tls.read(min(maximum, CHUNK)))

    def identity(self):
        self.policy.validate()
        address, port = self.observed_peer()
        certificate = self.tls.getpeercert(binary_form=True)
        require(type(certificate) is bytes and certificate and self.tls.version() in ('TLSv1.2', 'TLSv1.3')
                and self.tls.selected_alpn_protocol() in (None, 'http/1.1'), 'TLS peer/protocol incomplete')
        return {'host': self.host, 'address': address, 'port': port,
                'policy_sha256': self.policy.identity, 'ca_sha256': self.policy.ca_sha256,
                'peer_certificate_sha256': digest(certificate), 'tls_version': self.tls.version(),
                'cipher': list(self.tls.cipher()), 'resolver': 'none-static-numeric-ip'}

    def observed_peer(self):
        context = self.tls.context
        require(context.verify_mode == ssl.CERT_REQUIRED and context.check_hostname is True
                and context.minimum_version == ssl.TLSVersion.TLSv1_2
                and context.maximum_version == ssl.TLSVersion.TLSv1_3
                and self.tls.server_hostname == self.host, 'Effective TLS verifier policy changed')
        peer = self.socket.getpeername()
        require(isinstance(peer, tuple) and len(peer) >= 2 and type(peer[1]) is int and peer[1] == 443,
                'Observed TLS peer port mismatch')
        address = str(ipaddress.ip_address(peer[0]))
        require(address == self.policy.addresses[self.host], 'Observed TLS peer address mismatch')
        return address, peer[1]

    def close(self):
        try:
            try:
                self.tls.unwrap()
            except (ssl.SSLWantReadError, ssl.SSLWantWriteError):
                pass
            self.drain()
        finally:
            self.socket.close()

    def abort(self):
        self.socket.close()


class TLSReader(io.RawIOBase):
    def __init__(self, channel):
        self.channel = channel

    def readable(self):
        return True

    def readinto(self, buffer):
        raw = self.channel.read(len(buffer))
        buffer[:len(raw)] = raw
        return len(raw)


class ResponseSocket:
    def __init__(self, channel):
        self.stream = io.BufferedReader(TLSReader(channel), buffer_size=8192)
        self.header_remaining = HEADER_BYTES

    def makefile(self, *_):
        return self

    def readline(self, limit=-1):
        bound = self.header_remaining + 1 if limit < 0 else min(limit, self.header_remaining + 1)
        raw = self.stream.readline(bound)
        require(len(raw) <= self.header_remaining, 'HTTP header/trailer byte bound')
        self.header_remaining -= len(raw)
        return raw

    def read(self, maximum):
        return self.stream.read(maximum)

    def close(self):
        self.stream.close()

    def flush(self):
        return self.stream.flush()


def response_body(response, limit):
    require(response.headers.get_all('Content-Encoding', []) in ([], ['identity'])
            and len(response.headers.get_all('Content-Length', [])) <= 1
            and len(response.headers.get_all('Transfer-Encoding', [])) <= 1
            and not (response.headers.get('Content-Length') and response.headers.get('Transfer-Encoding')),
            'HTTP ambiguous length/encoding refused')
    require(response.headers.get('Transfer-Encoding') in (None, 'chunked'), 'HTTP transfer encoding refused')
    length = response.headers.get('Content-Length')
    require(length is None or length.isascii() and length.isdecimal() and len(length) <= 12
            and int(length) <= limit, 'HTTP declared body length refused')
    chunks, total = [], 0
    while not response.isclosed():
        require(total < limit, 'Quarantine response byte bound or unconfirmed EOF')
        raw = response.read(min(CHUNK, limit - total))
        require(type(raw) is bytes and len(raw) <= limit - total, 'HTTP response exceeded read bound')
        if not raw:
            require(response.isclosed(), 'Incomplete HTTP body')
            break
        chunks.append(raw)
        total += len(raw)
    require(length is None or total == int(length), 'Incomplete declared HTTP body')
    return b''.join(chunks)


def request(url, policy, ledger, limit):
    require(contract.EXECUTION_ENABLED, 'HELD: no archive request admitted')
    require(positive(limit) and limit <= MAX_BODY, 'HTTP body allowance UNBOUND')
    parsed = urlsplit(url)
    require(parsed.scheme == 'https' and parsed.hostname in HOSTS and parsed.netloc == parsed.hostname
            and not parsed.query and not parsed.fragment and parsed.path.startswith('/')
            and len(url) <= 4096 and url.isascii() and all(32 < ord(char) < 127 for char in url),
            'HTTP request authority/path refused')
    channel = TLSChannel(parsed.hostname, policy, ledger)
    response = None
    try:
        raw = (f'GET {parsed.path} HTTP/1.1\r\nHost: {parsed.hostname}\r\n'
               'User-Agent: waterline-source-observer\r\nAccept: application/vnd.github+json\r\n'
               'Accept-Encoding: identity\r\nConnection: close\r\n\r\n').encode('ascii')
        channel.write(raw)
        response = http.client.HTTPResponse(ResponseSocket(channel))
        response.begin()
        require(response.version in (10, 11) and response.status in (200, 302), 'HTTP response status refused')
        locations = response.headers.get_all('Location', [])
        require(len(locations) == (1 if response.status == 302 else 0), 'HTTP redirect shape refused')
        body = response_body(response, min(limit, HEADER_BYTES) if response.status == 302 else limit)
        observed = {'url': url, 'status': response.status, 'location': locations[0] if locations else None,
                    'tls': channel.identity()}
        channel.close()
        return body, observed
    except BaseException:
        channel.abort()
        raise
    finally:
        if response is not None:
            response.close()
