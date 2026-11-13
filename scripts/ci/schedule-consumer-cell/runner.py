"""Finite source-overlay candidate cell; consume one independently reviewed packet.

This proposed runner has not executed. Its exact program and complete stdin packet
must be frozen and independently reviewed before the first Docker effect.
"""
import base64
import hashlib
import json
import os
import pathlib
import platform
import re
import secrets
import selectors
import signal
import subprocess
import sys
import time
import urllib.request

EXPECTED = {'candidate': {'sha256': '668b0299980683b2e53f0db56537960654ee21f9b5ec13dca815c649b7ecd41a', 'bytes': 9704876}, 'loader': {'sha256': 'ccedf708092a35477cf2f54202c0ca0f882f3abaef8ec3dc5a881de178e741d3', 'bytes': 4136}, 'proxy': {'sha256': 'b930ae341b97d414029e7adac2c369bde10ff94faf018475a85b1edf23c99d0c', 'bytes': 4806}, 'browser': {'sha256': 'e7dadf67338dc454911016f4d489531ed9ade55b109aa33522d748833ab016bf', 'bytes': 6368}, 'tools': {'sha256': 'f51ce58aef92d863dc04ea0b690c79a150a007d372f436ca211b4cd24560eed8', 'bytes': 25408909}, 'worker': {'sha256': '04802ce2d7fff2d978d6b8f368300b9ae8ba76249555a1307342778920edf0b4', 'bytes': 8537}, 'requirements': {'sha256': '7102fdc18f3ba8c29e5b05bcfd7a2a29f36c7ab48d2cc03fd9ad5b9a52398e47', 'bytes': 866}, 'ownership': {'sha256': '17ea9df38ac37df5c01e7c9c0ffcd2a4136d4bc5be782aa373ccc1846d6e2d40', 'bytes': 1267}, 'ca': {'sha256': '50f79f98dac192b20dbf73f35c56f0b0c557ab267ff5199d1117cc1180b70785', 'bytes': 238856}, 'workspace': {'sha256': '5513ae43acaae388631bddb3db999677734b2ee69627bd00f6b72c7e517b6ccb', 'bytes': 8078}}
PYTHON = 'python@sha256:935c3c5b12357a7e10caa9c1e6ca5692344d97cf229832ef584be2cec9fc03df'
BROWSER = 'mcr.microsoft.com/playwright@sha256:6e3fb709361521eb27161606e6da6d2674e5e89fa16516cb37d6fdd91372273d'
APPLICATION = 'docker.io/durableworkflow/waterline@sha256:2750484b0ec80b6b34026cbebb69677a966f4c342a26e7c9313f2b89d3cbb315'
CANDIDATE = '4d89fcdd7597a78fcc8a3fdc43804eb0afb4d6c5'
SERVER_SOURCE = 'b6e04ce7e679464e91f6ceae83310d3bb3204092'
RUNTIME = {'index':'sha256:ca92c866fc0f3819068c5088fbd0a79115bb5588c06a4f5324b9280356c08bad', 'amd64':'sha256:9d64e9aeee84b46d21715826a3e7e5a96eb8d4ce7fccb126b88b15596ab14973', 'version':'3.10.0', 'source':SERVER_SOURCE}


def digest(data):
    return hashlib.sha256(data).hexdigest()


def captured_inputs(packet):
    if set(packet) != {'subjects', 'runtime'} or set(packet['subjects']) != set(EXPECTED):
        raise ValueError('complete exact packet required')
    captured = {}
    for name, expected in EXPECTED.items():
        data = base64.b64decode(packet['subjects'][name], validate=True)
        if len(data) != expected['bytes'] or digest(data) != expected['sha256']:
            raise ValueError('captured subject differs')
        captured[name] = data
    runtime = packet['runtime']
    if runtime != RUNTIME:
        raise ValueError('runtime differs from qualified owner handoff')
    if (set(runtime) != {'index', 'amd64', 'version', 'source'}
        or runtime['version'] != '3.10.0' or runtime['source'] != SERVER_SOURCE
        or any(re.fullmatch(r'sha256:[0-9a-f]{64}', runtime[k]) is None for k in ['index', 'amd64'])):
        raise ValueError('independently selected native 3.10 tuple required')
    if json.loads(captured['candidate'])['commit'] != CANDIDATE:
        raise ValueError('candidate commit differs')
    return captured, runtime


