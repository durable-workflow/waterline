#!/usr/bin/env python3
"""Offline countercontrols for comparing the complete production package graph."""

import copy
import importlib.util
import json
import os
from pathlib import Path
import tempfile
import unittest
from unittest import mock


ROOT = Path(__file__).resolve().parents[2]
spec = importlib.util.spec_from_file_location(
    "waterline_release_identity", Path(__file__).with_name("waterline_release_identity.py")
)
identity = importlib.util.module_from_spec(spec)
spec.loader.exec_module(identity)


class InstalledReleaseGraphTest(unittest.TestCase):
    def setUp(self):
        self.lock = json.loads((ROOT / "standalone/composer.lock").read_text())
        # Synthetic metadata tests the comparator, not an installed artifact.
        self.installed = {"dev": False, "dev-package-names": [],
                          "packages": copy.deepcopy(self.lock["packages"])}

    def test_complete_graph_matches_every_production_record(self):
        self.installed["packages"].reverse()
        result = identity.validate_installed_graph(self.lock, self.installed)
        self.assertEqual(len(self.lock["packages"]), result["package_count"])
        self.assertEqual(set(p["name"] for p in self.lock["packages"]), set(result["packages"]))
        self.assertNotIn(identity.WORKFLOW_PACKAGE, result["packages"])

    def test_stale_symfony_version_or_source_is_refused_even_with_the_same_sdk(self):
        for field, value in (("version", "v7.4.21"), ("reference", "0" * 40)):
            installed = copy.deepcopy(self.installed)
            package = next(p for p in installed["packages"] if p["name"] == "symfony/http-foundation")
            if field == "reference":
                package["source"][field] = value
            else:
                package[field] = value
            with self.subTest(field=field), self.assertRaises(identity.IdentityError):
                identity.validate_installed_graph(self.lock, installed)

    def test_missing_extra_duplicate_and_malformed_records_are_refused(self):
        inventories = [self.installed["packages"][:-1], [], None,
                       [*self.installed["packages"], self.installed["packages"][0]],
                       [*self.installed["packages"], {"name": "extra/package", "version": "1.0.0",
                                                    "source": {"reference": "a" * 40}}]]
        malformed = copy.deepcopy(self.installed["packages"])
        malformed[0]["source"]["reference"] = "main"
        inventories.append(malformed)
        for packages in inventories:
            with self.subTest(packages=type(packages).__name__), self.assertRaises(identity.IdentityError):
                identity.validate_installed_graph(self.lock, {**self.installed, "packages": packages})

    def test_development_flags_and_core_packages_are_refused(self):
        changes = [{"dev": True}, {"dev": None}, {"dev-package-names": ["phpunit/phpunit"]},
                   {"dev-package-names": None}]
        for change in changes:
            with self.subTest(change=change), self.assertRaises(identity.IdentityError):
                identity.validate_installed_graph(self.lock, {**self.installed, **change})
        for change in ({"dev_requirement": True}, {"name": identity.WORKFLOW_PACKAGE}):
            installed = copy.deepcopy(self.installed)
            installed["packages"][0].update(change)
            with self.subTest(change=change), self.assertRaises(identity.IdentityError):
                identity.validate_installed_graph(self.lock, installed)

    def test_changed_authorities_refuse_even_when_old_projection_matches(self):
        changes = [
            ("source", "url", "https://example.invalid/foreign.git"),
            ("source", "type", "svn"),
            ("source", "url", None),
            ("dist", "url", "https://example.invalid/foreign.zip"),
            ("dist", "reference", "b" * 40),
            ("dist", "shasum", None),
        ]
        for kind, field, value in changes:
            installed = copy.deepcopy(self.installed)
            installed["packages"][0][kind][field] = value
            with self.subTest(kind=kind, field=field):
                # The predecessor compared only names, versions and source SHA.
                self.assertEqual(identity.production_graph(self.lock["packages"]),
                                 identity.production_graph(installed["packages"]))
                with self.assertRaises(identity.IdentityError):
                    identity.validate_installed_graph(self.lock, installed)

    def test_missing_extra_and_mistyped_authority_fields_refuse(self):
        for kind in ("source", "dist"):
            for mutation in ("missing", "extra", "mistyped"):
                installed = copy.deepcopy(self.installed)
                authority = installed["packages"][0][kind]
                if mutation == "missing":
                    del authority["url"]
                elif mutation == "extra":
                    authority["mirrors"] = []
                else:
                    authority["url"] = False
                with self.subTest(kind=kind, mutation=mutation), self.assertRaises(identity.IdentityError):
                    identity.validate_installed_graph(self.lock, installed)

    def test_duplicate_dev_flag_predecessor_passes_candidate_refuses(self):
        raw = json.dumps(self.installed).encode().replace(b'"dev": false', b'"dev": true, "dev": false', 1)
        identity.validate_installed_graph(self.lock, json.loads(raw))
        with self.assertRaisesRegex(identity.IdentityError, "duplicate"):
            identity.decode_json(raw, "installed graph")


