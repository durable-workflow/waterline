"""Source/marker controls only. No acceptance module or Docker is executed."""

import hashlib
import os
from pathlib import Path
import sys
import tempfile
import unittest

import yaml
from unittest.mock import patch

sys.path.insert(0, str(Path(__file__).resolve().parent))
import service_smoke_loading as loading


class FrozenLoadingTest(unittest.TestCase):
    def setUp(self):
        self.directory = tempfile.TemporaryDirectory(prefix="waterline-loading-source-")
        self.addCleanup(self.directory.cleanup)
        self.path = Path(self.directory.name)
        (self.path / "scripts/ci").mkdir(parents=True)
        self.sources = {name: b"synthetic_marker = 'captured'\n" for name in loading.MODULES}
        self.subjects = {name: hashlib.sha256(raw).hexdigest() for name, raw in self.sources.items()}
        for name, raw in self.sources.items():
            (self.path / "scripts/ci" / (name + ".py")).write_bytes(raw)
        self.root = loading.SourceRoot(self.path)
        self.addCleanup(self.root.close)
        self.addCleanup(self.unload)

    def unload(self):
        for name in loading.MODULES:
            sys.modules.pop(name, None)

    def test_pre_open_replacement_is_refused_before_any_compilation(self):
        name = loading.MODULES[-1]
        target = self.path / "scripts/ci" / (name + ".py")
        target.write_bytes(b"unselected = True\n")
        with patch("builtins.compile") as compile_source:
            with self.assertRaises(loading.LoadingError):
                loading.capture_sources(self.root, self.subjects)
            compile_source.assert_not_called()
        self.assertTrue(all(name not in sys.modules for name in loading.MODULES))

    def selected_node_step(self):
        workflow = yaml.safe_load((Path(__file__).parents[2] / '.github/workflows/published-image-qualification.yml').read_text())
        steps = workflow['jobs']['native-browser']['steps']
        matches = [step for step in steps if step.get('name') == 'Secure selected Node path before held executable capture']
        self.assertEqual(1, len(matches))
        raw = matches[0]['run']
        return raw.split("<<'PY_NODE'\n", 1)[1].rsplit('PY_NODE', 1)[0]

    def test_selected_node_path_is_secured_before_unchanged_held_capture(self):
        cache = self.path / 'synthetic-toolcache'
        node = cache / 'node/24.22.1/x64/bin/node'
        node.parent.mkdir(parents=True)
        node.write_bytes(b'synthetic Node file; never executed')
        cache.chmod(0o777)
        node.chmod(0o775)
        with self.assertRaisesRegex(loading.LoadingError, 'Source parent is writable'):
            loading.SourceRoot(node.parent)
        before = node.read_bytes()
        identity = (node.stat().st_dev, node.stat().st_ino, node.stat().st_uid)
        with patch.dict(os.environ, {'RUNNER_TOOL_CACHE': str(cache)}), patch('platform.machine', return_value='x86_64'), \
                patch('shutil.which', return_value=str(node)), patch('subprocess.run', side_effect=AssertionError('No process in synthetic control')):
            exec(compile(self.selected_node_step(), '<selected workflow Node path boundary>', 'exec'), {})
        self.assertEqual(before, node.read_bytes())
        after_identity = (node.stat().st_dev, node.stat().st_ino, node.stat().st_uid)
        self.assertEqual(identity, after_identity)
        root = loading.SourceRoot(node.parent)
        try:
            self.assertEqual(before, root.read('node'))
            cache.chmod(0o770)
            with self.assertRaisesRegex(loading.LoadingError, 'Source parent is writable'):
                root.check()
        finally:
            cache.chmod(0o700)
            root.close()

    def test_owned_ancestor_above_cache_is_hardened_without_touching_siblings(self):
        ancestor = self.path / 'shared-hosted-prefix'
        cache = ancestor / 'toolcache'
        node = cache / 'node/24.22.1/x64/bin/node'
        node.parent.mkdir(parents=True)
        node.write_bytes(b'synthetic Node; never executed')
        node.chmod(0o755)
        sibling = ancestor / 'unselected-sibling'
        sibling.write_bytes(b'unrelated bytes')
        sibling.chmod(0o666)
        ancestor.chmod(0o777)
        current = self.selected_node_step()
        predecessor = current.replace('    if before.st_mode & 0o022:\n',
            "    if before.st_mode & 0o022:\n        if path != cache and cache not in path.parents:\n            raise RuntimeError('An ancestor outside the selected Node cache is writable')\n")
        self.assertEqual('ec58ead1606cb16a443fd6c0b0497855121b2f2c7ee9aa47eb614c341d466c69', hashlib.sha256(predecessor.encode()).hexdigest())
        old_code = compile(predecessor, '<683 original Node path boundary>', 'exec')
        new_code = compile(current, '<corrected Node path boundary>', 'exec')
        with patch.dict(os.environ, {'RUNNER_TOOL_CACHE': str(cache)}), patch('platform.machine', return_value='x86_64'), \
                patch('shutil.which', return_value=str(node)), patch('subprocess.run', side_effect=AssertionError('No process in synthetic control')):
            with self.assertRaisesRegex(RuntimeError, 'ancestor outside the selected Node cache'):
                exec(old_code, {})
            self.assertEqual(0o777, ancestor.stat().st_mode & 0o777)
            exec(new_code, {})
        self.assertEqual(0o755, ancestor.stat().st_mode & 0o777)
        self.assertEqual(0o666, sibling.stat().st_mode & 0o777)
        self.assertEqual(b'unrelated bytes', sibling.read_bytes())
        root = loading.SourceRoot(node.parent)
        try:
            self.assertEqual(b'synthetic Node; never executed', root.read('node'))
        finally:
            root.close()

    def test_an_unselected_node_path_refuses_before_permission_mutation(self):
        cache = self.path / 'synthetic-toolcache'
        cache.mkdir(mode=0o777)
        cache.chmod(0o777)
        code = compile(self.selected_node_step(), '<selected workflow Node path boundary>', 'exec')
        with patch.dict(os.environ, {'RUNNER_TOOL_CACHE': str(cache)}), patch('platform.machine', return_value='x86_64'), \
                patch('shutil.which', return_value='/foreign/node'), patch('subprocess.run') as processes:
            with self.assertRaisesRegex(RuntimeError, 'exact pinned hosted-tool-cache'):
                exec(code, {})
            processes.assert_not_called()
        self.assertEqual(0o777, cache.stat().st_mode & 0o777)
        cache.chmod(0o700)

    def test_old_path_lookup_sees_swap_but_frozen_bytes_execute_the_original_marker(self):
        captured = loading.capture_sources(self.root, self.subjects)
        name = loading.MODULES[0]
        target = self.path / "scripts/ci" / (name + ".py")
        target.write_bytes(b"synthetic_marker = 'replacement'\n")
        predecessor = {}
        exec(compile(target.read_bytes(), "<old path lookup>", "exec"), predecessor)
        self.assertEqual("replacement", predecessor["synthetic_marker"])
        modules = loading.load_sources(captured, self.subjects)
        self.assertTrue(all(module.synthetic_marker == "captured" for module in modules.values()))

    def test_memory_substitution_is_refused_before_any_compilation(self):
        captured = loading.capture_sources(self.root, self.subjects)
        captured[loading.MODULES[-1]] = b"unselected = True\n"
        with patch("builtins.compile") as compile_source:
            with self.assertRaises(loading.LoadingError):
                loading.load_sources(captured, self.subjects)
            compile_source.assert_not_called()

    def test_symlink_or_changed_root_refuses_without_loading_code(self):
        target = self.path / "scripts/ci" / (loading.MODULES[0] + ".py")
        target.unlink()
        target.symlink_to("/unselected-source")
        with self.assertRaises(OSError):
            loading.capture_sources(self.root, self.subjects)
        moved = self.path.with_name(self.path.name + "-moved")
        self.path.rename(moved)
        self.path.mkdir(mode=0o700)
        try:
            with self.assertRaises(loading.LoadingError):
                self.root.check()
        finally:
            self.path.rmdir()
            moved.rename(self.path)

    def test_writable_files_oversize_and_incomplete_review_subjects_refuse(self):
        target = self.path / "scripts/ci" / (loading.MODULES[0] + ".py")
        target.chmod(0o666)
        with self.assertRaises(loading.LoadingError):
            self.root.read("scripts/ci/" + target.name)
        target.chmod(0o600)
        target.write_bytes(b"x" * (loading.MAX_SOURCE + 1))
        with self.assertRaises(loading.LoadingError):
            self.root.read("scripts/ci/" + target.name)
        with self.assertRaises(loading.LoadingError):
            loading.capture_sources(self.root, {})

    def test_preimported_module_cannot_supply_acceptance_authority(self):
        captured = loading.capture_sources(self.root, self.subjects)
        sys.modules[loading.MODULES[0]] = object()
        with self.assertRaises(loading.LoadingError):
            loading.load_sources(captured, self.subjects)

    @staticmethod
    def writable(path):
        for parent, directories, files in os.walk(path):
            Path(parent).chmod(0o700)
            for name in directories:
                (Path(parent) / name).chmod(0o700)
            for name in files:
                (Path(parent) / name).chmod(0o600)

    def test_frozen_cleanup_code_snapshot_survives_refused_data_root(self):
        captured = loading.capture_sources(self.root, self.subjects)
        extra = {name: (b"synthetic bootstrap bytes\n", hashlib.sha256(b"synthetic bootstrap bytes\n").hexdigest())
                 for name in ("service_smoke_loading.py", "service_smoke_bootstrap.py")}
        snapshot = loading.store_snapshot(self.root, captured, self.subjects, extra)
        self.addCleanup(snapshot.close)
        self.addCleanup(self.writable, snapshot.path)
        evidence = self.path / "evidence"
        evidence.mkdir(mode=0o700)
        data = loading.SourceRoot(evidence)
        self.addCleanup(data.close)
        evidence.rename(self.path / "moved-evidence")
        evidence.mkdir(mode=0o700)
        with self.assertRaises(loading.LoadingError):
            data.check()
        self.assertEqual(captured, loading.capture_sources(snapshot, self.subjects))
        self.assertEqual(0o500, snapshot.path.stat().st_mode & 0o777)
        self.assertEqual(0o400, (snapshot.path / "scripts/ci" / (loading.MODULES[0] + ".py")).stat().st_mode & 0o777)

    def test_unverified_bootstrap_snapshot_refuses_before_writing(self):
        captured = loading.capture_sources(self.root, self.subjects)
        extra = {name: (b"unselected bytes", "0" * 64)
                 for name in ("service_smoke_loading.py", "service_smoke_bootstrap.py")}
        before = set(self.path.iterdir())
        with self.assertRaises(loading.LoadingError):
            loading.store_snapshot(self.root, captured, self.subjects, extra)
        self.assertEqual(before, set(self.path.iterdir()))


if __name__ == "__main__":
    unittest.main()
