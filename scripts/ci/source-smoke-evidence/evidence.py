"""Inert source-smoke evidence contract; expectations are host-owned Git data."""
from __future__ import annotations

import hashlib
from decimal import Decimal
import io
import json
import os
import re
import selectors
import signal
import stat
import subprocess
import tarfile
import time
from pathlib import Path

SOURCE = '3d68d7d8954a0743104b318d03708515c7e3c530'
TREE = 'aadb0484b0330ed5bfaabde42245450cafa990c4'
LOCK = 'bc2014dd77b48074d41b9aded14a82cbab5c3676b59e36e5b2203aeb644e3506'
MAX_JSON = 4 * 1024 * 1024
MAX_EVIDENCE = 16 * 1024 * 1024
MAX_FILE = 16 * 1024 * 1024
MAX_FILES = 4096
MAX_TOTAL = 128 * 1024 * 1024
DIGEST = re.compile(r'[a-f0-9]{64}\Z')
CLASSES = {
    'Waterline\\WaterlineServiceProvider': 'waterline/app/WaterlineServiceProvider.php',
    'Waterline\\Http\\Controllers\\Remote\\RemoteWorkflowsController': 'waterline/app/Http/Controllers/Remote/RemoteWorkflowsController.php',
    'Waterline\\Support\\WorkflowStreamPresenter': 'waterline/app/Support/WorkflowStreamPresenter.php',
}


class Refusal(ValueError):
    pass


def require(condition, message='Source-smoke evidence refused'):
    if not condition:
        raise Refusal(message)


def unique_object(pairs):
    result = {}
    for key, value in pairs:
        require(key not in result, 'Duplicate JSON key')
        result[key] = value
    return result


def refuse_nonfinite(_):
    raise Refusal('Nonfinite JSON')


def bounded_json(raw, limit=MAX_JSON):
    require(isinstance(raw, bytes) and len(raw) <= limit, 'Oversized JSON')
    try:
        value = json.loads(raw, object_pairs_hook=unique_object,
                           parse_constant=refuse_nonfinite)
    except (ValueError, RecursionError) as error:
        raise Refusal('Malformed bounded JSON') from error
    def check(item, depth=0):
        require(depth <= 32, 'JSON nesting bound')
        if isinstance(item, str):
            require(len(item.encode()) <= 16384, 'JSON field bound')
        elif isinstance(item, dict):
            require(len(item) <= MAX_FILES, 'JSON object bound')
            for key, child in item.items():
                check(key, depth+1)
                check(child, depth+1)
        elif isinstance(item, list):
            require(len(item) <= MAX_FILES, 'JSON array bound')
            for child in item:
                check(child, depth+1)
    check(value)
    return value


def object_bytes(root: Path, relative: str, limit=MAX_FILE):
    """Open every path component without following links; FIFO cannot block."""
    parts = relative.split('/')
    require(parts and all(p not in ('', '.', '..') for p in parts), 'Unsafe relative object')
    root = Path(root)
    require(root.is_absolute() and root.resolve() == root, 'Noncanonical object root')
    parent = os.open(root, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW)
    leaf = None
    try:
        for part in parts[:-1]:
            next_fd = os.open(part, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW, dir_fd=parent)
            os.close(parent)
            parent = next_fd
        before = os.stat(parts[-1], dir_fd=parent, follow_symlinks=False)
        require(stat.S_ISREG(before.st_mode) and before.st_nlink == 1 and 0 <= before.st_size <= limit,
                'Not a bounded single-link regular object')
        leaf = os.open(parts[-1], os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK, dir_fd=parent)
        opened = os.fstat(leaf)
        chunks, size = [], 0
        while True:
            chunk = os.read(leaf, min(65536, limit + 1 - size))
            if not chunk:
                break
            chunks.append(chunk)
            size += len(chunk)
            require(size <= limit, 'Object read bound')
        closed = os.fstat(leaf)
        after = os.stat(parts[-1], dir_fd=parent, follow_symlinks=False)
        identity = lambda s: (s.st_dev, s.st_ino, s.st_mode, s.st_nlink, s.st_size, s.st_mtime_ns, s.st_ctime_ns)
        require(identity(before) == identity(opened) == identity(closed) == identity(after), 'Object replaced during read')
        raw = b''.join(chunks)
        require(len(raw) == before.st_size, 'Object changed during read')
        return raw, {'mode': stat.S_IMODE(before.st_mode), 'size': size, 'sha256': hashlib.sha256(raw).hexdigest()}
    except OSError as error:
        raise Refusal('Object open refused') from error
    finally:
        if leaf is not None:
            os.close(leaf)
        os.close(parent)


