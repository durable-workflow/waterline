"""Identity cleanup and independent deadline controls with synthetic resources."""
import copy
import pathlib
import select
import subprocess
import sys
import tempfile
import unittest
from unittest import mock

sys.path.insert(0, str(pathlib.Path(__file__).parent))
import contract as c
import guardian as g


class FakeDocker:
    def __init__(self):
        self.values, self.removed = {}, []

    def named(self, plan):
        return plan['name'] is not None and any((value.get('Name') or '').removeprefix('/') == plan['name']
                                                for value in self.values.values())

    def present(self, plan, identity):
        return identity.get('Id', identity.get('Name')) in self.values

    def owner(self, plan):
        result = []
        for key, value in self.values.items():
            labels = value.get('Config', {}).get('Labels', value.get('Labels', {}))
            if value['kind'] == plan['kind'] and labels.get(plan['label']) == plan['owner']:
                result.append(key)
        return result

    def find(self, plan):
        return [key for key in self.owner(plan) if
                self.values[key].get('Config', {}).get('Labels', self.values[key].get('Labels', {}))
                .get(plan['label'] + '.resource') == plan['nonce']]

    def inspect(self, plan, identifier):
        return copy.deepcopy(self.values[identifier])

    def remove(self, plan, identity):
        identifier = identity.get('Id', identity.get('Name'))
        self.removed.append(identifier)
        del self.values[identifier]


def plan(kind, number=1):
    return {'kind': kind, 'name': None if kind == 'image' else f'waterline-source-{kind}-{number}',
            'label': g.LABELS[0], 'owner': 'a' * 32, 'nonce': f'{number:032x}'}


def value(planned, number=1):
    labels = {planned['label']: planned['owner'], planned['label'] + '.resource': planned['nonce']}
    result = {'kind': planned['kind'], 'Name': planned['name']}
    if planned['kind'] in ('image', 'container'):
        result['Config'] = {'Labels': labels}
    else:
        result['Labels'] = labels
    if planned['kind'] == 'volume':
        result.update(CreatedAt='2026-01-01T00:00:00Z', Mountpoint='/synthetic-volume', Driver='local', Options={})
    else:
        result['Id'] = ('sha256:' if planned['kind'] == 'image' else '') + f'{number:064x}'
    return result


