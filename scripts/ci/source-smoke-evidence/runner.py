#!/usr/bin/env python3
"""Disabled extension of service-mode-image-smoke.sh, never a release lane.

Changing EXECUTION_ENABLED requires a separately reviewed source change and
General/Security admission. There is intentionally no CLI/env override.
"""
from __future__ import annotations

import argparse
import hashlib
import json
import os
import re
import secrets
import signal
import subprocess
import sys
import time
from pathlib import Path

# The live script cannot import a later-replaced worktree helper before its hold.
if __name__=='__main__':
    raise SystemExit('HELD: use only an independently admitted Git-object launcher')

from packet_snapshot import reviewed_packet, exact_manifest, snapshot_archive, verify_archive, SEAL_PROGRAM
from evidence import (SOURCE, MAX_JSON, Refusal, api_response, bounded_json, command,
                      expected_from_git, final_receipt, graph, identity, object_bytes, require)

EXECUTION_ENABLED = False
LABEL = 'dev.durable-workflow.waterline-source-evidence'
CONFIG = {'backend':'service', 'path':'waterline', 'namespace':'smoke',
          'access_mode':'operator', 'allow_unauthenticated':True, 'service_budget':'.2'}
ROLES = {'app':('1','768m','256'), 'fixture':('.25','256m','128'),
         'proxy':('.25','256m','128'), 'browser':('1','1g','256')}
ARTISAN = '/app/artisan'
SMOKE_TYPE = 'smoke.order'
PACKET = Path(__file__).resolve().parent
ROOT = PACKET.parents[2]


def validate_spec(spec):
    require(isinstance(spec, dict) and set(spec) == {
        'source','platform','image_id','image_config_sha256','browser_image_id',
        'browser_config_sha256','vendor_preparation_receipt_sha256','packet_commit','packet_tree'}, 'Admission input shape')
    require(all(isinstance(spec[k],str) and re.fullmatch(r'[a-f0-9]{40}',spec[k]) for k in ('packet_commit','packet_tree')), 'Reviewed packet admission required')
    require(spec['source'] == SOURCE and spec['platform'] in ('linux/amd64','linux/arm64'), 'Admission source/platform')
    for key in ('image_id','browser_image_id'):
        require(isinstance(spec[key], str) and re.fullmatch(r'sha256:[a-f0-9]{64}',spec[key]), 'Local immutable image required')
    for key in ('image_config_sha256','browser_config_sha256','vendor_preparation_receipt_sha256'):
        require(isinstance(spec[key],str) and re.fullmatch(r'[a-f0-9]{64}',spec[key]), 'Independent input receipt required')


