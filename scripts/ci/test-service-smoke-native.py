"""Inert lifecycle failures; admission primitives have separate focused suites.

This suite replaces all role, transport, source and archive inputs with local
synthetic objects. It cannot create an image, container, network or HTTP request.
"""

from contextlib import ExitStack
from pathlib import Path
from types import SimpleNamespace
import hashlib
import json
import subprocess
import unittest
from unittest.mock import patch
import sys

sys.path.insert(0, str(Path(__file__).resolve().parent))
import service_smoke_native as native


class Source:
    commit = "a" * 40

    def read(self, _path):
        return b"synthetic committed source"

    def check(self):
        pass


class FakeRoleSet:
    def __init__(self, *_args, **kwargs):
        self.run = kwargs["run"]
        self.network = None
        self.events = []
        self.absent = True

    def create(self, role, **_kwargs):
        self.events.append(role)
        return SimpleNamespace(role=role, identifier=role, command=lambda *_args, **_kwargs: SimpleNamespace(returncode=0, stderr=b"", stdout=b""))

    def cleanup(self):
        self.events.append("cleanup")
        return {"absent": self.absent}

    def evidence(self):
        return {"synthetic": True}


class FakeNetwork:
    def __init__(self, *_args):
        self.events = []

    def create(self):
        self.events.append("create")
        return "b" * 64

    def cleanup(self):
        self.events.append("cleanup")
        return {"absent": True}


class NativeLifecycleTest(unittest.TestCase):
    def setUp(self):
        self.stack = ExitStack()
        self.addCleanup(self.stack.close)
        self.roles = None
        self.network = FakeNetwork()
        self.selected = {"config_digest": "sha256:" + "c" * 64, "architecture": "amd64", "source": Source.commit}
        self.archive = SimpleNamespace(verify_unchanged=lambda: None)

        def roles(*args, **kwargs):
            self.roles = FakeRoleSet(*args, **kwargs)
            return self.roles

        replacements = {"image_configuration": lambda *_args: {"Env": []}, "RoleSet": roles,
                        "Network": lambda *_args, **_kwargs: self.network, "trust_controls": lambda *_args: {},
                        "memory_refusal": lambda *_args: {}, "Fixture": lambda *_args: object(),
                        "running": lambda *_args: {}, "loopback_port": lambda *_args: 41823,
                        "Loopback": lambda *_args: object(), "await_health": lambda *_args: 1.0,
                        "verify_uid": lambda *_args: {"synthetic": True}, "cache_phases": lambda *_args: {"synthetic": True}}
        for key, value in replacements.items():
            self.stack.enter_context(patch.object(native, key, value))
        self.metadata = self.stack.enter_context(patch.object(native, "verify_metadata", return_value=SimpleNamespace(report={})))

    def run_case(self):
        return native.exercise_native(lambda *_args: None, lambda *_args: None,
                                      run="waterline-service-" + "d" * 32, archive=self.archive,
                                      subject=self.selected, source=Source(), program=b"synthetic")

    def test_bad_metadata_stops_before_trust_network_fixture_or_application(self):
        self.metadata.side_effect = native.OwnershipError("stale graph")
        result = self.run_case()
        self.assertEqual("nonpass", result["status"])
        self.assertEqual(["metadata", "cleanup"], self.roles.events)
        self.assertEqual(["cleanup"], self.network.events)
        self.assertEqual("stale graph", result["failure"]["message"])

    def test_cache_failure_preserves_original_error_and_attempts_all_cleanup(self):
        with patch.object(native, "cache_phases", side_effect=native.OwnershipError("stale cached routes")):
            result = self.run_case()
        self.assertEqual("nonpass", result["status"])
        self.assertEqual("stale cached routes", result["failure"]["message"])
        self.assertEqual("cleanup", self.roles.events[-1])
        self.assertEqual(["create", "cleanup"], self.network.events)

    def test_http_failure_preserves_closed_evidence_and_still_cleans_every_role(self):
        evidence = {"expected_status": 200, "actual_status": 502, "body_bytes": 2, "body_sha256": "a" * 64}
        error = native.HttpError("Unexpected HTTP status for /waterline/api/flows/running", evidence=evidence)
        with patch.object(native, "cache_phases", side_effect=error):
            result = self.run_case()
        self.assertEqual("nonpass", result["status"])
        self.assertEqual(evidence, result["failure"].get("http"))
        self.assertEqual("cleanup", self.roles.events[-1])
        self.assertEqual(["create", "cleanup"], self.network.events)

    def test_existing_log_tail_is_bounded_to_captured_roles(self):
        raw = b"x" * 20000 + b"synthetic application error"
        calls = []

        def command(*args, **kwargs):
            calls.append((args[1:], kwargs))
            return SimpleNamespace(returncode=0, stdout=raw, stderr=b"")

        result = native.failure_logs(None, {"application": SimpleNamespace(command=command), "fixture": None})
        self.assertEqual([(("logs", []), {"options": ("--tail", "200")})], calls)
        self.assertEqual(len(raw), result["application"]["bytes"])
        self.assertEqual(hashlib.sha256(raw).hexdigest(), result["application"]["sha256"])
        self.assertEqual(raw[-16384:].decode(), result["application"]["tail"])
        self.assertTrue(result["application"]["truncated"])

    def test_log_capture_refusal_keeps_the_original_http_failure_and_cleanup(self):
        evidence = {"actual_status": 500}
        error = native.HttpError("Unexpected HTTP status for /waterline/api/flows/running", evidence=evidence)
        with patch.object(native, "cache_phases", side_effect=error), \
                patch.object(native, "failure_logs", return_value={"application": {"error_class": "OwnershipError"}}):
            result = self.run_case()
        self.assertEqual(evidence, result["failure"]["http"])
        self.assertEqual("nonpass", result["status"])
        self.assertTrue(result["cleanup"]["absent"])
        self.assertTrue(result["network_cleanup"]["absent"])

    def test_cleanup_failure_cannot_turn_a_completed_inner_flow_into_pass(self):
        with patch.object(FakeRoleSet, "cleanup", return_value={"absent": False}):
            result = self.run_case()
        self.assertIsNone(result["failure"])
        self.assertEqual("nonpass", result["status"])
        self.assertEqual("cleanup", self.network.events[-1])

    def test_artifact_source_mismatch_refuses_before_role_creation(self):
        self.selected["source"] = "f" * 40
        with self.assertRaises(native.OwnershipError):
            self.run_case()
        self.assertIsNone(self.roles)

    def test_trust_failure_does_not_start_application(self):
        with patch.object(native, "trust_controls", side_effect=native.OwnershipError("decoder guard")):
            result = self.run_case()
        self.assertEqual("nonpass", result["status"])
        self.assertEqual(["metadata", "cleanup"], self.roles.events)
        self.assertEqual(["cleanup"], self.network.events)

    def test_actual_upstream_audit_cannot_borrow_an_earlier_phase(self):
        audit = {"phase": "before", "protocol": ["GET /api/workflows/smoke-order/runs/smoke-run/streams",
                 "POST /api/workflows/smoke-order/runs/smoke-run/query/current",
                 "POST /api/workflows/smoke-order/runs/smoke-run/signal/approve"]}
        native.fixture_protocol(audit, "before")
        with self.assertRaises(native.OwnershipError):
            native.fixture_protocol(audit, "cached")
        audit["protocol"].pop()
        with self.assertRaises(native.OwnershipError):
            native.fixture_protocol(audit, "before")


