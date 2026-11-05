#!/usr/bin/env python3
"""Identity-bound expiry source, loaded only from the reviewed packet bytes."""
import os
import re
import select
import sys
import time

if __name__ == '__main__':
    raise SystemExit('HELD: no mutable-worktree cleanup launcher is admitted')

from evidence import bounded_json, command, require

LABEL = 'dev.durable-workflow.waterline-source-evidence'
RESOURCE_LABEL = LABEL + '.resource'
LISTINGS = {'container': ('ps', '-aq', '--no-trunc'), 'network': ('network', 'ls', '-q', '--no-trunc'),
            'volume': ('volume', 'ls', '-q')}
REMOVALS = {'container': ('rm', '-f'), 'network': ('network', 'rm'),
            'volume': ('volume', 'rm', '-f')}


def resource_binding(kind, value, planned, token):
    labels = value.get('Config', {}).get('Labels') if kind == 'container' else value.get('Labels')
    require(isinstance(labels, dict) and labels.get(LABEL) == token
            and labels.get(RESOURCE_LABEL) == planned['nonce'], 'Foreign resource labels')
    name = value.get('Name', '').removeprefix('/')
    require(name == planned['name'], 'Resource name mismatch')
    if kind == 'volume':
        require(isinstance(value.get('CreatedAt'), str) and value['CreatedAt'], 'Volume creation identity missing')
        return {key: value.get(key) for key in ('Name', 'CreatedAt', 'Driver', 'Scope', 'Mountpoint', 'Labels', 'Options')}
    identifier = value.get('Id')
    require(isinstance(identifier, str) and re.fullmatch(r'[a-f0-9]{64}', identifier), 'Resource ID missing')
    return {'Id': identifier, 'Name': value['Name'], 'nonce': planned['nonce']}


def inspect_resource(docker, kind, identifier):
    values = bounded_json(docker(kind, 'inspect', identifier))
    require(isinstance(values, list) and len(values) == 1 and isinstance(values[0], dict), 'Resource inspect shape')
    return values[0]


def cleanup_owned(docker, token, plan, attempted, bindings):
    # Inventory grants no authority. Every deletion needs the exact planned
    # name/nonce and, when returned, the independently retained creation ID.
    expected_absent = {kind: set() for kind in LISTINGS}
    for kind, listing in LISTINGS.items():
        present = set(docker(*listing).decode().split())
        for name in sorted(attempted):
            if name not in bindings or plan[name]['kind'] != kind:
                continue
            retained = bindings[name]
            identifier = retained.get('Id', retained.get('Name'))
            expected_absent[kind].add(identifier)
            if identifier in present:
                actual = resource_binding(kind, inspect_resource(docker, kind, identifier), plan[name], token)
                require(actual == retained, 'Replaced cleanup resource')
                docker(*REMOVALS[kind], identifier)
        identifiers = docker(*listing, '--filter', 'label=' + LABEL + '=' + token).decode().split()
        for identifier in dict.fromkeys(identifiers):
            pattern = r'waterline-source-[a-f0-9]{16}-[a-z][a-z0-9-]*' if kind == 'volume' else r'[a-f0-9]{64}'
            require(re.fullmatch(pattern, identifier), 'Cleanup ID shape')
            value = inspect_resource(docker, kind, identifier)
            name = value.get('Name', '').removeprefix('/')
            require(name in attempted and name in plan and plan[name]['kind'] == kind, 'Unowned cleanup resource')
            actual = resource_binding(kind, value, plan[name], token)
            require(name not in bindings, 'Bound resource survived direct cleanup')
            expected_absent[kind].add(actual.get('Id', actual['Name']))
            docker(*REMOVALS[kind], actual.get('Id', actual['Name']))
    receipt = {}
    for kind, listing in LISTINGS.items():
        identifiers = set(docker(*listing).decode().split())
        require(not expected_absent[kind].intersection(identifiers), 'Expected resource ID survived cleanup')
        receipt[{'container': 'containers', 'network': 'networks', 'volume': 'volumes'}[kind]] = (
            docker(*listing, '--filter', 'label=' + LABEL + '=' + token).decode().split())
        listing = ('ps', '-a') if kind == 'container' else (kind, 'ls')
        names = docker(*listing, '--format', '{{.Names}}' if kind == 'container' else '{{.Name}}').decode().split()
        require(not set(names).intersection(attempted), 'Expected resource name survived cleanup')
    return receipt


def accept_event(event, plan, attempted, bindings, armed):
    operation = event.get('operation')
    if operation == 'ping':
        return armed, False
    if operation == 'arm':
        require(not armed, 'Backstop already armed')
        return True, False
    if operation == 'attempt':
        require(armed and event.get('name') in plan and event['name'] not in attempted, 'Unplanned creation')
        attempted.add(event['name'])
        return armed, False
    if operation == 'bind':
        require(armed and event.get('name') in attempted and event['name'] not in bindings, 'Unplanned binding')
        bindings[event['name']] = event['binding']
        return armed, False
    if operation == 'stop':
        return armed, True
    raise ValueError('Unknown backstop event')


def backstop_admission(arguments):
    token, deadline, raw_plan = arguments
    deadline = float(deadline)
    require(re.fullmatch(r'[a-f0-9]{16}', token) and time.monotonic() < deadline <= time.monotonic() + 1200,
            'Invalid backstop admission')
    plan = bounded_json(raw_plan.encode())
    require(isinstance(plan, dict) and len(plan) == 8, 'Backstop plan shape')
    for name, entry in plan.items():
        require(entry.get('name') == name and name.startswith('waterline-source-' + token + '-')
                and entry.get('kind') in LISTINGS and re.fullmatch(r'[a-f0-9]{32}', entry.get('nonce', '')),
                'Backstop resource admission')
    return token, deadline, plan


def backstop_main():
    token, deadline, plan = backstop_admission(sys.argv[1:])
    attempted, bindings, armed = set(), {}, False
    print('ready', flush=True)
    pending = bytearray()
    normal_stop = False
    try:
        while time.monotonic() < deadline - 30:
            ready, _, _ = select.select([sys.stdin], [], [], min(.2, max(0, deadline - 30 - time.monotonic())))
            if not ready:
                continue
            chunk = os.read(sys.stdin.fileno(), 4096)
            if not chunk:
                break
            pending.extend(chunk)
            require(len(pending) <= 65536, 'Backstop command bound')
            while b'\n' in pending:
                line, _, remaining = pending.partition(b'\n')
                pending = bytearray(remaining)
                event = bounded_json(bytes(line))
                armed, normal_stop = accept_event(event, plan, attempted, bindings, armed)
                if normal_stop:
                    print('stopped', flush=True)
                    return
                print('ack', flush=True)
    finally:
        if armed and attempted and not normal_stop:
            def docker(*args):
                return command(['docker', *args], min(deadline, time.monotonic() + 3))[0]
            receipt = cleanup_owned(docker, token, plan, attempted, bindings)
            require(all(not identifiers for identifiers in receipt.values()), 'Backstop cleanup incomplete')
    raise SystemExit(2)
