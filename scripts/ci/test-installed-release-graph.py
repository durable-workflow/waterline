#!/usr/bin/env python3
"""Offline countercontrols for comparing the complete production package graph."""

import copy
import importlib.util
import json
import os
from pathlib import Path
import shutil
import subprocess
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
            self.assertEqual(({"dev": False}, b'{"dev":false}'), identity.load_json(path, "input", root=Path(directory)))
            path.write_bytes(b' ' * (identity.MAX_JSON_BYTES + 1))
            with self.assertRaises(identity.IdentityError):
                identity.load_json(path, "input", root=Path(directory))

    def test_symlink_directory_and_fifo_refuse_before_read(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            (root / "target").write_bytes(b'{}')
            (root / "link").symlink_to(root / "target")
            os.mkfifo(root / "fifo")
            for path in (root / "link", root, root / "fifo"):
                with self.subTest(path=path.name), self.assertRaises(identity.IdentityError):
                    identity.load_json(path, "input", root=root)

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
                    identity.load_json(path, "input", root=Path(directory))

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
                value, raw = identity.load_json(path, "input", root=Path(directory))
            self.assertEqual(({"dev": False}, b'{"dev":false}'), (value, raw))
            self.assertEqual(b'{"dev":true}', path.read_bytes())

    def test_traversal_adjacent_prefix_and_parent_symlink_cannot_escape_root(self):
        with tempfile.TemporaryDirectory() as directory:
            base = Path(directory)
            allowed = base / "allowed"
            foreign = base / "allowed-foreign"
            allowed.mkdir()
            foreign.mkdir()
            target = foreign / "input.json"
            target.write_bytes(b'{}')
            (allowed / "link").symlink_to(foreign, target_is_directory=True)
            for path in (target, allowed / ".." / foreign.name / target.name, allowed / "link" / target.name):
                self.assertEqual({}, json.loads(path.read_bytes()))
                with self.subTest(path=str(path)), self.assertRaises(identity.IdentityError):
                    identity.load_json(path, "input", root=allowed)


class RouteCacheDataTest(unittest.TestCase):
    def setUp(self):
        self.before = [{"methods": ["GET", "HEAD"], "uri": "up", "domain": None,
                        "name": None, "action": "Closure", "middleware": [],
                        "wheres": {}, "defaults": {}, "binding_fields": {},
                        "scoped_bindings": False},
                       {"methods": ["POST"], "uri": "waterline/api/saved-views", "domain": None,
                        "name": "waterline.saved-views.store", "action": "ViewsController@store",
                        "middleware": ["web", "waterline.auth"], "wheres": {},
                        "defaults": {}, "binding_fields": {}, "scoped_bindings": False}]
        self.cached = copy.deepcopy(self.before)
        self.cached[0]["name"] = "generated::aB3dE5gH7jK9mN2p"

    def test_accepts_only_cached_generated_name_for_originally_unnamed_route(self):
        result = identity.validate_route_cache_routes(self.before, self.cached, self.before)
        self.assertEqual(2, result["route_count"])
        for name in ("", None):
            before = copy.deepcopy(self.before)
            before[0]["name"] = name
            identity.validate_route_cache_routes(before, self.cached, before)

    def test_changes_hidden_by_name_only_comparison_refuse(self):
        for field, value in (("methods", ["DELETE"]), ("uri", "other"), ("domain", "other.test"),
                             ("action", "OtherController@store"), ("middleware", []),
                             ("wheres", {"id": "[a-z]+"}), ("defaults", {"access": "operator"}),
                             ("binding_fields", {"id": "slug"}), ("scoped_bindings", True)):
            cached = copy.deepcopy(self.cached)
            cached[1][field] = value
            with self.subTest(field=field):
                self.assertEqual([r["name"] for r in self.cached], [r["name"] for r in cached])
                with self.assertRaises(identity.IdentityError):
                    identity.validate_route_cache_routes(self.before, cached, self.before)

    def test_count_order_stale_clear_and_explicit_name_changes_refuse(self):
        cases = [(self.cached[:-1], self.before), (list(reversed(self.cached)), self.before),
                 (self.cached, self.cached)]
        renamed = copy.deepcopy(self.cached)
        renamed[1]["name"] = "generated::aB3dE5gH7jK9mN3p"
        cases.append((renamed, self.before))
        for cached, cleared in cases:
            with self.subTest(cached=cached), self.assertRaises(identity.IdentityError):
                identity.validate_route_cache_routes(self.before, cached, cleared)

    def test_default_json_types_remain_distinct(self):
        before = copy.deepcopy(self.before)
        before[1]["defaults"] = {"value": 1}
        for value in (True, 1.0):
            cached = copy.deepcopy(self.cached)
            cached[1]["defaults"] = {"value": value}
            self.assertEqual(before[1]["defaults"], cached[1]["defaults"])
            with self.subTest(value=value), self.assertRaises(identity.IdentityError):
                identity.validate_route_cache_routes(before, cached, before)

    def test_collisions_malformed_records_and_arbitrary_cached_names_refuse(self):
        variants = [[], [*self.before, self.before[0]], [*self.before] * 1025]
        for field, value in (("name", self.before[1]["name"]), ("methods", ["GET", "GET"]),
                             ("defaults", {"number": float("inf")}), ("scoped_bindings", 1)):
            value_list = copy.deepcopy(self.before)
            value_list[0][field] = value
            variants.append(value_list)
        missing = copy.deepcopy(self.before)
        del missing[0]["middleware"]
        variants.append(missing)
        for routes in variants:
            with self.subTest(count=len(routes)), self.assertRaises(identity.IdentityError):
                identity.validate_route_cache_routes(routes, routes, routes)
        cached = copy.deepcopy(self.cached)
        cached[0]["name"] = "some-new-name"
        with self.assertRaises(identity.IdentityError):
            identity.validate_route_cache_routes(self.before, cached, self.before)


class UnwiredRuntimeProbeSourceTest(unittest.TestCase):
    def emitted_source(self):
        wrapper = (ROOT / "scripts/ci/service-mode-image-smoke.sh").read_bytes()
        start = wrapper.index(b"current_runtime_probe_source() {")
        terminator = b"\nWATERLINE_CURRENT_RUNTIME_PHP\n}"
        end = wrapper.index(terminator, start) + len(terminator)
        result = subprocess.run(
            ["sh"], input=wrapper[start:end] + b"\ncurrent_runtime_probe_source\n",
            capture_output=True, timeout=5, env={"PATH": "/usr/bin:/bin"},
        )
        self.assertEqual(0, result.returncode, result.stderr)
        self.assertEqual(b"", result.stderr)
        return result.stdout

    def test_actual_function_stdout_equals_canonical_literal_including_final_lf(self):
        wrapper = (ROOT / "scripts/ci/service-mode-image-smoke.sh").read_bytes()
        literal = wrapper.split(b"cat <<'WATERLINE_CURRENT_RUNTIME_PHP'\n", 1)[1].split(
            b"WATERLINE_CURRENT_RUNTIME_PHP\n}", 1,
        )[0]
        self.assertTrue(literal.endswith(b"\n"))
        self.assertEqual(literal, self.emitted_source())
        self.assertNotEqual(literal.rstrip(b"\n"), self.emitted_source())

    def test_probe_has_no_ordinary_callsite_and_metadata_precedes_autoload(self):
        wrapper = (ROOT / "scripts/ci/service-mode-image-smoke.sh").read_text()
        self.assertEqual(1, wrapper.count("current_runtime_probe_source"))
        source = self.emitted_source().decode()
        self.assertLess(source.index("if ($mode === 'metadata')"), source.index("require '/app/vendor/autoload.php'"))
        self.assertEqual(0, subprocess.run(
            ["sh", "-n", str(ROOT / "scripts/ci/service-mode-image-smoke.sh")],
            capture_output=True, timeout=5,
        ).returncode)

    @unittest.skipUnless(shutil.which("php"), "PHP syntax control needs the existing PHP runtime")
    def test_php_probe_compiles_without_executing_application_code(self):
        result = subprocess.run([shutil.which("php"), "-l"], input=self.emitted_source(),
                                capture_output=True, timeout=5)
        self.assertEqual(0, result.returncode, result.stderr)

    def handler_control(self, operation):
        source = self.emitted_source().decode()
        handler = source.split("$mode = $argv[1] ?? '';\n", 1)[1].split("\ntry {", 1)[0]
        # Only the emitted handler runs. The application and package probe body
        # are excluded; the missing path belongs to this disposable fixture.
        with tempfile.TemporaryDirectory() as directory:
            return subprocess.run(
                [shutil.which("php"), "-n", "-d", "display_errors=0", "-r",
                 "error_reporting(0);\n" + handler + "\n" + operation,
                 str(Path(directory) / "optional-missing.env")],
                capture_output=True, timeout=5,
            )

    @unittest.skipUnless(shutil.which("php"), "PHP warning controls need the existing PHP runtime")
    def test_suppressed_optional_read_then_enabled_warning_retains_strict_handler(self):
        result = self.handler_control("""
if (@file_get_contents($argv[1]) !== false || error_reporting() !== E_ALL) { exit(2); }
try { file_get_contents($argv[1]); exit(3); }
catch (ErrorException $error) {
    echo json_encode([$error->getSeverity(), error_reporting() === E_ALL]);
}
""")
        self.assertEqual(0, result.returncode, result.stderr)
        self.assertEqual(b"", result.stderr)
        self.assertEqual([2, True], json.loads(result.stdout))

    @unittest.skipUnless(shutil.which("php"), "PHP warning controls need the existing PHP runtime")
    def test_enabled_user_warning_and_notice_still_throw(self):
        result = self.handler_control("""
$seen = [];
foreach ([E_USER_WARNING, E_USER_NOTICE] as $severity) {
    try { trigger_error('synthetic enabled source control', $severity); exit(2); }
    catch (ErrorException $error) { $seen[] = $error->getSeverity(); }
}
echo json_encode($seen);
""")
        self.assertEqual(0, result.returncode, result.stderr)
        self.assertEqual(b"", result.stderr)
        self.assertEqual([512, 1024], json.loads(result.stdout))

    @unittest.skipUnless(shutil.which("php"), "PHP warning controls need the existing PHP runtime")
    def test_at_operator_cannot_suppress_fatal_user_error(self):
        result = self.handler_control("""
try { @trigger_error('synthetic fatal source control', E_USER_ERROR); exit(2); }
catch (ErrorException $error) { echo json_encode($error->getSeverity()); }
""")
        self.assertEqual(0, result.returncode, result.stderr)
        self.assertEqual(b"", result.stderr)
        self.assertEqual(256, json.loads(result.stdout))


if __name__ == "__main__":
    unittest.main()