def command_event(selector,key,input_bytes,position,result,limits):
    if key.data==2:
        position+=os.write(key.fileobj.fileno(),input_bytes[position:position+65536])
        if position==len(input_bytes):
            selector.unregister(key.fileobj)
            key.fileobj.close()
        return position
    chunk=os.read(key.fileobj.fileno(),65536)
    if not chunk:
        selector.unregister(key.fileobj)
        return position
    require(len(result[key.data])+len(chunk)<=limits[key.data],'Command output bound')
    result[key.data].extend(chunk)
    return position


def stop_command(process):
    try:
        os.killpg(process.pid,signal.SIGTERM)
        process.wait(timeout=1)
    except (ProcessLookupError,subprocess.TimeoutExpired):
        pass
    # A dead group leader does not prove that its descendants have exited.
    try:
        os.killpg(process.pid,signal.SIGKILL)
    except ProcessLookupError:
        pass
    process.wait(timeout=2)


def command(argv, deadline, stdout_limit=MAX_JSON, stderr_limit=65536, input_bytes=None):
    """No shell, bounded pipes, process-group TERM/KILL and an outer deadline."""
    require(isinstance(argv, list) and all(isinstance(v, str) and '\0' not in v for v in argv), 'Invalid command')
    require(time.monotonic() < deadline, 'Expired command deadline')
    require(input_bytes is None or isinstance(input_bytes,bytes) and len(input_bytes)<=8*1024*1024, 'Command input bound')
    process = subprocess.Popen(argv, stdin=subprocess.PIPE if input_bytes is not None else subprocess.DEVNULL,
                               stdout=subprocess.PIPE, stderr=subprocess.PIPE, start_new_session=True)
    result = [bytearray(), bytearray()]
    selector = selectors.DefaultSelector()
    for index, stream in enumerate((process.stdout, process.stderr)):
        os.set_blocking(stream.fileno(), False)
        selector.register(stream, selectors.EVENT_READ, index)
    position=0
    if input_bytes:
        os.set_blocking(process.stdin.fileno(),False)
        selector.register(process.stdin,selectors.EVENT_WRITE,2)
    elif input_bytes is not None:
        process.stdin.close()
    try:
        while selector.get_map():
            require(time.monotonic() < deadline, 'Command timeout')
            for key, _ in selector.select(min(.1, max(0, deadline-time.monotonic()))):
                position=command_event(selector,key,input_bytes,position,result,(stdout_limit,stderr_limit))
        code = process.wait(timeout=max(.01, deadline-time.monotonic()))
        require(code == 0, 'Command failed')
        return bytes(result[0]), {'argv_sha256': hashlib.sha256(json.dumps(argv).encode()).hexdigest(),
                                  'exit': code, 'stdout_sha256': hashlib.sha256(result[0]).hexdigest(),
                                  'stderr_sha256': hashlib.sha256(result[1]).hexdigest()}
    except BaseException:
        stop_command(process)
        raise
    finally:
        selector.close()
        process.stdout.close()
        process.stderr.close()
        if process.stdin is not None and not process.stdin.closed:
            process.stdin.close()


def authority_object(value, fields):
    require(isinstance(value,dict) and set(value)==set(fields),'Missing/extra Composer authority field')
    for item in value.values():
        require(item is None or isinstance(item,str) and len(item.encode())<=4096,'Composer authority value bound')
    return dict(value)


def graph(packages):
    require(isinstance(packages, list) and len(packages) == 79, 'Production graph must have exactly 79 records')
    result = {}
    for package in packages:
        require(isinstance(package, dict), 'Malformed package')
        name, version = package.get('name'), package.get('version')
        require(isinstance(name, str) and 0 < len(name) <= 255 and name not in result
                and name != 'durable-workflow/workflow', 'Duplicate/dev/Core package')
        require(isinstance(version, str) and 0 < len(version) <= 128, 'Package version missing')
        require(not ({'source-mirrors','dist-mirrors','mirrors'} & set(package)), 'Unexpected mirror authority')
        source=authority_object(package.get('source'),('type','url','reference'))
        dist=authority_object(package.get('dist'),('type','url','reference','shasum'))
        result[name] = {'version': version, 'source':source, 'dist':dist}
    return dict(sorted(result.items()))


