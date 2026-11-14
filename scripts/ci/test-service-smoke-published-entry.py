"""Inert current published job gate and phase-root controls only."""

import os
from pathlib import Path
import sys
import tempfile
import unittest
from unittest.mock import patch

sys.path.insert(0, str(Path(__file__).resolve().parent))
import service_smoke_published_entry as entry
from service_smoke_loading import SourceRoot


class PublishedEntryTest(unittest.TestCase):
    def selected(self):
        return {"index_digest": "sha256:" + "a" * 64, "architecture": "amd64", "source": "b" * 40,
                "source_tree": "c" * 40, "runner_commit": "d" * 40, "runner_tree": "e" * 40,
                "publisher_commit": "f" * 40, "producer_run_id": 1, "producer_run_attempt": 1, "run_id": 2, "run_attempt": 1}

    def test_missing_producer_or_runner_subject_cannot_select_a_lane(self):
        entry.selected_context(self.selected())
        for key in self.selected():
            value = self.selected()
            del value[key]
            with self.subTest(key=key), self.assertRaises(entry.EntryError):
                entry.selected_context(value)

    def test_source_reader_is_not_called_before_existing_producer_gate(self):
        selected = self.selected()
        with patch.object(entry, "PublishedInputs") as subjects:
            with self.assertRaises(entry.EntryError):
                entry.run_published(selected, repository=None, inputs=None, outputs=None, dependencies=None,
                                    node_descriptor=-1, node_sha256="unselected", root_type=None,
                                    context_ready=None, producer_gate_succeeded=False)
            subjects.assert_not_called()

    def test_each_existing_browser_lane_has_a_distinct_private_root(self):
        with tempfile.TemporaryDirectory(prefix="waterline-published-source-") as path:
            root = SourceRoot(Path(path))
            try:
                roots = [entry.child_root(root, kind, SourceRoot) for kind in ("primary", "automatic")]
                try:
                    self.assertNotEqual(os.fstat(roots[0].descriptor).st_ino, os.fstat(roots[1].descriptor).st_ino)
                    for value in roots:
                        self.assertEqual(0o700, os.fstat(value.descriptor).st_mode & 0o777)
                finally:
                    for value in roots:
                        value.close()
                with self.assertRaises(entry.EntryError):
                    entry.child_root(root, "../foreign", SourceRoot)
            finally:
                root.close()


if __name__ == "__main__":
    unittest.main()
