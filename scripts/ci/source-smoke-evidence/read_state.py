#!/usr/bin/env python3
"""Bound source-fixture JSON reads before crossing the container boundary."""
import re
import sys
from pathlib import Path
sys.path.insert(0,'/inputs/packet')
from evidence import MAX_JSON, object_bytes, require

if __name__=='__main__':
    relative=sys.argv[1]
    require(re.fullmatch(r'(?:request-budget-evidence/)?[a-z-]+\.(?:json|jsonl)',relative), 'State path refused')
    raw,_=object_bytes(Path('/state'),relative,MAX_JSON)
    sys.stdout.buffer.write(raw)
