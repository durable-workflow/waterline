"""Inert pre-effect packet controls; no Docker or application is executed."""
import base64
import hashlib
import importlib.util
import json
from pathlib import Path
import unittest
from unittest.mock import patch

ROOT = Path(__file__).resolve().parent
spec = importlib.util.spec_from_file_location('schedule_cell_contract', ROOT / 'runner.py')
cell = importlib.util.module_from_spec(spec)
spec.loader.exec_module(cell)


class PacketBoundaryTest(unittest.TestCase):
    def packet(self):
        data = {name: name.encode() for name in cell.EXPECTED}
        data['candidate'] = json.dumps({'commit': cell.CANDIDATE}).encode()
        expected = {name: {'bytes': len(value), 'sha256': hashlib.sha256(value).hexdigest()}
                    for name, value in data.items()}
        return {'subjects': {name: base64.b64encode(value).decode() for name, value in data.items()},
                'runtime': dict(cell.RUNTIME)}, expected

    def test_exact_static_helper_files_match_retained_subjects(self):
        for name, file in {'loader': 'load-candidate.php', 'proxy': 'counting-proxy.py',
                           'browser': 'browser.mjs', 'ownership': 'owned-docker.py',
                           'requirements': 'consumer-requirements.txt', 'workspace': 'workspace.py',
                           'worker': '../schedule-gated-worker.py'}.items():
            with self.subTest(subject=name):
                data = (ROOT / file).read_bytes()
                self.assertEqual(cell.EXPECTED[name], {'bytes': len(data), 'sha256': hashlib.sha256(data).hexdigest()})

    def test_valid_capture_is_inert(self):
        packet, expected = self.packet()
        with patch.object(cell, 'EXPECTED', expected), patch.object(cell.subprocess, 'run', side_effect=AssertionError('effect')):
            subjects, runtime = cell.captured_inputs(packet)
        self.assertEqual(set(subjects), set(expected))
        self.assertEqual(runtime, cell.RUNTIME)

    def test_each_replaced_subject_refuses_before_effects(self):
        for name in cell.EXPECTED:
            with self.subTest(subject=name):
                packet, expected = self.packet()
                packet['subjects'][name] = base64.b64encode(b'replacement').decode()
                with patch.object(cell, 'EXPECTED', expected), patch.object(cell.subprocess, 'run', side_effect=AssertionError('effect')):
                    with self.assertRaises(ValueError):
                        cell.captured_inputs(packet)

    def test_other_validly_shaped_native_subject_refuses(self):
        for field, replacement in [('index', 'sha256:' + '0' * 64), ('amd64', 'sha256:' + '0' * 64),
                                   ('source', '0' * 40), ('version', '3.9.2')]:
            with self.subTest(field=field):
                packet, expected = self.packet()
                packet['runtime'][field] = replacement
                with patch.object(cell, 'EXPECTED', expected), patch.object(cell.subprocess, 'run', side_effect=AssertionError('effect')):
                    with self.assertRaises(ValueError):
                        cell.captured_inputs(packet)

    def test_candidate_cannot_supply_replacement_expected_hash(self):
        packet, expected = self.packet()
        packet['expectedPayloadSha256'] = '0' * 64
        with patch.object(cell, 'EXPECTED', expected):
            with self.assertRaises(ValueError):
                cell.captured_inputs(packet)

    def test_missing_or_additional_subject_refuses(self):
        for extra in [False, True]:
            packet, expected = self.packet()
            if extra:
                packet['subjects']['unknown'] = base64.b64encode(b'unknown').decode()
            else:
                del packet['subjects']['loader']
            with patch.object(cell, 'EXPECTED', expected):
                with self.assertRaises(ValueError):
                    cell.captured_inputs(packet)


    def test_id_cleanup_still_runs_after_workspace_refusal(self):
        class RefusedWorkspace:
            def check(self):
                raise ValueError('workspace replaced')
        fixture = object.__new__(cell.Cell)
        fixture.workspace = RefusedWorkspace()
        fixture.ids = {'runtime': 'a' * 64}
        fixture.labels = {'runtime': {'owner': 'waterline'}}
        fixture.images = {'runtime': 'sha256:' + 'b' * 64}
        fixture.recovered_ids = {}
        fixture.owned_role_ids = lambda role: []
        fixture.cleanup_docker = ['/usr/bin/docker', '--config', '/proc/self/fd']
        fixture.processes = []; fixture.files = []; fixture.credentials = []
        removed = []
        fixture.raw_inspect = lambda *args: {'State': {'OOMKilled': False}}
        fixture.remove = lambda docker, identifier, labels: removed.append((docker, identifier, labels))
        with self.assertRaises(ValueError):
            fixture.cleanup()
        self.assertEqual(removed, [(fixture.cleanup_docker, 'a' * 64, fixture.labels['runtime'])])

if __name__ == '__main__':
    unittest.main()
