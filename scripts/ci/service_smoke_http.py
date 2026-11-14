"""Unwired ordinary native API assertions, repeated for each cache phase.

The caller supplies its sealed roles, numeric loopback port and fixture control.
Importing this module performs no request or application operation.
"""

from __future__ import annotations

from dataclasses import dataclass
import json
import re
from urllib.parse import quote

from service_smoke_io import bounded_process
from waterline_release_identity import decode_json


API = "/waterline/api"
SELECTED = API + "/instances/smoke-order/runs/smoke-run"


class HttpError(RuntimeError):
    """The actual HTTP observation differs from the ordinary service contract."""


def require(condition: bool, message: str) -> None:
    if not condition:
        raise HttpError(message)


@dataclass(frozen=True)
class Reply:
    status: int
    body: bytes
    elapsed: float

    def document(self) -> dict:
        value = decode_json(self.body, "native service HTTP response")
        require(isinstance(value, dict), "Expected an API object")
        return value


class Loopback:
    def __init__(self, port: int, check):
        require(type(port) is int and 1 <= port <= 65535, "Expected one numeric loopback publication")
        self.port, self.check = port, check

    def request(self, method: str, path: str, payload: dict | None = None) -> Reply:
        require(method in {"GET", "POST"} and isinstance(path, str) and len(path) <= 2048
                and re.fullmatch(r"/[A-Za-z0-9_/?=&.%:-]+", path, re.ASCII) is not None
                and not path.startswith("//") and ".." not in path,
                "Unselected local HTTP operation")
        require((payload is None and method == "GET") or (isinstance(payload, dict) and method == "POST"),
                "Unselected HTTP payload")
        raw = None if payload is None else json.dumps(payload, allow_nan=False, separators=(",", ":")).encode()
        require(raw is None or len(raw) <= 4096, "HTTP request exceeds its byte bound")
        self.check()
        arguments = ["/usr/bin/curl", "--disable", "--silent", "--show-error", "--globoff", "--path-as-is",
                     "--proxy", "", "--noproxy", "*", "--proto", "=http", "--max-redirs", "0",
                     "--connect-timeout", "1", "--max-time", "5", "--request", method,
                     "--header", "Accept: application/json", "--header", "Content-Type: application/json",
                     "--write-out", "\n%{http_code}\n%{time_total}\n"]
        if raw is not None:
            arguments += ["--data-binary", "@-"]
        result = bounded_process([*arguments, "http://127.0.0.1:" + str(self.port) + path],
                                 data=raw or b"", timeout=6)
        self.check()
        require(result.returncode == 0 and not result.stderr, "Finite loopback HTTP request failed")
        parts = result.stdout.rsplit(b"\n", 3)
        require(len(parts) == 4 and parts[3] == b"" and re.fullmatch(rb"[1-5][0-9]{2}", parts[1]) is not None
                and re.fullmatch(rb"[0-5]\.[0-9]{6}", parts[2]) is not None, "Malformed finite HTTP result")
        return Reply(int(parts[1]), parts[0], float(parts[2]))


def field(value, path: str):
    for component in path.split("."):
        if isinstance(value, list) and component.isdigit():
            index = int(component)
            value = value[index] if index < len(value) else None
        else:
            value = value.get(component) if isinstance(value, dict) else None
    return value


def exact_fields(value: dict, expected: dict, label: str) -> None:
    for path, selected in expected.items():
        actual = field(value, path)
        require(type(actual) is type(selected) and actual == selected, label + " field differs: " + path)


def document(request, method: str, path: str, status: int, payload: dict | None = None) -> dict:
    reply = request(method, path, payload)
    require(type(reply.status) is int and reply.status == status, "Unexpected HTTP status for " + path)
    return reply.document()


