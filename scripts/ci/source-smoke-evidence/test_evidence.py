"""Offline synthetic authority/refusal controls. Never run candidate PHP/Docker."""
import copy
import json
import os
import pathlib
import sys
import tempfile
import time
import unittest
from unittest import mock

sys.path.insert(0, str(pathlib.Path(__file__).parent))
import evidence as e


class EvidenceTests(unittest.TestCase):
    def row(self, name=None, **extra):
        return {'method': 'GET', 'uri': 'up', 'name': name,
                'action': 'Controller@health', 'middleware': [], **extra}

    def route(self, rows, cached=False):
        return {'cached': cached, 'cache_sha256': 'a'*64 if cached else None, 'routes': rows}

    def test_json_is_bounded_before_decode(self):
        with self.assertRaises(e.Refusal):
            e.bounded_json(b' '*(e.MAX_JSON+1))
        for data in [b'{"a":1,"a":2}', b'{"x":NaN}', b'\xff', b'{', b'['*100+b']'*100]:
            with self.subTest(data=data[:30]), self.assertRaises(e.Refusal):
                e.bounded_json(data)

    def test_fields_and_collections_are_bounded(self):
        for item in ['x'*16385, list(range(e.MAX_FILES+1))]:
            with self.assertRaises(e.Refusal):
                e.bounded_json(json.dumps(item).encode())

    def test_object_refuses_fifo_links_escape_and_oversize(self):
        with tempfile.TemporaryDirectory() as directory:
            root = pathlib.Path(directory).resolve()
            (root/'good').write_bytes(b'public source')
            raw, metadata = e.object_bytes(root, 'good')
            self.assertEqual(raw, b'public source')
            self.assertEqual(metadata['size'], 13)
            os.symlink(root/'good', root/'symbolic')
            os.link(root/'good', root/'hard')
            os.mkfifo(root/'fifo')
            for name in ['symbolic', 'hard', 'good', 'fifo', '../escape']:
                with self.subTest(name=name), self.assertRaises(e.Refusal):
                    e.object_bytes(root, name)
            (root/'large').write_bytes(b'abc')
            with self.assertRaises(e.Refusal):
                e.object_bytes(root, 'large', 2)

    def test_object_replacement_is_refused(self):
        with tempfile.TemporaryDirectory() as directory:
            root = pathlib.Path(directory).resolve()
            (root/'file').write_bytes(b'old')
            real_read = os.read
            def replacing_read(fd, size):
                value = real_read(fd, size)
                (root/'new').write_bytes(b'new')
                os.replace(root/'new', root/'file')
                return value
            with mock.patch('evidence.os.read', side_effect=replacing_read), self.assertRaises(e.Refusal):
                e.object_bytes(root, 'file')

    def test_graph_requires_unique_complete_production_identities(self):
        graph = [{'name': 'package/'+str(i), 'version': '1.0.0', 'source': {'reference': 'a'*40}} for i in range(79)]
        self.assertEqual(len(e.graph(graph)), 79)
        invalid = [[], graph[:-1], graph+[graph[0]], [graph[0]]*79]
        for key, value in [('name', 'durable-workflow/workflow'), ('version', None), ('source', {})]:
            changed = copy.deepcopy(graph)
            changed[0][key] = value
            invalid.append(changed)
        for changed in invalid:
            with self.assertRaises(e.Refusal):
                e.graph(changed)

    def test_route_normalization_is_position_and_original_name_bound(self):
        before = self.route([self.row(path='source.php')])
        after = self.route([self.row('generated::abc', path='cache.php')], True)
        e.routes(before, after, True)
        # Explicit names, actions, method/domain/URI/middleware/unknown fields
        # and order remain behavior, even when counts and displayed paths match.
        for field, value in [('name', 'renamed'), ('action', 'Other@health'), ('method', 'POST'),
                             ('uri', 'private'), ('domain', 'evil.test'), ('middleware', ['auth'])]:
            changed = copy.deepcopy(after)
            changed['routes'][0][field] = value
            with self.subTest(field=field), self.assertRaises(e.Refusal):
                e.routes(before, changed, True)
        with self.assertRaises(e.Refusal):
            e.routes(self.route([self.row('explicit')]), after, True)
        with self.assertRaises(e.Refusal):
            e.routes(self.route([self.row(), self.row(uri='second')]),
                     self.route([self.row('generated::same'), self.row('generated::same', uri='second')], True), True)
        with self.assertRaises(e.Refusal):
            e.routes(before, self.route([], True), True)
        with self.assertRaises(e.Refusal):
            e.routes(before, self.route([self.row()], False), True)

    def test_identity_cannot_source_its_own_expectations(self):
        expected = {'php':'8.3.36', 'int_size':8, 'graph':{'one':'identity'},
                    'files':{'composer.lock':{'sha256': e.LOCK}}, 'classes':e.CLASSES, 'lock':e.LOCK}
        observed = {k:copy.deepcopy(v) for k,v in expected.items() if k!='lock'}
        observed.update(core_installed=False, installed_json_sha256='b'*64)
        e.identity(expected, observed)
        for key, value in [('php','8.5.12'), ('int_size',4), ('graph',{}), ('files',{}),
                           ('classes',{'escaped':'/tmp/overlay.php'}), ('core_installed',True), ('installed_json_sha256','')]:
            changed = copy.deepcopy(observed)
            changed[key] = value
            with self.subTest(key=key), self.assertRaises(e.Refusal):
                e.identity(expected, changed)

    def test_command_failure_timeout_and_unbounded_logs_refuse(self):
        out, receipt = e.command([sys.executable, '-c', 'print("fixture")'], time.monotonic()+2)
        self.assertEqual(out, b'fixture\n')
        self.assertEqual(receipt['exit'], 0)
        for code in ['raise SystemExit(1)', 'import time;time.sleep(5)', 'print("x"*10000)']:
            with self.assertRaises(e.Refusal):
                e.command([sys.executable, '-c', code], time.monotonic()+.15, stdout_limit=128)

    def test_api_and_cleanup_are_acceptance_not_optional_notes(self):
        e.api_response({'status':200,'body':{'result':{'state':'ready'}}},200,{'result.state':'ready'})
        for response in [{'status':502,'body':{}}, {'status':200,'body':{'result':{'state':'wrong'}}}]:
            with self.assertRaises(e.Refusal):
                e.api_response(response,200,{'result.state':'ready'})
        for receipt in [{}, {'containers':['survivor'],'networks':[],'volumes':[],'processes':[]},
                        {'containers':[],'networks':[],'volumes':[],'processes':['deadline-monitor']}]:
            with self.assertRaises(e.Refusal):
                e.cleanup_receipt(receipt)
        e.cleanup_receipt(dict.fromkeys(['containers','networks','volumes','processes'], []))

    def test_retained_evidence_cannot_be_overwritten(self):
        with tempfile.TemporaryDirectory() as directory:
            path = pathlib.Path(directory).resolve()/'evidence.json'
            path.write_text('older evidence')
            with self.assertRaises(e.Refusal):
                e.final_receipt({}, [], [], {}, path)
            self.assertEqual(path.read_text(), 'older evidence')


if __name__ == '__main__':
    unittest.main()