class Cell:
    def __init__(self, workspace, subjects):
        self.workspace = workspace
        self.root = workspace.path
        self.subjects = subjects
        self.ids = {}
        self.labels = {}
        self.processes = []
        self.files = []
        self.nonce = secrets.token_hex(12)
        config = workspace.directory('docker')
        workspace.write('config.json', '{"auths":{}}\n', parent=config)
        self.docker = ['/usr/bin/docker', '--host', 'unix:///var/run/docker.sock', '--config', workspace.proc(config)]
        self.cleanup_docker = ['/usr/bin/docker', '--host', 'unix:///var/run/docker.sock', '--config', '/proc/self/fd']
        self.credentials = []
        scope = {}
        exec(compile(subjects['ownership'], 'captured-owned-docker.py', 'exec'), scope)
        self.raw_inspect = scope['inspect_owned']
        self.remove = scope['remove_owned']

    def check(self):
        recheck = globals().get('__waterline_recheck_inputs__')
        if not callable(recheck):
            raise RuntimeError('literal reviewed bootstrap required')
        recheck()
        self.workspace.check()

    def inspect(self, *args, **kwargs):
        self.check()
        result = self.raw_inspect(*args, **kwargs)
        self.check()
        return result

    def run(self, args, **kwargs):
        self.check()
        result = subprocess.run(self.docker + args, check=True, capture_output=True, timeout=kwargs.pop('timeout', 30), **kwargs)
        if args[0] != 'create':
            self.check()
        return result

    def create(self, role, image, options, command):
        labels = {'dev.durable-workflow.fixture-owner': 'waterline',
                  'dev.durable-workflow.fixture-run': self.nonce,
                  'dev.durable-workflow.fixture-role': role}
        flags = [part for k, v in labels.items() for part in ['--label', k + '=' + v]]
        result = self.run(['create', '--name', 'waterline-222-' + role + '-' + self.nonce,
                           '--init', '--read-only', '--cap-drop', 'ALL', '--security-opt', 'no-new-privileges',
                           *flags, *options, image, *command])
        identifier = result.stdout.decode().strip()
        if re.fullmatch(r'[0-9a-f]{64}', identifier) is None:
            raise ValueError('create did not return full ID')
        self.ids[role], self.labels[role] = identifier, labels
        state = self.inspect(self.docker, identifier, labels)
        if any(mount['Type'] != 'tmpfs' for mount in state['Mounts']):
            raise ValueError('host mounts and anonymous volumes are forbidden')
        self.run(['start', identifier])
        return identifier

    def command(self, role, *args):
        self.inspect(self.docker, self.ids[role], self.labels[role])
        return self.docker + [args[0], self.ids[role], *args[1:]]

    def execute(self, role, program, binary='python', user=None, data=None, timeout=30):
        self.inspect(self.docker, self.ids[role], self.labels[role])
        options = ['exec', '-i']
        if user is not None:
            options += ['--user', user]
        command = self.docker + options + [self.ids[role], binary]
        command += ['-r', program] if binary == 'php' else ['-I', '-B', '-c', program]
        result = subprocess.run(command, input=data, check=True, capture_output=True, timeout=timeout).stdout
        self.check()
        return result

    def process(self, role, arguments, data, name, user=None):
        self.inspect(self.docker, self.ids[role], self.labels[role])
        stdout_item = self.workspace.write(name + '.stdout', b'', immutable=False)
        stderr_item = self.workspace.write(name + '.stderr', b'', immutable=False)
        stdout_item['maximum'] = stderr_item['maximum'] = 4 * 1024 * 1024
        output = os.fdopen(os.dup(stdout_item['fd']), 'wb')
        errors = os.fdopen(os.dup(stderr_item['fd']), 'wb')
        self.files.extend([output, errors])
        options = ['exec', '-i'] + (['--user', user] if user else [])
        child = subprocess.Popen(self.docker + options + [self.ids[role], *arguments],
                                 stdin=subprocess.PIPE, stdout=output, stderr=errors)
        self.processes.append(child)
        if data is not None:
            child.stdin.write(data)
            child.stdin.close()
            child.stdin = None
        return child

    def cleanup(self):
        errors, oom = [], []
        for role in reversed(list(self.ids)):
            try:
                state = self.raw_inspect(self.cleanup_docker, self.ids[role], self.labels[role], True)
                if state is not None and state['State'].get('OOMKilled'):
                    oom.append(role)
                self.remove(self.cleanup_docker, self.ids[role], self.labels[role])
            except Exception as error:
                errors.append(role + ':' + type(error).__name__)
        for process in self.processes:
            try:
                if process.poll() is None:
                    process.kill()
                process.wait(timeout=2)
            except Exception as error:
                errors.append('process:' + type(error).__name__)
        for file in self.files:
            file.close()
        result = {'containers_absent': not errors, 'oom': oom, 'errors': errors,
                  'ids': self.ids, 'labels': self.labels, 'host_mounts': [], 'anonymous_volumes': []}
        for item in self.credentials:
            try:
                self.workspace.remove(item)
            except Exception as error:
                errors.append('credential:' + type(error).__name__)
        result['errors'] = errors
        self.workspace.check()
        self.workspace.write('cleanup.json', json.dumps(result, indent=2))
        if errors or oom:
            raise RuntimeError('cleanup or OOM gate failed')