def native_api(request, phase: str) -> dict:
    require(phase in {"before", "cached", "cleared"}, "Unselected API phase")
    require(request("GET", "/up", None).status == 200, "Application health failed")
    listing = document(request, "GET", API + "/flows/running", 200)
    saved = document(request, "GET", API + "/saved-views?bucket=running", 200)
    created = document(request, "POST", API + "/saved-views", 201,
                       {"name": "Smoke orders " + phase, "bucket": "running",
                        "filters": {"workflow_type": "smoke.order"}, "shared": True})
    selected = created.get("id")
    require(isinstance(selected, str) and 0 < len(selected) <= 256, "Saved view ID is absent or oversized")
    narrowed = document(request, "GET", API + "/flows/running?view=" + quote(selected, safe=""), 200)
    detail = document(request, "GET", SELECTED, 200)
    query = document(request, "POST", SELECTED + "/queries/current", 200, {"arguments": []})
    signal = document(request, "POST", SELECTED + "/signals/approve", 200, {"arguments": ["manager"]})
    require(isinstance(listing.get("data"), list) and len(listing["data"]) == 2, "Ordinary run list changed")
    exact_fields(listing, {"data.0.instance_id": "smoke-order"}, "Run list")
    exact_fields(saved, {"filter_version": 7, "supported_filter_versions": [6, 7],
                         "filter_definition.version": 7, "filter_definition.fields.repair_state.label": "Repair State",
                         "filter_definition.fields.repair_state.filterable": False}, "Saved views")
    exact_fields(created, {"filter_version": 7, "filters.workflow_type": "smoke.order",
                           "service_mode_available": True}, "Created view")
    require(isinstance(narrowed.get("data"), list) and len(narrowed["data"]) == 1, "Saved view did not narrow rows")
    exact_fields(narrowed, {"data.0.instance_id": "smoke-order", "visibility_filters.saved_view.id": selected,
                            "visibility_filters.saved_view_applied": True,
                            "visibility_filters.applied.workflow_type": "smoke.order",
                            "visibility_filters.capability.fully_applied": True}, "Selected view")
    exact_fields(detail, {"selected_run_id": "smoke-run", "timeline.0.event_type": "WorkflowStarted",
                          "timeline.0.type": "WorkflowStarted", "timeline.0.recorded_at": "2026-07-22T12:00:00Z",
                          "timeline.0.id": "smoke-run:history:1", "workflow_streams_mode": "service",
                          "workflow_streams.0.stream_name": "receipts", "workflow_streams.0.status": "errored",
                          "workflow_streams.0.last_offset": 4, "workflow_streams.0.pending_items": 2,
                          "workflow_streams.0.error_reason": "producer_failed",
                          "workflow_streams.0.supports_inbound_workflow_messaging": False}, "Selected run")
    exact_fields(query, {"query": "current", "result.state": "awaiting_approval",
                         "result.selected_run_id": "smoke-run"}, "Query")
    exact_fields(signal, {"command_status": "accepted", "signal_name": "approve", "selected_run_id": "smoke-run",
                          "input_received": True}, "Signal")
    return {"phase": phase, "list": listing, "saved_views": saved, "created_view": created,
            "filtered_list": narrowed, "detail": detail, "query": query, "signal": signal}


def native_budget(request, fixture) -> dict:
    fixture.mode("backend-stall")
    reply = request("GET", API + "/flows/running", None)
    require(reply.status == 502 and 0.15 <= reply.elapsed < 1.8, "Server read exceeded its selected request budget")
    failure = reply.document()
    exact_fields(failure, {"reason": "request_timeout", "remote_details.timeout_seconds": 0.2}, "Read timeout")
    fixture.drain(3.3)
    fixture.mode("healthy")
    recovered = document(request, "GET", API + "/flows/running", 200)
    require(isinstance(recovered.get("data"), list) and len(recovered["data"]) == 2, "Explicit read recovery failed")
    fixture.mode("write-stall")
    write = request("POST", SELECTED + "/cancel", {})
    require(write.status == 502 and 0.15 <= write.elapsed < 1.8, "Server write did not refuse within its request budget")
    exact_fields(write.document(), {"reason": "request_timeout", "remote_details.timeout_seconds": 0.2}, "Write timeout")
    fixture.drain(2.3)
    audit = fixture.audit()
    require(isinstance(audit, dict) and isinstance(audit.get("writes"), list)
            and len(audit["writes"]) == 1, "Timed-out mutation was retried or lacks its one actual receipt")
    exact_fields(audit["writes"][0], {"method": "POST", "path": "/api/workflows/smoke-order/runs/smoke-run/cancel"}, "Mutation audit")
    fixture.mode("healthy")
    return {"read_timeout": failure, "read_elapsed": reply.elapsed, "recovered_rows": 2,
            "write_timeout": write.document(), "write_elapsed": write.elapsed, "fixture_audit": audit}
