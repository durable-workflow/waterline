#!/usr/bin/env python3
"""Independent exact-label expiry; never started by the disabled entrypoint."""
import re
import subprocess
import sys
import time

LABEL='dev.durable-workflow.waterline-source-evidence'

def cleanup(token):
    if re.fullmatch(r'[a-f0-9]{16}',token) is None:
        raise ValueError('Invalid exact owner label')
    for listing,remove in [(('ps','-aq'),('rm','-f')),
                           (('network','ls','-q'),('network','rm')),
                           (('volume','ls','-q'),('volume','rm','-f'))]:
        ids=subprocess.check_output(['docker',*listing,'--filter','label='+LABEL+'='+token],timeout=2).decode().split()
        for identifier in ids:
            if re.fullmatch(r'[a-f0-9]{12,64}|waterline-source-[a-f0-9-]+',identifier) is None:
                raise ValueError('Unexpected cleanup identity')
            subprocess.run(['docker',*remove,identifier],check=True,timeout=2,
                           stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL)

if __name__=='__main__':
    token,deadline=sys.argv[1:]
    deadline=float(deadline)
    if not time.time() <= deadline <= time.time()+1200:
        raise SystemExit('Invalid expiry')
    # Short sleeps keep this independent of owner progress or model lifetime.
    while time.time()<deadline-30:
        time.sleep(min(1,max(0,deadline-30-time.time())))
    cleanup(token)
