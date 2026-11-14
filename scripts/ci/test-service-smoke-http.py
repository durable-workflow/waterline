"""Inert ordinary API vectors and timeout receipts; no HTTP request is sent."""

import copy
import json
from pathlib import Path
import subprocess
import sys
import unittest
from unittest.mock import patch

sys.path.insert(0, str(Path(__file__).resolve().parent))
import service_smoke_http as smoke


def reply(value, status=200, elapsed=0.3):
    return smoke.Reply(status, json.dumps(value).encode(), elapsed)


class ApiFixture:
    def __init__(self):
        self.calls = []
        self.created = []
        self.responses = {
            "/up": {},
            smoke.API + "/flows/running": {"data": [{"instance_id": "smoke-order"}, {"instance_id": "smoke-invoice"}]},
            smoke.API + "/saved-views?bucket=running": {
                "filter_version": 7, "supported_filter_versions": [6, 7], "filter_definition": {
                    "version": 7, "fields": {"repair_state": {"label": "Repair State", "filterable": False}}}},
            smoke.SELECTED: {"selected_run_id": "smoke-run", "timeline": [{"event_type": "WorkflowStarted",
                "type": "WorkflowStarted", "recorded_at": "2026-07-22T12:00:00Z", "id": "smoke-run:history:1"}],
                "workflow_streams_mode": "service", "workflow_streams": [{"stream_name": "receipts", "status": "errored",
                    "last_offset": 4, "pending_items": 2, "error_reason": "producer_failed", "supports_inbound_workflow_messaging": False}]},
            smoke.SELECTED + "/queries/current": {"query": "current", "result": {"state": "awaiting_approval", "selected_run_id": "smoke-run"}},
            smoke.SELECTED + "/signals/approve": {"command_status": "accepted", "signal_name": "approve",
                "selected_run_id": "smoke-run", "input_received": True},
        }

    def request(self, method, path, payload):
        self.calls.append((method, path, copy.deepcopy(payload)))
        if path == smoke.API + "/saved-views":
            self.created.append(payload["name"])
            return reply({"id": "opaque view", "filter_version": 7, "filters": {"workflow_type": "smoke.order"},
                          "service_mode_available": True}, 201)
        if path.startswith(smoke.API + "/flows/running?view="):
            return reply({"data": [{"instance_id": "smoke-order"}], "visibility_filters": {
                "saved_view": {"id": "opaque view"}, "saved_view_applied": True,
                "applied": {"workflow_type": "smoke.order"}, "capability": {"fully_applied": True}}})
        return reply(self.responses[path])


class BudgetFixture:
    def __init__(self):
        self.calls = []
        self.selected = None
        self.writes = [{"method": "POST", "path": "/api/workflows/smoke-order/runs/smoke-run/cancel"}]
        self.elapsed = 0.3
        self.timeout = 0.2

    def mode(self, selected):
        self.selected = selected
        self.calls.append(("mode", selected))

    def drain(self, seconds):
        self.calls.append(("drain", seconds))

    def audit(self):
        return {"writes": self.writes}

    def request(self, method, path, payload):
        self.calls.append((method, path))
        if self.selected == "healthy":
            return reply({"data": [{"instance_id": "smoke-order"}, {"instance_id": "smoke-invoice"}]})
        return reply({"reason": "request_timeout", "remote_details": {"timeout_seconds": self.timeout}}, 502, self.elapsed)


