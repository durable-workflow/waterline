"""Inert probe-return vectors; no PHP, package, Docker or cache effect runs.

Transport/ownership/profile checks have their own controls. This suite supplies
only synthetic role results to test graph sealing and phase causality.
"""

import base64
import copy
from dataclasses import replace
import hashlib
import json
from pathlib import Path
import subprocess
import sys
import unittest
from unittest.mock import patch

sys.path.insert(0, str(Path(__file__).resolve().parent))
import service_smoke_observation as observation
import waterline_release_identity as identity


ROOT = Path(__file__).resolve().parents[2]


class InertRole:
    def __init__(self, role, reports):
        self.role = role
        self.run = "waterline-service-" + "4" * 32
        self.image = "sha256:" + "3" * 64
        self.identifier = ("1" if role == "metadata" else "2") * 64
        self.network = None if role == "metadata" else "5" * 64
        self.reports = copy.deepcopy(reports)
        self.commands = []
        self.launch = {"Entrypoint": ["/usr/local/bin/php"], "Cmd": ["-n", "/dev/stdin", "metadata"]}
        self.state = {"OOMKilled": False, "Status": "exited" if role == "metadata" else "running", "ExitCode": 0}
        self.code = 0
        self.stderr = b""

    def inspect(self, _transport):
        return {"Config": self.launch, "State": self.state}

    def command(self, _transport, verb, arguments, **options):
        mode = "metadata" if verb == "start" else arguments[2]
        self.commands.append({"mode": mode, "arguments": arguments, **options})
        report = self.reports.pop(0)
        raw = report if isinstance(report, bytes) else json.dumps(report).encode()
        return subprocess.CompletedProcess([], self.code, raw, self.stderr)


