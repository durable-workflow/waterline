"""Offline exact Git-object/immutable-handoff controls; no Docker/collector."""
import copy
import hashlib
import io
import json
import os
import pathlib
import subprocess
import sys
import tarfile
import tempfile
import unittest

sys.path.insert(0,str(pathlib.Path(__file__).parent))
import packet_snapshot as snapshot


class SnapshotTests(unittest.TestCase):
    def repository(self,root):
        subprocess.run(['git','init','-q',str(root)],check=True)
        folder=root/snapshot.PACKET_PATH;folder.mkdir(parents=True)
        for name in snapshot.PACKET_FILES:
            (folder/name).write_text('# synthetic reviewed public fixture '+name+'\n')
        return folder

    def commit(self,root):
        subprocess.run(['git','-C',str(root),'add','.'],check=True)
        subprocess.run(['git','-C',str(root),'-c','user.name=Waterline source test',
                        '-c','user.email=waterline-source-test@example.invalid',
                        'commit','-qm','Synthetic packet'],check=True)
        commit=subprocess.check_output(['git','-C',str(root),'rev-parse','HEAD'],text=True).strip()
        tree=subprocess.check_output(['git','-C',str(root),'rev-parse','HEAD^{tree}'],text=True).strip()
        return commit,tree

    def test_worktree_replace_restore_and_extra_import_do_not_change_admitted_bytes(self):
        with tempfile.TemporaryDirectory() as directory:
            root=pathlib.Path(directory).resolve();folder=self.repository(root);commit,tree=self.commit(root)
            packet=snapshot.reviewed_packet(root,commit,tree)
            expected=packet['cleanup.py']['bytes']
            # The old compare-live-then-mount design observes this replacement.
            live=folder/'cleanup.py';self.assertEqual(live.read_bytes(),expected)
            replacement=folder/'replacement';replacement.write_bytes(b'# transient unreviewed helper\n')
            os.replace(replacement,live)
            self.assertNotEqual(live.read_bytes(),expected)
            (folder/'injected.py').write_text('raise AssertionError("unexpected import")\n')
            admitted=snapshot.reviewed_packet(root,commit,tree)
            self.assertEqual(admitted['cleanup.py']['bytes'],expected)
            self.assertNotIn('injected.py',admitted)
            program=snapshot.host_program(admitted,{'packet_commit':commit,'packet_tree':tree},root,root/'receipt',root/'evidence')
            self.assertNotIn(b'transient unreviewed helper',program)
            self.assertNotIn(b'injected.py',program)
            live.write_bytes(expected)
            self.assertEqual(snapshot.reviewed_packet(root,commit,tree),packet)

    def test_extra_missing_symlink_and_wrong_tree_in_git_are_refused(self):
        for change in ('extra','missing','symlink'):
            with self.subTest(change=change),tempfile.TemporaryDirectory() as directory:
                root=pathlib.Path(directory).resolve();folder=self.repository(root)
                if change=='extra':(folder/'injected.py').write_text('# unexpected')
                else:
                    (folder/'cleanup.py').unlink()
                    if change=='symlink':os.symlink('runner.py',folder/'cleanup.py')
                commit,tree=self.commit(root)
                self.assertRaises(snapshot.SnapshotRefusal,snapshot.reviewed_packet,root,commit,tree)
        with tempfile.TemporaryDirectory() as directory:
            root=pathlib.Path(directory).resolve();self.repository(root);commit,tree=self.commit(root)
            self.assertRaises(snapshot.SnapshotRefusal,snapshot.reviewed_packet,root,commit,'0'*40)

    def test_packet_cleanup_bytes_and_modes_cannot_diverge_from_manifest(self):
        with tempfile.TemporaryDirectory() as directory:
            root=pathlib.Path(directory).resolve();self.repository(root);commit,tree=self.commit(root)
            packet=snapshot.reviewed_packet(root,commit,tree)
            for key,value in [('bytes',b'# changed cleanup'),('identity',{'mode':0o644,'size':0,'sha256':'0'*64})]:
                changed=copy.deepcopy(packet);changed['cleanup.py'][key]=value
                self.assertRaises(snapshot.SnapshotRefusal,snapshot.host_program,changed,{},root,root/'receipt',root/'evidence')
            packet['extra.py']=packet['cleanup.py']
            self.assertRaises(snapshot.SnapshotRefusal,snapshot.host_program,packet,{},root,root/'receipt',root/'evidence')

    def test_archive_rejects_missing_extra_duplicate_and_nonregular_members(self):
        body=b'public source';files={'packet/cleanup.py':{'bytes':body,'identity':snapshot.identity(body,0o644)}}
        archive,manifest=snapshot.snapshot_archive(files);snapshot.verify_archive(archive,manifest)
        for kind in ('missing','extra','duplicate','symlink','hardlink','fifo','changed'):
            with self.subTest(kind=kind):
                buffer=io.BytesIO()
                with tarfile.open(fileobj=buffer,mode='w:') as tar:
                    if kind!='missing':
                        name='extra.py' if kind=='extra' else 'packet/cleanup.py'
                        info=tarfile.TarInfo(name);info.mode=0o644
                        data=body if kind!='changed' else b'changed'
                        info.size=len(data)
                        if kind in ('symlink','hardlink','fifo'):
                            info.type={'symlink':tarfile.SYMTYPE,'hardlink':tarfile.LNKTYPE,'fifo':tarfile.FIFOTYPE}[kind]
                            info.linkname='other';info.size=0
                            tar.addfile(info)
                        else:tar.addfile(info,io.BytesIO(data))
                        if kind=='duplicate':tar.addfile(info,io.BytesIO(data))
                raw=buffer.getvalue()
                self.assertRaises(snapshot.SnapshotRefusal,snapshot.verify_archive,raw,manifest)

    def test_frozen_archive_survives_input_object_replacement(self):
        body=b'original';files={'packet/cleanup.py':{'bytes':body,'identity':snapshot.identity(body,0o644)}}
        raw,manifest=snapshot.snapshot_archive(files)
        files['packet/cleanup.py']['bytes']=b'replaced after check'
        snapshot.verify_archive(raw,manifest)
        with tarfile.open(fileobj=io.BytesIO(raw)) as tar:
            self.assertEqual(tar.extractfile('packet/cleanup.py').read(),body)


if __name__=='__main__':unittest.main()
