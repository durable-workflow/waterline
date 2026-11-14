"""Unwired outer subject/report binding for ordinary native acceptance.

The reviewed literal invocation loads this module only after checking all code
subjects. There is no CLI, fallback image, remote retrieval or workflow call.
"""

from __future__ import annotations

from datetime import datetime, timezone
import hashlib
import json
import os
from pathlib import Path
import re
import stat
import uuid

import oci_release
from service_smoke_io import Docker
from service_smoke_native import exercise_native
from service_smoke_resources import ROLES, exact_id
from service_smoke_subjects import GitInputs, HeldArchive, file_identity
from waterline_release_identity import decode_json


SELECTION = {"source", "source_tree", "runner_commit", "runner_tree", "architecture", "archive_digest",
             "security_digest", "workflow_sha256", "run_id", "run_attempt"}
OUTPUT_LIMIT = 4 * 1024 * 1024


class EntryError(RuntimeError):
    """An independently selected native subject or output identity changed."""


def timestamp() -> str:
    return datetime.now(timezone.utc).isoformat()


def check_selection(selected: dict):
    if not isinstance(selected, dict) or set(selected) != SELECTION:
        raise EntryError("Incomplete selected ordinary native subjects")
    for key in ("source", "source_tree", "runner_commit", "runner_tree"):
        if not isinstance(selected[key], str) or re.fullmatch(r"[a-f\d]{40}", selected[key], re.ASCII) is None:
            raise EntryError("Invalid immutable source or runner subject")
    for key in ("archive_digest", "security_digest"):
        if not isinstance(selected[key], str) or re.fullmatch(r"sha256:[a-f\d]{64}", selected[key], re.ASCII) is None:
            raise EntryError("Invalid independently selected native input digest")
    if (not isinstance(selected["workflow_sha256"], str)
            or re.fullmatch(r"[a-f\d]{64}", selected["workflow_sha256"], re.ASCII) is None
            or selected["architecture"] not in {"amd64", "arm64"}
            or any(type(selected[key]) is not int or selected[key] <= 0 for key in ("run_id", "run_attempt"))):
        raise EntryError("Invalid workflow/platform/run subjects")


class Output:
    """New regular output files held through writes, never a later named open."""

    def __init__(self, root, name: str, limit: int):
        if name not in {"native-cleanup.jsonl", "native-acceptance.json"}:
            raise EntryError("Unselected ordinary acceptance output")
        root.check()
        self.root, self.name, self.limit = root, name, limit
        self.descriptor = os.open(name, os.O_CREAT | os.O_EXCL | os.O_RDWR | os.O_NOFOLLOW | os.O_CLOEXEC, 0o600, dir_fd=root.descriptor)
        self.identity = file_identity(os.fstat(self.descriptor))
        self.size = 0
        self.captured = bytearray()

    def check(self):
        self.root.check()
        held = os.fstat(self.descriptor)
        named = os.stat(self.name, dir_fd=self.root.descriptor, follow_symlinks=False)
        if (not stat.S_ISREG(held.st_mode) or held.st_nlink != 1 or held.st_uid != os.getuid()
                or stat.S_IMODE(held.st_mode) != 0o600 or file_identity(held) != self.identity
                or file_identity(named) != self.identity
                or os.pread(self.descriptor, self.size + 1, 0) != self.captured):
            raise EntryError("Held acceptance output identity changed")

    def append(self, value: dict):
        raw = json.dumps(value, sort_keys=True, separators=(",", ":"), allow_nan=False).encode() + b"\n"
        self.check()
        if len(raw) + self.size > self.limit:
            raise EntryError("Acceptance output exceeds its selected byte bound")
        pending = memoryview(raw)
        while pending:
            written = os.write(self.descriptor, pending)
            if written == 0:
                raise EntryError("Acceptance output write was incomplete")
            pending = pending[written:]
        os.fsync(self.descriptor)
        self.size += len(raw)
        self.captured.extend(raw)
        self.identity = file_identity(os.fstat(self.descriptor))
        self.check()

    def close(self):
        if self.descriptor >= 0:
            os.close(self.descriptor)
            self.descriptor = -1


