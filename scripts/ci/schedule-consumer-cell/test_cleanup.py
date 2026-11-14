"""Lost-create cleanup controls using an inert Docker model only."""
import importlib.util
import json
from pathlib import Path
import subprocess
from types import SimpleNamespace
import unittest
from unittest.mock import patch


ROOT = Path(__file__).resolve().parent
spec = importlib.util.spec_from_file_location('schedule_cleanup_control', ROOT / 'runner.py')
cell = importlib.util.module_from_spec(spec)
spec.loader.exec_module(cell)


class Workspace:
    def check(self):
        pass

    def write(self, name, data):
        self.report = json.loads(data)


class Engine:
    def __init__(self, loss='malformed'):
        self.loss = loss
        self.objects = {}
        self.events = []

    def run(self, args, **_kwargs):
        if args[0] == 'start':
            self.events.append(('start', args[1]))
            return SimpleNamespace(stdout=b'')
        if args[0] != 'create':
            raise AssertionError('unexpected modeled effect')
        labels = {args[i + 1].split('=', 1)[0]: args[i + 1].split('=', 1)[1]
                  for i, value in enumerate(args) if value == '--label'}
        identifier = ('a' if not self.objects else 'b') * 64
        self.objects[identifier] = {'Id': identifier, 'Image': 'sha256:' + 'c' * 64,
                                    'Config': {'Labels': labels}, 'Mounts': [],
                                    'State': {'OOMKilled': False}}
        self.events.append(('create', identifier))
        if labels['dev.durable-workflow.fixture-role'] == 'runtime':
            if self.loss == 'exception':
                raise subprocess.CalledProcessError(1, ['modeled-create'])
            if self.loss == 'malformed':
                return SimpleNamespace(stdout=b'')
        return SimpleNamespace(stdout=identifier.encode() + b'\n')

    def inspect(self, _docker, identifier, labels, missing_ok=False):
        value = self.objects.get(identifier)
        if value is None and missing_ok:
            return None
        if value is None or value['Id'] != identifier or value['Config']['Labels'] != labels:
            raise ValueError('modeled ownership refusal')
        return value

    def remove(self, docker, identifier, labels):
        if self.inspect(docker, identifier, labels, True) is not None:
            del self.objects[identifier]
            self.events.append(('destroy', identifier))

    def cli(self, args, **_kwargs):
        start = args.index('ps')
        selected = args[start + 1:]
        wanted = dict(value.removeprefix('label=').split('=', 1)
                      for i, value in enumerate(selected) if i and selected[i - 1] == '--filter')
        identifiers = [identifier for identifier, value in self.objects.items()
                       if all(value['Config']['Labels'].get(key) == expected for key, expected in wanted.items())]
        return SimpleNamespace(stdout=''.join(identifier + '\n' for identifier in identifiers).encode())


class CleanupBoundaryTest(unittest.TestCase):
    def fixture(self, loss='malformed'):
        engine = Engine(loss)
        fixture = object.__new__(cell.Cell)
        fixture.workspace = Workspace()
        fixture.ids = {}; fixture.labels = {}; fixture.images = {}; fixture.recovered_ids = {}
        fixture.processes = []; fixture.files = []; fixture.credentials = []
        fixture.nonce = '1' * 24
        fixture.docker = ['modeled-docker']
        fixture.cleanup_docker = ['modeled-docker', '--config', '/proc/self/fd']
        fixture.run = engine.run
        fixture.raw_inspect = engine.inspect
        fixture.inspect = engine.inspect
        fixture.remove = engine.remove
        return fixture, engine

    def create(self, fixture, role='runtime'):
        return fixture.create(role, 'sha256:' + 'c' * 64, [], ['fixed-inert-command'])

    def test_lost_or_failed_create_is_recovered_for_deletion_without_start(self):
        for loss, exception in [('malformed', ValueError), ('exception', subprocess.CalledProcessError)]:
            fixture, engine = self.fixture(loss)
            with self.subTest(loss=loss), patch.object(cell.subprocess, 'run', engine.cli):
                with self.assertRaises(exception):
                    self.create(fixture)
                fixture.cleanup()
                self.assertEqual({}, engine.objects)
                self.assertEqual([('create', 'a' * 64), ('destroy', 'a' * 64)], engine.events)
                self.assertEqual({'runtime': 'a' * 64}, fixture.recovered_ids)

    def test_known_other_role_is_independently_removed(self):
        fixture, engine = self.fixture()
        with patch.object(cell.subprocess, 'run', engine.cli):
            with self.assertRaises(ValueError):
                self.create(fixture)
            self.create(fixture, 'consumer')
            fixture.cleanup()
        self.assertEqual({}, engine.objects)
        self.assertEqual([('start', 'b' * 64)], [event for event in engine.events if event[0] == 'start'])

    def test_foreign_image_or_duplicate_role_is_preserved_as_nonpass(self):
        for mutation in ('image', 'duplicate'):
            fixture, engine = self.fixture()
            with self.subTest(mutation=mutation), patch.object(cell.subprocess, 'run', engine.cli):
                with self.assertRaises(ValueError):
                    self.create(fixture)
                if mutation == 'image':
                    engine.objects['a' * 64]['Image'] = 'sha256:' + 'd' * 64
                else:
                    engine.objects['b' * 64] = dict(engine.objects['a' * 64], Id='b' * 64)
                with self.assertRaises(RuntimeError):
                    fixture.cleanup()
                self.assertTrue(engine.objects)
                self.assertFalse(any(event[0] in ('start', 'destroy') for event in engine.events))

    def test_known_id_replacement_is_preserved(self):
        fixture, engine = self.fixture('none')
        with patch.object(cell.subprocess, 'run', engine.cli):
            self.create(fixture)
            engine.objects['b' * 64] = dict(engine.objects.pop('a' * 64), Id='b' * 64)
            with self.assertRaises(RuntimeError):
                fixture.cleanup()
        self.assertEqual(['b' * 64], list(engine.objects))
        self.assertFalse(any(event[0] == 'destroy' for event in engine.events))


if __name__ == '__main__':
    unittest.main()