class ApiTest(unittest.TestCase):
    def test_current_target_keeps_ordinary_assertions_and_unique_phase_writes(self):
        fixture = ApiFixture()
        for phase in ("before", "cached", "cleared"):
            observed = smoke.native_api(fixture.request, phase)
            self.assertEqual(phase, observed["phase"])
        self.assertEqual(["Smoke orders before", "Smoke orders cached", "Smoke orders cleared"], fixture.created)
        self.assertIn(("POST", smoke.SELECTED + "/signals/approve", {"arguments": ["manager"]}), fixture.calls)
        self.assertIn(("GET", smoke.API + "/flows/running?view=opaque%20view", None), fixture.calls)

    def test_missing_stream_history_or_mutation_receipt_refuses(self):
        vectors = [(smoke.SELECTED, "workflow_streams"), (smoke.SELECTED, "timeline"),
                   (smoke.SELECTED + "/queries/current", "result"),
                   (smoke.SELECTED + "/signals/approve", "input_received")]
        for path, key in vectors:
            fixture = ApiFixture()
            del fixture.responses[path][key]
            with self.subTest(key=key), self.assertRaises(smoke.HttpError):
                smoke.native_api(fixture.request, "cached")

    def test_typed_boolean_and_selected_run_identity_are_required(self):
        for field, value in (("input_received", 1), ("selected_run_id", "predecessor-run")):
            fixture = ApiFixture()
            fixture.responses[smoke.SELECTED + "/signals/approve"][field] = value
            with self.subTest(field=field), self.assertRaises(smoke.HttpError):
                smoke.native_api(fixture.request, "cleared")

    def test_unselected_phase_refuses_before_http(self):
        with patch.object(ApiFixture, "request") as request, self.assertRaises(smoke.HttpError):
            smoke.native_api(request, "borrowed-base")
        request.assert_not_called()


class BudgetTest(unittest.TestCase):
    def test_actual_timeout_explicit_recovery_and_one_write_receipt(self):
        fixture = BudgetFixture()
        result = smoke.native_budget(fixture.request, fixture)
        self.assertEqual(2, result["recovered_rows"])
        self.assertEqual("healthy", fixture.selected)
        self.assertEqual(1, sum(1 for method, _path in fixture.calls if method == "POST"))

    def test_retried_or_wrong_target_write_cannot_pass(self):
        for writes in ([], [{"method": "POST", "path": "foreign"}],
                       [{"method": "POST", "path": "foreign"}] * 2):
            fixture = BudgetFixture()
            fixture.writes = writes
            with self.subTest(writes=writes), self.assertRaises(smoke.HttpError):
                smoke.native_budget(fixture.request, fixture)

    def test_slow_or_untyped_timeout_cannot_supply_the_budget(self):
        for elapsed, timeout in ((2.0, 0.2), (0.01, 0.2), (0.3, "0.2")):
            fixture = BudgetFixture()
            fixture.elapsed, fixture.timeout = elapsed, timeout
            with self.subTest(elapsed=elapsed, timeout=timeout), self.assertRaises(smoke.HttpError):
                smoke.native_budget(fixture.request, fixture)

    def test_loopback_subject_and_paths_refuse_before_socket_creation(self):
        for port in (True, 0, 65536, "8080"):
            with self.subTest(port=port), self.assertRaises(smoke.HttpError):
                smoke.Loopback(port, lambda: None)
        transport = smoke.Loopback(8080, lambda: None)
        with patch.object(smoke, "bounded_process") as connection:
            for path in ("http://foreign.test/", "//foreign", "/waterline/../private", "/up\r\nX: value"):
                with self.subTest(path=path), self.assertRaises(smoke.HttpError):
                    transport.request("GET", path)
            connection.assert_not_called()

    def test_http_tool_configuration_and_finite_output_are_closed(self):
        transport = smoke.Loopback(41823, lambda: None)
        returned = subprocess.CompletedProcess([], 0, b'{"synthetic":true}\n200\n0.300000\n', b"")
        with patch.object(smoke, "bounded_process", return_value=returned) as command:
            self.assertTrue(transport.request("GET", "/up").document()["synthetic"])
            arguments = command.call_args.args[0]
            self.assertEqual(["/usr/bin/curl", "--disable"], arguments[:2])
            self.assertEqual("http://127.0.0.1:41823/up", arguments[-1])
            self.assertIn("--max-time", arguments)
            self.assertEqual(6, command.call_args.kwargs["timeout"])


if __name__ == "__main__":
    unittest.main()