class NativeInputTest(unittest.TestCase):
    def test_oversized_browser_hostname_is_not_selected_as_the_backend(self):
        invalid = "waterline-service-" + "d" * 32 + "-browser-fixture"
        self.assertEqual(66, len(invalid))
        with self.assertRaises(native.OwnershipError):
            native.app_environment(invalid)

    def test_backend_endpoint_uses_the_same_dns_safe_captured_role_name(self):
        run = "waterline-service-" + "d" * 32
        for role in ("fixture", "browser-fixture"):
            with self.subTest(role=role):
                name = native.container_name(run, role)
                self.assertLessEqual(len(name), 63)
                self.assertEqual("http://" + name + ":18081", native.app_environment(name)["WATERLINE_SERVER_ENDPOINT"])

    def test_rendered_default_fields_do_not_change_exact_image_binding(self):
        config = {"os": "linux", "architecture": "amd64", "config": {"Env": ["PATH=/usr/local/bin:/usr/bin"]},
                  "rootfs": {"diff_ids": ["sha256:" + "a" * 64]}}
        raw = json.dumps(config).encode()
        config_id = "sha256:" + hashlib.sha256(raw).hexdigest()
        record = {"Id": config_id, "Os": "linux", "Architecture": "amd64",
                  "Config": {**config["config"], "OnBuild": None, "ArgsEscaped": False},
                  "RootFS": {"Type": "layers", "Layers": config["rootfs"]["diff_ids"]}}
        subject = {"config_digest": config_id, "architecture": "amd64"}
        archive = SimpleNamespace(config_bytes=lambda _selected: raw)
        def docker(_arguments):
            return subprocess.CompletedProcess([], 0, json.dumps([record]).encode(), b"")
        self.assertEqual(config["config"], native.image_configuration(docker, archive, subject))
        record["Id"] = "sha256:" + "b" * 64
        with self.assertRaises(native.OwnershipError):
            native.image_configuration(docker, archive, subject)

    def test_cold_start_budget_includes_creation_and_does_not_reset(self):
        with patch.object(native.time, "monotonic", return_value=131):
            with self.assertRaises(native.OwnershipError):
                native.await_health(None, None, 100)

    def test_port_binding_rejects_wildcard_extra_or_non_numeric_publication(self):
        for binding in ([{"HostIp": "0.0.0.0", "HostPort": "8080"}],
                        [{"HostIp": "127.0.0.1", "HostPort": "0"}],
                        [{"HostIp": "127.0.0.1", "HostPort": "8080"}] * 2):
            with self.subTest(binding=binding), self.assertRaises(native.OwnershipError):
                native.loopback_port({"NetworkSettings": {"Ports": {"8080/tcp": binding}}})


if __name__ == "__main__":
    unittest.main()
