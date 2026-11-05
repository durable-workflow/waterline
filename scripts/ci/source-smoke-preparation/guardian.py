"""Global bounded cleanup source. Ordinary CLI use is held before imports."""
if __name__ == '__main__':
    raise SystemExit('HELD: no lifecycle guardian or Docker operation is admitted')

import os
import re
import select
import time

import contract
from contract import Refusal, bounded_json, require

LABELS = ('dev.durable-workflow.waterline-source-preparation',
          'dev.durable-workflow.waterline-source-evidence')
LABEL_FILTER = 'label='
LIST = {'container': ('ps', '-aq'), 'network': ('network', 'ls', '-q'),
        'volume': ('volume', 'ls', '-q'), 'image': ('image', 'ls', '-q', '--no-trunc')}
REMOVE = {'container': ('rm', '-f'), 'network': ('network', 'rm'),
          'volume': ('volume', 'rm'), 'image': ('image', 'rm')}


def planned(value):
    require(isinstance(value, dict) and set(value) == {'kind', 'name', 'label', 'owner', 'nonce'}, 'Guardian plan shape')
    require(isinstance(value['kind'], str) and value['kind'] in LIST and value['label'] in LABELS
            and isinstance(value['owner'], str) and isinstance(value['nonce'], str)
            and re.fullmatch(r'[a-f0-9]{16}|[a-f0-9]{32}', value['owner'])
            and re.fullmatch(r'[a-f0-9]{32}', value['nonce']), 'Guardian owner scope')
    require(value['name'] is None if value['kind'] == 'image' else
            isinstance(value['name'], str) and re.fullmatch(r'waterline-[a-z0-9-]{1,120}', value['name']),
            'Guardian resource name')


def binding(value, plan):
    kind = plan['kind']
    labels = value.get('Config', {}).get('Labels') if kind in ('container', 'image') else value.get('Labels')
    require(isinstance(labels, dict) and labels.get(plan['label']) == plan['owner']
            and labels.get(plan['label'] + '.resource') == plan['nonce'], 'Foreign cleanup resource')
    if kind != 'image':
        require(value.get('Name', '').removeprefix('/') == plan['name'], 'Cleanup name mismatch')
    if kind == 'volume':
        require(value.get('CreatedAt') and value.get('Mountpoint'), 'Volume creation identity missing')
        return {key: value.get(key) for key in ('Name', 'CreatedAt', 'Mountpoint', 'Driver', 'Labels', 'Options')}
    identifier = value.get('Id')
    pattern = r'sha256:[a-f0-9]{64}' if kind == 'image' else r'[a-f0-9]{64}'
    require(isinstance(identifier, str) and re.fullmatch(pattern, identifier), 'Cleanup immutable ID missing')
    return {'Id': identifier, 'nonce': plan['nonce']}


class DockerCleanup:
    """The only live cleanup adapter. It has no credential/provider operations."""
    def __init__(self, evidence, deadline, daemon_id):
        self.evidence, self.deadline, self.daemon_id = evidence, deadline, daemon_id

    def call(self, *args):
        require(contract.EXECUTION_ENABLED, 'HELD: no Docker lifecycle admission')
        now = time.monotonic()
        actual, _ = self.evidence.command(['docker', 'info', '--format', '{{.ID}}'], min(self.deadline, now + 3))
        require(actual.strip().decode() == self.daemon_id, 'Cleanup daemon changed')
        return self.evidence.command(['docker', *args], min(self.deadline, time.monotonic() + 3))[0]

    def find(self, plan):
        args = [*LIST[plan['kind']], '--filter', LABEL_FILTER + plan['label'] + '=' + plan['owner'],
                '--filter', LABEL_FILTER + plan['label'] + '.resource=' + plan['nonce']]
        return self.call(*args).decode().split()

    def named(self, plan):
        if plan['name'] is None:
            return False
        listing = ('ps', '-a') if plan['kind'] == 'container' else (plan['kind'], 'ls')
        field = '{{.Names}}' if plan['kind'] == 'container' else '{{.Name}}'
        return plan['name'] in self.call(*listing, '--format', field).decode().split()

    def owner(self, plan):
        return self.call(*LIST[plan['kind']], '--filter',
                         LABEL_FILTER + plan['label'] + '=' + plan['owner']).decode().split()

    def present(self, plan, identity):
        # Label filters alone cannot establish absence of an already bound ID.
        identifiers = self.call(*LIST[plan['kind']], *(['--no-trunc'] if plan['kind'] == 'container' else [])).decode().split()
        return identity.get('Id', identity.get('Name')) in identifiers

    def inspect(self, plan, identifier):
        values = bounded_json(self.call(plan['kind'], 'inspect', identifier))
        require(isinstance(values, list) and len(values) == 1, 'Cleanup inspect shape')
        return values[0]

    def remove(self, plan, identity):
        self.call(*REMOVE[plan['kind']], identity.get('Id', identity.get('Name')))


