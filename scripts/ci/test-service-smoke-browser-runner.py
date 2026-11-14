"""Synthetic descriptor/output controls. No Node, browser or Docker is run."""

import hashlib
import os
from pathlib import Path
import sys
import tempfile
import unittest

sys.path.insert(0, str(Path(__file__).resolve().parent))
import service_smoke_browser_runner as runner
from service_smoke_loading import SourceRoot


class BrowserRunnerTest(unittest.TestCase):
    def setUp(self):
        self.directory = tempfile.TemporaryDirectory(prefix="waterline-browser-source-")
        self.addCleanup(self.directory.cleanup)
        self.root = Path(self.directory.name)

    def hold(self, path, expected):
        descriptor = os.open(path, os.O_RDONLY | os.O_NOFOLLOW)
        try:
            image = runner.NodeImage(descriptor, expected)
        finally:
            os.close(descriptor)
        self.addCleanup(image.close)
        return image

    def test_tooling_pre_open_substitution_and_later_byte_change_refuse(self):
        path = self.root / "inert-native-marker"
        path.write_bytes(b"original synthetic image")
        expected = hashlib.sha256(path.read_bytes()).hexdigest()
        path.write_bytes(b"replacement marker")
        with self.assertRaises(runner.BrowserError):
            self.hold(path, expected)
        path.write_bytes(b"original synthetic image")
        held = self.hold(path, expected)
        path.write_bytes(b"later replacement")
        with self.assertRaises(runner.BrowserError):
            held.check()

    def test_tooling_writable_file_is_not_accepted(self):
        path = self.root / "inert-native-marker"
        path.write_bytes(b"synthetic marker")
        expected = hashlib.sha256(path.read_bytes()).hexdigest()
        path.chmod(0o666)
        with self.assertRaises(runner.BrowserError):
            self.hold(path, expected)

    def test_phase_output_is_private_unique_and_cannot_select_parent(self):
        root = SourceRoot(self.root)
        self.addCleanup(root.close)
        name, descriptor, temporary = runner.phase_output(root, "cached")
        try:
            self.assertEqual("browser-cached", name)
            self.assertEqual(0o700, os.fstat(descriptor).st_mode & 0o777)
            self.assertEqual(0o700, os.fstat(temporary).st_mode & 0o777)
            with self.assertRaises(FileExistsError):
                runner.phase_output(root, "cached")
            with self.assertRaises(runner.BrowserError):
                runner.phase_output(root, "../foreign")
        finally:
            os.close(temporary)
            os.close(descriptor)


if __name__ == "__main__":
    unittest.main()