class Journal:
    def __init__(self, root, context: dict):
        self.output = Output(root, "native-cleanup.jsonl", 65536)
        self.context = context
        self.output.append({"schema": "waterline.ordinary-cleanup/v1", "context": context})

    def checkpoint(self, kind: str, role: str, identifier: str | None):
        if (kind == "container" and role not in ROLES) or (kind == "network" and role != "network") or kind not in {"container", "network"}:
            raise EntryError("Unselected cleanup checkpoint")
        if identifier is not None:
            exact_id(identifier)
        self.output.append({"kind": kind, "role": role, "id": identifier, "recorded_at": timestamp()})


def probe_source(raw: bytes) -> bytes:
    begin = b"cat <<'WATERLINE_CURRENT_RUNTIME_PHP'\n"
    end = b"WATERLINE_CURRENT_RUNTIME_PHP\n}"
    if raw.count(begin) != 1 or raw.count(end) != 1:
        raise EntryError("Current runtime emitter is ambiguous")
    return raw.split(begin, 1)[1].split(end, 1)[0]


def run_native(selected: dict, *, repository: Path, inputs, outputs, frozen_document) -> dict:
    check_selection(selected)
    source = runner = archive = docker = journal = None
    report = {"schema": "waterline.ordinary-native-acceptance/v1", "selected": dict(selected), "started_at": timestamp()}
    try:
        inputs.check()
        outputs.check()
        source = GitInputs(repository, selected["source"])
        runner = GitInputs(repository, selected["runner_commit"])
        if source.tree != selected["source_tree"] or runner.tree != selected["runner_tree"]:
            raise EntryError("Selected source or runner tree changed")
        workflow = runner.read(".github/workflows/service-image.yml")
        if hashlib.sha256(workflow).hexdigest() != selected["workflow_sha256"]:
            raise EntryError("Actual runner workflow differs from the selected workflow")
        security_raw = inputs.read("security-summary.json")
        if "sha256:" + hashlib.sha256(security_raw).hexdigest() != selected["security_digest"]:
            raise EntryError("Native scanner summary changed before admission")
        security = decode_json(security_raw, "selected native scanner summary")
        archive_descriptor = inputs.open("image.oci.tar", 1024 * 1024 * 1024)
        try:
            archive = HeldArchive(archive_descriptor)
        finally:
            os.close(archive_descriptor)
        if archive.digest != selected["archive_digest"]:
            raise EntryError("Native OCI differs from its independently selected bytes")
        oci_release.SECURITY_POLICY = frozen_document(runner.read("release/service-image-security.json"))
        subject = archive.native_subject(selected["architecture"], selected["source"], security)
        program = probe_source(runner.read("scripts/ci/service-mode-image-smoke.sh"))
        nonce = "waterline-service-" + uuid.uuid4().hex
        report["run"] = nonce
        journal = Journal(outputs, {"run": nonce, "image": subject["config_digest"], "selected": selected})
        docker = Docker(outputs.path)

        def guarded(arguments, **kwargs):
            inputs.check()
            outputs.check()
            journal.output.check()
            source.check()
            runner.check()
            archive.check()
            value = docker(arguments, **kwargs)
            inputs.check()
            outputs.check()
            archive.check()
            return value

        report["subject"] = subject
        report["result"] = exercise_native(guarded, docker.cleanup, run=nonce, archive=archive, subject=subject,
                                           source=source, program=program, checkpoint=journal.checkpoint)
        report["status"] = report["result"]["status"]
    except Exception as error:
        report["status"] = "nonpass"
        report["outer_failure"] = {"class": type(error).__name__, "message": str(error)}
    finally:
        for value in (docker, archive, source, runner):
            if value is not None:
                value.close()
        if journal is not None:
            journal.output.close()
    report["ended_at"] = timestamp()
    result = Output(outputs, "native-acceptance.json", OUTPUT_LIMIT)
    try:
        result.append(report)
    finally:
        result.close()
    return report
