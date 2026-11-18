"""First-code substitution controls with a benign controller, no Docker."""
import hashlib
import importlib.util
import os
from pathlib import Path
import subprocess
import tempfile
import unittest

ROOT = Path(__file__).resolve().parent
spec = importlib.util.spec_from_file_location('literal_bootstrap_control', ROOT / 'trusted_bootstrap.py')
module = importlib.util.module_from_spec(spec)
spec.loader.exec_module(module)


class FirstInstructionBoundaryTest(unittest.TestCase):
    def test_preopen_replacement_executes_predecessor_and_bootstrap_refuses(self):
        for replacement in ['file', 'symlink']:
            with self.subTest(replacement=replacement), tempfile.TemporaryDirectory() as temporary:
                root = Path(temporary)
                repository = root / 'repository'
                repository.mkdir()
                controller = repository / 'scripts/ci/schedule-consumer-cell/runner.py'
                controller.parent.mkdir(parents=True)
                original = b'raise RuntimeError("benign controller should not execute in this control")\n'
                controller.write_bytes(original)
                environment = {'PATH': os.defpath, 'GIT_CONFIG_NOSYSTEM': '1', 'GIT_CONFIG_GLOBAL': '/dev/null'}
                for args in [['init', '-q'], ['add', '.'], ['-c', 'user.name=Waterline', '-c', 'user.email=waterline@example.invalid', '-c', 'commit.gpgsign=false', 'commit', '-qm', 'benign frozen controller']]:
                    subprocess.run(['/usr/bin/git', '-C', str(repository), *args], env=environment, check=True, capture_output=True)
                commit = subprocess.check_output(['/usr/bin/git', '-C', str(repository), 'rev-parse', 'HEAD'], env=environment, text=True).strip()
                packet = root / 'packet.json'
                packet.write_bytes(b'{}')
                bootstrap = module.render(commit, str(repository), str(packet), hashlib.sha256(original).hexdigest(), hashlib.sha256(b'{}').hexdigest())
                substitute = b'from pathlib import Path\nPath("substitution-marker").write_bytes(b"executed")\n'
                if replacement == 'file':
                    controller.write_bytes(substitute)
                else:
                    other = root / 'substitute.py'
                    other.write_bytes(substitute)
                    controller.unlink()
                    controller.symlink_to(other)
                subprocess.run(['/usr/bin/python3', '-I', '-B', str(controller)], cwd=root, check=True, env=environment)
                marker = root / 'substitution-marker'
                self.assertTrue(marker.exists())
                marker.unlink()
                result = subprocess.run(['/usr/bin/python3', '-I', '-S', '-B', '-c', bootstrap], cwd=root, env=environment, capture_output=True)
                self.assertNotEqual(result.returncode, 0)
                self.assertFalse(marker.exists())
                self.assertFalse(any(root.glob('waterline-schedule-cell-*')))

    def test_changed_packet_refuses_before_controller_executes(self):
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            repository = root / 'repository'
            repository.mkdir()
            controller = repository / 'scripts/ci/schedule-consumer-cell/runner.py'
            controller.parent.mkdir(parents=True)
            data = b'from pathlib import Path\nPath("controller-executed").touch()\n'
            controller.write_bytes(data)
            env = {'PATH': os.defpath, 'GIT_CONFIG_NOSYSTEM': '1', 'GIT_CONFIG_GLOBAL': '/dev/null'}
            for args in [['init', '-q'], ['add', '.'], ['-c', 'user.name=Waterline', '-c', 'user.email=waterline@example.invalid', '-c', 'commit.gpgsign=false', 'commit', '-qm', 'benign frozen controller']]:
                subprocess.run(['/usr/bin/git', '-C', str(repository), *args], env=env, check=True, capture_output=True)
            commit = subprocess.check_output(['/usr/bin/git', '-C', str(repository), 'rev-parse', 'HEAD'], env=env, text=True).strip()
            packet = root / 'packet.json'
            packet.write_bytes(b'replacement')
            bootstrap = module.render(commit, str(repository), str(packet), hashlib.sha256(data).hexdigest(), hashlib.sha256(b'{}').hexdigest())
            result = subprocess.run(['/usr/bin/python3', '-I', '-S', '-B', '-c', bootstrap], cwd=root, env=env, capture_output=True)
            self.assertNotEqual(result.returncode, 0)
            self.assertFalse((root / 'controller-executed').exists())


if __name__ == '__main__':
    unittest.main()