class GuardianTests(unittest.TestCase):
    def test_all_four_resource_kinds_bind_and_cleanup_in_reverse_creation_order(self):
        docker = FakeDocker()
        ledger = g.Ledger(docker, 1000)
        created = []
        for number, kind in enumerate(('image', 'volume', 'network', 'container'), 1):
            expected = plan(kind, number)
            ledger.attempt(kind, expected)
            actual = value(expected, number)
            identifier = actual.get('Id', actual['Name'])
            docker.values[identifier] = actual
            ledger.bind(kind, identifier)
            created.append(identifier)
        ledger.cleanup()
        self.assertEqual(docker.removed, created[::-1])
        self.assertEqual(docker.values, {})

    def test_exact_foreign_names_and_owner_label_collisions_refuse_before_allocation(self):
        for failure in ('name', 'owner'):
            with self.subTest(failure=failure):
                docker = FakeDocker()
                expected = plan('volume')
                foreign = value(expected)
                if failure == 'name':
                    foreign['Labels'][expected['label']] = 'f' * 32
                else:
                    foreign['Name'] += '-other'
                    foreign['Labels'][expected['label'] + '.resource'] = 'f' * 32
                docker.values[foreign['Name']] = foreign
                ledger = g.Ledger(docker, 1000)
                self.assertRaises(c.Refusal, ledger.attempt, 'volume', expected)
                self.assertEqual(ledger.plans, {})
                self.assertEqual(docker.removed, [])

    def test_foreign_and_replaced_cleanup_objects_are_never_removed(self):
        for kind in ('container', 'network', 'volume', 'image'):
            for failure in ('identity', 'owner'):
                with self.subTest(kind=kind, failure=failure):
                    docker = FakeDocker()
                    expected = plan(kind)
                    ledger = g.Ledger(docker, 1000)
                    ledger.attempt('resource', expected)
                    actual = value(expected)
                    identifier = actual.get('Id', actual['Name'])
                    docker.values[identifier] = actual
                    ledger.bind('resource', identifier)
                    if failure == 'identity':
                        actual['CreatedAt' if kind == 'volume' else 'Id'] += 'changed'
                    else:
                        labels = actual.get('Config', {}).get('Labels', actual.get('Labels', {}))
                        labels[expected['label']] = 'f' * 32
                    self.assertRaises(c.Refusal, ledger.cleanup)
                    self.assertEqual(docker.removed, [])

    def test_creation_attempt_survives_lost_return_and_unknown_resource_is_not_adopted(self):
        docker = FakeDocker()
        expected = plan('container')
        ledger = g.Ledger(docker, 1000)
        ledger.attempt('resource', expected)
        actual = value(expected)
        docker.values[actual['Id']] = actual
        ledger.cleanup()
        self.assertEqual(docker.removed, [actual['Id']])
        ledger.attempt('another', plan('container', 2))
        unknown = value(plan('container', 3), 3)
        docker.values[unknown['Id']] = unknown
        self.assertRaisesRegex(c.Refusal, 'absence uncertain', ledger.cleanup)
        self.assertNotIn(unknown['Id'], docker.removed)

    def test_foreign_object_does_not_prevent_other_owned_cleanup(self):
        docker = FakeDocker()
        ledger = g.Ledger(docker, 1000)
        for number in (1, 2):
            expected = plan('container', number)
            ledger.attempt(str(number), expected)
            actual = value(expected, number)
            docker.values[actual['Id']] = actual
            ledger.bind(str(number), actual['Id'])
        foreign_id = f'{2:064x}'
        docker.values[foreign_id]['Config']['Labels'][g.LABELS[0]] = 'f' * 32
        self.assertRaisesRegex(c.Refusal, 'cleanup incomplete', ledger.cleanup)
        self.assertEqual(docker.removed, [f'{1:064x}'])
        self.assertEqual(set(docker.values), {foreign_id})

    def test_live_adapter_hold_precedes_even_daemon_inventory(self):
        evidence = mock.Mock()
        adapter = g.DockerCleanup(evidence, 10 ** 20, 'synthetic-daemon')
        self.assertRaisesRegex(c.Refusal, 'HELD', adapter.call, 'ps')
        evidence.command.assert_not_called()

    def test_independent_eof_partial_message_deadline_and_expected_stop(self):
        folder = pathlib.Path(__file__).resolve().parent
        program = '''import pathlib,sys,time
sys.path.insert(0,sys.argv[1])
from guardian import supervise
class Ledger:
 def __init__(self):self.deadline=time.monotonic()+301
 def cleanup(self):pathlib.Path(sys.argv[2]).write_text('independent cleanup')
supervise(Ledger(),sys.stdin,sys.stdout)
'''
        for case in ('eof', 'partial', 'stop'):
            with self.subTest(case=case), tempfile.TemporaryDirectory() as directory:
                marker = pathlib.Path(directory) / 'cleanup'
                process = subprocess.Popen([sys.executable, '-I', '-c', program, str(folder), str(marker)],
                                           stdin=subprocess.PIPE, stdout=subprocess.PIPE, stderr=subprocess.PIPE)
                try:
                    ready, _, _ = select.select([process.stdout], [], [], 2)
                    self.assertTrue(ready)
                    self.assertEqual(process.stdout.readline(), b'ready\n')
                    if case == 'eof':
                        process.stdin.close()
                    elif case == 'partial':
                        process.stdin.write(b'{')
                        process.stdin.flush()
                    else:
                        process.stdin.write(b'{"operation":"stop"}\n')
                        process.stdin.flush()
                    result = process.wait(timeout=3)
                    self.assertEqual(result, 0 if case == 'stop' else 2)
                    self.assertTrue(marker.exists())
                finally:
                    if process.poll() is None:
                        process.kill()
                    process.wait(timeout=2)
                    for stream in (process.stdin, process.stdout, process.stderr):
                        stream.close()


if __name__ == '__main__':
    unittest.main()