def docker_copy_targets(name):
    destinations = []
    if name.startswith('standalone/'):
        destinations.append(name[len('standalone/'):])
    if name.split('/')[0] in ('app', 'config', 'database', 'public', 'resources', 'routes'):
        destinations.append('waterline/'+name)
    if name.startswith('public/'):
        destinations.append('public/vendor/waterline/'+name[len('public/'):])
    return destinations


def archived_content(archive, member):
    require(member.isfile() and 0 <= member.size <= MAX_FILE, 'Archived source object type/bound')
    content = archive.extractfile(member).read(MAX_FILE+1)
    require(len(content) == member.size, 'Archived object length mismatch')
    return content


def expected_from_git(repository: Path):
    """Independent exact Git archive, never the candidate's own lock/source."""
    raw_tree, _ = command(['git', '-C', str(repository), 'rev-parse', SOURCE+'^{tree}'], time.monotonic()+5)
    require(raw_tree.strip().decode() == TREE, 'Reviewed source tree mismatch')
    archive, _ = command(['git', '-C', str(repository), 'archive', '--format=tar', SOURCE], time.monotonic()+15,
                         stdout_limit=MAX_TOTAL)
    files, lock, total = {}, None, 0
    with tarfile.open(fileobj=io.BytesIO(archive), mode='r:') as tar:
        for member in tar:
            name = member.name
            if member.isdir():
                continue
            destinations = docker_copy_targets(name)
            if not destinations:
                continue
            content = archived_content(tar, member)
            total += len(content)
            require(total <= MAX_TOTAL and len(files)+len(destinations) <= MAX_FILES, 'Archive aggregate bound')
            if name == 'standalone/composer.lock':
                require(hashlib.sha256(content).hexdigest() == LOCK, 'Reviewed lock mismatch')
                lock = bounded_json(content)
            for target in destinations:
                require(target not in files, 'Ambiguous Docker COPY mapping')
                mode = 0o755 if target in ('artisan', 'entrypoint.sh') else member.mode
                files[target] = {'mode': mode, 'size': len(content), 'sha256': hashlib.sha256(content).hexdigest()}
    require(lock is not None, 'Reviewed lock absent')
    return {'source': SOURCE, 'tree': TREE, 'lock': LOCK, 'php': '8.3.36', 'int_size': 8,
            'graph': graph(lock['packages']), 'files': dict(sorted(files.items())), 'classes': CLASSES}


def identity(expected, observed):
    require(isinstance(observed, dict), 'Missing identity observation')
    require(observed.get('core_installed') is False, 'Core installed in standalone')
    for key in ('php', 'int_size', 'graph', 'files', 'classes', 'installed_json_sha256'):
        require(observed.get(key) == expected[key], 'Installed '+key+' mismatch')
    require(observed['files'].get('composer.lock', {}).get('sha256') == expected['lock'], 'Observed lock mismatch')
    require(isinstance(observed.get('installed_json_sha256'), str)
            and DIGEST.fullmatch(observed['installed_json_sha256']) is not None, 'Installed bytes digest missing')


def route_rows(observation, cached):
    require(isinstance(observation, dict) and observation.get('cached') is cached, 'Wrong route-cache state')
    digest = observation.get('cache_sha256')
    require((isinstance(digest, str) and DIGEST.fullmatch(digest)) if cached else digest is None, 'Wrong cache identity')
    rows = observation.get('routes')
    require(isinstance(rows, list) and 0 < len(rows) <= 2048, 'Route count bound')
    names = set()
    for row in rows:
        require(isinstance(row, dict) and {'method', 'uri', 'name', 'action', 'middleware'}.issubset(row), 'Malformed route')
        name = row['name']
        if name not in (None, ''):
            require(isinstance(name, str) and name not in names, 'Ambiguous route name')
            names.add(name)
    return rows


def routes(before, after, cached):
    left, right = route_rows(before, False), route_rows(after, cached)
    require(len(left) == len(right), 'Route count changed')
    for original, observed in zip(left, right):
        a, b = dict(original), dict(observed)
        # Laravel's source display path is diagnostic, never routing behavior.
        a.pop('path', None)
        b.pop('path', None)
        if a.get('name') in (None, '') and isinstance(b.get('name'), str) and b['name'].startswith('generated::'):
            b['name'] = a.get('name')
        require(a == b, 'Route behavior/order changed')


def at(value, path):
    for key in path.split('.'):
        try:
            value = value[int(key)] if isinstance(value, list) else value[key]
        except (KeyError, IndexError, TypeError, ValueError):
            raise Refusal('API expectation missing') from None
    return value


