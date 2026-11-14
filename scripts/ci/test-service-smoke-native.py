"""Inert lifecycle failures; admission primitives have separate focused suites.

This suite replaces all role, transport, source and archive inputs with local
synthetic objects. It cannot create an image, container, network or HTTP request.
"""

from contextlib import ExitStack
from pathlib import Path
from types import SimpleNamespace
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
        audit = {"phase": "before", "protocol": ["GET /api/cluster/info",
                 "GET /api/workflows/smoke-order/runs/smoke-run/streams",
                 "POST /api/workflows/smoke-order/runs/smoke-run/query/current",
                 "POST /api/workflows/smoke-order/runs/smoke-run/signal/approve"]}
        native.fixture_protocol(audit, "before")
        with self.assertRaises(native.OwnershipError):
            native.fixture_protocol(audit, "cached")
        audit["protocol"].pop()
        with self.assertRaises(native.OwnershipError):
            native.fixture_protocol(audit, "before")


class NativeInputTest(unittest.TestCase):
    def test_port_binding_rejects_wildcard_extra_or_non_numeric_publication(self):
        for binding in ([{"HostIp": "0.0.0.0", "HostPort": "8080"}],
                        [{"HostIp": "127.0.0.1", "HostPort": "0"}],
                        [{"HostIp": "127.0.0.1", "HostPort": "8080"}] * 2):
            with self.subTest(binding=binding), self.assertRaises(native.OwnershipError):
                native.loopback_port({"NetworkSettings": {"Ports": {"8080/tcp": binding}}})


if __name__ == "__main__":
    unittest.main()
