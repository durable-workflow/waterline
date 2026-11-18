import json,re,subprocess

def inspect_owned(docker,identifier,labels,missing_ok=False):
 if not re.fullmatch('[0-9a-f]{64}',identifier):raise ValueError('full container ID required')
 r=subprocess.run(docker+['inspect',identifier],capture_output=True,text=True,timeout=5)
 if r.returncode:
  message=r.stderr.strip()
  missing=re.fullmatch(r'(?:[Ee]rror:|[Ee]rror response from daemon:) [Nn]o such (?:object|container): '+re.escape(identifier),message) is not None and r.stdout.strip() in {'','[]'} and r.returncode==1
  if missing and missing_ok:return None
  raise RuntimeError('container inspection did not prove owned object or exact absence')
 values=json.loads(r.stdout)
 if len(values)!=1 or values[0]['Id']!=identifier:raise ValueError('container identity differs')
 actual=values[0]['Config'].get('Labels') or {}
 if any(actual.get(k)!=v for k,v in labels.items()):raise ValueError('container ownership labels differ')
 return values[0]

def remove_owned(docker,identifier,labels):
 if inspect_owned(docker,identifier,labels,True) is not None:
  subprocess.run(docker+['rm','--force','--volumes',identifier],check=True,capture_output=True,timeout=5)
 if inspect_owned(docker,identifier,labels,True) is not None:raise RuntimeError('owned container remains')