def api_response(response, status, checks):
    require(isinstance(response, dict) and response.get('status') == status, 'API HTTP mismatch')
    body = response.get('body')
    for path, expected in checks.items():
        require(at(body, path) == expected, 'API value mismatch: '+path)
    return body


def cleanup_receipt(receipt):
    require(isinstance(receipt, dict) and set(receipt) == {'containers', 'networks', 'volumes', 'processes'}, 'Missing cleanup readback')
    require(all(value == [] for value in receipt.values()), 'Owned resource survived')


def fixed_budget(value, milliseconds):
    require(type(value) in (int,float), 'Budget must be a JSON number')
    return Decimal(str(value))*1000 == milliseconds


def browser_receipts(primary, automatic):
    require(isinstance(primary,dict) and primary.get('source')==SOURCE and primary.get('errors')==[], 'Primary browser provenance/error')
    checks=primary.get('checks')
    require(isinstance(checks,list) and len(checks)==16, 'Incomplete inherited browser controls')
    require([c.get('scenario') for c in checks[:3]]==['backend-stall','browser-stall','write-stall']
            and checks[-1].get('scenario')=='schedule-write-stall', 'Browser scenario identity')
    for check in checks[:2]:
        require(.15 <= check.get('elapsed',0) < 1.8 and check.get('recoveredRows')==2
                and fixed_budget(check.get('serviceBudget'),200) and fixed_budget(check.get('browserBudget'),800), 'Browser budget/recovery mismatch')
    backend=checks[0].get('api')
    require(isinstance(backend,list) and len(backend)>0 and backend[0].get('http')==502
            and at(backend[0],'body.reason')=='request_timeout'
            and fixed_budget(at(backend[0],'body.remote_details.timeout_seconds'),200),'Service timeout mismatch')
    for check in (checks[2],checks[-1]):
        require(check.get('outcome')=='unknown' and len(check.get('writes',[]))==1,'Mutation outcome/retry mismatch')
    represented=set()
    for check in checks[3:-1]:
        key=(check.get('view'),check.get('theme'),check.get('width'))
        require(check.get('scenario')=='affected-view-browser-stall' and key not in represented
                and check.get('recovered') is True and .6 <= check.get('elapsed',0) < 1.8
                and fixed_budget(check.get('browserBudget'),800), 'Affected-view budget/recovery mismatch')
        represented.add(key)
    require(represented=={(v,t,w) for v in ('schedules','workers','services') for t in ('dark','light') for w in (390,1440)}, 'Affected-view cohort mismatch')
    require(isinstance(automatic,dict) and automatic.get('source')==SOURCE and automatic.get('errors')==[]
            and fixed_budget(automatic.get('browserBudget'),3500) and 3 <= automatic.get('elapsed',0)<5
            and automatic.get('recoveredRows')==2 and len(automatic.get('requests',[]))==2,
            'Automatic outstanding-read/recovery mismatch')


def final_receipt(expected, observed, commands, cleanup, destination: Path):
    require(destination.parent.resolve() == destination.parent and not destination.exists(), 'Retained evidence contamination')
    cleanup_receipt(cleanup)
    require(len(commands) > 0 and all(c.get('exit') == 0 for c in commands), 'Incomplete command history')
    require(len(observed) == 3 and [p.get('phase') for p in observed] == ['clear-before', 'cached', 'clear-after'], 'Missing cache phase')
    for phase in observed:
        identity(expected, phase['identity'])
        require(phase.get('installed_graph')==expected['graph'], 'Host decoded installed graph mismatch')
        api_response(phase['health'], 200, {})
        require(phase.get('api')=={'checks':7,'result':'pass','fixture':'synthetic source protocol; not native compatibility'}, 'Missing strict API controls')
        browser_receipts(phase.get('browser'),phase.get('automatic'))
    routes(observed[0]['routes'], observed[1]['routes'], True)
    routes(observed[0]['routes'], observed[2]['routes'], False)
    raw = json.dumps({'schema': 'waterline.disabled-source-smoke-evidence/v1', 'expected': expected,
                      'observed': observed, 'commands': commands, 'cleanup': cleanup}, sort_keys=True).encode()
    require(len(raw) <= MAX_EVIDENCE, 'Final evidence bound')
    descriptor = os.open(destination, os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW, 0o600)
    with os.fdopen(descriptor, 'wb') as stream:
        stream.write(raw)
        stream.flush()
        os.fsync(stream.fileno())
    return hashlib.sha256(raw).hexdigest()
