#!/usr/bin/env python3
"""Prepare independent public source inputs only; never build/install/collect."""
import io
import os
import shutil
import tarfile
import time
from pathlib import Path
from evidence import SOURCE, MAX_FILE, MAX_TOTAL, command, object_bytes, require


def prepare(repository, packet, destination, vendor_receipt):
    repository,packet,destination,vendor_receipt=map(Path,(repository,packet,destination,vendor_receipt))
    require(destination.is_absolute() and destination.parent.resolve()==destination.parent and not destination.exists(),
            'Fresh independent input directory required')
    archive,_=command(['git','-C',str(repository),'archive','--format=tar',SOURCE],time.monotonic()+15,stdout_limit=MAX_TOTAL)
    destination.mkdir(mode=0o700)
    (destination/'source').mkdir()
    (destination/'packet').mkdir()
    try:
        with tarfile.open(fileobj=io.BytesIO(archive),mode='r:') as source:
            for member in source:
                parts=Path(member.name).parts
                require(parts and not Path(member.name).is_absolute() and '..' not in parts, 'Unsafe archive path')
                target=destination/'source'/member.name
                if member.isdir():
                    target.mkdir(parents=True,exist_ok=True)
                    continue
                require(member.isfile() and 0<=member.size<=MAX_FILE, 'Unsafe archive object')
                target.parent.mkdir(parents=True,exist_ok=True)
                raw=source.extractfile(member).read(MAX_FILE+1)
                require(len(raw)==member.size, 'Archive length mismatch')
                with target.open('xb') as output:
                    output.write(raw)
                target.chmod(member.mode)
        for path in sorted(packet.iterdir()):
            if path.is_file() and path.suffix in ('.py','.php','.md','.mjs'):
                raw,meta=object_bytes(packet,path.name)
                target=destination/'packet'/path.name
                target.write_bytes(raw)
                target.chmod(meta['mode'])
        raw,_=object_bytes(vendor_receipt.parent.resolve(),vendor_receipt.name)
        (destination/'vendor-preparation.json').write_bytes(raw)
        # The future runner mounts these paths read-only. Ownership stays with
        # the host, and evidence is never placed under this tree.
        return destination
    except BaseException:
        shutil.rmtree(destination)
        raise
