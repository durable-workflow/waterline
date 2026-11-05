"""Inert effect callbacks, not a network/proxy/preparation qualification."""
import dataclasses
import pathlib
import subprocess
import sys
import threading
import unittest
from unittest import mock

sys.path.insert(0, str(pathlib.Path(__file__).parent))
import contract as c
import custody
import proxy


class ProxyTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.repository = pathlib.Path(__file__).resolve().parents[3]
        def git(*args):
            return subprocess.check_output(['git', '-C', str(cls.repository), *args], timeout=5)
        commit = git('rev-parse', 'HEAD').strip().decode()
        tree = git('rev-parse', 'HEAD^{tree}').strip().decode()
        manifest = custody.snapshot(git, commit, tree)[2]
        cls.authority = custody.SourceAuthority(git, commit, tree, manifest)
        cls.context = c.Context(commit, tree, 301, 1, 'synthetic-daemon', '3' * 64, '4' * 32)

    def ledger(self, network=20, preparation=20):
        now = [100]
        ledger = proxy.ProxyLedger(self.context, self.authority, deadline=150,
                                   clock=lambda: now[0], network_limit=network,
                                   preparation_limit=preparation)
        return ledger, now

    def observation(self, ledger):
        ledger.seal()
        return ledger.observation(self.context, self.authority)

    def test_transport_directions_and_admitted_bytes_are_separate(self):
        ledger, _ = self.ledger()
        writes = []
        self.assertEqual(ledger.write(lambda raw: len(raw), b'GET'), 3)
        body = ledger.read(lambda bound: b'input', 5)
        def partial_write(raw):
            writes.append(raw[:2])
            return len(raw[:2])
        ledger.admit(body, 5, c.digest(body), partial_write)
        observed = self.observation(ledger)
        self.assertEqual((observed['network_read_bytes'], observed['network_write_bytes'],
                          observed['network_bytes'], observed['admitted_preparation_bytes']), (5, 3, 8, 5))
        self.assertEqual(b''.join(writes), body)
        self.assertEqual(observed['proxy_source_sha256'], c.digest(self.authority._raw['proxy.py']))

    def test_next_raw_effect_is_clipped_before_exact_shared_ceiling(self):
        ledger, _ = self.ledger(network=10)
        calls = []
        def receive(bound):
            calls.append(bound)
            return b'x' * bound
        ledger.read(receive, 7)
        self.assertEqual(ledger.write(lambda raw: calls.append(raw) or len(raw), b'abcdef'), 3)
        self.assertRaises(c.Refusal, ledger.read, receive)
        self.assertEqual(calls, [7, b'abc'])
        self.assertRaises(c.Refusal, ledger.seal)

    def test_new_connections_error_responses_and_retries_never_reset_allowance(self):
        ledger, _ = self.ledger(network=10)
        for raw in (b'TLS', b'ERROR', b'RETRY'):
            ledger.write(lambda chunk: len(chunk), raw)
        observed = self.observation(ledger)
        self.assertEqual(observed['network_bytes'], 10)
        self.assertEqual(observed['operations'], 3)
        self.assertEqual(observed['admitted_preparation_bytes'], 0)

    def test_concurrent_connections_cannot_spend_the_same_allowance(self):
        ledger, _ = self.ledger(network=10)
        barrier, writes, outcomes = threading.Barrier(3), [], []
        def effect(raw):
            writes.append(raw)
            return len(raw)
        def send():
            barrier.wait(timeout=5)
            try:
                outcomes.append(ledger.write(effect, b'x' * 10))
            except c.Refusal:
                outcomes.append('refused')
        threads = [threading.Thread(target=send) for _ in range(2)]
        for thread in threads:
            thread.start()
        barrier.wait(timeout=5)
        for thread in threads:
            thread.join(timeout=5)
            self.assertFalse(thread.is_alive())
        self.assertCountEqual(outcomes, [10, 'refused'])
        self.assertEqual(sum(map(len, writes)), 10)

    def test_partial_writes_charge_only_actual_count_and_empty_read_is_zero(self):
        ledger, _ = self.ledger()
        self.assertEqual(ledger.write(lambda raw: 2, b'abcd'), 2)
        self.assertEqual(ledger.read(lambda bound: b''), b'')
        observed = self.observation(ledger)
        self.assertEqual(observed['network_bytes'], 2)
        self.assertEqual(observed['operations'], 2)

    def test_bad_raw_result_and_uncertain_effect_permanently_refuse(self):
        for result in (bytearray(b'x'), b'x' * 21, None):
            ledger, _ = self.ledger()
            self.assertRaises(c.Refusal, ledger.read, lambda bound: result)
            self.assertRaises(c.Refusal, ledger.seal)
        for result in (-1, 0, True, 4, None):
            ledger, _ = self.ledger()
            self.assertRaises(c.Refusal, ledger.write, lambda raw: result, b'abc')
            self.assertRaises(c.Refusal, ledger.seal)
        ledger, _ = self.ledger()
        def uncertain(_):
            raise InterruptedError('synthetic unknown write')
        self.assertRaises(c.Refusal, ledger.write, uncertain, b'a')
        self.assertRaises(c.Refusal, ledger.observation, self.context, self.authority)

    def test_clock_regression_expiry_and_nonfinite_values_precede_effect(self):
        for value in (99, 150, 151, float('nan'), float('inf'), True):
            ledger, now = self.ledger()
            now[0] = value
            effect = mock.Mock()
            self.assertRaises(c.Refusal, ledger.read, effect)
            effect.assert_not_called()
        ledger, now = self.ledger()
        def slow_effect(_):
            now[0] = 151
            return b'a'
        self.assertRaises(c.Refusal, ledger.read, slow_effect)
        self.assertRaises(c.Refusal, ledger.seal)

    def test_interrupt_or_lost_clock_cannot_become_a_successful_zero(self):
        ledger, _ = self.ledger()
        def interrupted(_):
            raise KeyboardInterrupt()
        self.assertRaises(KeyboardInterrupt, ledger.write, interrupted, b'a')
        self.assertRaises(c.Refusal, ledger.seal)
        ledger, _ = self.ledger()
        with mock.patch.object(ledger, '_clock', side_effect=OSError('synthetic clock failure')):
            self.assertRaises(c.Refusal, ledger.read, mock.Mock())
        self.assertRaises(c.Refusal, ledger.seal)

    def test_object_identity_and_encoding_are_checked_before_any_write(self):
        for raw, size, hashed, encoding in [(b'body', 3, c.digest(b'body'), 'identity'),
                (b'body', 4, c.digest(b'other'), 'identity'),
                (bytearray(b'body'), 4, c.digest(b'body'), 'identity'),
                (b'body', 4, c.digest(b'body'), 'gzip')]:
            ledger, _ = self.ledger()
            effect = mock.Mock()
            self.assertRaises(c.Refusal, ledger.admit, raw, size, hashed, effect, encoding)
            effect.assert_not_called()

    def test_aggregate_preparation_limit_refuses_whole_next_object(self):
        ledger, _ = self.ledger(preparation=6)
        effect = mock.Mock(side_effect=len)
        ledger.admit(b'body', 4, c.digest(b'body'), effect)
        self.assertRaises(c.Refusal, ledger.admit, b'new', 3, c.digest(b'new'), effect)
        self.assertEqual(effect.call_count, 1)

    def test_chunk_and_operation_bounds_apply_before_effect(self):
        ledger, _ = self.ledger(network=2 * proxy.CHUNK)
        receive = mock.Mock(side_effect=lambda bound: b'x' * bound)
        with mock.patch.object(proxy, 'MAX_OPERATIONS', 2):
            ledger.read(receive, 2 * proxy.CHUNK)
            ledger.read(receive, 2 * proxy.CHUNK)
            self.assertRaises(c.Refusal, ledger.read, receive)
        self.assertEqual(receive.call_args_list, [mock.call(proxy.CHUNK), mock.call(proxy.CHUNK)])

    def test_only_sealed_same_cell_counters_can_be_observed(self):
        ledger, _ = self.ledger()
        self.assertRaises(c.Refusal, ledger.observation, self.context, self.authority)
        ledger.seal()
        wrong = dataclasses.replace(self.context, nonce='5' * 32)
        self.assertRaises(c.Refusal, ledger.observation, wrong, self.authority)
        first = ledger.observation(self.context, self.authority)
        first['network_bytes'] = 999
        self.assertEqual(ledger.observation(self.context, self.authority)['network_bytes'], 0)
        effect = mock.Mock()
        self.assertRaises(c.Refusal, ledger.read, effect)
        effect.assert_not_called()
        self.assertRaises(c.Refusal, ledger.observation, self.context, self.authority)

    def test_cli_literal_hold_precedes_imports_and_network_authority(self):
        result = subprocess.run([sys.executable, '-I',
            str(pathlib.Path(proxy.__file__).resolve()), '--enable'],
            capture_output=True, timeout=5)
        self.assertNotEqual(result.returncode, 0)
        self.assertIn(b'HELD: proxy accounting source only', result.stderr)


if __name__ == '__main__':
    unittest.main()
