"""Offline synthetic authority/refusal controls. Never run candidate PHP/Docker."""
import copy
import json
import os
import pathlib
import subprocess
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
        self.assertRaises(e.Refusal, e.bounded_json, b' ' * (e.MAX_JSON + 1))
        for data in [b'{"a":1,"a":2}', b'{"x":NaN}', b'\xff', b'{', b'['*100+b']'*100]:
            with self.subTest(data=data[:30]):
                self.assertRaises(e.Refusal, e.bounded_json, data)

    def test_fields_and_collections_are_bounded(self):
        for item in ['x'*16385, list(range(e.MAX_FILES+1))]:
            self.assertRaises(e.Refusal, e.bounded_json, json.dumps(item).encode())

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
                with self.subTest(name=name):
                    self.assertRaises(e.Refusal, e.object_bytes, root, name)
            (root/'large').write_bytes(b'abc')
            self.assertRaises(e.Refusal, e.object_bytes, root, 'large', 2)

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
            with mock.patch('evidence.os.read', side_effect=replacing_read):
                self.assertRaises(e.Refusal, e.object_bytes, root, 'file')

    def test_graph_requires_unique_complete_production_identities(self):
        graph = [{'name': 'package/'+str(i), 'version': '1.0.0', 'source': {'type':'git','url':'https://example.invalid/source','reference':'a'*40}, 'dist': {'type':'zip','url':'https://example.invalid/dist','reference':'b'*40,'shasum':''}} for i in range(79)]
        self.assertEqual(len(e.graph(graph)), 79)
        invalid = [[], graph[:-1], graph+[graph[0]], [graph[0]]*79]
        for key, value in [('name', 'durable-workflow/workflow'), ('version', None), ('source', {})]:
            changed = copy.deepcopy(graph)
            changed[0][key] = value
            invalid.append(changed)
        for changed in invalid:
            self.assertRaises(e.Refusal, e.graph, changed)

    def test_source_and_dist_authority_are_independent_and_exact(self):
        packages=[{'name':'package/'+str(i),'version':'1.0.0',
                   'source':{'type':'git','url':'https://example.invalid/source','reference':'a'*40},
                   'dist':{'type':'zip','url':'https://example.invalid/dist','reference':'b'*40,'shasum':''}}
                  for i in range(79)]
        expected=e.graph(packages)
        for authority,fields in [('source',('type','url','reference')),('dist',('type','url','reference','shasum'))]:
            for field in fields:
                changed=copy.deepcopy(packages)
                changed[0][authority][field]='changed'
                self.assertNotEqual(expected,e.graph(changed),(authority,field))
                del changed[0][authority][field]
                self.assertRaises(e.Refusal,e.graph,changed)
            changed=copy.deepcopy(packages)
            changed[0][authority]['mirrors']=[]
            self.assertRaises(e.Refusal,e.graph,changed)
        changed=copy.deepcopy(packages)
        changed[0]['dist']['shasum']=None
        self.assertNotEqual(expected,e.graph(changed))
        for field in ('source-mirrors','dist-mirrors','mirrors'):
            changed=copy.deepcopy(packages);changed[0][field]=[]
            self.assertRaises(e.Refusal,e.graph,changed)

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
            with self.subTest(field=field):
                self.assertRaises(e.Refusal, e.routes, before, changed, True)
        self.assertRaises(e.Refusal, e.routes, self.route([self.row('explicit')]), after, True)
        self.assertRaises(e.Refusal, e.routes, self.route([self.row(), self.row(uri='second')]), self.route([self.row('generated::same'), self.row('generated::same', uri='second')], True), True)
        self.assertRaises(e.Refusal, e.routes, before, self.route([], True), True)
        self.assertRaises(e.Refusal, e.routes, before, self.route([self.row()], False), True)

    def test_identity_cannot_source_its_own_expectations(self):
        expected = {'php':'8.3.36', 'int_size':8, 'graph':{'one':'identity'},
                    'files':{'composer.lock':{'sha256': e.LOCK}}, 'classes':e.CLASSES, 'lock':e.LOCK, 'installed_json_sha256':'b'*64}
        observed = {k:copy.deepcopy(v) for k,v in expected.items() if k!='lock'}
        observed.update(core_installed=False, installed_json_sha256='b'*64)
        e.identity(expected, observed)
        for key, value in [('php','8.5.12'), ('int_size',4), ('graph',{}), ('files',{}),
                           ('classes',{'escaped':'/tmp/overlay.php'}), ('core_installed',True), ('installed_json_sha256',''), ('installed_json_sha256','c'*64)]:
            changed = copy.deepcopy(observed)
            changed[key] = value
            with self.subTest(key=key):
                self.assertRaises(e.Refusal, e.identity, expected, changed)

    def test_command_failure_timeout_and_unbounded_logs_refuse(self):
        out, receipt = e.command([sys.executable, '-c', 'print("fixture")'], time.monotonic()+2)
        self.assertEqual(out, b'fixture\n')
        self.assertEqual(receipt['exit'], 0)
        for code in ['raise SystemExit(1)', 'import time;time.sleep(5)', 'print("x"*10000)']:
            self.assertRaises(e.Refusal, e.command, [sys.executable, '-c', code], time.monotonic() + 0.15, stdout_limit=128)

    def test_failed_command_retains_bounded_status_and_hashes_without_logging_diagnostics(self):
        argv = [sys.executable, '-c', 'import sys;print("[]");sys.stderr.write("synthetic diagnostic\\n");sys.exit(1)']
        with self.assertRaises(e.CommandFailure) as caught:
            e.command(argv, time.monotonic() + 2)
        error = caught.exception
        self.assertEqual(error.stdout, b'[]\n')
        self.assertEqual(error.stderr, b'synthetic diagnostic\n')
        self.assertEqual(error.receipt['exit'], 1)
        self.assertEqual(error.receipt['argv_sha256'], e.hashlib.sha256(json.dumps(argv).encode()).hexdigest())
        self.assertEqual(error.receipt['stderr_sha256'], e.hashlib.sha256(error.stderr).hexdigest())
        self.assertEqual(str(error), 'Command failed')
        self.assertFalse(e.command_accepted(error.receipt))

    def test_only_closed_inspect_absence_receipts_are_accepted_as_negative_observations(self):
        for kind, identifier in [('container', 'a' * 64), ('network', 'b' * 64),
                                 ('volume', 'waterline-source-' + 'c' * 16 + '-inputs')]:
            with self.subTest(kind=kind):
                _, _, native = e.absent_inspect_result(kind, identifier)
                receipt = {**native, 'inspect_absent': {'kind': kind, 'identifier': identifier}}
                self.assertEqual(receipt['exit'], 1)
                self.assertTrue(e.command_accepted(receipt))
                self.assertFalse(e.command_accepted(native))
                for key, value in [('exit', 2), ('argv_sha256', 'f' * 64), ('stderr_sha256', 'f' * 64),
                                   ('inspect_absent', {'kind': kind, 'identifier': identifier + '-other'})]:
                    self.assertFalse(e.command_accepted({**receipt, key: value}))

    def test_command_input_is_bounded_and_drained_with_both_output_pipes(self):
        body=b'public fixture'*65536
        code='import sys;data=sys.stdin.buffer.read();sys.stderr.write("fixture diagnostic");print(len(data))'
        out,receipt=e.command([sys.executable,'-c',code],time.monotonic()+3,input_bytes=body)
        self.assertEqual(int(out),len(body))
        self.assertEqual(receipt['exit'],0)
        self.assertRaises(e.Refusal,e.command,[sys.executable,'-c','pass'],time.monotonic()+1,input_bytes=b'x'*(8388609))

    def test_timeout_kills_ignoring_child_after_group_leader_exits(self):
        with tempfile.TemporaryDirectory() as directory:
            pidfile=pathlib.Path(directory)/'child.pid'
            child='import signal,time;signal.signal(signal.SIGTERM,signal.SIG_IGN);print("ready",flush=True);time.sleep(10)'
            parent='import pathlib,subprocess,sys;child=subprocess.Popen([sys.executable,"-c",'+repr(child)+']);pathlib.Path('+repr(str(pidfile))+').write_text(str(child.pid))'
            self.assertRaises(e.Refusal,e.command,[sys.executable,'-c',parent],time.monotonic()+.5)
            pid=int(pidfile.read_text())
            stop=time.monotonic()+1
            while True:
                state=subprocess.run(['ps','-o','stat=','-p',str(pid)],capture_output=True,text=True,timeout=1).stdout.strip()
                if not state or state.startswith('Z'):break
                self.assertLess(time.monotonic(),stop,'Owned timeout child survived')
                time.sleep(.02)

    def test_api_and_cleanup_are_acceptance_not_optional_notes(self):
        e.api_response({'status':200,'body':{'result':{'state':'ready'}}},200,{'result.state':'ready'})
        for response in [{'status':502,'body':{}}, {'status':200,'body':{'result':{'state':'wrong'}}}]:
            self.assertRaises(e.Refusal, e.api_response, response, 200, {'result.state': 'ready'})
        for receipt in [{}, {'containers':['survivor'],'networks':[],'volumes':[],'processes':[]},
                        {'containers':[],'networks':[],'volumes':[],'processes':['deadline-monitor']}]:
            self.assertRaises(e.Refusal, e.cleanup_receipt, receipt)
        e.cleanup_receipt(dict.fromkeys(['containers','networks','volumes','processes'], []))

    def test_retained_evidence_cannot_be_overwritten(self):
        with tempfile.TemporaryDirectory() as directory:
            path = pathlib.Path(directory).resolve()/'evidence.json'
            path.write_text('older evidence')
            self.assertRaises(e.Refusal, e.final_receipt, {}, [], [], {}, path)
            self.assertEqual(path.read_text(), 'older evidence')


if __name__ == '__main__':
    unittest.main()
