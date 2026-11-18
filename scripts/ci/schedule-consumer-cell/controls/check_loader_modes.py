"""Synthetic parser/permission control only; no candidate or app executes."""
import base64
import hashlib
import importlib.util
import json
import os
from pathlib import Path
import secrets
import subprocess

SOURCE=Path(__file__).resolve().parent.parent
SNAPSHOT=SOURCE.parents[2]
def load(name,file):
    spec=importlib.util.spec_from_file_location(name,SOURCE/file)
    module=importlib.util.module_from_spec(spec);spec.loader.exec_module(module);return module
runner=load('permission_runner','runner.py');owner=load('permission_owner','owned-docker.py')
subjects,_=runner.captured_inputs(json.loads((SNAPSHOT/'inputs/packet.json').read_bytes()))
old=(SNAPSHOT/'predecessors/load-candidate-01.php').read_bytes()
assert hashlib.sha256(old).hexdigest()=='cecbf80deab40f0229e3fcb4e823e9083df65875fd5fd93ac107e489907cf0c6'
leaves=[]
for folder in ['app','config','database','public','resources','routes']:
    data=b'synthetic'
    leaves.append({'path':folder+'/fixture.txt','git_mode':'100644','git_blob':'0'*40,'sha256':hashlib.sha256(data).hexdigest(),'size':len(data),'base64':base64.b64encode(data).decode()})
fixture=json.dumps({'classification':'source-overlay-candidate','commit':runner.CANDIDATE,'tree':'0'*40,'bytes':sum(x['size']for x in leaves),'leaves':leaves},separators=(',',':')).encode()
# This isolated parser control owns its synthetic expected input, never product authority.
runner.EXPECTED={**runner.EXPECTED,'candidate':{'sha256':hashlib.sha256(fixture).hexdigest(),'bytes':len(fixture)}}
os.environ.clear();os.environ.update({'PATH':os.defpath})
docker=['/usr/bin/docker','--host','unix:///var/run/docker.sock','--config','/proc/self/fd']
image='sha256:5e6f71b4bc79c9a98a2c1f45dc2d003522a0fb440719a96546eac33f5a3487a1'
results=[]
for role,source,outsider_reads in [('predecessor',old,True),('candidate',subjects['loader'],False)]:
    nonce=secrets.token_hex(12)
    labels={'dev.durable-workflow.fixture-owner':'waterline','dev.durable-workflow.fixture-run':nonce,'dev.durable-workflow.fixture-role':role}
    flags=[piece for key,value in labels.items()for piece in ['--label',key+'='+value]]
    identifier=subprocess.check_output(docker+['create','--name','waterline-loader-modes-'+nonce,*flags,'--init','--cpus','1','--memory','128m','--pids-limit','64','--read-only','--network','none','--cap-drop','ALL','--user','0:82','--tmpfs','/app/waterline:rw,noexec,nosuid,nodev,size=2m,gid=82,mode=0750','--tmpfs','/app/public/vendor/waterline:rw,noexec,nosuid,nodev,size=2m,gid=82,mode=0750','--entrypoint','php',image,'-r','sleep(60);'],text=True,timeout=10).strip()
    try:
        owner.inspect_owned(docker,identifier,labels)
        subprocess.run(docker+['start',identifier],check=True,capture_output=True,timeout=10)
        program=runner.loader_program({'loader':source})
        result=subprocess.run(docker+['exec','-i','--user','0:82',identifier,'php','-r',program],input=fixture,capture_output=True,timeout=10)
        if result.returncode:raise RuntimeError('synthetic parser refused')
        code='exit(@file_get_contents("/app/waterline/app/fixture.txt")==="synthetic"?0:1);'
        app=subprocess.run(docker+['exec','--user','82:82',identifier,'php','-r',code],capture_output=True,timeout=5)
        outsider=subprocess.run(docker+['exec','--user','1000:1000',identifier,'php','-r',code],capture_output=True,timeout=5)
        assert app.returncode==0 and (outsider.returncode==0)==outsider_reads
        results.append({'role':role,'application_group_reads':True,'unrelated_uid_reads':outsider.returncode==0})
    finally:
        owner.remove_owned(docker,identifier,labels)
print(json.dumps({'outcome':'pass','classification':'synthetic-parser-permission-control','controls':results,'all_created_ids_absent':True}))