def image(cell, reference, expected_child=None, revision=None, version=None):
    raw = cell.run(['buildx', 'imagetools', 'inspect', '--raw', reference]).stdout
    if digest(raw) != reference.rsplit(':', 1)[1]:
        raise ValueError('registry index bytes differ')
    if expected_child and not any(item.get('platform') == {'architecture': 'amd64', 'os': 'linux'}
                                  and item['digest'] == expected_child for item in json.loads(raw)['manifests']):
        raise ValueError('amd64 child differs')
    cell.run(['pull', '--platform', 'linux/amd64', reference], timeout=180)
    actual = json.loads(cell.run(['image', 'inspect', reference]).stdout)[0]
    if actual['Architecture'] != 'amd64' or actual['Os'] != 'linux':
        raise ValueError('native platform differs')
    labels = actual['Config'].get('Labels') or {}
    if revision and labels.get('org.opencontainers.image.revision') != revision:
        raise ValueError('image source differs')
    if version and labels.get('dev.durable-workflow.release.tag') != version:
        raise ValueError('image version differs')
    return actual['Id']


def request(base, path, token=None, body=None, parse_json=True):
    headers = {'Accept': 'application/json', 'X-Namespace': 'default',
               'X-Durable-Workflow-Control-Plane-Version': '2'}
    if token:
        headers['Authorization'] = 'Bearer ' + token
    if body is not None:
        headers['Content-Type'] = 'application/json'
    class NoRedirect(urllib.request.HTTPRedirectHandler):
        def redirect_request(self, *args, **kwargs):
            return None
    opener = urllib.request.build_opener(urllib.request.ProxyHandler({}), NoRedirect())
    value = json.dumps(body).encode() if body is not None else None
    with opener.open(urllib.request.Request(base + path, data=value, headers=headers), timeout=5) as response:
        raw = response.read(4 * 1024 * 1024 + 1)
        if len(raw) > 4 * 1024 * 1024:
            raise ValueError('HTTP response exceeded bound')
        return (json.loads(raw) if raw else None) if parse_json else response.status


