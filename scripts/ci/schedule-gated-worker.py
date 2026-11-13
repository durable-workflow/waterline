"""Owned fixture for a real scheduled run that fails after external release.

Uses the published Python SDK, without a repository import fallback. This is
fixture proof only until the caller binds the published Server/Waterline tuple.
"""
from __future__ import annotations

import argparse
import asyncio
import contextlib
import hashlib
import importlib.metadata
import importlib.util
import json
import logging
import os
from pathlib import Path
import stat
import sys
import threading
import time
import traceback
from typing import Any
from urllib.parse import urlparse
import zipfile

SDK_VERSION = "2.17.2"
WHEEL_SHA256 = "7dc77d3882fa5cbddbeaee6a4cc63b05285e280523c8ea40dc31e03cc12a40bc"
FAILURE = "waterline-schedule-observation-controlled-failure"


def installed_identity(wheel: Path) -> dict[str, Any]:
    assert hashlib.sha256(wheel.read_bytes()).hexdigest() == WHEEL_SHA256
    distribution = importlib.metadata.distribution("durable-workflow")
    assert distribution.version == SDK_VERSION
    assert distribution.read_text("direct_url.json") is None
    spec = importlib.util.find_spec("durable_workflow")
    assert spec is not None and spec.origin is not None
    assert Path(spec.origin).resolve() == distribution.locate_file("durable_workflow/__init__.py").resolve()
    count = 0
    with zipfile.ZipFile(wheel) as archive:
        for name in archive.namelist():
            if name.startswith("durable_workflow/") and not name.endswith("/"):
                installed = distribution.locate_file(name)
                assert installed.read_bytes() == archive.read(name), "installed SDK differs from approved wheel"
                count += 1
    assert count == 46
    return {"sdk_version": SDK_VERSION, "wheel_sha256": WHEEL_SHA256, "matched_sdk_files": count, "isolated_python": bool(sys.flags.isolated)}


def token_from(path: Path) -> str:
    descriptor = os.open(path, os.O_RDONLY | os.O_NOFOLLOW)
    try:
        metadata = os.fstat(descriptor)
        assert stat.S_ISREG(metadata.st_mode) and stat.S_IMODE(metadata.st_mode) == 0o600
        assert 32 <= metadata.st_size <= 256
        token = os.read(descriptor, 257).decode("ascii")
        assert token and token.strip() == token
        return token
    finally:
        os.close(descriptor)


def fixture_types(sdk: Any, release: Path, started: threading.Event, stop: threading.Event):
    @sdk.activity.defn(name="waterline.schedule-release-gate")
    def gate(marker: str) -> str:
        assert Path(marker) == release
        started.set()
        deadline = time.monotonic() + 90
        while time.monotonic() < deadline and not stop.is_set():
            if release.exists():
                assert stat.S_ISREG(release.lstat().st_mode) and release.lstat().st_size == 0
                return "released"
            time.sleep(0.05)
        raise RuntimeError("waterline-schedule-gate-not-released")

    @sdk.workflow.defn(name="waterline.schedule-controlled-failure")
    class ControlledFailure:
        def run(self, ctx: Any, marker: str):
            observed = yield ctx.schedule_activity("waterline.schedule-release-gate", [marker])
            assert observed == "released"
            raise RuntimeError(FAILURE)

    return ControlledFailure, gate