class CapturedJsonTest(unittest.TestCase):
    def test_rejects_nested_duplicates_nonfinite_non_utf8_and_trailing_json(self):
        samples = (b'{"source":{"url":"a","url":"b"}}', b'{"v":NaN}',
                   b'{"v":Infinity}', b'{"v":-Infinity}', b'{"v":1e999}',
                   b'{"v":"\xff"}', b'{}{}', b'[]')
        for raw in samples:
            with self.subTest(raw=raw), self.assertRaises(identity.IdentityError):
                identity.decode_json(raw, "captured input")

    def test_preserves_null_and_empty_string_and_accepts_exact_limit(self):
        raw = b'{"null":null,"empty":""}'
        self.assertEqual({"null": None, "empty": ""}, identity.decode_json(raw, "input"))
        raw = b'{}' + b' ' * (identity.MAX_JSON_BYTES - 2)
        self.assertEqual({}, identity.decode_json(raw, "input"))
        with self.assertRaises(identity.IdentityError):
            identity.decode_json(raw + b' ', "input")

    def test_regular_file_read_captures_bytes_and_refuses_oversized_input(self):
        with tempfile.TemporaryDirectory() as directory:
            path = Path(directory) / "input.json"
            path.write_bytes(b'{"dev":false}')
            self.assertEqual(({"dev": False}, b'{"dev":false}'), identity.load_json(path, "input"))
            path.write_bytes(b' ' * (identity.MAX_JSON_BYTES + 1))
            with self.assertRaises(identity.IdentityError):
                identity.load_json(path, "input")

    def test_symlink_directory_and_fifo_refuse_before_read(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            (root / "target").write_bytes(b'{}')
            (root / "link").symlink_to(root / "target")
            os.mkfifo(root / "fifo")
            for path in (root / "link", root, root / "fifo"):
                with self.subTest(path=path.name), self.assertRaises(identity.IdentityError):
                    identity.load_json(path, "input")

    def test_changed_open_file_refuses_instead_of_emitting_success(self):
        with tempfile.TemporaryDirectory() as directory:
            path = Path(directory) / "input.json"
            path.write_bytes(b'{"dev":false}')
            original = os.fstat
            calls = 0

            def mutate_after_read(descriptor):
                nonlocal calls
                calls += 1
                if calls == 2:
                    path.write_bytes(b'{"dev":true}')
                return original(descriptor)

            with mock.patch.object(identity.os, "fstat", side_effect=mutate_after_read):
                with self.assertRaisesRegex(identity.IdentityError, "changed"):
                    identity.load_json(path, "input")

    def test_path_replacement_cannot_retarget_the_open_descriptor(self):
        with tempfile.TemporaryDirectory() as directory:
            path = Path(directory) / "input.json"
            path.write_bytes(b'{"dev":false}')
            replacement = Path(directory) / "replacement.json"
            replacement.write_bytes(b'{"dev":true}')
            original = os.fstat
            calls = 0

            def replace_after_open(descriptor):
                nonlocal calls
                calls += 1
                if calls == 1:
                    replacement.replace(path)
                return original(descriptor)

            with mock.patch.object(identity.os, "fstat", side_effect=replace_after_open):
                value, raw = identity.load_json(path, "input")
            self.assertEqual((value, raw), ({"dev": False}, b'{"dev":false}'))
            self.assertEqual(path.read_bytes(), b'{"dev":true}')


if __name__ == "__main__":
    unittest.main()