# These fixed programs are part of this runner's independently reviewed bytes.
SETUP = '''import base64,hashlib,json,os,pathlib,subprocess,sys,venv
b=json.load(sys.stdin);r=pathlib.Path('/run/waterline-fixture')
assert sys.version_info[:3]==(3,12,16)
for name in ['requirements','ca']:
 data=base64.b64decode(b[name],validate=True);(r/name).write_bytes(data)
venv.EnvBuilder(with_pip=True,symlinks=False).create('/opt/waterline/venv')
pip=['/opt/waterline/venv/bin/python','-I','-B','-m','pip','--isolated']
common=['--only-binary=:all:','--require-hashes','--disable-pip-version-check','--progress-bar','off','--no-cache-dir','-r',str(r/'requirements')]
e={'PATH':os.defpath,'TMPDIR':'/tmp','PIP_CONFIG_FILE':'/dev/null'}
subprocess.run(pip+['download','--no-deps','--dest',str(r/'wheels'),'--cert',str(r/'ca'),'--index-url','https://pypi.org/simple']+common,env=e,check=True,timeout=180)
subprocess.run(pip+['install','--no-compile','--no-index','--find-links',str(r/'wheels')]+common,env=e,check=True,timeout=60)
w=(r/'wheels/durable_workflow-2.17.2-py3-none-any.whl').read_bytes()
assert hashlib.sha256(w).hexdigest()=='7dc77d3882fa5cbddbeaee6a4cc63b05285e280523c8ea40dc31e03cc12a40bc'
(r/'sdk.whl').write_bytes(w)
'''
INVENTORY = '''import hashlib,json,pathlib,stat
r=pathlib.Path('/opt/waterline/venv');files={}
for p in sorted(r.rglob('*')):
 if p.is_symlink():
  assert p.resolve().is_relative_to(r);value=['link',str(p.readlink())]
 elif p.is_file():value=['file',hashlib.sha256(p.read_bytes()).hexdigest(),stat.S_IMODE(p.stat().st_mode)]
 else:continue
 files[p.relative_to(r).as_posix()]=value
print(json.dumps({'file_count':len(files),'sha256':hashlib.sha256(json.dumps(files,sort_keys=True,separators=(',',':')).encode()).hexdigest()}))
'''
WORKER_LOAD = '''import base64,hashlib,json,linecache,pathlib,sys,types
b=json.load(sys.stdin);data=base64.b64decode(b['source'],validate=True);assert hashlib.sha256(data).hexdigest()==b['sha256']
r=pathlib.Path('/run/waterline-fixture');p=r/'token';p.write_text(b['token']);p.chmod(0o600)
m=types.ModuleType('captured_schedule_worker');m.__file__='captured-schedule-worker.py';sys.modules[m.__name__]=m
linecache.cache[m.__file__]=(len(data),None,data.decode().splitlines(keepends=True),m.__file__)
exec(compile(data,m.__file__,'exec'),m.__dict__)
sys.argv=[m.__file__,'--server','http://127.0.0.1:8080','--schedule-id',b['schedule_id']]
try:status=m.main()
finally:p.unlink(missing_ok=True)
raise SystemExit(status)
'''
APP_FINGERPRINT = r'''
require '/app/vendor/autoload.php';
$sdk = [Composer\InstalledVersions::getPrettyVersion('durable-workflow/sdk'), Composer\InstalledVersions::getReference('durable-workflow/sdk')];
if ($sdk !== ['3.16.0', '00a531e688ccbcaf4ce59a662cdbcd03dd49ee4c']) { throw new RuntimeException('Base SDK identity differs.'); }
$files=[];
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator('/app/vendor', FilesystemIterator::SKIP_DOTS)) as $file) {
 if ($file->isLink()) { $target=realpath($file->getPathname());if(!is_string($target)||!str_starts_with($target,'/app/vendor/'))throw new RuntimeException('Vendor link escapes immutable root.');$files[substr($file->getPathname(),12)]='link:'.readlink($file->getPathname());continue; }
 if ($file->isFile()) { $files[substr($file->getPathname(),12)]=hash_file('sha256',$file->getPathname()); }
}
ksort($files); $classes=[];
foreach ([Waterline\Http\Controllers\Remote\RemoteSchedulesController::class, Waterline\Support\ScheduleDispatchSummary::class, Waterline\Support\ScheduleCurrentRun::class] as $class) {
 $file=(new ReflectionClass($class))->getFileName();
 if (!is_string($file) || !str_starts_with($file,'/app/waterline/app/')) { throw new RuntimeException('Candidate class escaped capture.'); }
 $classes[$class]=$file;
}
$input=json_decode(stream_get_contents(STDIN),true,flags:JSON_THROW_ON_ERROR);$candidate=[];
foreach($input['leaves'] as $leaf) {
 $name=$leaf['path'];$file='/app/waterline/'.$name;$metadata=lstat($file);
 if ($metadata['uid']!==0 || $metadata['gid']!==82 || ($metadata['mode'] & 0777)!==0440 || hash_file('sha256',$file)!==$leaf['sha256']) { throw new RuntimeException('Candidate leaf changed.'); }
 if(str_starts_with($name,'public/') && hash_file('sha256','/app/public/vendor/waterline/'.substr($name,7))!==$leaf['sha256']) { throw new RuntimeException('Served asset changed.'); }
 $candidate[$name]=$leaf['sha256'];
}
echo json_encode(['sdk'=>$sdk,'vendor_files'=>count($files),'vendor_sha256'=>hash('sha256',json_encode($files,JSON_THROW_ON_ERROR)),'classes'=>$classes,'candidate'=>$candidate],JSON_THROW_ON_ERROR);
'''
TOOLS_LOAD = r'''
const fs=require('node:fs'),path=require('node:path'),crypto=require('node:crypto');
let text='';process.stdin.on('data',part=>{text+=part;if(Buffer.byteLength(text)>32*1024*1024)throw Error('tool input exceeded');});
process.stdin.on('end',()=>{const data=JSON.parse(text),root='/opt/waterline-browser';
 if(data.version!=='1.65.0'||data.leaves.length!==176)throw Error('tool profile differs');
 const validated=[],names=new Set();let total=0;
 for(const item of data.leaves){
  if(!/^node_modules\/(playwright|playwright-core)\/(?:[A-Za-z0-9_.@+-]+\/)*[A-Za-z0-9_.@+-]+$/.test(item.path)||item.path.split('/').some(x=>x==='.'||x==='..')||names.has(item.path))throw Error('unexpected tool leaf');
  const bytes=Buffer.from(item.base64,'base64');total+=bytes.length;
  if(bytes.length!==item.size||crypto.createHash('sha256').update(bytes).digest('hex')!==item.sha256||total>24*1024*1024)throw Error('tool bytes differ');
  names.add(item.path);validated.push([path.join(root,item.path),bytes]);
 }
 const dirs=new Set([root]);
 for(const [file,bytes]of validated){let dir=path.dirname(file);fs.mkdirSync(dir,{recursive:true});while(dir!==root){dirs.add(dir);dir=path.dirname(dir);}fs.writeFileSync(file,bytes,{flag:'wx',mode:0o444});}
 fs.writeFileSync(root+'/package.json',JSON.stringify({private:true}),{flag:'wx',mode:0o444});
 for(const dir of [...dirs].sort((a,b)=>b.length-a.length))fs.chmodSync(dir,0o555);
 console.log('tool capture sealed');
});
'''
SEED = '''import asyncio,json,os,stat,sys
import durable_workflow as sdk
b=json.load(sys.stdin);fd=os.open('/run/waterline-fixture/token',os.O_RDONLY|os.O_NOFOLLOW)
try:
 m=os.fstat(fd);assert stat.S_ISREG(m.st_mode) and stat.S_IMODE(m.st_mode)==0o600 and 32<=m.st_size<=256
 token=os.read(fd,257).decode('ascii');assert token and token.strip()==token
finally:os.close(fd)
async def main():
 async with sdk.Client('http://127.0.0.1:8080',token=token,namespace='default') as client:
  for i in range(50):
   await client.create_schedule(schedule_id=b['prefix']+f'-{i:02}',spec=sdk.ScheduleSpec(intervals=[{'every':'PT24H'}],timezone='UTC'),action=sdk.ScheduleAction(workflow_type='waterline.schedule-controlled-failure',task_queue=b['schedule_id'],input=['/run/waterline-fixture/release']),overlap_policy='skip',jitter_seconds=0,max_runs=5)
asyncio.run(main());print('50 later schedules created')
'''


