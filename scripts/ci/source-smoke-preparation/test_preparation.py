"""Pure countercontrols. Real application source, synthetic provider/daemon facts."""
import copy
import ast
import argparse
import base64
import dataclasses
import io
import importlib.util
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
import proxy
import recipe
import test_guardian as synthetic
import test_capture as archive_fixtures


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
        ledger = proxy.ProxyLedger(context, self.authority, deadline=200, clock=lambda: 100)
        transport_policy, _, _, archives = archive_fixtures.captured_fixture(context, self.authority, self.expected, ledger)
        ledger.seal()
        host = producer.HostObservation(context, app, tool,
            c.canonical({'packages': self.packages, 'dev': False, 'dev-package-names': []}),
            self.expected['files'], self.expected['classes'], '8.3.36', 8, actual_tool,
            {'cpus': 2, 'memory': 3 * 1024 ** 3, 'pids': 256, 'jobs': 2,
             'scripts': False, 'plugins': False, 'request_concurrency': 2,
             'effective_trust_verified': True,
             'network_bytes': ledger.observation(context, self.authority)['network_bytes'], 'storage_bytes': 100},
            transport_policy.ca_sha256, 'b' * 64, True, ledger, archives)
        admission = {'platform': 'linux/amd64', 'app_config': copy.deepcopy(app['Config']),
                     'tool_config': copy.deepcopy(tool['Config']), 'ca_sha256': host.ca_sha256,
                     'preparation_tool_config_sha256': host.preparation_tool_config_sha256,
                     'transport_policy_sha256': transport_policy.identity}
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

    def test_hosted_derivation_defers_candidate_autoload_and_uses_only_offline_composer(self):
        original = subprocess.check_output(['git', '-C', str(self.repository), 'show', c.SOURCE + ':Dockerfile'])
        legacy = recipe.derive(original)
        self.assertRaisesRegex(c.Refusal, 'artifact-only recipe drift', recipe.validate_hosted_recipe, original, legacy)
        proposed = recipe.derive_hosted(original)
        self.assertEqual(proposed, (self.repository / 'docker/held-native.Dockerfile').read_bytes())
        self.assertEqual(recipe.validate_hosted_recipe(original, proposed), c.digest(proposed))
        self.assertNotIn(b'vendor/autoload.php', proposed)
        self.assertNotIn(b'ReflectionClass', proposed)
        commands = re.findall(rb'^RUN (.*?)(?=\n[^ ]|\Z)', proposed, re.MULTILINE | re.DOTALL)
        composer_commands = [command for command in commands
                             if re.match(rb'(?:--[^ ]+\s+)*composer\s', command)]
        self.assertEqual(len(composer_commands), 2)
        for command in composer_commands:
            self.assertTrue(command.startswith(b'--network=none composer '))
            self.assertIn(b'--no-plugins', command)
            self.assertIn(b'--no-scripts', command)
        self.assertIn(b'COMPOSER_CACHE_READ_ONLY=1', proposed)
        self.assertIn(b'COMPOSER_DISABLE_NETWORK=1', proposed)
        self.assertIn(b'COMPOSER_MAX_PARALLEL_HTTP=2 COMPOSER_MAX_PARALLEL_PROCESSES=2', proposed)
        self.assertIn(b'docker-php-ext-install -j2', proposed)
        for changed in (original, legacy, proposed + b'\nRUN php /app/artisan\n'):
            self.assertRaises(c.Refusal, recipe.validate_hosted_recipe, original, changed)
        self.assertRaises(c.Refusal, recipe.derive_hosted, original + b'\n')

    def test_current_manifests_do_not_fabricate_any_operational_input(self):
        recipe.validate_tool(self.lock)
        self.assertTrue(all(value is None for value in self.lock['unbound_static_inputs'].values()))
        self.assertRaisesRegex(c.Refusal, 'UNBOUND', recipe.validate_tool, self.lock, True)
        self.assertRaisesRegex(c.Refusal, 'UNBOUND', c.require_static_admission, c.unbound_admission())
        spec = c.unbound_spec()
        self.assertEqual(len(spec), 9)
        self.assertEqual(sum(value is None for value in spec.values()), 6)
        self.assertRaisesRegex(c.Refusal, 'UNBOUND', c.require_complete_spec, spec)

    def hosted_fixture(self, architecture='amd64', mutate=None):
        context = c.Context(self.preparation_commit, self.preparation_tree,
                            301, 1, 'synthetic-daemon', '3' * 64, '4' * 32, workflow=c.NATIVE_WORKFLOW)
        ledger = proxy.ProxyLedger(context, self.authority, deadline=200, clock=lambda: 100)
        _policy, _pool, _reader, archives = archive_fixtures.captured_fixture(
            context, self.authority, self.expected, ledger)
        ledger.seal()
        contents = {archive_fixtures.capture.composer_cache_path(name, package): archive_fixtures.BODY
                    for name, package in self.expected['graph'].items()}
        proposed = (self.repository / 'docker/held-native.Dockerfile').read_bytes()
        build = producer.HostedBuildInputs(context, self.authority, archives, ledger, contents,
                                          b'', architecture, proposed)
        bindings = producer.hosted_inputs(build, self.repository)['bindings']
        spec = importlib.util.spec_from_file_location('hosted_oci_fixtures',
                                                      self.repository / 'scripts/ci/test-oci-release.py')
        fixtures = importlib.util.module_from_spec(spec)
        spec.loader.exec_module(fixtures)
        # This held lane reads archives with its sealed publisher helper, so
        # the fixture labels use that helper's version, not the current one.
        fixtures.oci.VERSION = accepted.accepted_oci(self.repository).VERSION
        def bind(parts):
            provenance = parts['provenance']
            provenance['subject'] = [{'name': 'image',
                'digest': {'sha256': parts['image_descriptor']['digest'].split(':')[1]}}]
            arguments = provenance['predicate']['buildDefinition']['externalParameters']['request']['args']
            for field, argument in (('recipe_sha256', 'WATERLINE_RECIPE_SHA256'),
                                     ('source_manifest_sha256', 'WATERLINE_SOURCE_MANIFEST_SHA256'),
                                     ('cache_manifest_sha256', 'WATERLINE_CACHE_MANIFEST_SHA256'),
                                     ('context_sha256', 'WATERLINE_CONTEXT_SHA256')):
                arguments['build-arg:' + argument] = bindings[field]
            if mutate is not None:
                mutate(parts)
        with tempfile.TemporaryDirectory(prefix='waterline-native-fixture-') as temporary:
            archive, entries, manifest, config, attestation = fixtures.native_archive(
                pathlib.Path(temporary), architecture, source=c.SOURCE, mutate=bind)
            build = dataclasses.replace(build, archive=archive.read_bytes())
        return build, bindings, entries, config, fixtures

    def test_hosted_artifact_binds_actual_oci_and_original_sealed_source_attempt(self):
        for architecture in ('amd64', 'arm64'):
            build, bindings, _entries, config, _fixtures = self.hosted_fixture(architecture)
            receipt = producer.hosted_artifact(build, self.repository)
            self.assertEqual(receipt['schema'], 'waterline.build-only-native-artifact/v1')
            self.assertIs(receipt['qualified'], False)
            self.assertIs(receipt['execution_verified'], False)
            self.assertIs(receipt['provider_origin_verified'], False)
            self.assertIs(receipt['publication_admitted'], False)
            self.assertEqual(receipt['context'], build.context.report_context())
            self.assertEqual(receipt['context']['workflow'], c.NATIVE_WORKFLOW)
            self.assertEqual(receipt['inputs'], bindings)
            self.assertEqual(receipt['archive']['sha256'], c.digest(build.archive))
            self.assertEqual(receipt['archive']['platform'], 'linux/' + architecture)
            self.assertEqual(receipt['archive']['config_digest'], config)
            self.assertIs(receipt['archive']['daemon_image_id'], None)
            self.assertEqual(len(receipt['archive']['blobs']), 8)
            self.assertEqual(receipt['cache_objects'], 79)

    def test_hosted_artifact_refuses_wrong_authority_before_archive_inspection(self):
        build, _bindings, _entries, _config, _fixtures = self.hosted_fixture()
        changed_cache = dict(build.cache_contents)
        changed_cache[next(iter(changed_cache))] = b'changed'
        changes = [dataclasses.replace(build, context=dataclasses.replace(build.context, attempt=2)),
                   dataclasses.replace(build, context=dataclasses.replace(build.context, workflow=c.WORKFLOW)),
                   dataclasses.replace(build, cache_contents=changed_cache),
                   dataclasses.replace(build, recipe=build.recipe + b'\nRUN php /app/artisan\n'),
                   dataclasses.replace(build, proxy=proxy.ProxyLedger(build.context, self.authority,
                                       deadline=200, clock=lambda: 100)),
                   {'candidate_report': 'not independent host inputs'}]
        for changed in changes:
            with mock.patch.object(producer, 'native_archive_subject') as inspect:
                self.assertRaises(c.Refusal, producer.hosted_artifact, changed, self.repository)
                inspect.assert_not_called()

    def test_hosted_artifact_refuses_changed_platform_and_opaque_or_mutable_archive(self):
        build, _bindings, _entries, _config, _fixtures = self.hosted_fixture()
        changes = [dataclasses.replace(build, architecture='arm64'),
                   dataclasses.replace(build, architecture='unknown'),
                   dataclasses.replace(build, archive=build.archive + b'opaque trailing payload'),
                   dataclasses.replace(build, archive=bytearray(build.archive))]
        for changed in changes:
            self.assertRaises(c.Refusal, producer.hosted_artifact, changed, self.repository)

    def test_hosted_artifact_refuses_a_provenance_substitution_of_each_independent_input(self):
        for argument in ('WATERLINE_RECIPE_SHA256', 'WATERLINE_SOURCE_MANIFEST_SHA256',
                         'WATERLINE_CACHE_MANIFEST_SHA256', 'WATERLINE_CONTEXT_SHA256'):
            def mutate(parts):
                args = parts['provenance']['predicate']['buildDefinition']['externalParameters']['request']['args']
                args['build-arg:' + argument] = '0' * 64
            build, _bindings, _entries, _config, _fixtures = self.hosted_fixture(mutate=mutate)
            self.assertRaises(c.Refusal, producer.hosted_artifact, build, self.repository)
        build, _bindings, _entries, _config, _fixtures = self.hosted_fixture(
            mutate=lambda parts: parts['provenance'].update(subject=[]))
        self.assertRaises(c.Refusal, producer.hosted_artifact, build, self.repository)

    def test_hosted_artifact_rejects_unreferenced_blob_accepted_by_the_old_oci_reader(self):
        build, _bindings, entries, _config, fixtures = self.hosted_fixture()
        entries['blobs/sha256/' + c.digest(b'unreferenced')] = b'unreferenced'
        with tempfile.TemporaryDirectory(prefix='waterline-native-fixture-') as temporary:
            archive = pathlib.Path(temporary) / 'extra.tar'
            fixtures.write_tar(archive, entries)
            oci = accepted.accepted_oci(self.repository)
            oci.unpack_oci(archive, pathlib.Path(temporary) / 'layout')
            oci.inspect_oci(pathlib.Path(temporary) / 'layout', 'amd64', c.SOURCE)
            changed = dataclasses.replace(build, archive=archive.read_bytes())
        self.assertRaises(c.Refusal, producer.hosted_artifact, changed, self.repository)

    def test_native_context_cannot_reuse_the_old_preparation_handoff(self):
        host, _tool, _admission, _report, _raw, run, artifact = self.model()
        native = dataclasses.replace(host.context, workflow=c.NATIVE_WORKFLOW)
        self.assertRaisesRegex(c.Refusal, 'Unsupported preparation handoff', binder.provider, run, artifact, native)
        lifecycle = cell.Lifecycle(native, c.CellBudget(0), self.authority)
        self.assertRaisesRegex(c.Refusal, 'Unsupported preparation handoff', lifecycle.arm, 1, {})

    def test_hosted_context_contains_exact_m1_leaves_and_sealed_cache_without_a_mutable_path(self):
        build, _bindings, _entries, _config, _fixtures = self.hosted_fixture()
        inputs = producer.hosted_inputs(build, self.repository)
        snapshot = inputs['snapshot']
        self.assertIs(type(snapshot['archive']), bytes)
        self.assertEqual(inputs['bindings']['context_sha256'], c.digest(snapshot['archive']))
        projected, cached = {}, set()
        with tarfile.open(fileobj=io.BytesIO(snapshot['archive']), mode='r:') as archive:
            members = {member.name: member for member in archive}
            self.assertEqual(archive.extractfile(members['Dockerfile']).read(), build.recipe)
            self.assertEqual(members['standalone/artisan'].mode, 0o755)
            self.assertEqual(members['standalone/entrypoint.sh'].mode, 0o755)
            self.assertEqual(c.digest(archive.extractfile(members['standalone/composer.lock']).read()), c.LOCK)
            for name, member in members.items():
                self.assertTrue(member.isfile())
                self.assertEqual((member.uid, member.gid, member.mtime), (0, 0, 0))
                raw = archive.extractfile(member).read()
                for target in self.evidence.docker_copy_targets(name):
                    final_mode = 0o755 if target in ('artisan', 'entrypoint.sh') else member.mode
                    projected[target] = {'mode': final_mode, 'size': len(raw), 'sha256': c.digest(raw)}
                if name.startswith('.waterline-observed-source/cache/'):
                    cached.add(name.removeprefix('.waterline-observed-source/'))
            self.assertNotIn('.git/config', members)
        self.assertEqual(projected, self.expected['files'])
        self.assertEqual(cached, set(build.cache_contents))

    def test_hosted_build_command_uses_only_bound_stdin_context_and_unpublished_oci_output(self):
        bindings = {field: '1' * 64 for field, _argument in c.HOSTED_BUILD_ARGUMENTS}
        command = recipe.hosted_build_command('amd64', bindings, '/private/image.oci.tar')
        self.assertEqual(command[-1], '-')
        self.assertEqual(command[command.index('--file') + 1], 'Dockerfile')
        self.assertEqual(command[command.index('--platform') + 1], 'linux/amd64')
        for option in ('--push', '--load', '--tag', '--cache-to', '--cache-from'):
            self.assertNotIn(option, command)
        for _field, argument in c.HOSTED_BUILD_ARGUMENTS:
            self.assertIn(argument + '=' + '1' * 64, command)
        for path in ('/private/../image.oci.tar', '/private/image.oci.tar,push=true',
                     'https://foreign/image.oci.tar', '/private/other.tar'):
            self.assertRaises(c.Refusal, recipe.hosted_build_command, 'amd64', bindings, path)
        self.assertRaises(c.Refusal, recipe.hosted_build_command, 'unknown', bindings, '/private/image.oci.tar')
        changed = dict(bindings)
        del changed['context_sha256']
        self.assertRaises(c.Refusal, recipe.hosted_build_command, 'amd64', changed, '/private/image.oci.tar')

    def test_context_archive_policy_does_not_relax_the_existing_filesystem_profile(self):
        original = recipe.ArchiveBudget(65536, 32768, 32768, 32)
        context = dataclasses.replace(original, profile=recipe.CONTEXT_PROFILE)
        record = {'kind': 'file', 'mode': 0o664, 'uid': 0, 'gid': 0, 'size': 1, 'sha256': c.digest(b'x')}
        self.assertRaises(c.Refusal, recipe.member_manifest, record, original)
        self.assertEqual(recipe.member_manifest(record, context), record)
        self.assertRaises(c.Refusal, recipe.closed_path, 'Dockerfile')
        self.assertEqual(recipe.closed_path('Dockerfile', recipe.CONTEXT_PROFILE), 'dockerfile')
        self.assertRaises(c.Refusal, recipe.closed_path, 'etc/passwd', recipe.CONTEXT_PROFILE)
        self.assertRaises(c.Refusal, dataclasses.replace(original, profile='unknown').validate)

    def test_hosted_artifact_refuses_pax_metadata_the_baseline_reader_accepts(self):
        build, _bindings, entries, _config, _fixtures = self.hosted_fixture()
        with tempfile.TemporaryDirectory(prefix='waterline-native-fixture-') as temporary:
            archive = pathlib.Path(temporary) / 'pax.tar'
            with tarfile.open(archive, mode='w', format=tarfile.PAX_FORMAT,
                              pax_headers={'comment': 'opaque metadata'}) as output:
                for name, raw in entries.items():
                    member = tarfile.TarInfo(name)
                    member.size = len(raw)
                    output.addfile(member, io.BytesIO(raw))
            oci = accepted.accepted_oci(self.repository)
            oci.unpack_oci(archive, pathlib.Path(temporary) / 'layout')
            oci.inspect_oci(pathlib.Path(temporary) / 'layout', 'amd64', c.SOURCE)
            changed = dataclasses.replace(build, archive=archive.read_bytes())
        self.assertRaises(c.Refusal, producer.hosted_artifact, changed, self.repository)

    def test_build_only_receipt_cannot_reach_the_existing_verified_publisher_output(self):
        build, _bindings, _entries, _config, fixtures = self.hosted_fixture()
        receipt = producer.hosted_artifact(build, self.repository)
        publisher = fixtures.OciReleaseTest()
        publisher.setUp()
        try:
            selected = publisher.artifact_fixture()
            target = publisher.root / 'amd64.zip'
            with zipfile.ZipFile(target) as archive:
                raw = archive.read('image.oci.tar')
            with zipfile.ZipFile(target, 'w') as archive:
                archive.writestr('image.oci.tar', raw)
                archive.writestr('provenance.json', c.canonical(receipt))
            oci = fixtures.oci
            selected['artifacts']['amd64'].update(digest=oci.digest_file(target), size_in_bytes=target.stat().st_size)
            (publisher.root / 'plan.json').write_bytes(oci.encode(selected))
            arguments = argparse.Namespace(directory=publisher.root)
            with self.assertRaisesRegex(oci.EvidenceError, 'Unexpected provenance fields'):
                oci.prepare(arguments)
            self.assertFalse((publisher.root / 'verified.json').exists())
        finally:
            publisher.doCleanups()

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

    def staged_fixture(self, names=('app/a.php',), content=b'body', directories=(), regular_type=tarfile.REGTYPE):
        expected = {name: {'kind': recipe.DIRECTORY if name in directories else 'file',
            'uid': 0, 'gid': 0, 'mode': 0o755 if name in directories else 0o644,
            'size': 0 if name in directories else len(content),
            'sha256': None if name in directories else c.digest(content)} for name in names}
        output = io.BytesIO()
        with tarfile.open(fileobj=output, mode='w:') as archive:
            for name in names:
                info = tarfile.TarInfo(name)
                info.mode, info.size = expected[name]['mode'], expected[name]['size']
                info.type = tarfile.DIRTYPE if name in directories else regular_type
                archive.addfile(info, io.BytesIO(content))
        return output.getvalue(), expected

    def test_staged_archive_opposite_file_order_has_identical_sealed_bytes(self):
        names = ('app/a.php', 'app/b.php')
        raw, expected = self.staged_fixture(names)
        reversed_raw, reversed_expected = self.staged_fixture(tuple(reversed(names)))
        self.assertEqual(expected, reversed_expected)
        self.assertNotEqual(raw, reversed_raw)
        budget = recipe.ArchiveBudget(65536, 32768, 32768, 32)
        self.assertEqual(recipe.sealed_archive(raw, expected, budget),
                         recipe.sealed_archive(reversed_raw, expected, budget))

    def test_staged_archive_directory_child_and_manifest_order_are_canonical(self):
        names = ('app', 'app/dir', 'app/dir/a.php', 'app/z.php')
        directories = ('app', 'app/dir')
        raw, expected = self.staged_fixture(names, directories=directories)
        reversed_raw, reversed_expected = self.staged_fixture(tuple(reversed(names)), directories=directories)
        self.assertEqual(expected, reversed_expected)
        self.assertNotEqual(raw, reversed_raw)
        budget = recipe.ArchiveBudget(65536, 32768, 32768, 32)
        sealed = recipe.sealed_archive(raw, expected, budget)
        self.assertEqual(sealed, recipe.sealed_archive(reversed_raw, reversed_expected, budget))
        with tarfile.open(fileobj=io.BytesIO(sealed['archive'])) as archive:
            self.assertEqual(archive.getnames(), list(names))
            self.assertTrue(archive.getmember('app/dir').isdir())
            self.assertEqual(archive.extractfile('app/dir/a.php').read(), b'body')

    def test_staged_archive_regular_header_alias_is_canonical(self):
        raw, expected = self.staged_fixture()
        alternate, _ = self.staged_fixture(regular_type=tarfile.AREGTYPE)
        self.assertNotEqual(raw, alternate)
        budget = recipe.ArchiveBudget(65536, 32768, 32768, 32)
        self.assertEqual(recipe.sealed_archive(raw, expected, budget),
                         recipe.sealed_archive(alternate, expected, budget))

    def test_staged_archive_is_canonical_immutable_and_manifest_bound(self):
        raw, expected = self.staged_fixture()
        budget = recipe.ArchiveBudget(65536, 32768, 32768, 32)
        sealed = recipe.sealed_archive(raw, expected, budget)
        self.assertIs(type(sealed['archive']), bytes)
        self.assertEqual(sealed['archive_sha256'], c.digest(sealed['archive']))
        self.assertEqual(sealed['manifest_sha256'], c.digest(c.canonical(expected)))
        self.assertEqual(sealed['content_bytes'], 4)
        with tarfile.open(fileobj=io.BytesIO(sealed['archive'])) as archive:
            self.assertEqual(archive.extractfile('app/a.php').read(), b'body')
        self.assertRaises(c.Refusal, recipe.sealed_archive, bytearray(raw), expected, budget)
        self.assertRaises(c.Refusal, recipe.sealed_archive, io.BytesIO(raw), expected, budget)

    def test_staged_archive_path_duplicate_case_and_trailing_payload_refuse(self):
        budget = recipe.ArchiveBudget(65536, 32768, 32768, 32)
        for name in ('../escape', '/app/a.php', 'app/../escape', 'app/.wh.secret', 'app\\a.php', 'proc/status'):
            with self.subTest(name=name):
                raw, expected = self.staged_fixture((name,))
                self.assertRaises(c.Refusal, recipe.sealed_archive, raw, expected, budget)
        for names in (('app/a.php', 'app/a.php'), ('app/A.php', 'app/a.php'), ('app/a.php', 'app/a.php/child'),
                      ('app/Dir', 'app/dir/a.php')):
            raw, expected = self.staged_fixture(names)
            self.assertRaises(c.Refusal, recipe.sealed_archive, raw, expected, budget)
        raw, expected = self.staged_fixture()
        self.assertRaises(c.Refusal, recipe.sealed_archive, raw + b'opaque trailing data', expected, budget)
        changed = copy.deepcopy(expected)
        changed['app/a.php']['sha256'] = 'f' * 64
        self.assertRaises(c.Refusal, recipe.sealed_archive, raw, changed, budget)

    def test_staged_archive_special_files_metadata_and_sparse_records_refuse(self):
        raw, expected = self.staged_fixture()
        budget = recipe.ArchiveBudget(65536, 32768, 32768, 32)
        for kind in (tarfile.SYMTYPE, tarfile.LNKTYPE, tarfile.CHRTYPE, tarfile.BLKTYPE, tarfile.FIFOTYPE):
            output = io.BytesIO()
            with tarfile.open(fileobj=output, mode='w:') as archive:
                info = tarfile.TarInfo('app/a.php')
                info.type, info.mode = kind, 0o644
                archive.addfile(info)
            self.assertRaises(c.Refusal, recipe.sealed_archive, output.getvalue(), expected, budget)
        for mutate in [lambda x: setattr(x, 'sparse', []), lambda x: setattr(x, 'uid', 1000),
                       lambda x: setattr(x, 'mode', 0o4644), lambda x: setattr(x, 'mtime', 1),
                       lambda x: x.pax_headers.update(comment='opaque'),
                       lambda x: x.pax_headers.update({'GNU.sparse.map': '0,4'})]:
            info = tarfile.TarInfo('app/a.php')
            info.mode, info.size = 0o644, 4
            mutate(info)
            self.assertRaises(c.Refusal, recipe.closed_member, info, expected['app/a.php'], mock.Mock())

    def test_staged_archive_limits_and_missing_members_refuse(self):
        raw, expected = self.staged_fixture(('app/a.php', 'app/b.php'))
        for budget in [recipe.ArchiveBudget(None, None, None, None), recipe.ArchiveBudget(1024, 32, 32, 32),
                       recipe.ArchiveBudget(65536, 4, 6, 32), recipe.ArchiveBudget(65536, 32, 32, 1)]:
            self.assertRaises(c.Refusal, recipe.sealed_archive, raw, expected, budget)
        short, _ = self.staged_fixture(('app/a.php',))
        self.assertRaises(c.Refusal, recipe.sealed_archive, short, expected,
                          recipe.ArchiveBudget(65536, 32, 32, 32))

    def test_producer_requires_sealed_same_cell_proxy_counters(self):
        host, lock, admission, *_ = self.model()
        active = proxy.ProxyLedger(host.context, self.authority, deadline=200, clock=lambda: 100)
        for changed in [dataclasses.replace(host, proxy={}), dataclasses.replace(host, proxy=active),
                        dataclasses.replace(host, policy={**host.policy, 'network_bytes': 1})]:
            self.assertRaises(c.Refusal, producer.produce, changed, self.expected, lock,
                              self.evidence, admission, self.authority)

    def test_authenticated_counter_lies_refuse_and_receipt_keeps_real_counters(self):
        values = list(self.model())
        values[3]['network']['network_read_bytes'] = 1
        values[3]['network']['network_bytes'] = 1
        values[4] = self.archive(c.canonical(values[3]))
        values[6] = {**values[6], 'digest': 'sha256:' + c.digest(values[4]), 'size_in_bytes': len(values[4])}
        self.assertRaises(c.Refusal, self.bound, values)
        real = self.model()
        receipt, _ = self.bound(real)
        self.assertEqual(c.bounded_json(receipt)['network'], real[3]['network'])

    def test_authenticated_provider_cannot_relabel_observed_archives_as_upstream_checksums(self):
        values = list(self.model())
        archives = values[3]['archives']
        archives['manifest']['upstream_published_checksum'] = True
        archives['manifest']['provenance'] = 'upstream-published-checksum'
        archives['manifest_sha256'] = c.digest(c.canonical(archives['manifest']))
        values[4] = self.archive(c.canonical(values[3]))
        values[6] = {**values[6], 'digest': 'sha256:' + c.digest(values[4]), 'size_in_bytes': len(values[4])}
        self.assertRaises(c.Refusal, self.bound, values)


if __name__ == '__main__':
    unittest.main()
