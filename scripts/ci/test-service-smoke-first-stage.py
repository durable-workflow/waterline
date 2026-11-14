"""Exact first-stage marker controls; no acceptance method or package is run."""

import hashlib
import os
from pathlib import Path
import sys
import subprocess
import tempfile
import unittest
from unittest.mock import patch

sys.path.insert(0, str(Path(__file__).resolve().parent))
import service_smoke_first_stage as stage


class FirstStageTest(unittest.TestCase):
    def setUp(self):
        self.directory = tempfile.TemporaryDirectory(prefix="waterline-first-stage-source-")
        self.addCleanup(self.directory.cleanup)
        self.root = Path(self.directory.name)
        (self.root / "scripts/ci").mkdir(parents=True)
        self.path = self.root / "scripts/ci/service_smoke_bootstrap.py"
        self.raw = b"def native():\n    return 'captured synthetic marker'\n"
        self.path.write_bytes(self.raw)
        self.guard = patch.object(stage, "BOOTSTRAP_SHA256", hashlib.sha256(self.raw).hexdigest())
        self.guard.start()
        self.addCleanup(self.guard.stop)

    def test_exact_actual_first_stage_compiles_only_captured_marker_bytes(self):
        self.assertEqual("captured synthetic marker", stage.launch(self.root, "native"))

    def test_pre_open_swap_refuses_before_any_compilation(self):
        self.path.write_bytes(b"def native():\n    return 'unselected replacement'\n")
        with patch("builtins.compile") as compile_source:
            with self.assertRaisesRegex(RuntimeError, "substituted before compilation"):
                stage.launch(self.root, "native")
            compile_source.assert_not_called()

    def test_old_path_reopen_runs_replacement_while_actual_first_stage_refuses(self):
        self.path.write_bytes(b"def native():\n    return 'unselected replacement'\n")
        predecessor = {}
        exec(compile(self.path.read_bytes(), "<old lookup>", "exec"), predecessor)
        self.assertEqual("unselected replacement", predecessor["native"]())
        with self.assertRaises(RuntimeError):
            stage.launch(self.root, "native")

    def test_symlink_writable_source_and_unselected_call_refuse(self):
        self.path.chmod(0o666)
        with self.assertRaises(RuntimeError):
            stage.launch(self.root, "native")
        self.path.unlink()
        self.path.symlink_to(self.root / "unselected")
        with self.assertRaises(OSError):
            stage.launch(self.root, "native")
        with self.assertRaises(RuntimeError):
            stage.launch(self.root, "foreign")

    def test_termination_reaches_owned_finally_and_disables_ordinary_work(self):
        raw = b'''import os,signal
def native():
    try:
        os.kill(os.getpid(),signal.SIGTERM)
    finally:
        print('owned finally reached',flush=True)
        try:
            __scope_check__()
        except InterruptedError:
            print('ordinary work refused',flush=True)
'''
        self.path.write_bytes(raw)
        expected = hashlib.sha256(raw).hexdigest()
        program = '''import importlib.util,sys
from pathlib import Path
spec=importlib.util.spec_from_file_location('canonical_stage',sys.argv[1])
stage=importlib.util.module_from_spec(spec);spec.loader.exec_module(stage)
stage.BOOTSTRAP_SHA256=sys.argv[3]
try:
 stage.launch(Path(sys.argv[2]),'native')
except InterruptedError:
 print('interruption retained',flush=True)
'''
        result = subprocess.run([sys.executable, "-I", "-S", "-c", program, str(Path(stage.__file__)), str(self.root), expected],
                                capture_output=True, timeout=3, env={"PATH": "/usr/bin:/bin", "LANG": "C.UTF-8"})
        self.assertEqual(0, result.returncode, result.stderr)
        self.assertEqual([b"owned finally reached", b"ordinary work refused", b"interruption retained"], result.stdout.splitlines())


if __name__ == "__main__":
    unittest.main()