def wait_json(cell, role, filename, predicate, deadline):
    # Only fixed fixture data files can be observed; no caller-supplied paths.
    if filename not in {'report.json', 'proxy.json'}:
        raise ValueError('fixed report required')
    code = 'import json,pathlib;p=pathlib.Path("/run/waterline-fixture/' + filename + '");print(p.read_text() if p.exists() else "null")'
    while time.monotonic() < deadline:
        result = json.loads(cell.execute(role, code))
        if result is not None and predicate(result):
            return result
        time.sleep(0.1)
    raise TimeoutError('fixture observation deadline')


class BrowserProtocol:
    def __init__(self, cell):
        self.cell = cell
        item = cell.workspace.write('browser.stderr', b'', immutable=False)
        item['maximum'] = 4 * 1024 * 1024
        errors = os.fdopen(os.dup(item['fd']), 'wb')
        cell.files.append(errors)
        cell.inspect(cell.docker, cell.ids['browser'], cell.labels['browser'])
        self.child = subprocess.Popen(cell.docker + ['exec', '-i', '--user', '1000:1000', cell.ids['browser'],
                                      'node', '--input-type=module', '-e', cell.subjects['browser'].decode()],
                                      stdin=subprocess.PIPE, stdout=subprocess.PIPE, stderr=errors)
        cell.processes.append(self.child)
        self.selector = selectors.DefaultSelector()
        self.selector.register(self.child.stdout, selectors.EVENT_READ)
        self.buffer = b''

    def send(self, phase, **fields):
        self.child.stdin.write((json.dumps({'phase': phase, **fields}) + '\n').encode())
        self.child.stdin.flush()

    def receive(self, expected):
        deadline = time.monotonic() + 40
        while time.monotonic() < deadline:
            if b'\n' in self.buffer:
                line, self.buffer = self.buffer.split(b'\n', 1)
                result = json.loads(line)
                if result['phase'] == 'screenshot':
                    if result['name'] not in {'desktop-preserved-dialog', 'mobile-result-row'}:
                        raise ValueError('unexpected screenshot')
                    data = base64.b64decode(result['base64'], validate=True)
                    if len(data) > 4 * 1024 * 1024:
                        raise ValueError('screenshot exceeds budget')
                    self.cell.workspace.write(result['name'] + '.png', data)
                    continue
                if result['phase'] != expected:
                    raise ValueError('unexpected browser phase')
                return result
            if not self.selector.select(max(0, deadline - time.monotonic())):
                break
            part = os.read(self.child.stdout.fileno(), 65536)
            if not part:
                raise RuntimeError('browser ended before required observation')
            self.buffer += part
            if len(self.buffer) > 8 * 1024 * 1024:
                raise ValueError('browser output exceeds budget')
        raise TimeoutError('browser observation deadline')

    def finish(self):
        self.child.stdin.close()
        if self.child.wait(timeout=15) != 0:
            raise RuntimeError('browser exit gate failed')
        self.selector.close()


def loader_program(subjects):
    loader_source = subjects['loader'].decode().removeprefix('<?php')
    if loader_source.count('declare(strict_types=1);') != 1:
        raise ValueError('loader declaration differs')
    return loader_source.replace('declare(strict_types=1);', 'declare(strict_types=1);\n$expectedPayloadSha256="' + EXPECTED['candidate']['sha256'] + '";', 1)


