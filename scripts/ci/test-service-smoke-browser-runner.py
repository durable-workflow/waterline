"""Synthetic descriptor/output controls. No Node, browser or Docker is run."""

import hashlib
import json
import os
from pathlib import Path
import sys
import tempfile
import unittest
from unittest import mock

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


class RetainedReportTest(unittest.TestCase):
    def setUp(self):
        self.directory = tempfile.TemporaryDirectory(prefix="waterline-browser-report-")
        self.addCleanup(self.directory.cleanup)
        self.root = Path(self.directory.name)
        self.descriptor = os.open(self.root, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW)
        self.addCleanup(os.close, self.descriptor)
        self.report = {"source": "a" * 40, "status": "pass", "checks": [], "errors": []}

    def capture(self, value=None):
        raw = json.dumps(self.report if value is None else value, indent=2).encode()
        (self.root / "browser.json").write_bytes(raw)
        return raw

    def verify(self):
        runner.retained_report(self.descriptor, "browser.json", {**self.report, "phase": "before"})

    def test_pretty_report_can_exceed_compact_completion_frame_size(self):
        self.report["checks"] = [{"response": {"rows": [{"id": i, "state": "running"}
                                                         for i in range(90)]}} for _ in range(16)]
        self.assertLess(len(json.dumps(self.report, separators=(",", ":")).encode()), 65536)
        self.assertGreater(len(self.capture()), 65536)
        self.verify()

    def test_exact_file_bound_passes_and_one_byte_over_refuses_before_decode(self):
        raw = self.capture()
        path = self.root / "browser.json"
        path.write_bytes(raw + b" " * (runner.MAX_RETAINED_REPORT_BYTES - len(raw)))
        self.verify()
        with path.open("ab") as stream:
            stream.write(b" ")
        with mock.patch.object(runner, "decode_json") as decode:
            with self.assertRaisesRegex(runner.BrowserError, "Invalid bounded"):
                self.verify()
            decode.assert_not_called()

    def test_stdout_and_retained_content_must_remain_equal(self):
        self.capture({**self.report, "status": "nonpass"})
        with self.assertRaisesRegex(runner.BrowserError, "stdout and retained"):
            self.verify()

    def test_symlink_hardlink_and_directory_are_refused(self):
        self.capture()
        path = self.root / "browser.json"
        path.rename(self.root / "original.json")
        path.symlink_to("original.json")
        with self.assertRaises(OSError):
            self.verify()
        path.unlink()
        os.link(self.root / "original.json", path)
        with self.assertRaisesRegex(runner.BrowserError, "Invalid bounded"):
            self.verify()
        path.unlink()
        path.mkdir()
        with self.assertRaisesRegex(runner.BrowserError, "Invalid bounded"):
            self.verify()

    def test_file_changed_during_read_is_refused(self):
        self.capture()
        original_read = os.read

        def changed(descriptor, size):
            raw = original_read(descriptor, size)
            with (self.root / "browser.json").open("ab") as stream:
                stream.write(b" ")
            return raw

        with mock.patch.object(runner.os, "read", side_effect=changed):
            with self.assertRaisesRegex(runner.BrowserError, "changed during capture"):
                self.verify()


if __name__ == "__main__":
    unittest.main()