async def prove(args: argparse.Namespace, report: dict[str, Any]) -> None:
    if not sys.flags.isolated or sys.flags.optimize:
        raise RuntimeError("launch this fixture with python -I and assertions enabled")
    report.update(installed_identity(args.wheel))
    url = urlparse(args.server)
    assert url.scheme == "http" and url.hostname in {"127.0.0.1", "localhost", "::1"}
    assert not url.username and not url.password and not url.query and not url.fragment and url.path in {"", "/"}
    assert args.schedule_id.startswith("waterline-") and len(args.schedule_id) <= 100
    assert not args.release_file.exists() and not args.report.exists()
    token = token_from(args.token_file)
    import durable_workflow as sdk

    started = threading.Event()
    stop = threading.Event()
    workflow_type, activity_type = fixture_types(sdk, args.release_file, started, stop)

    def persist() -> None:
        temporary = args.report.with_suffix(".next")
        with temporary.open("x", encoding="utf-8") as file:
            json.dump(report, file, indent=2)
        temporary.replace(args.report)

    async with sdk.Client(args.server, token=token, namespace="default") as client:
        worker = sdk.Worker(client, task_queue=args.schedule_id, workflows=[workflow_type], activities=[activity_type], poll_timeout=1.0, shutdown_timeout=5.0)
        task = asyncio.create_task(worker.run())
        try:
            deadline = time.monotonic() + 15
            while not worker.status().ready:
                assert time.monotonic() < deadline, "worker did not become ready"
                await asyncio.sleep(0.1)
            await client.create_schedule(schedule_id=args.schedule_id,
                spec=sdk.ScheduleSpec(intervals=[{"every": "PT24H"}], timezone="UTC"),
                action=sdk.ScheduleAction(workflow_type="waterline.schedule-controlled-failure", task_queue=args.schedule_id, input=[str(args.release_file)]),
                overlap_policy="skip", jitter_seconds=0, max_runs=5)
            result = await client.trigger_schedule(args.schedule_id)
            assert result.outcome == "triggered" and result.workflow_id and result.run_id
            report.update({"schedule_id": args.schedule_id, "workflow_id": result.workflow_id, "run_id": result.run_id})
            deadline = time.monotonic() + 25
            while not started.is_set():
                assert time.monotonic() < deadline, "real activity did not start"
                await asyncio.sleep(0.1)
            before = await client.describe_workflow(result.workflow_id)
            assert before.status in {"pending", "running", "waiting"}
            report.update({"gate": "activity_started", "before_status": before.status, "before_bucket": "running"})
            persist()
            if args.auto_release_after is not None:
                await asyncio.sleep(args.auto_release_after)
                args.release_file.touch(exist_ok=False)
            deadline = time.monotonic() + 95
            while True:
                observed = await client.describe_workflow(result.workflow_id)
                if observed.status == "failed":
                    break
                assert observed.status in {"pending", "running", "waiting"}
                assert time.monotonic() < deadline, "run did not reach failed"
                await asyncio.sleep(0.2)
            assert args.release_file.is_file(), "failure without external gate release is not acceptance"
            history = await client.get_history(result.workflow_id, result.run_id, page_size=100)
            encoded = json.dumps(history, sort_keys=True, ensure_ascii=False).encode()
            assert FAILURE.encode() in encoded, "terminal history does not carry the deliberate failure"
            report.update({"after_status": observed.status, "history_sha256": hashlib.sha256(encoded).hexdigest(), "controlled_failure": FAILURE})
            persist()
        finally:
            stop.set()
            await worker.stop()
            task.cancel()
            with contextlib.suppress(asyncio.CancelledError):
                await task
    report["outcome"] = "pass"
    persist()


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--server", required=True)
    parser.add_argument("--token-file", type=Path, required=True)
    parser.add_argument("--wheel", type=Path, required=True)
    parser.add_argument("--schedule-id", required=True)
    parser.add_argument("--release-file", type=Path, required=True)
    parser.add_argument("--report", type=Path, required=True)
    parser.add_argument("--auto-release-after", type=int, choices=range(1, 16))
    args = parser.parse_args()
    logging.basicConfig(level=logging.WARNING)
    report: dict[str, Any] = {"classification": "installed_worker_fixture", "outcome": "pending"}
    try:
        asyncio.run(asyncio.wait_for(prove(args, report), timeout=140))
    except Exception as error:
        # Do not print request headers, arguments or credential contents.
        frame = traceback.extract_tb(error.__traceback__)[-1]
        print(f"Controlled schedule fixture NONPASS: {type(error).__name__} at {Path(frame.filename).name}:{frame.lineno}", file=sys.stderr)
        return 1
    print(json.dumps(report))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