def prove(cell, runtime):
    subjects = cell.subjects
    native = 'ghcr.io/durable-workflow/server@' + runtime['index']
    # Independently rehash both published registry indexes before importing.
    mirror = cell.run(['buildx', 'imagetools', 'inspect', '--raw', 'docker.io/durableworkflow/server@' + runtime['index']]).stdout
    if digest(mirror) != runtime['index'].split(':')[1]:
        raise ValueError('native mirror differs')
    native_id = image(cell, native, runtime['amd64'], SERVER_SOURCE)
    python_id = image(cell, PYTHON, 'sha256:0f086103b026048cc50cb5da25ec435c023c5241a83fc44daea593377abc61c4')
    application_id = image(cell, APPLICATION, 'sha256:55930b99e5d0e87968331c1c4acb27d79c01172c816838244e5087f2d4c5c14d',
                           '9c241c573f479a13982fa390c21b226ed19759d1', '2.18.0')
    browser_id = image(cell, BROWSER)
    token = secrets.token_hex(32)
    env = cell.workspace.write('runtime.env', 'DW_AUTH_DRIVER=token\nDW_AUTH_TOKEN=' + token + '\nDB_CONNECTION=sqlite\n')
    cell.credentials.append(env)
    cell.create('runtime', native_id,
        ['--cpus','1','--memory','1g','--pids-limit','256', '--env-file',cell.workspace.proc(env['fd']),
         '--publish','127.0.0.1::8080','--publish','127.0.0.1::8081',
         '--tmpfs','/app/database:rw,nosuid,nodev,noexec,size=256m,uid=1000,gid=1000,mode=0750',
         '--tmpfs','/app/storage:rw,nosuid,nodev,noexec,size=128m,uid=1000,gid=1000,mode=0750',
         '--tmpfs','/tmp:rw,nosuid,nodev,noexec,size=64m,mode=1777'], [])
    state = cell.inspect(cell.docker, cell.ids['runtime'], cell.labels['runtime'])
    ports = state['NetworkSettings']['Ports']
    base = 'http://127.0.0.1:' + ports['8080/tcp'][0]['HostPort']
    app_base = 'http://127.0.0.1:' + ports['8081/tcp'][0]['HostPort']
    deadline = time.monotonic() + 30
    while True:
        try:
            request(base, '/api/ready', parse_json=False)
            break
        except Exception:
            if time.monotonic() >= deadline:
                raise TimeoutError('native readiness gate')
            time.sleep(.2)
    shared = 'container:' + cell.ids['runtime']
    cell.create('consumer', python_id,
        ['--cpus','1','--memory','1g','--pids-limit','256','--user','1000:1000','--network',shared,
         '--tmpfs','/tmp:rw,nosuid,nodev,noexec,size=128m,mode=1777',
         '--tmpfs','/run/waterline-fixture:rw,nosuid,nodev,noexec,size=64m,uid=1000,gid=1000,mode=0700',
         '--tmpfs','/opt/waterline:rw,nosuid,nodev,exec,size=256m,uid=1000,gid=1000,mode=0700'],
        ['python','-I','-B','-c','import time;time.sleep(600)'])
    data = {name: base64.b64encode(subjects[name]).decode() for name in ['requirements','ca']}
    cell.execute('consumer', SETUP, data=json.dumps(data).encode(), timeout=250)
    environment_before = json.loads(cell.execute('consumer', INVENTORY))
    proxy_program = subjects['proxy'].decode() + '''\nimport json,pathlib,time
server,thread,records=make_proxy(8080,8082);thread.start()
p=pathlib.Path('/run/waterline-fixture/proxy.json')
try:
 for _ in range(3000):
  q=p.with_suffix('.next');q.write_text(json.dumps(records));q.replace(p);time.sleep(.1)
finally:server.shutdown();server.server_close();thread.join()
'''
    cell.process('consumer',['python','-I','-B','-c',proxy_program],None,'proxy')
    wait_json(cell,'consumer','proxy.json',lambda rows: isinstance(rows,list),time.monotonic()+5)
    key=base64.b64encode(secrets.token_bytes(32)).decode()
    app_env = cell.workspace.write('application.env', 'APP_KEY=base64:'+key+'\nWATERLINE_SERVER_ENDPOINT=http://127.0.0.1:8082\nWATERLINE_SERVER_TOKEN='+token+'\nWATERLINE_NAMESPACE=default\nWATERLINE_ACCESS_MODE=operator\nWATERLINE_ALLOW_UNAUTHENTICATED=true\n')
    cell.credentials.append(app_env)
    cell.create('application', application_id,
        ['--cpus','1','--memory','1g','--pids-limit','256','--user','82:82','--network',shared,
         '--env-file',cell.workspace.proc(app_env['fd']),'--entrypoint','sh','--workdir','/app/public',
         '--tmpfs','/app/waterline:rw,nosuid,nodev,noexec,size=16m,gid=82,mode=0750',
         '--tmpfs','/app/public/vendor/waterline:rw,nosuid,nodev,noexec,size=16m,gid=82,mode=0750',
         '--tmpfs','/app/bootstrap/cache:rw,nosuid,nodev,noexec,size=8m,uid=82,gid=82,mode=0750',
         '--tmpfs','/app/storage:rw,nosuid,nodev,noexec,size=64m,uid=82,gid=82,mode=0750',
         '--tmpfs','/data:rw,nosuid,nodev,noexec,size=64m,uid=82,gid=82,mode=0750',
         '--tmpfs','/tmp:rw,nosuid,nodev,noexec,size=64m,mode=1777'], ['-c','sleep 600'])
    # The expected hash comes from this frozen runner, not from candidate stdin.
    loader = loader_program(subjects)
    capture = json.loads(cell.execute('application',loader,binary='php',user='0:82',data=subjects['candidate']))
    expected = {leaf['path']:leaf['sha256'] for leaf in json.loads(subjects['candidate'])['leaves']}
    if capture['source'] != CANDIDATE or capture['leaves'] != expected:
        raise ValueError('copied package differs from retained Git capture')
    before = json.loads(cell.execute('application',APP_FINGERPRINT,binary='php',data=subjects['candidate']))
    init = r'''chdir('/app');foreach(['storage/framework/cache/data','storage/framework/sessions','storage/framework/views','storage/logs'] as $directory){if(!is_dir($directory)&&!mkdir($directory,0750,true))throw new RuntimeException('runtime directory failed');}require '/app/vendor/autoload.php';$app=require '/app/bootstrap/app.php';$kernel=$app->make(Illuminate\Contracts\Console\Kernel::class);$kernel->bootstrap();if(Illuminate\Support\Facades\Artisan::call('migrate',['--force'=>true])!==0)throw new RuntimeException('migration failed');'''
    cell.execute('application',init,binary='php')
    app = cell.process('application',['php','-d','variables_order=EGPCS','-S','0.0.0.0:8081','/app/vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php'],None,'application')
    deadline=time.monotonic()+20
    while True:
        try:
            request(app_base,'/up',parse_json=False)
            break
        except Exception:
            if app.poll() is not None or time.monotonic()>=deadline:
                raise TimeoutError('candidate readiness gate')
            time.sleep(.2)
    schedule='waterline-controlled-'+cell.nonce
    inputs={'source':base64.b64encode(subjects['worker']).decode(),'sha256':EXPECTED['worker']['sha256'],'token':token,'schedule_id':schedule}
    worker=cell.process('consumer',['/opt/waterline/venv/bin/python','-I','-B','-c',WORKER_LOAD],json.dumps(inputs).encode(),'worker')
    ready=wait_json(cell,'consumer','report.json',lambda r:r.get('gate')=='activity_started',time.monotonic()+40)
    if ready['outcome']!='pending' or ready['before_bucket']!='running':raise ValueError('worker gate differs')
    cell.execute('consumer',SEED,binary='/opt/waterline/venv/bin/python',data=json.dumps({'prefix':'waterline-newer-'+cell.nonce,'schedule_id':schedule}).encode())
    def counters():
        return wait_json(cell,'consumer','proxy.json',lambda rows:all('finished'in r for r in rows),time.monotonic()+5)
    start=len(counters())
    page=request(app_base,'/waterline/api/v2/schedules')
    deadline=time.monotonic()+5
    reads=wait_json(cell,'consumer','proxy.json',lambda rows:len(rows)>start and all('finished'in r for r in rows),deadline)[start:]
    list_reads=[r for r in reads if r['path']=='/api/schedules']
    metadata_reads=[r for r in reads if r['path']!='/api/schedules']
    allowed_metadata={'/api/system/operator-dashboard/bounded','/api/system/operator-metrics'}
    if (len(page['data'])!=50 or page['refresh_interval_ms']!=3000 or len(list_reads)!=1
        or len(metadata_reads)>2 or any(r['path'] not in allowed_metadata for r in metadata_reads)):
        raise ValueError('50-row list budget failed')
    cell.create('browser',browser_id,
        ['--cpus','2','--memory','2g','--pids-limit','256','--network','host','--user','1000:1000',
         '--entrypoint','node','--env','PLAYWRIGHT_BROWSERS_PATH=/ms-playwright',
         '--env','XDG_CONFIG_HOME=/tmp/config','--env','XDG_CACHE_HOME=/tmp/cache',
         '--tmpfs','/tmp:rw,nosuid,nodev,size=512m,mode=1777',
         '--tmpfs','/opt/waterline-browser:rw,nosuid,nodev,noexec,size=32m,mode=0755'],
        ['-e','setTimeout(()=>{},300000)'])
    cell.inspect(cell.docker,cell.ids['browser'],cell.labels['browser'])
    subprocess.run(cell.docker+['exec','-i','--user','0',cell.ids['browser'],'node','-e',TOOLS_LOAD],input=subjects['tools'],check=True,capture_output=True,timeout=20)
    browser=BrowserProtocol(cell)
    browser.send('start',url=app_base+'/',schedule_id=schedule,workflow_type='waterline.schedule-controlled-failure')
    browser.receive('ready_for_external_pause')
    request(base,'/api/schedules/'+schedule+'/pause',token,{})
    browser.send('paused');browser.receive('paused_visible')
    request(base,'/api/schedules/'+schedule+'/resume',token,{})
    cell.execute('consumer','import os;f=os.open("/run/waterline-fixture/release",os.O_WRONLY|os.O_CREAT|os.O_EXCL|os.O_NOFOLLOW,0o600);os.close(f)')
    browser.send('resumed_and_released')
    observed=browser.receive('complete')['report'];browser.finish()
    if worker.wait(timeout=30)!=0:raise RuntimeError('Worker exit gate failed')
    outcome=wait_json(cell,'consumer','report.json',lambda r:r['outcome']=='pass',time.monotonic()+5)
    expected_lists=len(observed['list_reads'])+1
    final_reads=wait_json(cell,'consumer','proxy.json',lambda rows:sum(r['path']=='/api/schedules' for r in rows)>=expected_lists and all('finished'in r for r in rows),time.monotonic()+5)
    if sum(r['path']=='/api/schedules' for r in final_reads)!=expected_lists:
        raise ValueError('known-page refresh was not one list call per browser read')
    if any(r['path'].startswith('/api/schedules/') or r.get('proxy_error') for r in final_reads):
        raise ValueError('per-row describe or proxy error observed')
    after=json.loads(cell.execute('application',APP_FINGERPRINT,binary='php',data=subjects['candidate']))
    environment_after=json.loads(cell.execute('consumer',INVENTORY))
    if before!=after or environment_before!=environment_after:raise ValueError('code/vendor/environment changed')
    return {'classification':'source-overlay-candidate','outcome':'pending_cleanup','business_observation':'pass', 'candidate':CANDIDATE,
            'runtime':runtime,'base_application':APPLICATION,'worker':outcome,'browser':observed,
            'list_budget':{'rows':50,'list_calls':1,'describe_calls':0,'metadata_calls':len(metadata_reads),'browser_list_reads':len(observed['list_reads']),'upstream_list_reads':expected_lists},'proxy_reads':final_reads,
            'application_fingerprint':before,'environment':environment_before,
            'loader_program_sha256':digest(loader.encode()),'capture':capture}


