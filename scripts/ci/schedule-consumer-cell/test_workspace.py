"""No Docker/application execution: held workspace substitution controls."""
import importlib.util
import os
from pathlib import Path
import tempfile
import unittest

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


if __name__ == '__main__':
    unittest.main()