class Ledger:
    """One cell, at most 32 attempted resources. Inventory never grants ownership."""
    def __init__(self, docker, deadline):
        self.docker, self.deadline = docker, deadline
        self.plans, self.bindings = {}, {}

    def attempt(self, name, plan):
        planned(plan)
        require(name not in self.plans and len(self.plans) < 32, 'Duplicate or excessive guardian resource')
        require(not self.docker.named(plan), 'Pre-existing exact guardian resource name')
        require(not self.docker.find(plan), 'Pre-existing guardian owner/resource label')
        previous = [value for value in self.plans.values() if (value['kind'], value['label'], value['owner'])
                    == (plan['kind'], plan['label'], plan['owner'])]
        if not previous:
            require(not self.docker.owner(plan), 'Pre-existing guardian owner label')
        self.plans[name] = dict(plan)

    def bind(self, name, identifier):
        require(name in self.plans and name not in self.bindings, 'Unattempted guardian binding')
        plan = self.plans[name]
        actual = binding(self.docker.inspect(plan, identifier), plan)
        require(identifier == actual.get('Id', actual.get('Name')), 'Guardian creation return mismatch')
        self.bindings[name] = actual

    def cleanup_plan(self, name, plan):
        known = self.bindings.get(name)
        if known and self.docker.present(plan, known):
            actual = binding(self.docker.inspect(plan, known.get('Id', known.get('Name'))), plan)
            require(known == actual, 'Replaced guardian resource')
        for identifier in self.docker.find(plan):
            actual = binding(self.docker.inspect(plan, identifier), plan)
            require(name not in self.bindings or self.bindings[name] == actual, 'Replaced guardian resource')
            self.docker.remove(plan, actual)

    def cleanup(self):
        # A foreign object blocks success, but must not block safe cleanup of
        # independently owned resources. Never prune or remove shared bases.
        failures = []
        for name, plan in reversed(list(self.plans.items())):
            try:
                self.cleanup_plan(name, plan)
            except (ValueError, OSError) as error:
                failures.append(error)
        if failures:
            raise Refusal('Guardian cleanup incomplete') from failures[0]
        require(all(not self.docker.find(plan) for plan in self.plans.values()), 'Guardian resource survived')
        require(all(not self.docker.present(self.plans[name], identity)
                    for name, identity in self.bindings.items()), 'Guardian bound ID survived')
        require(all(not self.docker.owner(plan) and not self.docker.named(plan) for plan in self.plans.values()),
                'Guardian owner/name absence uncertain')


def event(value, ledger):
    operation = value.get('operation')
    if operation == 'attempt':
        ledger.attempt(value['name'], value['plan'])
    elif operation == 'bind':
        ledger.bind(value['name'], value['id'])
    elif operation == 'cleanup':
        ledger.cleanup()
    else:
        require(operation in ('ping', 'stop'), 'Unknown guardian operation')
    return operation


def send(output_stream, value, deadline):
    raw, position = value.encode() + b'\n', 0
    fd = output_stream.fileno()
    os.set_blocking(fd, False)
    stop = min(deadline, time.monotonic() + 2)
    while position < len(raw):
        require(time.monotonic() < stop, 'Guardian output deadline')
        _, ready, _ = select.select([], [fd], [], max(0, stop - time.monotonic()))
        require(ready, 'Guardian output timeout')
        try:
            position += os.write(fd, raw[position:])
        except BlockingIOError:
            continue


def supervise(ledger, input_stream, output_stream):
    """Independent EOF/deadline cleanup; expected stop is a distinct outcome."""
    normal = False
    pending = bytearray()
    send(output_stream, 'ready', ledger.deadline)
    try:
        while time.monotonic() < ledger.deadline - 300:
            ready, _, _ = select.select([input_stream], [], [], .2)
            if not ready:
                continue
            raw = os.read(input_stream.fileno(), 4096)
            if not raw:
                break
            pending.extend(raw)
            require(len(pending) <= 65536, 'Guardian message bound')
            while b'\n' in pending:
                line, _, remaining = pending.partition(b'\n')
                pending = bytearray(remaining)
                operation = event(bounded_json(bytes(line)), ledger)
                if operation == 'stop':
                    ledger.cleanup()
                    normal = True
                    send(output_stream, 'stopped', ledger.deadline)
                    return
                send(output_stream, 'ack', ledger.deadline)
    finally:
        if not normal:
            ledger.cleanup()
    raise SystemExit(2)


def main():
    require(contract.EXECUTION_ENABLED, 'HELD: no independent guardian allocation is admitted')
    raise ValueError('HELD: executable source admission and immutable launcher are required')
