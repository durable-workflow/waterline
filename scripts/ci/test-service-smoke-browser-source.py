"""Compile-only original/callable byte controls. No browser or application."""

import hashlib
from pathlib import Path
import re
import shutil
import subprocess
import sys
import unittest
from unittest.mock import patch

sys.path.insert(0, str(Path(__file__).resolve().parent))
import service_smoke_browser_source as source


class BrowserSourceTest(unittest.TestCase):
    def original(self, kind):
        name = "request-budget-browser.mjs" if kind == "primary" else "request-budget-auto.mjs"
        return Path(__file__).with_name(name).read_bytes()

    def test_actual_callable_bytes_match_both_literal_subjects_and_preserve_all_assertions(self):
        for kind in ("primary", "automatic"):
            raw = self.original(kind)
            compiled = source.frozen_source(raw, kind)
            self.assertEqual(source.ORIGINAL[kind], hashlib.sha256(raw).hexdigest())
            self.assertEqual(source.COMPILED[kind], hashlib.sha256(compiled).hexdigest())
            assertions = lambda value: [line.strip() for line in value.splitlines() if re.search(rb"assert\.", line)]
            self.assertEqual(assertions(raw), assertions(compiled))
            self.assertNotIn(b"mode.json.tmp", compiled)
            self.assertNotIn(b"fs.readFileSync('writes.jsonl'", compiled)
            self.assertTrue(compiled.endswith(b"return report;\n}\n"))

    def test_original_substitution_refuses_before_transformation(self):
        raw = self.original("primary") + b"\n// unreviewed change\n"
        with patch.object(source, "transform_primary") as transform:
            with self.assertRaises(source.BrowserSourceError):
                source.frozen_source(raw, "primary")
            transform.assert_not_called()

    def test_actual_callable_substitution_or_omitted_boundary_refuses(self):
        raw = self.original("primary")
        with patch.object(source, "transformed_source", return_value=b"unselected callable"):
            with self.assertRaises(source.BrowserSourceError):
                source.frozen_source(raw, "primary")
        with self.assertRaises(source.BrowserSourceError):
            source.replace_selected("missing", "expected boundary", "replacement")

    @unittest.skipUnless(shutil.which("node"), "Node compile-only tool is unavailable")
    def test_node_parses_the_exact_callable_modules_without_import_or_execution(self):
        for kind in ("primary", "automatic"):
            compiled = source.frozen_source(self.original(kind), kind)
            result = subprocess.run([shutil.which("node"), "--check", "--input-type=module"], input=compiled,
                                    capture_output=True, timeout=5)
            self.assertEqual(0, result.returncode, result.stderr)
            self.assertEqual(b"", result.stdout)


if __name__ == "__main__":
    unittest.main()