class Session:
    def __init__(self, spec, inputs, evidence_directory, packet=None, repository=None):
        validate_spec(spec)
        self.started_at = time.monotonic()
        self.deadline = self.started_at+1200
        self.active = self.deadline-60
        self.utc_deadline = time.time()+1200
        self.token = secrets.token_hex(8)
        self.prefix = 'waterline-source-'+self.token
        self.spec, self.inputs, self.directory = spec, Path(inputs), Path(evidence_directory)
        require(self.inputs.resolve() == self.inputs and self.directory.resolve() == self.directory
                and self.inputs != self.directory, 'Separate canonical inputs/evidence roots required')
        require(not self.directory.is_relative_to(self.inputs), 'Evidence must be outside input mounts')
        require(self.directory.is_dir() and not any(self.directory.iterdir()), 'Fresh evidence directory required')
        self.commands, self.observed = [], []
        self.backstop = None
        self.packet=packet
        self.repository=Path(repository) if repository is not None else ROOT
        self.snapshot=None
        self.snapshot_manifest=None

    def docker(self, *args, seconds=45, limit=MAX_JSON, cleanup=False, input_bytes=None):
        stop = self.deadline if cleanup else self.active
        raw, receipt = command(['docker',*args], min(stop,time.monotonic()+seconds), stdout_limit=limit,input_bytes=input_bytes)
        self.commands.append(receipt)
        return raw

    def name(self, role):
        return self.prefix+'-'+role

    def exec(self, role, *args, seconds=45):
        return self.docker('exec', self.name(role), 'timeout', '-s','TERM','-k','2',str(seconds-3),*args,seconds=seconds)

    def collector(self, mode, request=None):
        args = ['php','/inputs/packet/collector.php',mode]
        if request is not None:
            args.append(json.dumps(request,separators=(',',':')))
        return bounded_json(self.exec('app',*args))

    def read_state(self, relative):
        require(re.fullmatch(r'(?:request-budget-evidence/)?[a-z-]+\.(?:json|jsonl)',relative), 'State path refused')
        # The bounded object reader is installed read-only in the tool role.
        raw = self.exec('browser','python3','/inputs/packet/read_state.py',relative)
        return bounded_json(raw)

    def write_mode(self, mode):
        raw = json.dumps({'mode':mode,'service_budget':.2,'browser_budget':.8},separators=(',',':'))
        self.exec('fixture','php','-r',
                  'if(file_put_contents("/state/mode.json.tmp",$argv[1])===false||!rename("/state/mode.json.tmp","/state/mode.json"))exit(1);',raw)

    def role(self, role, entrypoint, args, *, aliases=(), browser=False):
        cpu, memory, pids = ROLES[role]
        network = 'container:'+self.name('proxy') if browser else self.name('network')
        options = ['run','-d','--name',self.name(role),'--label',LABEL+'='+self.token,
                   '--init','--read-only','--user','82:82','--cpus',cpu,'--memory',memory,'--memory-swap',memory,
                   '--pids-limit',pids,'--cap-drop','ALL','--security-opt','no-new-privileges',
                   '--network',network,'--tmpfs','/tmp:rw,size=128m,uid=82,gid=82',
                   '--mount',f'type=volume,src={self.name("inputs")},dst=/inputs,readonly']
        for alias in aliases:
            options += ['--network-alias',alias]
        if role == 'app':
            options += ['--tmpfs','/data:rw,size=64m,uid=82,gid=82',
                        '--tmpfs','/app/bootstrap/cache:rw,size=32m,uid=82,gid=82',
                        '--tmpfs','/app/storage:rw,size=64m,uid=82,gid=82']
            fixture_environment = {
                'APP_KEY':'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=', # public synthetic fixture
                'APP_ENV':'production','APP_DEBUG':'false','WATERLINE_BACKEND':'service',
                'WATERLINE_PATH':'waterline','WATERLINE_NAMESPACE':'smoke','WATERLINE_SERVER_TOKEN':'smoke-token',
                'WATERLINE_SERVER_ENDPOINT':'http://budget-backend:18081','WATERLINE_ACCESS_MODE':'operator',
                'WATERLINE_ALLOW_UNAUTHENTICATED':'true','WATERLINE_SERVER_REQUEST_TIMEOUT':'.2',
                'WATERLINE_BROWSER_REQUEST_TIMEOUT':'.8','DB_CONNECTION':'sqlite','DB_DATABASE':'/data/fixture.sqlite'}
            for key,value in fixture_environment.items():
                options += ['-e',key+'='+value]
        else:
            options += ['--mount',f'type=volume,src={self.name("state")},dst=/state',
                        '-e','HOME=/tmp','-w','/state']
        if browser:
            options += ['-e','EXPECTED_CANDIDATE_SOURCE='+SOURCE, '-e','FIXED_BROWSER_BUDGET_SECONDS=.8',
                        '--shm-size','128m']
        image = self.spec['browser_image_id'] if browser else self.spec['image_id']
        self.docker(*options,'--entrypoint','timeout',image,'-s','TERM','-k','2',
                    str(max(1,int(self.active-time.monotonic()))),entrypoint,*args)

    def verify_image(self, image, expected_config, app):
        value = bounded_json(self.docker('image','inspect',image))
        require(isinstance(value,list) and len(value)==1, 'Local image readback missing')
        image_data = value[0]
        require(image_data.get('Id')==image and image_data.get('Os')+'/'+image_data.get('Architecture')==self.spec['platform'],
                'Local image/platform mismatch')
        config = image_data['Config']
        require(hashlib.sha256(json.dumps(config,sort_keys=True,separators=(',',':')).encode()).hexdigest()==expected_config,
                'Independent image config mismatch')
        if app:
            labels=config.get('Labels') or {}
            require(labels.get('org.opencontainers.image.revision')==SOURCE
                    and labels.get('dev.durable-workflow.release.tag')=='2.15.1', 'Source image labels mismatch')

    def bind_inputs(self, expected):
        reviewed=reviewed_packet(self.repository,self.spec['packet_commit'],self.spec['packet_tree'])
        require(self.packet is not None and exact_manifest(reviewed)==exact_manifest(self.packet), 'Packet admission mismatch')
        files={'packet/'+name:entry for name,entry in reviewed.items()}
        originals=['scripts/ci/request-budget-browser.mjs','scripts/ci/request-budget-auto.mjs',
                   'scripts/ci/request-budget-host.php','scripts/ci/request-budget-server.php',
                   'tests/Fixtures/service-mode-server.php']
        for name in originals:
            raw,_=command(['git','-C',str(self.repository),'show',SOURCE+':'+name],time.monotonic()+5)
            files['source/'+name]={'bytes':raw,'identity':{'mode':0o644,'size':len(raw),'sha256':hashlib.sha256(raw).hexdigest()}}
        raw,_=object_bytes(self.inputs,'vendor-preparation.json',MAX_JSON)
        require(hashlib.sha256(raw).hexdigest()==self.spec['vendor_preparation_receipt_sha256'], 'Vendor receipt digest mismatch')
        receipt=bounded_json(raw)
        require(receipt.get('source')==SOURCE and receipt.get('lock')==expected['lock']
                and receipt.get('graph')==expected['graph'] and receipt.get('scripts') is False
                and receipt.get('plugins') is False and receipt.get('effective_trust_verified') is True
                and receipt.get('request_concurrency')==2, 'Independent vendor preparation receipt mismatch')
        for key in ('ca_sha256','tool_config_sha256','provider_api_digest','installed_json_sha256'):
            require(isinstance(receipt.get(key),str) and re.fullmatch(r'[a-f0-9]{64}',receipt[key]), 'Preparation provenance missing')
        for key in ('provider_run_id','provider_artifact_id'):
            require(type(receipt.get(key)) is int and receipt[key]>0, 'Preparation provider identity missing')
        self.snapshot,self.snapshot_manifest=snapshot_archive(files)
        verify_archive(self.snapshot,self.snapshot_manifest)
        self.packet=reviewed
        expected['packet']={'commit':self.spec['packet_commit'],'tree':self.spec['packet_tree'],
                            'files':exact_manifest(reviewed)}
        expected['packet_and_fixture_inputs']=self.snapshot_manifest
        expected['vendor_preparation']=receipt
        expected['installed_json_sha256']=receipt['installed_json_sha256']
        self.expected=expected

    def seal_inputs(self):
        require(self.snapshot is not None and self.snapshot_manifest is not None,'Snapshot preparation missing')
        self.docker('volume','create','--label',LABEL+'='+self.token,'--driver','local','--opt','type=tmpfs',
                    '--opt','device=tmpfs','--opt','o=size=8m,uid=82,gid=82',self.name('inputs'))
        result=self.docker('run','--rm','-i','--name',self.name('seal'),'--label',LABEL+'='+self.token,
                           '--init','--read-only','--user','82:82','--network','none','--cpus','.5',
                           '--memory','512m','--memory-swap','512m','--pids-limit','128','--cap-drop','ALL',
                           '--security-opt','no-new-privileges','--tmpfs','/tmp:rw,size=16m,uid=82,gid=82',
                           '--mount',f'type=volume,src={self.name("inputs")},dst=/sealed',
                           '--entrypoint','timeout',self.spec['browser_image_id'],'-s','TERM','-k','2','40',
                           'python3','-I','-c',SEAL_PROGRAM,json.dumps(self.snapshot_manifest,sort_keys=True,separators=(',',':')),
                           input_bytes=self.snapshot)
        require(bounded_json(result)==self.snapshot_manifest,'Sealed manifest mismatch')
        require(self.docker('ps','-aq','--filter','name=^/'+self.name('seal')+'$')==b'', 'Snapshot creator survived')

    def start(self):
        launch = {'schema':'waterline.source-smoke-launch/v1','source':SOURCE,'owner_label':self.token,
                  'started_monotonic':self.started_at,'utc_cleanup_deadline':self.utc_deadline,
                  'active_seconds':1140,'total_seconds':1200,'roles':ROLES,'admission':self.spec}
        descriptor=os.open(self.directory/'launch.json',os.O_WRONLY|os.O_CREAT|os.O_EXCL|os.O_NOFOLLOW,0o600)
        with os.fdopen(descriptor,'w') as stream:
            json.dump(launch,stream,sort_keys=True)
            stream.flush()
            os.fsync(stream.fileno())
        # Captured absolute deadline precedes the first Docker/network operation.
        # This independent process owns only this exact unpredictable label.
        self.backstop = subprocess.Popen([sys.executable,'-I','-c',self.packet['cleanup.py']['bytes'].decode(),self.token,str(self.deadline)],
                                         stdin=subprocess.DEVNULL,stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL,
                                         start_new_session=True)
        self.verify_image(self.spec['image_id'],self.spec['image_config_sha256'],True)
        self.verify_image(self.spec['browser_image_id'],self.spec['browser_config_sha256'],False)
        self.seal_inputs()
        self.docker('network','create','--internal','--label',LABEL+'='+self.token,self.name('network'))
        self.docker('volume','create','--label',LABEL+'='+self.token,'--driver','local',
                    '--opt','type=tmpfs','--opt','device=tmpfs','--opt','o=size=128m,uid=82,gid=82',self.name('state'))
        self.role('fixture','php',['-S','0.0.0.0:18081','/inputs/packet/fixture.php'],aliases=('budget-backend',))
        self.write_mode('healthy')
        self.role('app','sleep',['1150'],aliases=('budget-app',))
        self.exec('app','sh','-c','mkdir -p /app/storage/framework/cache/data /app/storage/framework/sessions /app/storage/framework/views /app/storage/logs')
        self.exec('app','php',ARTISAN,'package:discover','--no-interaction')
        self.exec('app','php',ARTISAN,'migrate','--force','--no-interaction')
        self.role('proxy','php',['-S','0.0.0.0:8080','/inputs/packet/proxy.php'])
        self.role('browser','sleep',['1150'],browser=True)
        require(self.collector('config')==CONFIG, 'Actual loaded configuration mismatch')

    def web_server(self, budget):
        require(budget in (.8,3.5),'Unsupported fixture browser budget')
        # PIDs are scoped to this non-root, task-owned container. No host kill.
        self.exec('app','sh','-c','if [ -f /tmp/source-web.pid ]; then read pid < /tmp/source-web.pid; case "$pid" in ""|*[!0-9]*) exit 1;; esac; [ "$pid" -gt 1 ] || exit 1; kill "$pid"; fi')
        self.docker('exec','-d',self.name('app'),'sh','-c',
                    'echo $$ > /tmp/source-web.pid; cd /app/public; export WATERLINE_BROWSER_REQUEST_TIMEOUT="$1"; exec php -d variables_order=EGPCS -S 0.0.0.0:8082 /app/vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php',
                    'source-web',str(budget))
        stop=min(self.active,time.monotonic()+15)
        while time.monotonic()<stop:
            try:
                self.exec('proxy','php','-r',
                          '$c=curl_init("http://budget-app:8082/up");curl_setopt_array($c,[CURLOPT_TIMEOUT_MS=>1000,CURLOPT_WRITEFUNCTION=>static fn($c,$s)=>strlen($s)]);if(!curl_exec($c)||curl_getinfo($c,CURLINFO_RESPONSE_CODE)!==200)exit(1);',seconds=5)
                return
            except Refusal:
                time.sleep(.1)
        raise Refusal('Candidate HTTP readiness failed')

    def api(self, phase):
        def call(path, status, checks, method='GET', body=None):
            response=self.collector('api',{'path':path,'method':method,'body':body or {}})
            result=api_response(response,status,checks)
            return result
        listing=call('/waterline/api/flows/running',200,{'data.0.instance_id':'smoke-order'})
        require(len(listing['data'])==2,'Smoke list cardinality')
        call('/waterline/api/saved-views?bucket=running',200,{
            'filter_version':6,'filter_definition.fields.repair_state.label':'Repair State',
            'filter_definition.fields.repair_state.filterable':False})
        view=call('/waterline/api/saved-views',201,{'filters.workflow_type':SMOKE_TYPE,'service_mode_available':True},'POST',
                  {'name':'Source smoke '+phase,'bucket':'running','filters':{'workflow_type':SMOKE_TYPE},'shared':True})
        identifier=view.get('id')
        require(isinstance(identifier,str) and re.fullmatch(r'[A-Za-z0-9-]{1,128}',identifier),'Saved-view identity')
        filtered=call('/waterline/api/flows/running?view='+identifier,200,{
            'data.0.instance_id':'smoke-order','visibility_filters.saved_view.id':identifier,
            'visibility_filters.saved_view_applied':True,'visibility_filters.applied.workflow_type':SMOKE_TYPE,
            'visibility_filters.capability.fully_applied':True})
        require(len(filtered['data'])==1,'Smoke filtered cardinality')
        call('/waterline/api/instances/smoke-order/runs/smoke-run',200,{
            'selected_run_id':'smoke-run','timeline.0.event_type':'WorkflowStarted','timeline.0.type':'WorkflowStarted',
            'timeline.0.recorded_at':'2026-07-22T12:00:00Z','timeline.0.id':'smoke-run:history:1',
            'workflow_streams_mode':'service','workflow_streams.0.stream_name':'receipts',
            'workflow_streams.0.status':'errored','workflow_streams.0.last_offset':4,
            'workflow_streams.0.pending_items':2,'workflow_streams.0.error_reason':'producer_failed',
            'workflow_streams.0.supports_inbound_workflow_messaging':False})
        call('/waterline/api/instances/smoke-order/runs/smoke-run/queries/current',200,{
            'query':'current','result.state':'awaiting_approval','result.selected_run_id':'smoke-run'},'POST',{'arguments':[]})
        call('/waterline/api/instances/smoke-order/runs/smoke-run/signals/approve',200,{
            'command_status':'accepted','signal_name':'approve','selected_run_id':'smoke-run','input_received':True},'POST',{'arguments':['manager']})
        return {'checks':7,'result':'pass','fixture':'synthetic source protocol; not native compatibility'}

    def phase(self, name):
        self.write_mode('healthy')
        for action in (['route:cache'] if name=='cached' else ['route:clear']):
            self.exec('app','php',ARTISAN,action,'--no-interaction')
        installed_raw=self.exec('app','php','/inputs/packet/collector.php','graph')
        require(hashlib.sha256(installed_raw).hexdigest()==self.expected['installed_json_sha256'], 'Installed metadata byte identity mismatch')
        installed=bounded_json(installed_raw)
        require(installed.get('dev') is False and installed.get('dev-package-names',[])==[], 'Installed dev metadata refused')
        installed_graph=graph(installed.get('packages'))
        require(installed_graph==self.expected['graph'], 'Host parsed source/dist graph mismatch')
        observation={'phase':name,'installed_graph':installed_graph,'identity':self.collector('identity'),'routes':self.collector('routes'),
                     'health':self.collector('health')}
        observation['api']=self.api(name)
        self.web_server(.8)
        self.exec('browser','sh','-c','rm -f /state/writes.jsonl /state/schedule-writes.jsonl')
        self.exec('browser','node','--experimental-vm-modules','/inputs/packet/browser-driver.mjs','browser',seconds=160)
        observation['browser']=self.read_state('request-budget-evidence/browser.json')
        self.web_server(3.5)
        self.exec('browser','node','--experimental-vm-modules','/inputs/packet/browser-driver.mjs','auto',seconds=45)
        observation['automatic']=self.read_state('request-budget-evidence/browser-auto.json')
        self.observed.append(observation)

    def cleanup(self):
        # Include resources created before a failed command returned a handle.
        for kind, listing, remove in [('container',('ps','-aq'),('rm','-f')),
                                       ('network',('network','ls','-q'),('network','rm')),
                                       ('volume',('volume','ls','-q'),('volume','rm','-f'))]:
            ids=self.docker(*listing,'--filter','label='+LABEL+'='+self.token,cleanup=True).decode().split()
            for identifier in ids:
                require(re.fullmatch(r'[a-f0-9]{12,64}|waterline-source-[a-f0-9-]+',identifier),'Cleanup identity refused')
                self.docker(*remove,identifier,cleanup=True)
        receipt={}
        for kind, args in [('containers',('ps','-aq')),('networks',('network','ls','-q')),('volumes',('volume','ls','-q'))]:
            receipt[kind]=self.docker(*args,'--filter','label='+LABEL+'='+self.token,cleanup=True).decode().split()
        if self.backstop is not None:
            self.backstop.terminate()
            self.backstop.wait(timeout=2)
        receipt['processes']=[] if self.backstop is None or self.backstop.poll() is not None else ['cleanup-backstop']
        return receipt


def execute():
    require(EXECUTION_ENABLED, 'HELD: actual collection/cache execution requires separate General/Security admission')
    raise Refusal('HELD: no mutable-worktree execution path is admitted')


def execute_reviewed(spec, repository, receipt_directory, destination, packet):
    require(EXECUTION_ENABLED, 'HELD: reviewed source is not execution-cleared')
    expected=expected_from_git(Path(repository))
    expected['admission']=spec
    session=Session(spec,receipt_directory,Path(destination).parent,packet,repository)
    try:
        session.bind_inputs(expected)
        session.start()
        for name in ('clear-before','cached','clear-after'):
            session.phase(name)
    finally:
        cleanup=session.cleanup()
    return final_receipt(expected,session.observed,session.commands,cleanup,Path(destination))


def main():
    parser=argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--spec',type=Path)
    parser.add_argument('--inputs',type=Path)
    parser.add_argument('--evidence',type=Path)
    parser.parse_args()
    # No arguments, env value or malformed candidate output can bypass the hold.
    raise Refusal('HELD: disabled source proposal; no Docker command or collector will run')


if __name__=='__main__':
    try:
        main()
    except Refusal as error:
        print(str(error),file=sys.stderr)
        raise SystemExit(1)
