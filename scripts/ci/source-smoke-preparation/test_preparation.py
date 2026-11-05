"""Pure countercontrols. Real application source, synthetic provider/daemon facts."""
import copy
import dataclasses
import io
import json
import pathlib
import stat
import subprocess
import sys
import tempfile
import unittest
import warnings
import zipfile
from unittest import mock

sys.path.insert(0, str(pathlib.Path(__file__).parent))
import accepted
import binder
import cell
import contract as c
import producer
import recipe


class PreparationTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.folder = pathlib.Path(__file__).resolve().parent
        cls.repository = cls.folder.parents[2]
        cls.evidence = accepted.accepted_evidence(cls.repository)
        cls.expected = cls.evidence.expected_from_git(cls.repository)
        cls.lock = json.loads((cls.folder / 'browser-tool-lock.json').read_text())
        cls.packages = json.loads(subprocess.check_output(['git', '-C', str(cls.repository),
            'show', c.SOURCE + ':standalone/composer.lock']))['packages']

    def model(self):
        context = c.Context('1' * 40, '2' * 40, 301, 1, 'synthetic-daemon', '3' * 64, '4' * 32)
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
        report = producer.produce(host, self.expected, tool_lock, self.evidence, admission)
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

    def bound(self, values):
        host, lock, admission, _report, archive, run, artifact = values
        return binder.bind(run, artifact, archive, host.context, host, self.expected, lock, self.evidence, admission)

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
                    self.expected, values[1], self.evidence, values[2])

    def test_host_rechecks_source_config_tool_policy_and_offline_boundary(self):
        cases = [('network_removed_before_autoload', False), ('php', '8.4.0'), ('int_size', 4),
                 ('files', {}), ('classes', {}), ('ca_sha256', 'e' * 64),
                 ('preparation_tool_config_sha256', 'e' * 64), ('installed_raw', b'{}')]
        for field, value in cases:
            with self.subTest(field=field):
                model = self.model()
                host = dataclasses.replace(model[0], **{field: value})
                self.assertRaises(c.Refusal, producer.produce, host, self.expected, model[1], self.evidence, model[2])
        for field, value in [('plugins', True), ('scripts', True), ('request_concurrency', 3),
                             ('cpus', 0), ('memory', 0), ('pids', 0), ('jobs', 3),
                             ('network_bytes', c.MAX_NETWORK + 1), ('storage_bytes', c.MAX_STORAGE + 1)]:
            with self.subTest(policy=field):
                model = self.model()
                policy = {**model[0].policy, field: value}
                self.assertRaises(c.Refusal, producer.produce, dataclasses.replace(model[0], policy=policy),
                                  self.expected, model[1], self.evidence, model[2])

    def test_raw_metadata_duplicate_keys_and_authority_drift_refuse(self):
        model = self.model()
        for raw in [b'{"packages":[],"packages":[]}', b'{}' * (c.MAX_REPORT // 2 + 1)]:
            self.assertRaises(ValueError, producer.produce, dataclasses.replace(model[0], installed_raw=raw),
                              self.expected, model[1], self.evidence, model[2])
        installed = json.loads(model[0].installed_raw)
        installed['packages'][0]['dist']['reference'] = 'different'
        self.assertRaises(c.Refusal, producer.produce,
            dataclasses.replace(model[0], installed_raw=c.canonical(installed)),
            self.expected, model[1], self.evidence, model[2])

    def test_provider_archive_extra_link_escape_and_size_are_refused(self):
        for raw in [self.archive(b'{}', name='../escape'), self.archive(b'{}', mode=stat.S_IFLNK | 0o777),
                    self.archive(b'{}', duplicate=True), self.archive(b'x' * (c.MAX_REPORT + 1)), b'not-a-zip']:
            with self.subTest(size=len(raw)):
                self.assertRaises(ValueError, binder.report_bytes, raw, {'digest': 'sha256:' + c.digest(raw)})

    def test_cell_budget_covers_both_phases_and_requires_guardian_before_allocation(self):
        context = self.model()[0].context
        state = cell.Lifecycle(context, c.CellBudget(100))
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
        state.begin_cleanup(2400, True)
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


if __name__ == '__main__':
    unittest.main()