def main():
    if not sys.flags.isolated or sys.flags.optimize or platform.machine()!='x86_64' or sys.platform!='linux':
        raise RuntimeError('isolated assertions-enabled native amd64 host required')
    def timeout(_signum,_frame):raise TimeoutError('finite cell work deadline')
    signal.signal(signal.SIGALRM,timeout);signal.signal(signal.SIGTERM,timeout);signal.alarm(390)
    recheck = globals().get('__waterline_recheck_inputs__')
    if not callable(recheck):raise RuntimeError('literal reviewed bootstrap required')
    recheck()
    raw=sys.stdin.buffer.read(64*1024*1024+1)
    if len(raw)>64*1024*1024:raise ValueError('packet exceeds bound')
    subjects,runtime=captured_inputs(json.loads(raw))
    parent=pathlib.Path.cwd()
    metadata=parent.lstat()
    if not parent.is_absolute() or parent.resolve()!=parent or metadata.st_uid!=os.geteuid() or metadata.st_mode & 0o077:
        raise ValueError('owned private disk-backed working directory required')
    os.environ.clear()
    os.environ.update({'PATH':os.defpath})
    namespace = {}
    exec(compile(subjects['workspace'], 'captured-workspace.py', 'exec'), namespace)
    output = parent / ('waterline-schedule-cell-' + secrets.token_hex(12))
    with namespace['Workspace'](output) as workspace:
        cell=Cell(workspace,subjects)
        workspace.write('subjects.json', json.dumps({'packet_sha256':digest(raw),'subjects':EXPECTED,'runtime':runtime,'bootstrap':globals().get('__waterline_bootstrap_identity__')},indent=2))
        result={'outcome':'NONPASS'}
        try:
            result=prove(cell,runtime)
        finally:
            signal.alarm(0)
            try:
                cell.cleanup()
                if result.get('business_observation') == 'pass':
                    result['outcome']='pass'
            finally:
                cell.check()
                workspace.write('result.json', json.dumps(result,indent=2))
        # Emit only after rechecking the held evidence and every ancestor.
        cell.check()
        evidence=str(output)
        print(json.dumps({'outcome':'pass','classification':'source-overlay-candidate','packet_sha256':digest(raw),'evidence_directory':evidence}),flush=True)


if __name__=='__main__':
    try:main()
    except Exception as error:
        print('Schedule cell NONPASS: '+type(error).__name__,file=sys.stderr)
        raise SystemExit(1)
