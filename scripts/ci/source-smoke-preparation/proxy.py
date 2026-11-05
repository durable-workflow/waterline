"""Causal byte ledger for the held proxy. No connection or proxy executor."""

if __name__ == '__main__':
    raise SystemExit('HELD: proxy accounting source only; no network or preparation operation')

import math
import threading
import time

from contract import MAX_NETWORK, Refusal, digest, positive, require, same_cell, sha
from custody import SourceAuthority

CHUNK = 65536
MAX_OPERATIONS = 65536


class ProxyLedger:
    """One host-owned ledger shared by every external transport in a cell.

    Callbacks must be the trusted proxy's raw transport reads/writes, including
    TLS records and any resolver traffic, never TLS plaintext methods. This
    component opens no connection and cannot prove exclusive egress or callback
    provenance. Those adapters remain unimplemented and operationally held.
    A lock spans each effect, so concurrent connections cannot reuse allowance.
    """
    def __init__(self, context, authority, deadline, clock=time.monotonic,
                 network_limit=MAX_NETWORK, preparation_limit=MAX_NETWORK):
        require(isinstance(authority, SourceAuthority), 'Proxy source custody required')
        self._identity = authority.identity(context)
        self._source = digest(authority._raw['proxy.py'])
        self._context = context
        self._clock = clock
        self._last_time = clock()
        require(all(type(value) in (int, float) and math.isfinite(value)
                    for value in (self._last_time, deadline))
                and self._last_time < deadline <= self._last_time + 900, 'Proxy active deadline bound')
        require(all(positive(value) and value <= MAX_NETWORK
                    for value in (network_limit, preparation_limit)), 'Proxy byte ceiling bound')
        self._deadline = deadline
        self._network_limit, self._preparation_limit = network_limit, preparation_limit
        self._received = self._sent = self._admitted = self._operations = 0
        self._failed = self._sealed = self._uncertain = False
        self._lock = threading.Lock()

    def _ensure(self, condition, message):
        if not condition:
            self._failed = True
            raise Refusal(message)

    def _active(self):
        self._ensure(not self._failed and not self._sealed and not self._uncertain,
                     'Proxy ledger failed, sealed or has an uncertain effect')
        try:
            now = self._clock()
        except Exception as error:
            self._failed = True
            raise Refusal('Proxy clock unavailable') from error
        self._ensure(type(now) in (int, float) and math.isfinite(now)
                     and self._last_time <= now < self._deadline, 'Proxy clock regressed or expired')
        self._last_time = now

    def _call(self, effect, allowance):
        self._ensure(self._operations < MAX_OPERATIONS, 'Proxy operation count exceeded')
        self._operations += 1
        # Retain uncertainty even if a BaseException interrupts the call or
        # arrives between its return and the caller's validated accounting.
        self._uncertain = True
        try:
            return effect(allowance)
        except Exception as error:
            # An interrupted syscall can have an uncertain effect. No report
            # may turn that uncertainty into a zero-byte successful operation.
            self._failed = True
            raise Refusal('Proxy transport or preparation write is uncertain') from error

    def read(self, receive, maximum=CHUNK):
        with self._lock:
            self._active()
            self._ensure(positive(maximum), 'Proxy read size invalid')
            allowance = min(maximum, CHUNK, self._network_limit - self._received - self._sent)
            self._ensure(allowance > 0, 'Proxy external transport allowance exhausted')
            raw = self._call(receive, allowance)
            self._ensure(type(raw) is bytes and len(raw) <= allowance, 'Proxy raw read exceeded allowance')
            self._received += len(raw)
            self._uncertain = False
            self._active()
            return raw

    def write(self, send, raw):
        with self._lock:
            self._active()
            self._ensure(type(raw) is bytes and len(raw) > 0, 'Proxy write requires immutable bytes')
            allowance = min(len(raw), CHUNK, self._network_limit - self._received - self._sent)
            self._ensure(allowance > 0, 'Proxy external transport allowance exhausted')
            written = self._call(lambda bound: send(raw[:bound]), allowance)
            self._ensure(positive(written) and written <= allowance, 'Proxy raw write count invalid')
            self._sent += written
            self._uncertain = False
            self._active()
            return written

    def admit(self, raw, expected_size, expected_sha256, write, encoding='identity'):
        """Validate a whole immutable object before any preparation-state write.

        Stream capture/HTTP decoding is not implemented here. Only identity
        encoding is supported; callbacks may not substitute a later path read.
        """
        with self._lock:
            self._active()
            self._ensure(type(raw) is bytes and type(expected_size) is int
                         and 0 <= expected_size <= self._preparation_limit
                         and len(raw) == expected_size and sha(expected_sha256)
                         and digest(raw) == expected_sha256 and encoding == 'identity',
                         'Proxy object size/digest/encoding mismatch')
            self._ensure(len(raw) <= self._preparation_limit - self._admitted,
                         'Proxy aggregate preparation allowance exhausted')
            offset = 0
            while offset < len(raw):
                self._active()
                allowance = min(CHUNK, len(raw) - offset, self._preparation_limit - self._admitted)
                written = self._call(lambda bound, start=offset: write(raw[start:start + bound]), allowance)
                self._ensure(positive(written) and written <= allowance, 'Proxy preparation write count invalid')
                self._admitted += written
                offset += written
                self._uncertain = False
                self._active()

    def seal(self):
        with self._lock:
            self._active()
            self._sealed = True

    def observation(self, context, authority):
        """Host counters only; a dictionary cannot construct a completed ledger."""
        with self._lock:
            require(self._sealed and not self._failed and not self._uncertain,
                    'Proxy ledger is not successfully sealed')
            same_cell(self._context, context)
            require(isinstance(authority, SourceAuthority)
                    and authority.identity(context) == self._identity, 'Proxy source custody changed')
            return {'accounting': 'proxy-observed-external-transport/v1',
                    'context': context.report_context(), 'preparation': dict(self._identity),
                    'proxy_source_sha256': self._source,
                    'network_read_bytes': self._received, 'network_write_bytes': self._sent,
                    'network_bytes': self._received + self._sent,
                    'admitted_preparation_bytes': self._admitted,
                    'network_limit': self._network_limit, 'preparation_limit': self._preparation_limit,
                    'operations': self._operations}
