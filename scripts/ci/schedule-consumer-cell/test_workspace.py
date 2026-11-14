"""No Docker/application execution: held workspace substitution controls."""
import importlib.util
import os
import fcntl
from pathlib import Path
import tempfile
import subprocess
import sys
import unittest
from unittest.mock import patch

ROOT = Path(__file__).resolve().parent
spec = importlib.util.spec_from_file_location('held_workspace_control', ROOT / 'workspace.py')
module = importlib.util.module_from_spec(spec)
spec.loader.exec_module(module)


class WorkspaceBoundaryTest(unittest.TestCase):
    def test_root_replacement_keeps_held_data_and_refuses_evidence(self):
        with tempfile.TemporaryDirectory() as temporary:
            parent = Path(temporary)
            with module.Workspace(parent / 'work') as workspace:
                item = workspace.write('original', b'original')
                (parent / 'work').rename(parent / 'retained')
                (parent / 'work').mkdir(mode=0o700)
                (parent / 'work/original').write_bytes(b'replacement')
                self.assertEqual(os.pread(item['fd'], 20, 0), b'original')
                with self.assertRaises(ValueError):
                    workspace.write('result.json', b'false qualification')
                self.assertFalse((parent / 'work/result.json').exists())

    def test_config_directory_replacement_refuses(self):
        with tempfile.TemporaryDirectory() as temporary:
            parent = Path(temporary)
            with module.Workspace(parent / 'work') as workspace:
                folder = workspace.directory('docker')
                workspace.write('config.json', b'{"auths":{}}', parent=folder)
                (parent / 'work/docker').rename(parent / 'work/retained')
                (parent / 'work/docker').mkdir(mode=0o700)
                with self.assertRaises(ValueError):
                    workspace.check()

    def test_credentials_and_evidence_refuse_each_substitution(self):
        for name in ['runtime.env', 'application.env', 'result.json', 'subjects.json']:
            for replacement in ['rename', 'symlink', 'hardlink', 'rewrite']:
                with self.subTest(name=name, replacement=replacement), tempfile.TemporaryDirectory() as temporary:
                    parent = Path(temporary)
                    with module.Workspace(parent / 'work') as workspace:
                        item = workspace.write(name, b'original')
                        path = parent / 'work' / name
                        if replacement == 'rewrite':
                            path.write_bytes(b'changed!')
                        else:
                            path.rename(parent / 'work/retained')
                            outside = parent / 'outside'
                            outside.write_bytes(b'foreign!')
                            if replacement == 'rename':
                                path.write_bytes(b'foreign!')
                                path.chmod(0o600)
                            elif replacement == 'symlink':
                                path.symlink_to(outside)
                            else:
                                os.link(outside, path)
                        self.assertNotEqual(path.read_bytes(), b'original')
                        self.assertEqual(os.pread(item['fd'], 8, 0), b'changed!' if replacement == 'rewrite' else b'original')
                        with self.assertRaises(ValueError):
                            workspace.check()
                        with self.assertRaises(ValueError):
                            workspace.remove(item)
                        self.assertTrue(path.exists())

    def test_report_symlink_never_touches_foreign_marker(self):
        with tempfile.TemporaryDirectory() as temporary:
            parent = Path(temporary)
            marker = parent / 'marker'
            marker.write_bytes(b'preserve')
            with module.Workspace(parent / 'work') as workspace:
                (parent / 'work/result.json').symlink_to(marker)
                with self.assertRaises(FileExistsError):
                    workspace.write('result.json', b'changed')
                self.assertEqual(marker.read_bytes(), b'preserve')

    def test_parent_replacement_refuses(self):
        with tempfile.TemporaryDirectory() as temporary:
            parent = Path(temporary)
            folder = parent / 'parent'
            folder.mkdir(mode=0o700)
            with module.Workspace(folder / 'work') as workspace:
                folder.rename(parent / 'retained-parent')
                (folder / 'work').mkdir(parents=True)
                with self.assertRaises(ValueError):
                    workspace.write('result.json', b'false')
                self.assertFalse((folder / 'work/result.json').exists())

    def test_cleanup_configuration_does_not_depend_on_workspace(self):
        path = Path('/proc/self/fd/config.json')
        self.assertFalse(path.exists())
        with self.assertRaises(OSError):
            path.write_bytes(b'must not create')

    def test_credential_cleanup_never_unlinks_a_swapped_entry(self):
        with tempfile.TemporaryDirectory() as temporary:
            parent = Path(temporary)
            with module.Workspace(parent / 'work') as workspace:
                credential = getattr(workspace, 'credential', None)
                item = credential(b'original') if credential is not None else workspace.write('runtime.env', b'original')
                path = parent / 'work/runtime.env'
                if credential is not None:
                    path.write_bytes(b'foreign')
                    path.chmod(0o600)
                unlink = os.unlink

                def swap_then_unlink(name, **kwargs):
                    path.rename(parent / 'work/retained')
                    path.write_bytes(b'replacement')
                    path.chmod(0o600)
                    return unlink(name, **kwargs)

                with patch.object(module.os, 'unlink', side_effect=swap_then_unlink) as deletion:
                    workspace.remove(item)
                self.assertFalse(deletion.called, 'credential release must not delete a directory entry')
                self.assertEqual(b'foreign', path.read_bytes())

    def test_credential_is_anonymous_sealed_and_readable_by_its_child(self):
        with tempfile.TemporaryDirectory() as temporary:
            with module.Workspace(Path(temporary) / 'work') as workspace:
                item = workspace.credential(b'synthetic fixture data')
                self.assertEqual(0, os.fstat(item['fd']).st_nlink)
                self.assertEqual([], os.listdir(workspace.root))
                self.assertEqual(module.CREDENTIAL_SEALS, fcntl.fcntl(item['fd'], fcntl.F_GET_SEALS))
                with self.assertRaises(PermissionError):
                    os.pwrite(item['fd'], b'change', 0)
                with self.assertRaises(PermissionError):
                    os.ftruncate(item['fd'], 0)
                program = 'import pathlib,sys;sys.stdout.buffer.write(pathlib.Path(sys.argv[1]).read_bytes())'
                child = subprocess.run([sys.executable, '-I', '-S', '-c', program, workspace.proc(item['fd'])],
                                       check=True, capture_output=True, timeout=5)
                self.assertEqual(b'synthetic fixture data', child.stdout)
                workspace.check()
                workspace.remove(item)
                self.assertFalse(Path(workspace.proc(item['fd'])).exists())

    def test_credential_release_survives_replaced_workspace_without_touching_replacement(self):
        with tempfile.TemporaryDirectory() as temporary:
            parent = Path(temporary)
            with module.Workspace(parent / 'work') as workspace:
                item = workspace.credential(b'synthetic fixture data')
                (parent / 'work').rename(parent / 'retained')
                (parent / 'work').mkdir(mode=0o700)
                marker = parent / 'work/runtime.env'
                marker.write_bytes(b'preserve')
                with self.assertRaises(ValueError):
                    workspace.check()
                workspace.remove(item)
                self.assertEqual(b'preserve', marker.read_bytes())
                self.assertFalse(Path(workspace.proc(item['fd'])).exists())

    def test_credential_bound_is_checked_before_allocation(self):
        with tempfile.TemporaryDirectory() as temporary:
            with module.Workspace(Path(temporary) / 'work') as workspace:
                with patch.object(module.os, 'memfd_create') as create:
                    for value in [b'', b'x' * 8193, None, False]:
                        with self.subTest(value_type=type(value).__name__), self.assertRaises(ValueError):
                            workspace.credential(value)
                create.assert_not_called()

    def test_sealing_failure_closes_the_staged_anonymous_file(self):
        with tempfile.TemporaryDirectory() as temporary:
            with module.Workspace(Path(temporary) / 'work') as workspace:
                create = os.memfd_create
                created = []

                def capture_descriptor(*args):
                    descriptor = create(*args)
                    created.append(descriptor)
                    return descriptor

                with patch.object(module.os, 'memfd_create', side_effect=capture_descriptor), \
                        patch.object(module.fcntl, 'fcntl', side_effect=OSError('inert seal refusal')):
                    with self.assertRaises(OSError):
                        workspace.credential(b'synthetic fixture data')
                self.assertEqual(1, len(created))
                with self.assertRaises(OSError):
                    os.fstat(created[0])
                self.assertEqual([], os.listdir(workspace.root))
                workspace.check()


if __name__ == '__main__':
    unittest.main()
