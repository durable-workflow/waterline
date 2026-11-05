"""Fictional private-provider facts and inert archive/transport controls only."""

import copy
import argparse
import dataclasses
import importlib.util
import json
from pathlib import Path
import subprocess
import sys
import tempfile
import unittest
import zipfile

sys.path.insert(0, str(Path(__file__).parent))
import adapter
import base

ROOT = Path(__file__).resolve().parents[3]


def selection():
    nonce = '4' * 32
    identity = {'repository': {'id': 42, 'node_id': 'R_FICTIONAL',
                              'full_name': 'example-company/private-fixture',
                              'private': True, 'visibility': 'private'},
                'workflow': {'path': '.github/workflows/fictional-build.yml',
                             'commit': 'a' * 40, 'tree': 'b' * 40, 'sha256': 'c' * 64},
                'event': 'workflow_dispatch', 'ref': 'refs/heads/main', 'head_sha': 'a' * 40,
                'run_id': 301, 'attempt': 1, 'job_id': 401, 'job_name': 'fictional-build', 'runner_binding': '3' * 64,
                'daemon_id': 'fictional-daemon', 'nonce': nonce}
    root = '/fixture/quarantine/' + nonce
    value = {'schema': 'waterline.private-build-source-input/v1', 'new_task': 'new-sealed-source-build-only',
             'adapter_authority': {'commit': 'c' * 40, 'tree': 'd' * 40, 'manifest_sha256': '5' * 64},
             'origin': identity, 'audience': [{'id': 7, 'login': 'fixture-admin', 'permission': 'admin'}],
             'architecture': 'amd64', 'build_environment': {'PATH': '/usr/local/bin:/usr/bin:/bin',
                 'HOME': root + '/home', 'DOCKER_CONFIG': root + '/anonymous-docker'},
             'tools_sha256': 'd' * 64, 'parent_sha256': 'e' * 64,
             'resources_sha256': 'f' * 64, 'transport_sha256': '1' * 64,
             'stop_map_sha256': '2' * 64, 'executor': adapter.executor_policy('amd64'),
             'root': root, 'cleanup': adapter.cleanup_policy(nonce),
             'retained_stops': dict.fromkeys(adapter.STOPS, True), 'effects': dict.fromkeys(adapter.EFFECTS)}
    observed = {key: copy.deepcopy(value[key]) for key in
                ('adapter_authority', 'origin', 'audience', 'build_environment', 'tools_sha256', 'parent_sha256',
                 'resources_sha256', 'transport_sha256', 'stop_map_sha256')}
    observed['docker_config'] = b'{}\n'
    observed['runtime_identity'] = adapter.runtime_identity(identity)
    observed['token_route'] = adapter.token_route(identity)
    return value, observed


class PrivateAdapterTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.scope = base.primitives(ROOT)
        cls.p = cls.scope.__enter__()
        cls.expected = cls.p.accepted.accepted_evidence(ROOT).expected_from_git(ROOT)
        cls.capture_fixture = cls.p.bootstrap.load('private_capture_fixture',
                                                   cls.p.raw[base.PREFIX + 'test_capture.py'])
        spec = importlib.util.spec_from_file_location('private_oci_fixture', ROOT / 'scripts/ci/test-oci-release.py')
        cls.oci = importlib.util.module_from_spec(spec)
        spec.loader.exec_module(cls.oci)

    @classmethod
    def tearDownClass(cls):
        cls.scope.__exit__(None, None, None)

    def inputs(self, value=None, observed=None):
        if value is None:
            value, observed = selection()
        value['transport_sha256'] = observed['transport_sha256'] = self.capture_fixture.synthetic_policy().identity
        context = adapter.private_context(value, observed)
        ledger = self.p.proxy.ProxyLedger(context, self.p.authority, deadline=200, clock=lambda: 100)
        _policy, _pool, _reader, sealed = self.capture_fixture.captured_fixture(
            context, self.p.authority, self.expected, ledger)
        self.assertEqual(_policy.identity, value['transport_sha256'])
        ledger.seal()
        contents = {self.p.capture.composer_cache_path(name, package): self.capture_fixture.BODY
                    for name, package in self.expected['graph'].items()}
        prepared = adapter.prepare(self.p, value, observed, context, sealed, ledger, contents)
        return prepared, contents, sealed, ledger

    def output(self, prepared, mutate=None):
        bindings = json.loads(prepared.bindings)
        def bind(parts):
            parts['provenance']['subject'] = [{'name': 'image', 'digest': {
                'sha256': parts['image_descriptor']['digest'].split(':')[1]}}]
            args = parts['provenance']['predicate']['buildDefinition']['externalParameters']['request']['args']
            for field, argument in self.p.contract.HOSTED_BUILD_ARGUMENTS:
                args['build-arg:' + argument] = bindings[field]
            if mutate:
                mutate(parts)
        with tempfile.TemporaryDirectory(prefix='waterline-private-synthetic-') as folder:
            archive, _entries, _manifest, _config, _attestation = self.oci.native_archive(
                Path(folder), json.loads(prepared.selection)['architecture'],
                source=self.p.contract.SOURCE, mutate=bind)
            raw = archive.read_bytes()
        return {'origin': json.loads(prepared.selection)['origin'], 'command': prepared.command,
                'stdin_sha256': bindings['context_sha256'], 'invocations': 1, 'archive': raw,
                'stdout': b'fictional original stdout\n', 'stderr': b'fictional original stderr\n',
                'exit_code': 0, 'timed_out': False, 'oom_killed': False,
                'started_at': '2026-01-01T00:00:00Z', 'completed_at': '2026-01-01T00:01:00Z'}

    def test_private_origin_is_not_relabelled_public_context(self):
        prepared, _contents, _sealed, _ledger = self.inputs()
        report = prepared.context.report_context()
        self.assertEqual(report['producer']['repository']['full_name'], 'example-company/private-fixture')
        self.assertEqual(report['code_authority']['commit'], base.COMMIT)
        self.assertNotEqual(report['producer']['head_sha'], report['code_authority']['commit'])
        public = self.p.contract.Context(base.COMMIT, base.TREE, 301, 1, 'fictional-daemon', '3' * 64, '4' * 32)
        self.assertNotEqual(report, public.report_context())
        wrong = self.p.producer.HostedBuildInputs(prepared.context, self.p.authority, _sealed, _ledger,
                                                 _contents, b'', 'amd64', b'')
        self.assertRaises(self.p.contract.Refusal, self.p.producer.hosted_inputs, wrong, ROOT)

    def test_private_origin_and_each_actual_attempt_field_are_bound(self):
        selected, observed = selection()
        self.assertEqual(adapter.validate_task(selected, observed), base.canonical(selected))
        mutations = [lambda x: x['origin']['repository'].update(private=False, visibility='public'),
                     lambda x: x['origin']['repository'].update(id=99),
                     lambda x: x['origin']['repository'].update(node_id='R_OTHER'),
                     lambda x: x['origin']['repository'].update(full_name='example-company/other'),
                     lambda x: x['origin']['workflow'].update(sha256='9' * 64),
                     lambda x: x['origin'].update(event='push'),
                     lambda x: x['origin'].update(ref='refs/heads/other'),
                     lambda x: x['origin'].update(run_id=302),
                     lambda x: x['origin'].update(attempt=2),
                     lambda x: x['origin'].update(attempt=True),
                     lambda x: x['origin'].update(job_id=402),
                     lambda x: x['origin'].update(runner_binding='9' * 64),
                     lambda x: x['origin'].update(daemon_id='another-daemon'),
                     lambda x: x['origin'].update(nonce='9' * 32),
                     lambda x: x.update(audience=[]),
                     lambda x: x['audience'][0].update(permission='read'),
                     lambda x: x['audience'].append(copy.deepcopy(x['audience'][0]))]
        for change in mutations:
            changed = copy.deepcopy(observed)
            change(changed)
            with self.subTest(change=mutations.index(change)), self.assertRaises(ValueError):
                adapter.validate_task(selected, changed)

    def test_credential_routes_static_drift_and_stop_changes_refuse(self):
        selected, observed = selection()
        for key in ('GITHUB_TOKEN', 'GH_TOKEN', 'ACTIONS_RUNTIME_TOKEN', 'AWS_SECRET_ACCESS_KEY'):
            changed = copy.deepcopy(observed)
            changed['build_environment'][key] = 'FICTIONAL-NOT-A-CREDENTIAL'
            with self.subTest(key=key), self.assertRaises(ValueError):
                adapter.validate_task(selected, changed)
        for field in ('tools_sha256', 'parent_sha256', 'resources_sha256', 'transport_sha256', 'stop_map_sha256'):
            changed = copy.deepcopy(observed)
            changed[field] = '9' * 64
            self.assertRaises(ValueError, adapter.validate_task, selected, changed)
        for field in adapter.EFFECTS:
            changed = copy.deepcopy(selected)
            changed['effects'][field] = True
            self.assertRaises(ValueError, adapter.validate_task, changed, observed)
        for stop in adapter.STOPS:
            changed = copy.deepcopy(selected)
            changed['retained_stops'][stop] = False
            self.assertRaises(ValueError, adapter.validate_task, changed, observed)
        changed = copy.deepcopy(observed)
        changed['docker_config'] = b'{"auths":{"fixture.invalid":{}}}'
        self.assertRaises(ValueError, adapter.validate_task, selected, changed)

    def test_private_api_metadata_cannot_relabel_a_public_runtime_or_transferred_token(self):
        selected, observed = selection()
        for key in observed['runtime_identity']:
            changed = copy.deepcopy(observed)
            changed['runtime_identity'][key] = 'fictional-public-job'
            self.assertRaises(ValueError, adapter.validate_task, selected, changed)
        for key in observed['token_route']:
            changed = copy.deepcopy(observed)
            changed['token_route'][key] = 'fictional-other-route'
            self.assertRaises(ValueError, adapter.validate_task, selected, changed)
        for key, replacement in (('executor', {'attempts': True}), ('retained_stops', {'native_inspector': 1}),
                                 ('cleanup', {'discovery_only': True})):
            changed = copy.deepcopy(selected)
            changed[key].update(replacement)
            self.assertRaises(ValueError, adapter.validate_task, changed, observed)

    def test_exact_canonical_context_is_retained_after_cache_mutation(self):
        prepared, contents, sealed, ledger = self.inputs()
        original = prepared.archive
        contents.clear()
        self.assertEqual(prepared.archive, original)
        self.assertEqual(base.digest(original), json.loads(prepared.bindings)['context_sha256'])
        selected, observed = selection()
        self.assertRaises(self.p.contract.Refusal, adapter.prepare, self.p, selected, observed,
                          prepared.context, sealed, ledger, contents)
        self.assertEqual(prepared.command[-1], '-')
        self.assertNotIn('--push', prepared.command)
        self.assertNotIn('--load', prepared.command)

    def test_actual_oci_and_diagnostics_are_bound_and_remain_unqualified(self):
        for architecture in ('amd64', 'arm64'):
            selected, observed = selection()
            selected['architecture'] = architecture
            selected['executor'] = adapter.executor_policy(architecture)
            prepared, _contents, _sealed, _ledger = self.inputs(selected, observed)
            output = self.output(prepared)
            receipt, files = adapter.describe_output(self.p, prepared, output)
            self.assertEqual(receipt['archive']['platform'], 'linux/' + architecture)
            self.assertEqual(receipt['diagnostics']['stdout']['sha256'], base.digest(output['stdout']))
            self.assertEqual(len(receipt['observed_source_manifest']['objects']), 79)
            self.assertEqual(receipt['observed_source_manifest']['context'], receipt['context'])
            self.assertEqual(receipt['observed_source_manifest'], json.loads(prepared.source_manifest))
            for flag in ('execution_enabled', 'qualified', 'execution_verified', 'provider_origin_verified',
                         'publication_admitted', 'cleanup_verified'):
                self.assertIs(receipt[flag], False)
            proposal = adapter.upload_proposal(prepared, receipt, files)
            self.assertEqual(proposal['with']['retention-days'], 1)
            self.assertFalse(proposal['cross_repository_transfer'])
            self.assertIsNone(proposal['upload_receipt'])

    def test_each_original_input_and_provenance_substitution_refuses(self):
        prepared, _contents, _sealed, _ledger = self.inputs()
        output = self.output(prepared)
        for field, replacement in (('stdin_sha256', '9' * 64), ('invocations', 2),
                                   ('archive', bytearray(output['archive'])),
                                   ('stdout', b'x' * (adapter.MAX_DIAGNOSTIC + 1)),
                                   ('completed_at', '2026-02-30T00:00:00Z')):
            changed = dict(output, **{field: replacement})
            self.assertRaises(ValueError, adapter.describe_output, self.p, prepared, changed)
        modified = dataclasses.replace(prepared, command=(*prepared.command, '--push'))
        self.assertRaises(ValueError, adapter.describe_output, self.p, modified, output)
        for _field, argument in self.p.contract.HOSTED_BUILD_ARGUMENTS:
            def mutate(parts, arg=argument):
                parts['provenance']['predicate']['buildDefinition']['externalParameters']['request']['args'][
                    'build-arg:' + arg] = '9' * 64
            changed = self.output(prepared, mutate)
            self.assertRaises(ValueError, adapter.describe_output, self.p, prepared, changed)

    def test_nonpassing_original_diagnostics_do_not_fabricate_a_successful_export(self):
        prepared, _contents, _sealed, _ledger = self.inputs()
        output = self.output(prepared)
        output.update(archive=None, exit_code=124, timed_out=True, stderr=b'original fictional timeout')
        receipt, files = adapter.describe_output(self.p, prepared, output)
        self.assertEqual(receipt['exit_code'], 124)
        self.assertTrue(receipt['timed_out'])
        self.assertIsNone(receipt['archive'])
        self.assertNotIn('image.oci.tar', files)
        self.assertEqual(len(adapter.upload_proposal(prepared, receipt, files)['members']), 3)
        output.update(exit_code=0, timed_out=False)
        self.assertRaises(ValueError, adapter.describe_output, self.p, prepared, output)

    def test_changed_upload_bytes_extra_members_and_mutable_bytes_refuse(self):
        prepared, _contents, _sealed, _ledger = self.inputs()
        receipt, files = adapter.describe_output(self.p, prepared, self.output(prepared))
        for field in files:
            changed = dict(files, **{field: files[field] + b'changed'})
            self.assertRaises(ValueError, adapter.upload_proposal, prepared, receipt, changed)
        self.assertRaises(ValueError, adapter.upload_proposal, prepared, receipt, {**files, 'extra': b'x'})
        changed = dict(files, **{'stdout.log': bytearray(files['stdout.log'])})
        self.assertRaises(ValueError, adapter.upload_proposal, prepared, receipt, changed)

    def test_private_provider_retention_is_exactly_original_one_day_and_still_not_authentication(self):
        prepared, _contents, _sealed, _ledger = self.inputs()
        receipt, files = adapter.describe_output(self.p, prepared, self.output(prepared))
        proposal = adapter.upload_proposal(prepared, receipt, files)
        observed = {'origin': copy.deepcopy(proposal['origin']), 'audience': copy.deepcopy(proposal['audience']),
                    'artifact_id': 501, 'name': proposal['with']['name'], 'size': 12345, 'zip_sha256': '8' * 64,
                    'created_at': '2026-01-01T00:01:00Z', 'expires_at': '2026-01-02T00:01:00Z',
                    'retention_days': 1, 'expired': False, 'overwrite': False}
        self.assertIs(adapter.validate_retention(proposal, observed), False)
        for key, value in (('name', 'public-archive'), ('expires_at', '2026-01-03T00:01:00Z'),
                           ('expired', True), ('overwrite', True), ('retention_days', True), ('artifact_id', False)):
            changed = dict(observed, **{key: value})
            self.assertRaises(ValueError, adapter.validate_retention, proposal, changed)
        changed = copy.deepcopy(proposal)
        changed.update(token_source='public-job-runtime-token', cross_repository_transfer=True)
        self.assertRaises(ValueError, adapter.validate_retention, changed, observed)

    def test_base_drift_refuses_before_evaluating_source(self):
        git = base.git_reader(ROOT)
        def changed(*args):
            value = git(*args)
            return value + b'\n# changed' if args[:2] == ('cat-file', 'blob') else value
        self.assertRaises(ValueError, base.snapshot, changed)

    def test_sealed_transport_and_source_authority_cannot_be_spliced(self):
        prepared, contents, sealed, ledger = self.inputs()
        selected = json.loads(prepared.selection)
        observed = json.loads(prepared.observation)
        observed['docker_config'] = observed['docker_config'].encode()
        selected['transport_sha256'] = observed['transport_sha256'] = '9' * 64
        self.assertRaises(ValueError, adapter.prepare, self.p, selected, observed,
                          prepared.context, sealed, ledger, contents)
        selected, observed = selection()
        observed['adapter_authority']['manifest_sha256'] = '9' * 64
        self.assertRaises(ValueError, adapter.validate_task, selected, observed)

    def test_source_receipt_cannot_enter_existing_verified_publisher(self):
        prepared, _contents, _sealed, _ledger = self.inputs()
        receipt, files = adapter.describe_output(self.p, prepared, self.output(prepared))
        publisher = self.oci.OciReleaseTest()
        publisher.setUp()
        try:
            selected = publisher.artifact_fixture()
            target = publisher.root / 'amd64.zip'
            with zipfile.ZipFile(target, 'w') as archive:
                archive.writestr('image.oci.tar', files['image.oci.tar'])
                archive.writestr('provenance.json', base.canonical(receipt))
            oci = self.oci.oci
            selected['artifacts']['amd64'].update(digest=oci.digest_file(target), size_in_bytes=target.stat().st_size)
            (publisher.root / 'plan.json').write_bytes(oci.encode(selected))
            arguments = argparse.Namespace(directory=publisher.root)
            with self.assertRaisesRegex(oci.EvidenceError, 'Unexpected provenance fields'):
                oci.prepare(arguments)
            self.assertFalse((publisher.root / 'verified.json').exists())
        finally:
            publisher.doCleanups()

    def test_cli_and_execute_remain_literal_holds(self):
        self.assertRaisesRegex(ValueError, 'HELD', adapter.execute)
        result = subprocess.run([sys.executable, '-I', str(Path(adapter.__file__)), '--help'],
                                capture_output=True, timeout=5)
        self.assertNotEqual(result.returncode, 0)
        self.assertIn(b'HELD', result.stderr)


if __name__ == '__main__':
    unittest.main()