class ObservationTest(unittest.TestCase):
    def setUp(self):
        # No effective container is represented by this fixture. Delegated
        # profile validation is independently tested in the resource suite.
        guard = patch.object(observation, "validate_profile")
        guard.start()
        self.addCleanup(guard.stop)
        wrapper = (ROOT / "scripts/ci/service-mode-image-smoke.sh").read_bytes()
        self.program = wrapper.split(b"cat <<'WATERLINE_CURRENT_RUNTIME_PHP'\n", 1)[1].split(
            b"WATERLINE_CURRENT_RUNTIME_PHP\n}", 1,
        )[0]
        self.lock = (ROOT / "standalone/composer.lock").read_bytes()
        self.installed = {"dev": False, "dev-package-names": [], "packages": json.loads(self.lock)["packages"]}
        self.installed_raw = json.dumps(self.installed).encode()
        self.installed_sha = observation.digest(self.installed_raw)
        self.ini = {key: "" for key in observation.INI_KEYS}
        self.ini["memory_limit"] = "128M"
        self.ini["include_path"] = ".:/usr/local/lib/php"
        self.classes = {name: {"path": path, "sha256": hashlib.sha256(name.encode()).hexdigest()}
                        for name, path in observation.CLASSES.items()}
        self.source_classes = {name: data["sha256"] for name, data in self.classes.items()
                               if name.startswith("Waterline\\")}
        self.routes = [{"methods": ["GET", "HEAD"], "uri": "waterline/running", "domain": None,
                        "name": "waterline.running", "action": "Controller@index", "middleware": ["web"],
                        "wheres": {}, "defaults": {"value": 1}, "binding_fields": {}, "scoped_bindings": False}]

    def cache(self, present):
        return {"path": observation.CACHE_PATH, "present": present, "bytes": 128 if present else 0,
                "sha256": "6" * 64 if present else None}

    def base(self, mode):
        metadata = mode == "metadata"
        return {"schema": observation.SCHEMA, "mode": mode, "php_version": "8.3.36", "php_int_size": 8,
                "php_binary": "/usr/local/bin/php", "effective_uid": 1000 if metadata else 82,
                "ini_loaded_file": None, "ini_scanned_files": None, "ini_before": copy.deepcopy(self.ini),
                "ini_after": copy.deepcopy(self.ini), "lock_sha256": observation.LOCK_SHA256,
                "installed_sha256": self.installed_sha}

    def metadata_report(self, installed=None):
        raw = self.installed_raw if installed is None else json.dumps(installed).encode()
        return {**self.base("metadata"), "lock_base64": base64.b64encode(self.lock).decode(),
                "installed_base64": base64.b64encode(raw).decode(), "installed_sha256": observation.digest(raw),
                "cache": self.cache(False)}

    def verified(self):
        role = InertRole("metadata", [self.metadata_report()])
        return observation.verify_metadata(None, role, self.program, self.lock)

    def phase_reports(self):
        reports = []
        for mode, present in [("clear-before", False), ("inventory", False), ("cache-build", True),
                              ("inventory", True), ("clear-after", False), ("inventory", False)]:
            report = {**self.base(mode), "classes": copy.deepcopy(self.classes), "cache": self.cache(present)}
            report.update({"routes": copy.deepcopy(self.routes)} if mode == "inventory" else {
                "command": "route:cache" if mode == "cache-build" else "route:clear", "exit_status": 0,
            })
            reports.append(report)
        return reports

    def exercise(self, phase, role):
        self.exercised.append((phase, role.identifier))
        return {"scope": "synthetic-callback-only", "phase": phase}

    def phases(self, reports=None, metadata=None, source_classes=None):
        role = InertRole("application", self.phase_reports() if reports is None else reports)
        self.exercised = []
        result = observation.cache_phases(None, role, self.program, metadata or self.verified(),
                                          self.source_classes if source_classes is None else source_classes, self.exercise)
        return role, result

    def test_actual_callable_representation_and_complete_graph_are_bound(self):
        metadata = self.verified()
        self.assertEqual(self.lock, metadata.lock)
        self.assertEqual(self.installed_raw, metadata.installed)
        self.assertEqual(self.installed_sha, metadata.installed_sha256)
        self.assertEqual(79, len(json.loads(metadata.installed)["packages"]))

    def test_program_or_source_lock_swap_refuses_before_transport(self):
        for program, lock in ((self.program[:-1], self.lock), (self.program, self.lock + b"\n")):
            role = InertRole("metadata", [self.metadata_report()])
            with self.assertRaises(observation.ObservationError):
                observation.verify_metadata(None, role, program, lock)
            self.assertEqual([], role.commands)

    def test_wrong_interpreter_launch_refuses_before_transport(self):
        role = InertRole("metadata", [self.metadata_report()])
        role.launch["Cmd"] = ["/dev/stdin", "metadata"]
        with self.assertRaises(observation.ObservationError):
            observation.verify_metadata(None, role, self.program, self.lock)
        self.assertEqual([], role.commands)

    def test_missing_extra_duplicate_dev_or_authority_changed_package_refuses(self):
        variants = []
        for mutation in ("missing", "duplicate", "extra", "dev", "authority"):
            installed = copy.deepcopy(self.installed)
            if mutation == "missing":
                installed["packages"].pop()
            elif mutation == "duplicate":
                installed["packages"].append(copy.deepcopy(installed["packages"][0]))
            elif mutation == "extra":
                item = copy.deepcopy(installed["packages"][0])
                item["name"] = "synthetic/extra"
                installed["packages"].append(item)
            elif mutation == "dev":
                installed["dev"] = True
            else:
                installed["packages"][0]["dist"]["reference"] = "a" * 40
            variants.append((mutation, installed))
        for mutation, installed in variants:
            with self.subTest(mutation=mutation):
                role = InertRole("metadata", [self.metadata_report(installed)])
                with self.assertRaises(identity.IdentityError):
                    observation.verify_metadata(None, role, self.program, self.lock)
                self.assertEqual(["metadata"], [call["mode"] for call in role.commands])

    def test_stale_cache_or_changed_php_configuration_refuses(self):
        reports = [self.metadata_report(), self.metadata_report(), self.metadata_report()]
        reports[0]["cache"] = self.cache(True)
        reports[1]["ini_loaded_file"] = "/unselected/php.ini"
        reports[2]["ini_before"]["auto_prepend_file"] = "/unselected/input.php"
        reports[2]["ini_after"] = copy.deepcopy(reports[2]["ini_before"])
        for report in reports:
            with self.subTest(report=report["ini_loaded_file"]):
                role = InertRole("metadata", [report])
                with self.assertRaises(observation.ObservationError):
                    observation.verify_metadata(None, role, self.program, self.lock)

    def test_malformed_raw_metadata_and_duplicate_keys_refuse(self):
        reports = [self.metadata_report(), self.metadata_report(), b'{"schema":1,"schema":2}']
        reports[0]["installed_base64"] += "!"
        reports[1]["installed_sha256"] = "a" * 64
        for report in reports:
            with self.subTest(raw=isinstance(report, bytes)):
                role = InertRole("metadata", [report])
                with self.assertRaises((observation.ObservationError, identity.IdentityError)):
                    observation.verify_metadata(None, role, self.program, self.lock)

    def test_exact_phase_order_uses_one_role_and_the_verified_byte_seal(self):
        role, result = self.phases()
        self.assertEqual(["clear-before", "inventory", "cache-build", "inventory", "clear-after", "inventory"],
                         [call["mode"] for call in role.commands])
        self.assertTrue(all(call["data"] == self.program and call["arguments"][-1] == self.installed_sha for call in role.commands))
        self.assertEqual([("before", role.identifier), ("cached", role.identifier), ("cleared", role.identifier)], self.exercised)
        self.assertEqual({"outcome": "matched", "route_count": 1}, result["route_comparison"])

    def test_cross_image_or_run_and_missing_source_authority_refuse_before_command(self):
        metadata = self.verified()
        for changed in (replace(metadata, image="sha256:" + "9" * 64), replace(metadata, run="another")):
            role = InertRole("application", self.phase_reports())
            with self.assertRaises(observation.ObservationError):
                observation.cache_phases(None, role, self.program, changed, self.source_classes, self.exercise)
            self.assertEqual([], role.commands)
        role = InertRole("application", self.phase_reports())
        with self.assertRaises(observation.ObservationError):
            observation.cache_phases(None, role, self.program, metadata, {}, self.exercise)
        self.assertEqual([], role.commands)

    def test_changed_cached_routes_refuse_before_cached_http_effect(self):
        reports = self.phase_reports()
        reports[3]["routes"][0]["uri"] = "changed-route"
        with self.assertRaises(identity.IdentityError):
            self.phases(reports)
        self.assertEqual(["before"], [phase for phase, _identifier in self.exercised])

    def test_stale_cache_or_code_refuses_before_cached_http_effect(self):
        for change in ("cache", "classes", "configuration"):
            reports = self.phase_reports()
            if change == "cache":
                reports[3]["cache"]["sha256"] = "7" * 64
            elif change == "classes":
                reports[3]["classes"]["Illuminate\\Foundation\\Application"]["sha256"] = "8" * 64
            else:
                reports[3]["ini_before"]["include_path"] = "changed"
                reports[3]["ini_after"]["include_path"] = "changed"
            with self.subTest(change=change), self.assertRaises(observation.ObservationError):
                self.phases(reports)
            self.assertEqual(["before"], [phase for phase, _identifier in self.exercised])

    def test_boolean_command_exit_and_missing_clear_after_refuse(self):
        reports = self.phase_reports()
        reports[0]["exit_status"] = False
        with self.assertRaises(observation.ObservationError):
            self.phases(reports)
        reports = self.phase_reports()
        reports[4]["cache"] = self.cache(True)
        with self.assertRaises(observation.ObservationError):
            self.phases(reports)
        self.assertEqual(["before", "cached"], [phase for phase, _identifier in self.exercised])

    def test_failed_http_callback_stops_before_next_cache_effect(self):
        role = InertRole("application", self.phase_reports())
        def refuse(_phase, _role):
            raise RuntimeError("synthetic HTTP refusal")
        with self.assertRaisesRegex(RuntimeError, "synthetic HTTP refusal"):
            observation.cache_phases(None, role, self.program, self.verified(), self.source_classes, refuse)
        self.assertEqual(["clear-before", "inventory"], [call["mode"] for call in role.commands])


if __name__ == "__main__":
    unittest.main()
