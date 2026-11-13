"""Disposable predecessor/candidate ID controls; no app/image build/network."""
import importlib.util
import json
import os
from pathlib import Path
import re
import secrets
import subprocess
from unittest.mock import patch

SOURCE = Path(__file__).resolve().parent.parent
spec = importlib.util.spec_from_file_location('retained_owner_control', SOURCE / 'owned-docker.py')
owner = importlib.util.module_from_spec(spec)
spec.loader.exec_module(owner)
os.environ.clear(); os.environ.update({'PATH': os.defpath})
docker = ['/usr/bin/docker', '--host', 'unix:///var/run/docker.sock', '--config', '/proc/self/fd']
image = 'python@sha256:935c3c5b12357a7e10caa9c1e6ca5692344d97cf229832ef584be2cec9fc03df'
created, results = [], []
def create(name, nonce, role):
    labels = {'dev.durable-workflow.fixture-owner':'waterline','dev.durable-workflow.fixture-run':nonce,'dev.durable-workflow.fixture-role':role}
    flags = [piece for key,value in labels.items() for piece in ['--label',key+'='+value]]
    identifier = subprocess.check_output(docker+['create','--init','--name',name,'--cpus','.1','--memory','64m','--pids-limit','16','--read-only','--network','none',*flags,image,'python','-I','-c','pass'],text=True,timeout=10).strip()
    if not re.fullmatch('[0-9a-f]{64}',identifier):raise ValueError('full ID required')
    created.append((identifier,labels));owner.inspect_owned(docker,identifier,labels)
    return identifier,labels
try:
    for mode in ['old-name','bound-id']:
        nonce=secrets.token_hex(12);name='waterline-cleanup-'+nonce
        original,labels=create(name,nonce,'original')
        subprocess.run(docker+['rename',original,name+'-retained'],check=True,capture_output=True,timeout=10)
        other,other_labels=create(name,secrets.token_hex(12),'synthetic-unrelated')
        if mode=='old-name':
            # Both objects were created by this control; never use another owner's object.
            subprocess.run(docker+['rm','--force',name],check=True,capture_output=True,timeout=10)
            assert owner.inspect_owned(docker,original,labels) is not None
            assert owner.inspect_owned(docker,other,other_labels,True) is None
            results.append({'control':mode,'predecessor_removed_synthetic_replacement':True,'original_retained':True})
        else:
            owner.remove_owned(docker,original,labels)
            assert owner.inspect_owned(docker,other,other_labels) is not None
            try:owner.inspect_owned(docker,other,labels)
            except ValueError:pass
            else:raise AssertionError('wrong labels admitted')
            results.append({'control':mode,'original_removed':True,'replacement_preserved':True,'wrong_labels_refused':True})
    error=subprocess.CompletedProcess([],1,'[]','Error response from daemon: permission denied')
    with patch('subprocess.run',return_value=error):
        try:owner.inspect_owned(docker,'a'*64,{},True)
        except RuntimeError:pass
        else:raise AssertionError('generic failure treated as absence')
    results.append({'control':'exact-absence','generic_failure_refused':True})
finally:
    for identifier,labels in reversed(created):owner.remove_owned(docker,identifier,labels)
    assert all(owner.inspect_owned(docker,identifier,labels,True) is None for identifier,labels in created)
print(json.dumps({'outcome':'pass','controls':results,'all_created_ids_absent':True}))
