"""Inert creation/readback/backstop controls. Never contact a Docker daemon."""
import copy
import hashlib
import json
import pathlib
import sys
import tempfile
import unittest
from unittest import mock

sys.path.insert(0, str(pathlib.Path(__file__).parent))
import cleanup as c
import evidence as e
import runner as r
from test_runner import spec


class AuthorityTests(unittest.TestCase):
    def session(self, root):
        root = pathlib.Path(root).resolve()
        (root / 'inputs').mkdir()
        (root / 'output').mkdir()
        session = r.Session(spec(), root / 'inputs', root / 'output')
        packet = pathlib.Path(__file__).parent
        session.packet = {name: {'bytes': (packet / name).read_bytes()} for name in ('evidence.py', 'cleanup.py')}
        return session

    def test_each_admission_refusal_has_zero_docker_and_zero_cleanup(self):
        receipt = {'source': e.SOURCE, 'lock': 'synthetic-lock', 'graph': {}, 'scripts': False,
                   'plugins': False, 'effective_trust_verified': True, 'request_concurrency': 2,
                   **{key: 'a' * 64 for key in ('ca_sha256', 'tool_config_sha256', 'provider_api_digest', 'installed_json_sha256')},
                   'provider_run_id': 1, 'provider_artifact_id': 2}
        for failure in ('packet', 'receipt_digest', 'graph', 'snapshot'):
            with self.subTest(failure=failure), tempfile.TemporaryDirectory() as directory:
                s = self.session(directory)
                admitted = {'cleanup.py': {'bytes': b'# public synthetic packet',
                    'identity': {'mode': 0o644, 'size': 25, 'sha256': 'a' * 64}}}
                document = copy.deepcopy(receipt)
                if failure == 'graph':
                    document['graph'] = {'unapproved': True}
                raw = json.dumps(document).encode()
                admission = spec()
                admission['vendor_preparation_receipt_sha256'] = hashlib.sha256(raw).hexdigest()
                if failure == 'receipt_digest':
                    admission['vendor_preparation_receipt_sha256'] = '0' * 64
                calls = []
                def command(argv, *args, **kwargs):
                    calls.append(argv)
                    self.assertEqual(argv[0], 'git')
                    return b'# synthetic original', {}
                with mock.patch.object(r, 'EXECUTION_ENABLED', True), \
                     mock.patch.object(r, 'expected_from_git', return_value={'lock': 'synthetic-lock', 'graph': {}}), \
                     mock.patch.object(r, 'reviewed_packet', return_value=admitted), \
                     mock.patch.object(r, 'object_bytes', return_value=(raw, {})), \
                     mock.patch.object(r, 'command', side_effect=command), \
                     mock.patch.object(r.Session, 'cleanup', side_effect=AssertionError('admission entered cleanup')), \
                     mock.patch.object(r.Session, 'start', side_effect=AssertionError('admission entered start')), \
                     mock.patch.object(r, 'snapshot_archive', return_value=(b'synthetic', {})), \
                     mock.patch.object(r, 'verify_archive', side_effect=e.Refusal('snapshot refused') if failure == 'snapshot' else None):
                    self.assertRaises(e.Refusal, r.execute_reviewed, admission, directory, s.inputs,
                                      s.directory / 'final.json', {} if failure == 'packet' else admitted)
                self.assertTrue(all(argv[0] != 'docker' for argv in calls))
                self.assertEqual(list(s.directory.iterdir()), [])

    def test_every_exact_name_or_owner_label_collision_refuses_without_mutation(self):
        for kind in c.LISTINGS:
            for collision in ('name', 'label'):
                with self.subTest(kind=kind, collision=collision), tempfile.TemporaryDirectory() as directory:
                    s = self.session(directory)
                    name = next(name for name, entry in s.plan.items() if entry['kind'] == kind)
                    def docker(*args, **kwargs):
                        actual_kind = 'container' if args[0] == 'ps' else args[0]
                        if actual_kind == kind and (('--format' in args) == (collision == 'name')):
                            return name.encode()
                        return b''
                    s.docker = mock.Mock(side_effect=docker)
                    self.assertRaises(e.Refusal, s.preflight)
                    before = s.docker.call_count
                    s.cleanup()
                    self.assertEqual(s.docker.call_count, before)
                    self.assertTrue(all('create' not in call.args and 'rm' not in call.args for call in s.docker.call_args_list))

    def test_real_inert_backstop_ready_ack_and_expected_stop(self):
        with tempfile.TemporaryDirectory() as directory:
            s = self.session(directory)
            with mock.patch.object(r, 'command', side_effect=AssertionError('Docker called')):
                s.launch_backstop()
                s.backstop_event('arm')
                receipt = s.cleanup()
            self.assertEqual(receipt, {'containers': [], 'networks': [], 'volumes': [], 'processes': []})
            self.assertEqual(s.backstop.returncode, 0)

    def stop_test_process(self, s):
        if s.backstop.poll() is None:
            s.backstop.kill()
        s.backstop.wait(timeout=2)
        for stream in (s.backstop.stdin, s.backstop.stdout):
            if stream is not None:
                stream.close()

    def test_startup_refusal_precedes_any_docker_call(self):
        with tempfile.TemporaryDirectory() as directory:
            s = self.session(directory)
            s.packet['cleanup.py']['bytes'] = b'raise ImportError("synthetic startup failure")'
            try:
                with mock.patch.object(r, 'command', side_effect=AssertionError('Docker before readiness')):
                    self.assertRaises(e.Refusal, s.start)
            finally:
                self.stop_test_process(s)

    def test_start_collision_refuses_before_any_creation(self):
        with tempfile.TemporaryDirectory() as directory:
            s = self.session(directory)
            s.snapshot, s.snapshot_manifest = b'synthetic archive', {}
            s.verify_image = mock.Mock()
            def docker(*args, **kwargs):
                if 'create' in args or 'run' in args or 'rm' in args:
                    raise AssertionError('Mutation before collision refusal')
                return s.name('inputs').encode() if args[0] == 'volume' else b''
            s.docker = mock.Mock(side_effect=docker)
            try:
                self.assertRaises(e.Refusal, s.start)
            finally:
                self.stop_test_process(s)

    def test_actual_role_creation_never_pulls(self):
        with tempfile.TemporaryDirectory() as directory:
            s = self.session(directory)
            s.bindings = {s.name('network'): {'Id': 'b' * 64}}
            calls = []
            s.create_container = lambda role, options, image, arguments: calls.append(options) or 'a' * 64
            s.docker = lambda *args, **kwargs: calls.append(args) or b''
            s.check_bound = mock.Mock(return_value={'State': {'Running': True}})
            s.verify_container = mock.Mock()
            s.role('fixture', 'sleep', ['90'])
            self.assertEqual(len([call for call in calls if call[0] in ('create', 'run')]), 1)
            self.assertIn('--pull=never', calls[0])

    def test_startup_and_ready_then_early_exit_are_refused(self):
        for source in (b'raise ImportError("synthetic startup failure")',
                       b'import sys\ndef backstop_main():\n print("ready",flush=True)\n sys.exit(0)\n',
                       b'import time\ndef backstop_main():\n time.sleep(5)\n'):
            with tempfile.TemporaryDirectory() as directory:
                s = self.session(directory)
                s.packet['cleanup.py']['bytes'] = source
                with mock.patch.object(r, 'command', side_effect=AssertionError('Docker called')):
                    try:
                        self.assertRaises((e.Refusal, BrokenPipeError), s.launch_backstop)
                    finally:
                        self.stop_test_process(s)

    def test_premature_exit_blocks_work_and_final_acceptance(self):
        with tempfile.TemporaryDirectory() as directory:
            s = self.session(directory)
            s.launch_backstop()
            s.backstop.kill()
            s.backstop.wait(timeout=2)
            with mock.patch.object(r, 'command', side_effect=AssertionError('Docker called')):
                self.assertRaisesRegex(e.Refusal, 'prematurely', s.docker, 'ps')
                self.assertRaisesRegex(e.Refusal, 'prematurely', s.cleanup)
            self.stop_test_process(s)

    def owned(self, s, role, identifier='a' * 64):
        kind = s.plan[s.name(role)]['kind']
        labels = {r.LABEL: s.token, c.RESOURCE_LABEL: s.plan[s.name(role)]['nonce']}
        if kind == 'volume':
            return {'Name': s.name(role), 'CreatedAt': '2026-01-01T00:00:00Z', 'Driver': 'local', 'Scope': 'local',
                    'Labels': labels, 'Options': {'type': 'tmpfs', 'device': 'tmpfs', 'o': 'size=8m,uid=82,gid=82'},
                    'Mountpoint': '/synthetic-docker/' + s.name(role)}
        value = {'Name': '/' + s.name(role) if kind == 'container' else s.name(role), 'Id': identifier}
        value['Config' if kind == 'container' else 'Labels'] = {'Labels': labels} if kind == 'container' else labels
        return value

    def test_fresh_creation_bindings_reject_foreign_reused_or_replaced_resources(self):
        for role in ('fixture', 'network', 'inputs'):
            with self.subTest(role=role), tempfile.TemporaryDirectory() as directory:
                s = self.session(directory)
                s.backstop_event = mock.Mock()
                s.ownership_admitted = True
                s.attempted.add(s.name(role))
                value = self.owned(s, role)
                s.docker = mock.Mock(side_effect=lambda *args, **kw: json.dumps([value]).encode())
                identifier = value.get('Id', value['Name']).encode()
                s.bind_resource(role, identifier)
                s.check_bound(role)
                if role == 'inputs':
                    value['CreatedAt'] = '2026-01-02T00:00:00Z'
                else:
                    value['Id'] = 'b' * 64
                self.assertRaisesRegex(e.Refusal, 'replaced', s.check_bound, role)
                labels = value['Config']['Labels'] if role == 'fixture' else value['Labels']
                labels[c.RESOURCE_LABEL] = '0' * 32
                self.assertRaisesRegex(e.Refusal, 'Foreign', s.bind_resource, role, identifier)

    def test_cleanup_never_deletes_foreign_or_replaced_objects(self):
        for role in ('fixture', 'network', 'inputs'):
            for failure in ('nonce', 'name', 'binding'):
                with self.subTest(role=role, failure=failure), tempfile.TemporaryDirectory() as directory:
                    s = self.session(directory)
                    name = s.name(role)
                    value = self.owned(s, role)
                    s.attempted.add(name)
                    s.bindings[name] = c.resource_binding(s.plan[name]['kind'], value, s.plan[name], s.token)
                    if failure == 'nonce':
                        (value['Config']['Labels'] if role == 'fixture' else value['Labels'])[c.RESOURCE_LABEL] = '0' * 32
                    elif failure == 'name':
                        value['Name'] += '-foreign'
                    elif role == 'inputs':
                        value['CreatedAt'] = '2026-01-02T00:00:00Z'
                    else:
                        value['Id'] = 'b' * 64
                    calls = []
                    def docker(*args):
                        calls.append(args)
                        if 'inspect' in args:
                            return json.dumps([value]).encode()
                        if args[:len(c.LISTINGS[s.plan[name]['kind']])] == c.LISTINGS[s.plan[name]['kind']]:
                            return value.get('Id', value['Name']).encode()
                        return b''
                    self.assertRaises(e.Refusal, c.cleanup_owned, docker, s.token, s.plan, s.attempted, s.bindings)
                    self.assertTrue(all('rm' not in args for args in calls))

    def cleanup_docker(self, kind, value, discover=False, duplicate=False, remove=True, names=True, discover_once=False):
        identifier = value.get('Id', value['Name'])
        resources, removed = {identifier: value}, []
        discoveries = 0
        def docker(*args):
            nonlocal discoveries
            actual_kind = 'container' if args[0] in ('ps', 'rm', 'container') else args[0]
            if actual_kind != kind:
                return b''
            if 'inspect' in args:
                return json.dumps([resources[args[-1]]]).encode()
            if 'rm' in args:
                removed.append(args[-1])
                if remove:
                    del resources[args[-1]]
                return b''
            if '--format' in args:
                return ' '.join(v['Name'].removeprefix('/') for v in resources.values()).encode() if names else b''
            if '--filter' in args:
                discoveries += 1
                visible = discover and (not discover_once or discoveries == 1)
                return ' '.join(list(resources) * (2 if duplicate else 1)).encode() if visible else b''
            self.assertTrue(kind == 'volume' or '--no-trunc' in args)
            return ' '.join(resources).encode()
        return docker, resources, removed

    def test_bound_cleanup_does_not_depend_on_label_discovery(self):
        for role in ('fixture', 'network', 'inputs'):
            with self.subTest(role=role), tempfile.TemporaryDirectory() as directory:
                s = self.session(directory)
                name = s.name(role)
                kind = s.plan[name]['kind']
                value = self.owned(s, role)
                s.attempted.add(name)
                s.bindings[name] = c.resource_binding(kind, value, s.plan[name], s.token)
                docker, resources, removed = self.cleanup_docker(kind, value)
                receipt = c.cleanup_owned(docker, s.token, s.plan, s.attempted, s.bindings)
                self.assertEqual(receipt, {'containers': [], 'networks': [], 'volumes': []})
                self.assertEqual(removed, [value.get('Id', value['Name'])])
                self.assertEqual(resources, {})

    def test_lost_return_label_discovery_deduplicates_each_resource_kind(self):
        for role in ('fixture', 'network', 'inputs'):
            with self.subTest(role=role), tempfile.TemporaryDirectory() as directory:
                s = self.session(directory)
                name = s.name(role)
                kind = s.plan[name]['kind']
                value = self.owned(s, role)
                s.attempted.add(name)
                docker, resources, removed = self.cleanup_docker(kind, value, discover=True, duplicate=True)
                c.cleanup_owned(docker, s.token, s.plan, s.attempted, {})
                self.assertEqual(removed, [value.get('Id', value['Name'])])
                self.assertEqual(resources, {})

    def test_independent_full_id_readback_refuses_survival_when_name_listing_omits_it(self):
        for role in ('fixture', 'network', 'inputs'):
            for bound in (False, True):
                with self.subTest(role=role, bound=bound), tempfile.TemporaryDirectory() as directory:
                    s = self.session(directory)
                    name = s.name(role)
                    kind = s.plan[name]['kind']
                    value = self.owned(s, role)
                    s.attempted.add(name)
                    if bound:
                        s.bindings[name] = c.resource_binding(kind, value, s.plan[name], s.token)
                    docker, resources, removed = self.cleanup_docker(kind, value, discover=not bound,
                        discover_once=True, remove=False, names=False)
                    self.assertRaisesRegex(e.Refusal, 'resource ID survived', c.cleanup_owned,
                                           docker, s.token, s.plan, s.attempted, s.bindings)
                    self.assertEqual(removed, [value.get('Id', value['Name'])])
                    self.assertEqual(len(resources), 1)

    def container_vector(self, s):
        # Synthetic effective Docker state, independently specified from argv.
        s.bindings[s.name('inputs')] = {'Mountpoint': '/synthetic-inputs'}
        s.bindings[s.name('network')] = {'Id': 'b' * 64}
        s.image_configs[spec()['image_id']] = {'Env': ['BASE=public'], 'WorkingDir': '/app'}
        s.check_bound = mock.Mock()
        options = ['--cpus', '.25', '--memory', '256m', '--memory-swap', '256m', '--pids-limit', '128',
                   '--shm-size', '64m', '--network', 'b' * 64, '--tmpfs', '/tmp:rw,size=128m,uid=82,gid=82',
                   '--mount', 'type=volume,src=' + s.name('inputs') + ',dst=/inputs,readonly']
        arguments = ['-s', 'TERM', '-k', '2', '100', 'sleep', '90']
        value = self.owned(s, 'fixture')
        value['Image'] = spec()['image_id']
        value['Config'].update({'Image': spec()['image_id'], 'Env': ['BASE=public'], 'Entrypoint': ['timeout'],
                                'Cmd': arguments, 'User': '82:82', 'WorkingDir': '/app', 'Healthcheck': {'Test': ['NONE']}})
        value['HostConfig'] = {'Init': True, 'ReadonlyRootfs': True, 'NanoCpus': 250000000,
                              'Memory': 268435456, 'MemorySwap': 268435456, 'PidsLimit': 128, 'ShmSize': 67108864,
                              'CapDrop': ['ALL'], 'SecurityOpt': ['no-new-privileges'], 'Privileged': False,
                              'RestartPolicy': {'Name': 'no', 'MaximumRetryCount': 0}, 'AutoRemove': False,
                              'IpcMode': 'private', 'NetworkMode': 'b' * 64,
                              'Tmpfs': {'/tmp': 'rw,size=128m,uid=82,gid=82'}}
        value['Mounts'] = [{'Type': 'volume', 'Name': s.name('inputs'), 'Driver': 'local',
                            'Source': '/synthetic-inputs', 'Destination': '/inputs', 'RW': False}]
        value['NetworkSettings'] = {'Ports': {}, 'Networks': {'synthetic': {'NetworkID': 'b' * 64}}}
        return options, arguments, value

    def test_effective_container_mismatches_refuse_before_start(self):
        changes = [('Image', 'sha256:' + 'f' * 64), ('Config.User', '0:0'), ('Config.Env', ['BASE=changed']),
                   ('Config.Entrypoint', ['sh']), ('Config.Cmd', ['unreviewed']), ('Config.Labels', {}),
                   ('HostConfig.NanoCpus', 0), ('HostConfig.Memory', 0), ('HostConfig.MemorySwap', -1),
                   ('HostConfig.PidsLimit', 0), ('HostConfig.CapDrop', []), ('HostConfig.CapAdd', ['SYS_ADMIN']),
                   ('HostConfig.SecurityOpt', []), ('HostConfig.ReadonlyRootfs', False), ('HostConfig.Init', False),
                   ('HostConfig.Privileged', True), ('HostConfig.NetworkMode', 'host'), ('HostConfig.Tmpfs', {}),
                   ('HostConfig.Binds', ['/foreign:/data']), ('HostConfig.PortBindings', {'8080': []}),
                   ('HostConfig.Devices', [{'PathOnHost': '/dev/fake'}]), ('Mounts', []),
                   ('NetworkSettings.Networks', {'foreign': {'NetworkID': 'f' * 64}}),
                   ('NetworkSettings.Ports', {'8080/tcp': [{'HostPort': '8080'}]})]
        for path, replacement in changes:
            with self.subTest(path=path), tempfile.TemporaryDirectory() as directory:
                s = self.session(directory)
                options, arguments, value = self.container_vector(s)
                s.verify_container('fixture', value, options, spec()['image_id'], arguments)
                changed = copy.deepcopy(value)
                target = changed
                fields = path.split('.')
                for field in fields[:-1]:
                    target = target[field]
                target[fields[-1]] = replacement
                self.assertRaises(e.Refusal, s.verify_container, 'fixture', changed, options, spec()['image_id'], arguments)

    def test_malformed_effective_container_cannot_reach_start(self):
        with tempfile.TemporaryDirectory() as directory:
            s = self.session(directory)
            options, arguments, value = self.container_vector(s)
            options = ['create', '--pull=never', *options]
            value['State'] = {'Status': 'created'}
            value['HostConfig']['PidsLimit'] = 0
            s.attempt = mock.Mock()
            s.bind_resource = mock.Mock(return_value=value)
            s.docker = mock.Mock(return_value=b'a' * 64)
            self.assertRaises(e.Refusal, s.create_container, 'fixture', options, spec()['image_id'], arguments)
            self.assertEqual(s.docker.call_count, 1)
            self.assertEqual(s.docker.call_args.args[0], 'create')
            s.docker.reset_mock()
            self.assertRaisesRegex(e.Refusal, 'Local-image-only', s.create_container, 'fixture',
                                   ['create'], spec()['image_id'], arguments)
            s.docker.assert_not_called()

    def test_volume_and_network_effective_options_are_checked(self):
        cases = [('inputs', 'Driver', 'foreign'), ('inputs', 'Options', {}), ('inputs', 'Scope', 'global'),
                 ('network', 'Internal', False), ('network', 'Driver', 'host'), ('network', 'EnableIPv6', True),
                 ('network', 'Options', {'foreign': 'enabled'}), ('network', 'Attachable', True)]
        for role, key, wrong in cases:
            with self.subTest(role=role, key=key), tempfile.TemporaryDirectory() as directory:
                s = self.session(directory)
                s.ownership_admitted = True
                s.backstop_event = mock.Mock()
                value = self.owned(s, role)
                if role == 'network':
                    value.update({'Driver': 'bridge', 'Scope': 'local', 'Internal': True, 'EnableIPv6': False,
                                  'Attachable': False, 'Ingress': False, 'Options': {}})
                value[key] = wrong
                def docker(*args):
                    if 'inspect' in args:
                        return json.dumps([value]).encode()
                    return value.get('Id', value['Name']).encode()
                s.docker = mock.Mock(side_effect=docker)
                if role == 'network':
                    self.assertRaisesRegex(e.Refusal, 'Effective', s.network)
                else:
                    self.assertRaisesRegex(e.Refusal, 'Effective', s.volume, 'inputs', '8m')
                self.assertFalse(any(call.args[0] in ('start', 'run') for call in s.docker.call_args_list))


if __name__ == '__main__':
    unittest.main()
