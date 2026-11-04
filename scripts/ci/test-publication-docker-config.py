#!/usr/bin/env python3
"""Behaviour of the publisher's Docker config isolation around copy and attest."""

import os
from pathlib import Path
import stat
import subprocess
import tempfile
import unittest

SCRIPT = Path(__file__).with_name("publication-docker-config.sh")
RUNNER_CONFIG = b'{"auths":{},"credsStore":"runner"}\n'
PUBLICATION_CONFIG = b'{"auths":{"docker.io":{"auth":"cHVibGlzaGVyOnRva2Vu"}}}\n'


class PublicationDockerConfigTest(unittest.TestCase):
    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory()
        self.addCleanup(self.temporary.cleanup)
        root = Path(self.temporary.name)
        self.home, self.runner_temp = root / "home", root / "runner-temp"
        self.home.mkdir()
        self.runner_temp.mkdir()
        self.config = self.home / ".docker" / "config.json"

    def run_script(self, mode):
        env = {"HOME": str(self.home), "RUNNER_TEMP": str(self.runner_temp), "PATH": os.environ["PATH"]}
        return subprocess.run(["bash", str(SCRIPT), mode], env=env, capture_output=True, text=True, check=False)

    def runner_file(self):
        self.config.parent.mkdir(mode=0o700)
        self.config.write_bytes(RUNNER_CONFIG)
        self.config.chmod(0o644)

    def test_runner_file_is_set_aside_and_restored_after_publication(self):
        self.runner_file()
        self.assertEqual(self.run_script("isolate").returncode, 0)
        self.assertFalse(self.config.exists())
        saved = self.runner_temp / "waterline-runner-docker-config.json"
        self.assertEqual(saved.read_bytes(), RUNNER_CONFIG)
        self.assertEqual(stat.S_IMODE(saved.stat().st_mode), 0o600)
        self.config.write_bytes(PUBLICATION_CONFIG)
        self.assertEqual(self.run_script("restore").returncode, 0)
        self.assertEqual(self.config.read_bytes(), RUNNER_CONFIG)
        self.assertFalse(saved.exists())

    def test_restore_after_a_failed_login_returns_the_runner_file(self):
        self.runner_file()
        self.assertEqual(self.run_script("isolate").returncode, 0)
        # Login failed, so no publication config was ever written.
        self.assertEqual(self.run_script("restore").returncode, 0)
        self.assertEqual(self.config.read_bytes(), RUNNER_CONFIG)

    def test_without_a_runner_file_restore_removes_the_publication_login(self):
        self.assertEqual(self.run_script("isolate").returncode, 0)
        self.assertEqual(stat.S_IMODE(self.config.parent.stat().st_mode), 0o700)
        self.config.write_bytes(PUBLICATION_CONFIG)
        self.assertEqual(self.run_script("restore").returncode, 0)
        self.assertFalse(self.config.exists())

    def test_restore_before_isolation_leaves_the_runner_file(self):
        self.runner_file()
        self.assertEqual(self.run_script("restore").returncode, 0)
        self.assertEqual(self.config.read_bytes(), RUNNER_CONFIG)

    def test_a_second_isolation_or_a_linked_config_is_refused(self):
        self.runner_file()
        self.assertEqual(self.run_script("isolate").returncode, 0)
        second = self.run_script("isolate")
        self.assertNotEqual(second.returncode, 0)
        self.assertIn("::error::", second.stderr)
        self.assertEqual(self.run_script("restore").returncode, 0)
        target = self.home / "elsewhere.json"
        target.write_bytes(RUNNER_CONFIG)
        self.config.unlink()
        self.config.symlink_to(target)
        linked = self.run_script("isolate")
        self.assertNotEqual(linked.returncode, 0)
        self.assertIn("not a regular file", linked.stderr)
        self.assertEqual(target.read_bytes(), RUNNER_CONFIG)

    def test_contents_are_never_printed(self):
        self.runner_file()
        for mode in ("isolate", "restore"):
            result = self.run_script(mode)
            self.assertNotIn("credsStore", result.stdout + result.stderr)


if __name__ == "__main__":
    unittest.main()
