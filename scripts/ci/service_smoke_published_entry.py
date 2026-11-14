"""Unwired current published graph/cache/browser lane, with no acquisition.

The existing workflow must finish its signed producer gate and capture the four
public documents first. This function adds neither a producer-verification
fallback nor a remote archive/evidence retrieval path.
"""

from __future__ import annotations

import os
import re
import uuid

from service_smoke_browser_runner import BrowserRunner, NodeImage
from service_smoke_cleanup import context as cleanup_context
from service_smoke_entry import EntryError, Journal, OUTPUT_LIMIT, Output, probe_source, timestamp
from service_smoke_io import Docker
from service_smoke_native import exercise_native
from service_smoke_published import PublishedInputs
from service_smoke_subjects import GitInputs


FIELDS = {"index_digest", "architecture", "source", "source_tree", "runner_commit", "runner_tree",
          "publisher_commit", "producer_run_id", "producer_run_attempt", "run_id", "run_attempt"}
DOCS = {"index": "index.json", "child": "child.json", "config": "config.json", "attestation": "attestation.json"}


def selected_context(value: dict):
    if not isinstance(value, dict) or set(value) != FIELDS:
        raise EntryError("Incomplete published source/artifact/producer/runner context")
    for key in ("source", "source_tree", "runner_commit", "runner_tree", "publisher_commit"):
        if not isinstance(value[key], str) or re.fullmatch(r"[a-f\d]{40}", value[key], re.ASCII) is None:
            raise EntryError("Invalid published immutable source identity")
    for key in ("producer_run_id", "producer_run_attempt", "run_id", "run_attempt"):
        if type(value[key]) is not int or value[key] <= 0:
            raise EntryError("Invalid exact producer/acceptance run identity")
    if (value["architecture"] not in {"amd64", "arm64"} or not isinstance(value["index_digest"], str)
            or re.fullmatch(r"sha256:[a-f\d]{64}", value["index_digest"], re.ASCII) is None):
        raise EntryError("Invalid published index/platform subject")


def child_root(outputs, kind: str, root_type):
    if kind not in {"primary", "automatic"}:
        raise EntryError("Unselected existing browser lane")
    outputs.check()
    os.mkdir(kind, 0o700, dir_fd=outputs.descriptor)
    return root_type(outputs.path / kind)


def published_lane(*, kind: str, selected: dict, subject, source, runner, inputs, outputs,
                   dependencies, node: NodeImage, context_ready) -> dict:
    nonce = "waterline-service-" + uuid.uuid4().hex
    context_ready(kind, cleanup_context(nonce, subject.subject["config_digest"], selected["architecture"]))
    journal = Journal(outputs, {"run": nonce, "image": subject.subject["config_digest"], "selected": selected})
    docker = Docker(outputs.path)
    try:
        original = runner.read("scripts/ci/" + ("request-budget-browser.mjs" if kind == "primary" else "request-budget-auto.mjs"))
        browser = BrowserRunner(node=node, dependencies=dependencies, outputs=outputs, original=original,
                                kind=kind, source=selected["source"])

        def guarded(arguments, **kwargs):
            for root in (inputs, outputs, dependencies):
                root.check()
            journal.output.check()
            source.check()
            runner.check()
            subject.verify_unchanged()
            node.check()
            result = docker(arguments, **kwargs)
            for root in (inputs, outputs, dependencies):
                root.check()
            subject.verify_unchanged()
            return result

        program = probe_source(runner.read("scripts/ci/service-mode-image-smoke.sh"))
        return exercise_native(guarded, docker.cleanup, run=nonce, archive=subject, subject=subject.subject,
                               source=source, program=program, checkpoint=journal.checkpoint,
                               browser=browser, browser_budget=0.8 if kind == "primary" else 3.5)
    finally:
        docker.close()
        journal.output.close()


def run_published(selected: dict, *, repository, inputs, outputs, dependencies, node_descriptor: int,
                  node_sha256: str, root_type, context_ready, producer_gate_succeeded: bool) -> dict:
    selected_context(selected)
    # The value comes from the existing completed job dependency in the trusted
    # literal invocation, never from an application or retained data report.
    if producer_gate_succeeded is not True:
        raise EntryError("Existing signed producer verification must finish before published acceptance")
    source = runner = node = None
    report = {"schema": "waterline.ordinary-published-acceptance/v1", "selected": dict(selected),
              "started_at": timestamp(), "lanes": {}, "status": "nonpass"}
    try:
        captured = {key: inputs.read(path) for key, path in DOCS.items()}
        subject = PublishedInputs(index_digest=selected["index_digest"], architecture=selected["architecture"],
                                  source=selected["source"], **captured)
        source = GitInputs(repository, selected["source"])
        runner = GitInputs(repository, selected["runner_commit"])
        if source.tree != selected["source_tree"] or runner.tree != selected["runner_tree"]:
            raise EntryError("Published source/runner trees differ from their selected identities")
        node = NodeImage(node_descriptor, node_sha256)
        report["subject"] = subject.subject
        for kind in ("primary", "automatic"):
            lane_root = child_root(outputs, kind, root_type)
            try:
                result = published_lane(kind=kind, selected=selected, subject=subject, source=source, runner=runner,
                                        inputs=inputs, outputs=lane_root, dependencies=dependencies,
                                        node=node, context_ready=context_ready)
                report["lanes"][kind] = result
            finally:
                lane_root.close()
            if result["status"] != "pass":
                break
        if len(report["lanes"]) == 2 and all(value["status"] == "pass" for value in report["lanes"].values()):
            report["status"] = "pass"
        subject.verify_unchanged()
    except Exception as error:
        report["status"] = "nonpass"
        report["outer_failure"] = {"class": type(error).__name__, "message": str(error)}
    finally:
        for value in (node, source, runner):
            if value is not None:
                value.close()
    report["ended_at"] = timestamp()
    result = Output(outputs, "native-acceptance.json", OUTPUT_LIMIT)
    try:
        result.append(report)
    finally:
        result.close()
    return report
