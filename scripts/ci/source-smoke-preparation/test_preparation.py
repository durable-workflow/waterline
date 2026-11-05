"""Pure countercontrols. Real application source, synthetic provider/daemon facts."""
import copy
import ast
import base64
import dataclasses
import io
import json
import pathlib
import re
import shlex
import stat
import subprocess
import sys
import tempfile
import time
import tarfile
import unittest
import warnings
import zipfile
from unittest import mock

sys.path.insert(0, str(pathlib.Path(__file__).parent))
import accepted
import binder
import bootstrap
import cell
import contract as c
import custody
import producer
import recipe
import test_guardian as synthetic


class PreparationTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.folder = pathlib.Path(__file__).resolve().parent
        cls.repository = cls.folder.parents[2]
        cls.evidence = accepted.accepted_evidence(cls.repository)
        cls.expected = cls.evidence.expected_from_git(cls.repository)
        def git(*args):
            return cls.evidence.command(['git', '-C', str(cls.repository), *args],
                time.monotonic() + 5, stdout_limit=custody.MAX_MEMBER)[0]
        cls.git = staticmethod(git)
        cls.preparation_commit = git('rev-parse', 'HEAD').strip().decode()
        cls.preparation_tree = git('rev-parse', 'HEAD^{tree}').strip().decode()
        cls.manifest_sha256 = custody.snapshot(git, cls.preparation_commit, cls.preparation_tree)[2]
        cls.authority = custody.SourceAuthority(git, cls.preparation_commit, cls.preparation_tree, cls.manifest_sha256)
        cls.lock = json.loads((cls.folder / 'browser-tool-lock.json').read_text())
        cls.packages = json.loads(subprocess.check_output(['git', '-C', str(cls.repository),
            'show', c.SOURCE + ':standalone/composer.lock']))['packages']
        archive = cls.evidence.command(['git', '-C', str(cls.repository), 'archive', '--format=tar', c.SOURCE],
            time.monotonic() + 5, stdout_limit=accepted.MAX_FILESYSTEM)[0]
        cls.projected_bytes = {}
        with tarfile.open(fileobj=io.BytesIO(archive)) as source:
            for member in source:
                if member.isfile():
                    for target in cls.evidence.docker_copy_targets(member.name):
                        cls.projected_bytes[target] = source.extractfile(member).read()

    def model(self):
        context = c.Context(self.preparation_commit, self.preparation_tree,
                            301, 1, 'synthetic-daemon', '3' * 64, '4' * 32)
        tool_lock = copy.deepcopy(self.lock)
        tool_lock['unbound_static_inputs'] = {
            'base_image_id': 'sha256:' + '5' * 64, 'base_config_sha256': '6' * 64, 'platform': 'linux/amd64',
            'library_inventory': {'synthetic-lib': '1.0'},
            'browser_binary_hashes': {name: '7' * 64 for name in recipe.BROWSERS}}
        actual_tool = {'node': tool_lock['node'], 'python': tool_lock['python'], 'packages': tool_lock['packages'],
            'browsers_source_sha256': tool_lock['browsers_source_sha256'], 'browsers': tool_lock['browsers'],
            **tool_lock['unbound_static_inputs']}
        app = {'Id': 'sha256:' + '8' * 64, 'Os': 'linux', 'Architecture': 'amd64',
               'Config': {'Labels': {'org.opencontainers.image.revision': c.SOURCE,
                                    'dev.durable-workflow.release.tag': '2.15.1'}}}
        tool = {'Id': 'sha256:' + '9' * 64, 'Os': 'linux', 'Architecture': 'amd64',
                'Config': {'Labels': {'dev.durable-workflow.tool-closure': c.digest(c.canonical(tool_lock))}}}
        host = producer.HostObservation(context, app, tool,
            c.canonical({'packages': self.packages, 'dev': False, 'dev-package-names': []}),
            self.expected['files'], self.expected['classes'], '8.3.36', 8, actual_tool,
            {'cpus': 2, 'memory': 3 * 1024 ** 3, 'pids': 256, 'jobs': 2,
             'scripts': False, 'plugins': False, 'request_concurrency': 2,
             'effective_trust_verified': True, 'network_bytes': 100, 'storage_bytes': 100},
            'a' * 64, 'b' * 64, True)
        admission = {'platform': 'linux/amd64', 'app_config': copy.deepcopy(app['Config']),
                     'tool_config': copy.deepcopy(tool['Config']), 'ca_sha256': host.ca_sha256,
                     'preparation_tool_config_sha256': host.preparation_tool_config_sha256}
        report = producer.produce(host, self.expected, tool_lock, self.evidence, admission, self.authority)
        archive = self.archive(c.canonical(report))
        run = {'id': context.run_id, 'run_attempt': context.attempt, 'head_sha': context.preparation_commit,
               'path': c.WORKFLOW, 'repository': {'full_name': c.REPOSITORY},
               'status': 'in_progress', 'conclusion': None, 'event': 'workflow_dispatch'}
        artifact = {'id': 701, 'name': context.artifact_name(), 'expired': False, 'size_in_bytes': len(archive),
                    'workflow_run': {'id': context.run_id, 'head_sha': context.preparation_commit},
                    'digest': 'sha256:' + c.digest(archive)}
        return host, tool_lock, admission, report, archive, run, artifact

    def archive(self, raw, name='preparation-report.json', mode=stat.S_IFREG | 0o600, duplicate=False):
        buffer = io.BytesIO()
        with zipfile.ZipFile(buffer, 'w', compression=zipfile.ZIP_DEFLATED) as archive:
            info = zipfile.ZipInfo(name)
            info.create_system = 3
            info.external_attr = mode << 16
            archive.writestr(info, raw)
            if duplicate:
                with warnings.catch_warnings():
                    warnings.simplefilter('ignore', UserWarning)
                    archive.writestr(info, raw)
        return buffer.getvalue()

    def application_tar(self, installed, mutate=None):
        values = {name: (raw, self.expected['files'][name]['mode'], tarfile.REGTYPE)
                  for name, raw in self.projected_bytes.items()}
        values['vendor/composer/installed.json'] = (installed, 0o644, tarfile.REGTYPE)
        if mutate:
            mutate(values)
        output = io.BytesIO()
        with tarfile.open(fileobj=output, mode='w:') as archive:
            for name, (raw, mode, kind) in values.items():
                info = tarfile.TarInfo('./' + name)
                info.mode, info.type, info.size = mode, kind, len(raw)
                archive.addfile(info, io.BytesIO(raw))
        return output.getvalue()

    def verification_container(self, model):
        plan = synthetic.plan('container')
        plan['owner'] = model[0].context.nonce
        value = synthetic.value(plan)
        value.update(Image=model[0].app['Id'], State={'Running': True, 'OOMKilled': False},
            NetworkSettings={'Networks': {'none': {'IPAddress': ''}}}, Mounts=[])
        value['Config'].update(User='82:82', Env=None, Entrypoint=['sleep'], Cmd=['1200'], WorkingDir='/app')
        value['HostConfig'] = {'NetworkMode': 'none', 'ReadonlyRootfs': True, 'Privileged': False,
            'NanoCpus': 2_000_000_000, 'Memory': 3 * 1024 ** 3, 'MemorySwap': 3 * 1024 ** 3,
            'PidsLimit': 256, 'CapDrop': ['ALL'], 'SecurityOpt': ['no-new-privileges'],
            'RestartPolicy': {'Name': 'no', 'MaximumRetryCount': 0}, 'PidMode': '', 'IpcMode': 'private',
            'Tmpfs': dict(accepted.TMPFS)}
        return plan, value

    def capture_application(self, model, container, plan, archive, collector, calls):
        reader = accepted.ApplicationReader(self.evidence, model[0].context, self.authority, 10 ** 20)
        identity = {**self.expected, 'core_installed': False, 'installed_json_sha256': c.digest(model[0].installed_raw)}
        def transport(*args, **kwargs):
            calls.append(args)
            if args[0] == 'container':
                return c.canonical([container])
            if args[0] == 'image':
                return c.canonical([model[0].app])
            if args[0] == 'cp':
                return archive
            self.assertEqual(args[0], 'exec')
            self.assertEqual(kwargs['input_bytes'], collector)
            return c.canonical(identity)
        with mock.patch.object(reader, 'docker', side_effect=transport), mock.patch.object(c, 'EXECUTION_ENABLED', True):
            return reader.capture(container['Id'], plan, model[0].app['Id'], model[2]['app_config'],
                                  self.expected, collector)

    def test_application_reader_uses_daemon_files_before_offline_autoload(self):
        model = self.model()
        plan, container = self.verification_container(model)
        collector = self.git('show', c.PACKET_COMMIT + ':scripts/ci/source-smoke-evidence/collector.php')
        calls = []
        result = self.capture_application(model, container, plan,
            self.application_tar(model[0].installed_raw), collector, calls)
        self.assertEqual(result['files'], self.expected['files'])
        self.assertEqual(result['installed_raw'], model[0].installed_raw)
        self.assertTrue(result['network_removed_before_autoload'])
        positions = [entry[0] for entry in calls]
        self.assertLess(positions.index('cp'), positions.index('exec'))
        self.assertEqual(positions.count('container'), 3)
        self.assertEqual(positions.count('image'), 2)

    def test_application_reader_refuses_network_env_mount_files_and_metadata_before_autoload(self):
        model = self.model()
        collector = self.git('show', c.PACKET_COMMIT + ':scripts/ci/source-smoke-evidence/collector.php')
        for mutate in [lambda x: x['HostConfig'].update(NetworkMode='host'),
                       lambda x: x['NetworkSettings']['Networks'].update(bridge={'IPAddress': '192.0.2.1'}),
                       lambda x: x['HostConfig'].update(ReadonlyRootfs=False),
                       lambda x: x['Config'].update(Env=['SYNTHETIC_PROVIDER_AUTH=forbidden']),
                       lambda x: x['Mounts'].append({'Type': 'bind', 'Source': '/synthetic', 'Destination': '/host'})]:
            plan, container = self.verification_container(model)
            mutate(container)
            calls = []
            self.assertRaises(c.Refusal, self.capture_application, model, container, plan,
                self.application_tar(model[0].installed_raw), collector, calls)
            self.assertFalse(any(entry[0] == 'exec' for entry in calls))
        for mutate in [lambda x: x.update({'artisan': (b'changed', 0o755, tarfile.REGTYPE)}),
                       lambda x: x.update({'extra.php': (b'extra', 0o644, tarfile.REGTYPE)}),
                       lambda x: x.update({'artisan': (b'', 0o755, tarfile.SYMTYPE)}),
                       lambda x: x.update({'vendor/composer/installed.json': (b'{}', 0o644, tarfile.REGTYPE)})]:
            plan, container = self.verification_container(model)
            calls = []
            self.assertRaises(c.Refusal, self.capture_application, model, container, plan,
                self.application_tar(model[0].installed_raw, mutate), collector, calls)
            self.assertFalse(any(entry[0] == 'exec' for entry in calls))

    def test_live_application_reader_hold_precedes_even_daemon_inventory(self):
        model = self.model()
        evidence = mock.Mock()
        reader = accepted.ApplicationReader(evidence, model[0].context, self.authority, 10 ** 20)
        self.assertRaisesRegex(c.Refusal, 'HELD', reader.docker, 'info')
        evidence.command.assert_not_called()

    def test_application_reader_rejects_foreign_ownership_before_inventory(self):
        model = self.model()
        plan, container = self.verification_container(model)
        plan['owner'] = 'f' * 32
        reader = accepted.ApplicationReader(self.evidence, model[0].context, self.authority, 10 ** 20)
        with mock.patch.object(reader, 'docker') as docker, mock.patch.object(c, 'EXECUTION_ENABLED', True):
            self.assertRaisesRegex(c.Refusal, 'ownership missing', reader.capture, container['Id'], plan,
                model[0].app['Id'], model[2]['app_config'], self.expected, b'not a collector')
            docker.assert_not_called()

    def bound(self, values):
        host, lock, admission, _report, archive, run, artifact = values
        return binder.bind(run, artifact, archive, host.context, host, self.expected, lock,
                           self.evidence, admission, self.authority)

    def test_real_old_recipe_refuses_new_separate_contract_and_derived_recipe_passes(self):
        original = subprocess.check_output(['git', '-C', str(self.repository), 'show', c.SOURCE + ':Dockerfile'])
        self.assertRaisesRegex(c.Refusal, 'recipe drift', recipe.validate_recipe, original, original)
        proposed = (self.folder / 'preparation.Dockerfile').read_bytes()
        self.assertEqual(recipe.derive(original), proposed)
        recipe.validate_recipe(original, proposed)
        self.assertIn(b'COMPOSER_MAX_PARALLEL_HTTP=2', proposed)
        self.assertIn(b'--no-plugins --no-autoloader', proposed)
        self.assertIn(b'RUN --network=none composer dump-autoload', proposed)
        self.assertIn(b'RUN --network=none php -r', proposed)
        self.assertIn(b'USER 82:82', proposed)
        self.assertIn(b'FROM php@sha256:2424931b', proposed)
        def directories(raw):
            continued = raw.decode().replace('\\\n', ' ')
            command = re.search(r'mkdir -p (.*?)\s+&& chown', continued, re.DOTALL).group(1)
            return shlex.split(command)
        self.assertEqual(directories(original), directories(proposed))
        self.assertRaises(c.Refusal, recipe.derive, original + b'\n# changed source\n')

    def test_current_manifests_do_not_fabricate_any_operational_input(self):
        recipe.validate_tool(self.lock)
        self.assertTrue(all(value is None for value in self.lock['unbound_static_inputs'].values()))
        self.assertRaisesRegex(c.Refusal, 'UNBOUND', recipe.validate_tool, self.lock, True)
        self.assertRaisesRegex(c.Refusal, 'UNBOUND', c.require_static_admission, c.unbound_admission())
        spec = c.unbound_spec()
        self.assertEqual(len(spec), 9)
        self.assertEqual(sum(value is None for value in spec.values()), 6)
        self.assertRaisesRegex(c.Refusal, 'UNBOUND', c.require_complete_spec, spec)

    def test_source_package_closure_rejects_floating_extra_and_changed_integrities(self):
        for mutate in [lambda x: x['packages']['playwright'].update(version='latest'),
                       lambda x: x['packages'].update(extra={}),
                       lambda x: x['packages']['playwright'].update(dependencies={}),
                       lambda x: x['packages']['playwright'].update(integrity='sha512-' + 'A' * 86 + '=='),
                       lambda x: x['browsers'][0].update(revision='1'),
                       lambda x: x.update(browsers_source_sha256='0' * 64)]:
            with self.subTest(mutate=mutate):
                value = copy.deepcopy(self.lock)
                mutate(value)
                self.assertRaises(ValueError, recipe.validate_tool, value)

    def test_provider_digest_and_independent_host_facts_form_exact_receipt_and_spec(self):
        values = self.model()
        receipt, spec = self.bound(values)
        c.require_complete_spec(spec)
        parsed = c.bounded_json(receipt)
        self.assertEqual(parsed['provider_artifact_id'], 701)
        self.assertEqual(parsed['graph'], self.expected['graph'])
        self.assertEqual(spec['image_id'], values[0].app['Id'])
        self.assertEqual(spec['vendor_preparation_receipt_sha256'], c.digest(receipt))
        self.assertEqual(spec['packet_commit'], c.PACKET_COMMIT)
        self.assertEqual(parsed['preparation'], self.authority.identity(values[0].context))
        self.assertEqual(parsed['context'], values[0].context.report_context())
        self.assertEqual(parsed['provider_run_attempt'], values[0].context.attempt)

    def test_shared_fabricated_tree_refuses_at_git_custody_producer_and_binder(self):
        model = self.model()
        context = dataclasses.replace(model[0].context, preparation_tree='f' * 40)
        false_host = dataclasses.replace(model[0], context=context)
        self.assertRaisesRegex(c.Refusal, 'tree mismatch', custody.SourceAuthority,
            self.git, self.preparation_commit, context.preparation_tree, self.manifest_sha256)
        self.assertRaisesRegex(c.Refusal, 'source custody mismatch', producer.produce,
            false_host, self.expected, model[1], self.evidence, model[2], self.authority)
        report = {**model[3], 'context': context.report_context(),
                  'preparation': {**model[3]['preparation'], 'tree': context.preparation_tree}}
        archive = self.archive(c.canonical(report))
        artifact = {**model[6], 'digest': 'sha256:' + c.digest(archive), 'size_in_bytes': len(archive)}
        self.assertRaisesRegex(c.Refusal, 'source custody mismatch', binder.bind,
            model[5], artifact, archive, context, false_host, self.expected,
            model[1], self.evidence, model[2], self.authority)

    def test_previous_source_actually_accepts_the_shared_false_tree(self):
        # Execute only the immutable prior pure contracts, never its held CLI.
        program = '''import dataclasses,pathlib,subprocess,sys,types
repository=sys.argv[1]
for name in ('contract','accepted','recipe','producer','binder','cell','test_preparation'):
 raw=subprocess.check_output(['git','-C',repository,'show',
  '65d9a5b929f8eaf37a0495d5bb5afe7607a9cffd:scripts/ci/source-smoke-preparation/'+name+'.py'],timeout=5)
 module=types.ModuleType(name)
 module.__file__=str(pathlib.Path(repository)/'scripts/ci/source-smoke-preparation'/(name+'.py'))
 sys.modules[name]=module
 exec(compile(raw,module.__file__,'exec'),module.__dict__)
t=sys.modules['test_preparation'];t.PreparationTests.setUpClass();fixture=t.PreparationTests()
host,lock,admission,report,archive,run,artifact=fixture.model()
context=dataclasses.replace(host.context,preparation_tree='f'*40)
host=dataclasses.replace(host,context=context)
c=sys.modules['contract'];p=sys.modules['producer'];b=sys.modules['binder']
report=p.produce(host,fixture.expected,lock,fixture.evidence,admission)
archive=fixture.archive(c.canonical(report))
artifact={**artifact,'digest':'sha256:'+c.digest(archive),'size_in_bytes':len(archive)}
receipt,spec=b.bind(run,artifact,archive,context,host,fixture.expected,lock,fixture.evidence,admission)
c.require_complete_spec(spec)
assert 'preparation' not in c.bounded_json(receipt)
print('OLD-ACCEPT: fabricated shared tree produced complete unbound-identity spec')
'''
        result = subprocess.run([sys.executable, '-I', '-c', program, str(self.repository)],
                                capture_output=True, check=True, timeout=15)
        self.assertIn(b'OLD-ACCEPT', result.stdout)

    def test_lifecycle_requires_source_and_cell_context_in_receipt_and_collector_final(self):
        model = self.model()
        context = model[0].context
        guardian = {'context': context.report_context(), 'ready': True, 'alive': True, 'deadline': 2800}
        receipt, spec = self.bound(model)
        parsed = c.bounded_json(receipt)
        for section, field, value in [('preparation', 'tree', 'f' * 40),
                                      ('preparation', 'manifest_sha256', 'f' * 64),
                                      ('context', 'nonce', 'f' * 32), ('context', 'attempt', 2),
                                      ('context', 'daemon_id', 'other-daemon'),
                                      ('context', 'runner_binding', 'f' * 64)]:
            with self.subTest(section=section, field=field):
                state = cell.Lifecycle(context, c.CellBudget(100), self.authority)
                state.arm(101, guardian)
                changed = copy.deepcopy(parsed)
                changed[section][field] = value
                raw = c.canonical(changed)
                changed_spec = {**spec, 'vendor_preparation_receipt_sha256': c.digest(raw)}
                self.assertRaisesRegex(c.Refusal, 'source/cell identity', state.bind, raw, changed_spec, context, 200)
        state = cell.Lifecycle(context, c.CellBudget(100), self.authority)
        state.arm(101, guardian)
        state.bind(receipt, spec, context, 200)
        final = {'schema': 'waterline.disabled-source-smoke-evidence/v1',
                 'expected': {'source': c.SOURCE, 'admission': spec, 'vendor_preparation': parsed}}
        for mutate in [lambda x: x['expected']['vendor_preparation']['context'].update(daemon_id='other-daemon'),
                       lambda x: x['expected']['vendor_preparation'].pop('preparation'),
                       lambda x: x['expected']['admission'].update(image_id='sha256:' + 'f' * 64)]:
            changed = copy.deepcopy(final)
            mutate(changed)
            raw = c.canonical(changed)
            self.assertRaisesRegex(c.Refusal, 'final source/cell identity', state.begin_cleanup, 300, raw, c.digest(raw))
        raw = c.canonical(final)
        self.assertRaisesRegex(c.Refusal, 'final bytes mismatch', state.begin_cleanup, 300, raw, '0' * 64)
        state.begin_cleanup(300, raw, c.digest(raw))
        self.assertEqual(state.collection_sha256, c.digest(raw))

    def test_authenticated_digest_cannot_authorize_candidate_report_lies(self):
        for field, bad in [('source', '0' * 40), ('lock', '0' * 64), ('files_sha256', '0' * 64),
                           ('installed_json_sha256', '0' * 64), ('plugins', True), ('request_concurrency', 20),
                           ('app', {'id': 'sha256:' + 'f' * 64})]:
            with self.subTest(field=field):
                values = list(self.model())
                values[3][field] = bad
                values[4] = self.archive(c.canonical(values[3]))
                values[6]['digest'] = 'sha256:' + c.digest(values[4])
                values[6]['size_in_bytes'] = len(values[4])
                self.assertRaisesRegex(c.Refusal, 'independent live host facts', self.bound, values)

    def test_provider_cross_run_attempt_source_and_expiry_refuse(self):
        cases = [('run', 'id', 999), ('run', 'run_attempt', 2), ('run', 'head_sha', 'f' * 40),
                 ('run', 'path', '.github/workflows/service-image.yml'), ('run', 'event', 'pull_request'),
                 ('run', 'status', 'completed'), ('run', 'conclusion', 'success'),
                 ('artifact', 'id', True), ('artifact', 'expired', True),
                 ('artifact', 'name', 'old-receipt'), ('artifact', 'size_in_bytes', c.MAX_ARCHIVE + 1),
                 ('artifact', 'digest', 'sha256:' + '0' * 64)]
        for target, key, value in cases:
            with self.subTest(target=target, key=key):
                values = list(self.model())
                values[5 if target == 'run' else 6][key] = value
                self.assertRaises(c.Refusal, self.bound, values)

    def test_new_runner_or_daemon_cannot_adopt_metadata_retained_from_old_cell(self):
        for field, value in [('daemon_id', 'different-daemon'), ('runner_binding', 'e' * 64),
                             ('nonce', 'e' * 32), ('attempt', 2)]:
            with self.subTest(field=field):
                values = self.model()
                host = dataclasses.replace(values[0], context=dataclasses.replace(values[0].context, **{field: value}))
                self.assertRaisesRegex(c.Refusal, 'Cross-runner', binder.bind,
                    values[5], values[6], values[4], values[0].context, host,
                    self.expected, values[1], self.evidence, values[2], self.authority)

    def test_host_rechecks_source_config_tool_policy_and_offline_boundary(self):
        cases = [('network_removed_before_autoload', False), ('php', '8.4.0'), ('int_size', 4),
                 ('files', {}), ('classes', {}), ('ca_sha256', 'e' * 64),
                 ('preparation_tool_config_sha256', 'e' * 64), ('installed_raw', b'{}')]
        for field, value in cases:
            with self.subTest(field=field):
                model = self.model()
                host = dataclasses.replace(model[0], **{field: value})
                self.assertRaises(c.Refusal, producer.produce, host, self.expected, model[1], self.evidence,
                                  model[2], self.authority)
        for field, value in [('plugins', True), ('scripts', True), ('request_concurrency', 3),
                             ('cpus', 0), ('memory', 0), ('pids', 0), ('jobs', 3),
                             ('network_bytes', c.MAX_NETWORK + 1), ('storage_bytes', c.MAX_STORAGE + 1)]:
            with self.subTest(policy=field):
                model = self.model()
                policy = {**model[0].policy, field: value}
                self.assertRaises(c.Refusal, producer.produce, dataclasses.replace(model[0], policy=policy),
                                  self.expected, model[1], self.evidence, model[2], self.authority)

    def test_raw_metadata_duplicate_keys_and_authority_drift_refuse(self):
        model = self.model()
        for raw in [b'{"packages":[],"packages":[]}', b'{}' * (c.MAX_REPORT // 2 + 1)]:
            self.assertRaises(ValueError, producer.produce, dataclasses.replace(model[0], installed_raw=raw),
                              self.expected, model[1], self.evidence, model[2], self.authority)
        installed = json.loads(model[0].installed_raw)
        installed['packages'][0]['dist']['reference'] = 'different'
        self.assertRaises(c.Refusal, producer.produce,
            dataclasses.replace(model[0], installed_raw=c.canonical(installed)),
            self.expected, model[1], self.evidence, model[2], self.authority)

    def test_provider_archive_extra_link_escape_and_size_are_refused(self):
        for raw in [self.archive(b'{}', name='../escape'), self.archive(b'{}', mode=stat.S_IFLNK | 0o777),
                    self.archive(b'{}', duplicate=True), self.archive(b'x' * (c.MAX_REPORT + 1)), b'not-a-zip']:
            with self.subTest(size=len(raw)):
                self.assertRaises(ValueError, binder.report_bytes, raw, {'digest': 'sha256:' + c.digest(raw)})

    def test_cell_budget_covers_both_phases_and_requires_guardian_before_allocation(self):
        context = self.model()[0].context
        state = cell.Lifecycle(context, c.CellBudget(100), self.authority)
        self.assertRaises(c.Refusal, state.allocation, 'waterline-test', {'id': 'x'}, 101, True)
        guardian = {'context': context.report_context(), 'ready': True, 'alive': True, 'deadline': 2800}
        state.arm(101, guardian)
        state.allocation('waterline-preparation', {'id': 'synthetic'}, 102, True)
        self.assertRaises(c.Refusal, state.allocation, 'waterline-preparation', {'id': 'synthetic'}, 103, True)
        receipt, spec = self.bound(self.model())
        state.bind(receipt, spec, context, 1200)
        self.assertEqual(state.deadline, 2400)
        self.assertEqual(state.budget.final_deadline(), 2800)
        self.assertRaises(c.Refusal, state.allocation, 'waterline-late', {'id': 'x'}, 2400, True)
        final = c.canonical({'schema': 'waterline.disabled-source-smoke-evidence/v1',
            'expected': {'source': c.SOURCE, 'admission': spec, 'vendor_preparation': c.bounded_json(receipt)}})
        state.begin_cleanup(2400, final, c.digest(final))
        absence = {'containers': [], 'networks': [], 'volumes': [], 'images': [], 'processes': []}
        self.assertRaises(c.Refusal, state.finish, 2801, absence, 0)
        self.assertRaises(c.Refusal, state.finish, 2700, absence, -15)
        self.assertRaises(c.Refusal, state.finish, 2700, {**absence, 'images': ['remaining']}, 0)
        state.finish(2700, absence, 0)

    def test_same_job_order_refuses_separate_run_and_oci_transport(self):
        plan = cell.supported_handoff()
        cell.validate_handoff(plan)
        for key, value in [('jobs', 2), ('same_daemon', False), ('image_transport', ['oci-archive']),
                           ('provider_authority_in_candidate', True), ('global_seconds', 2701),
                           ('steps', list(reversed(plan['steps'])))]:
            self.assertRaises(c.Refusal, cell.validate_handoff, {**plan, key: value})

    def test_literal_bootstrap_hold_precedes_every_helper_import_and_environment_override(self):
        with tempfile.TemporaryDirectory() as directory:
            folder = pathlib.Path(directory)
            marker = folder / 'unexpected'
            (folder / 'bootstrap.py').write_bytes((self.folder / 'bootstrap.py').read_bytes())
            for name in ('contract.py', 'producer.py', 'binder.py', 'cell.py'):
                (folder / name).write_text('raise AssertionError("unreviewed helper import")')
            result = subprocess.run([sys.executable, '-I', str(folder / 'bootstrap.py')], capture_output=True, timeout=3)
            self.assertNotEqual(result.returncode, 0)
            self.assertIn(b'HELD', result.stderr)
            self.assertFalse(marker.exists())
        with mock.patch.dict('os.environ', {'EXECUTION_ENABLED': 'true', 'WATERLINE_SOURCE_PREPARATION_PROPOSAL': '1'}):
            self.assertRaisesRegex(c.Refusal, 'HELD', cell.execute_cell)
            self.assertRaisesRegex(c.Refusal, 'HELD', c.main)

    def test_immutable_launcher_ignores_worktree_helpers_and_keeps_operational_hold(self):
        context = self.model()[0].context
        before = {name: sys.modules.get(name) for name in bootstrap.BOOTSTRAP_MODULES}
        program = bootstrap.reviewed_program(self.repository, self.preparation_commit,
            self.preparation_tree, self.manifest_sha256, context)
        self.assertEqual(before, {name: sys.modules.get(name) for name in before})
        with tempfile.TemporaryDirectory() as directory:
            folder = pathlib.Path(directory)
            marker = folder / 'unreviewed-import'
            for name in bootstrap.MODULES:
                (folder / (name + '.py')).write_text('raise AssertionError("mutable worktree helper imported")')
            result = subprocess.run([sys.executable, '-I', '-'], input=program, cwd=folder,
                                    capture_output=True, timeout=10)
            self.assertNotEqual(result.returncode, 0)
            self.assertIn(b'HELD', result.stderr)
            self.assertNotIn(b'mutable worktree helper', result.stderr)
            self.assertFalse(marker.exists())
        bad_context = dataclasses.replace(context, preparation_tree='f' * 40)
        self.assertRaises(ValueError, bootstrap.reviewed_program, self.repository, self.preparation_commit,
                          self.preparation_tree, self.manifest_sha256, bad_context)
        self.assertRaises(ValueError, bootstrap.reviewed_program, self.repository, self.preparation_commit,
                          'f' * 40, self.manifest_sha256, context)
        with mock.patch.object(bootstrap, 'load') as load:
            self.assertRaisesRegex(ValueError, 'launcher manifest mismatch', bootstrap.reviewed_program,
                self.repository, self.preparation_commit, self.preparation_tree, '0' * 64, context)
            load.assert_not_called()

    def test_immutable_launcher_rejects_changed_source_bytes_before_module_import(self):
        context = self.model()[0].context
        program = bootstrap.reviewed_program(self.repository, self.preparation_commit,
            self.preparation_tree, self.manifest_sha256, context).decode()
        value = ast.literal_eval(ast.parse(program).body[1].value.args[0])
        payload = json.loads(value)
        payload['sources']['cell.py'] = base64.b64encode(b'raise AssertionError("unreviewed source executed")').decode()
        changed = json.dumps(payload, sort_keys=True, separators=(',', ':'))
        program = program.replace(repr(value), repr(changed), 1)
        result = subprocess.run([sys.executable, '-I', '-'], input=program.encode(), capture_output=True, timeout=5)
        self.assertNotEqual(result.returncode, 0)
        self.assertIn(b'sealed source bytes mismatch', result.stderr)
        self.assertNotIn(b'unreviewed source executed', result.stderr)


if __name__ == '__main__':
    unittest.main()
