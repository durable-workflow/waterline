"""Private synthetic output/subject controls. No native invocation runs."""

import json
import os
from pathlib import Path
import sys
import tempfile
import unittest

sys.path.insert(0, str(Path(__file__).resolve().parent))
import service_smoke_entry as entry
from service_smoke_loading import SourceRoot


class EntryTest(unittest.TestCase):
    def setUp(self):
        self.directory = tempfile.TemporaryDirectory(prefix="waterline-entry-source-")
        self.addCleanup(self.directory.cleanup)
        self.path = Path(self.directory.name)
        self.root = SourceRoot(self.path)
        self.addCleanup(self.root.close)

    def output(self, limit=65536):
        value = entry.Output(self.root, "native-cleanup.jsonl", limit)
        self.addCleanup(value.close)
        return value

    def test_new_output_retains_bytes_and_refuses_preexisting_destination(self):
        output = self.output()
        output.append({"synthetic": True})
        self.assertEqual({"synthetic": True}, json.loads((self.path / output.name).read_bytes()))
        self.assertEqual(0o600, (self.path / output.name).stat().st_mode & 0o777)
        with self.assertRaises(FileExistsError):
            self.output()

    def test_replacement_path_is_preserved_and_never_overwritten(self):
        output = self.output()
        output.append({"synthetic": True})
        target = self.path / output.name
        target.rename(self.path / "original")
        target.write_bytes(b"foreign replacement")
        with self.assertRaises(entry.EntryError):
            output.append({"late": True})
        self.assertEqual(b"foreign replacement", target.read_bytes())

    def test_output_content_mutation_and_byte_overrun_refuse(self):
        output = self.output(limit=25)
        output.append({"synthetic": True})
        with self.assertRaises(entry.EntryError):
            output.append({"exceeds": "selected bound"})
        os.pwrite(output.descriptor, b"foreign", 0)
        with self.assertRaises(entry.EntryError):
            output.check()

    def test_root_namespace_swap_refuses_before_writing(self):
        output = self.output()
        moved = self.path.with_name(self.path.name + "-moved")
        self.path.rename(moved)
        self.path.mkdir(mode=0o700)
        try:
            with self.assertRaises(RuntimeError):
                output.append({"unselected": True})
            self.assertEqual([], list(self.path.iterdir()))
            self.assertEqual(b"", (moved / output.name).read_bytes())
        finally:
            self.path.rmdir()
            moved.rename(self.path)

    def test_cleanup_checkpoints_reject_unknown_roles_and_short_ids(self):
        journal = entry.Journal(self.root, {"synthetic": True})
        self.addCleanup(journal.output.close)
        journal.checkpoint("container", "fixture", None)
        journal.checkpoint("container", "fixture", "a" * 64)
        for kind, role, identifier in (("container", "foreign", None), ("network", "application", None),
                                       ("start", "application", None), ("container", "application", "a" * 12)):
            with self.subTest(kind=kind, role=role), self.assertRaises(RuntimeError):
                journal.checkpoint(kind, role, identifier)
        self.assertEqual(3, len((self.path / journal.output.name).read_bytes().splitlines()))

    def test_selection_needs_all_independent_exact_subjects(self):
        selected = {"source": "a" * 40, "source_tree": "b" * 40, "runner_commit": "c" * 40,
                    "runner_tree": "d" * 40, "architecture": "amd64", "archive_digest": "sha256:" + "e" * 64,
                    "security_digest": "sha256:" + "f" * 64, "workflow_sha256": "0" * 64, "run_id": 1, "run_attempt": 1}
        entry.check_selection(selected)
        for key in selected:
            missing = dict(selected)
            del missing[key]
            with self.subTest(key=key), self.assertRaises(entry.EntryError):
                entry.check_selection(missing)
        for key, value in (("run_attempt", True), ("architecture", "emulated"), ("archive_digest", "mutable:tag")):
            with self.subTest(key=key), self.assertRaises(entry.EntryError):
                entry.check_selection({**selected, key: value})


if __name__ == "__main__":
    unittest.main()
