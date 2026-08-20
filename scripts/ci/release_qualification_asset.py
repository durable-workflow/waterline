#!/usr/bin/env python3
"""Build the immutable public evidence asset for a Waterline release audit."""

from __future__ import annotations

import argparse
import json
import re
from pathlib import Path
from typing import Any, Mapping, Sequence


SCHEMA = "durable-workflow.waterline.release-qualification-evidence"
QUALIFICATION_SCHEMA = "durable-workflow.exact-current-composer-qualification/v1"
RELEASE_SURFACES_SCHEMA = "durable-workflow.waterline-release-surfaces/v1"
REPOSITORY = "durable-workflow/waterline"
WORKFLOW_NAME = "Release Docs Audit"
WORKFLOW_PATH = ".github/workflows/release-docs-audit.yml"
EVENT = "repository_dispatch"
SHA = re.compile(r"^[0-9a-f]{40}$")
VERSION = re.compile(r"^2\.0\.0(?:(?:-(?:alpha|beta|rc)\.[1-9][0-9]*)?)$")
MAX_SOURCE_BYTES = 1024 * 1024


class EvidenceError(RuntimeError):
    """The completed audit cannot publish trusted release evidence."""


def exact_keys(value: Mapping[str, Any], expected: set[str], label: str) -> None:
    if set(value) != expected:
        raise EvidenceError(
            f"{label} keys must be exactly {', '.join(sorted(expected))}"
        )


def load_json(path: Path, label: str) -> dict[str, Any]:
    try:
        source = path.read_bytes()
        value = json.loads(source)
    except (OSError, json.JSONDecodeError) as error:
        raise EvidenceError(f"cannot load {label} {path}: {error}") from error
    if len(source) > MAX_SOURCE_BYTES or not isinstance(value, dict):
        raise EvidenceError(f"{label} must be a JSON object no larger than 1 MiB")
    return value


def asset_name(run_id: int, run_attempt: int) -> str:
    if run_id < 1 or run_attempt < 1:
        raise EvidenceError("workflow run identity must use positive integers")
    return f"waterline-exact-composer-qualification-{run_id}-{run_attempt}.json"


def build(
    qualification: Mapping[str, Any],
    release_surfaces: Mapping[str, Any],
    *,
    repository: str,
    workflow_name: str,
    workflow_path: str,
    event: str,
    run_id: int,
    run_attempt: int,
    run_url: str,
    head_sha: str,
    release_tag: str,
    release_commit: str,
) -> dict[str, Any]:
    exact_keys(
        qualification,
        {"composer_graphs", "outcome", "package_metadata", "packages", "schema"},
        "exact Composer qualification",
    )
    packages = qualification.get("packages")
    if not isinstance(packages, dict):
        raise EvidenceError("exact Composer qualification packages must be an object")
    exact_keys(packages, {"sdk-php", "waterline", "workflow"}, "package tuple")
    exact_keys(
        release_surfaces,
        {
            "github_release",
            "outcome",
            "packagist",
            "schema",
            "service_image",
            "source_commit",
            "version",
        },
        "release surfaces evidence",
    )

    if (
        repository != REPOSITORY
        or workflow_name != WORKFLOW_NAME
        or workflow_path != WORKFLOW_PATH
        or event != EVENT
    ):
        raise EvidenceError("workflow identity is not the trusted Waterline release audit")
    if run_id < 1 or run_attempt < 1:
        raise EvidenceError("workflow run identity must use positive integers")
    if run_url != f"https://github.com/{REPOSITORY}/actions/runs/{run_id}":
        raise EvidenceError("workflow run URL does not match the exact run identity")
    if SHA.fullmatch(head_sha) is None:
        raise EvidenceError("workflow run head SHA must identify an exact source commit")
    if SHA.fullmatch(release_commit) is None:
        raise EvidenceError("release source commit must be an exact commit")
    if VERSION.fullmatch(release_tag) is None:
        raise EvidenceError("release tag is not a supported exact Waterline 2.0 release")
    if (
        qualification.get("schema") != QUALIFICATION_SCHEMA
        or qualification.get("outcome") != "pass"
        or packages.get("waterline") != release_tag
        or any(
            not isinstance(packages.get(name), str) or not packages[name]
            for name in ("sdk-php", "waterline", "workflow")
        )
    ):
        raise EvidenceError("exact Composer qualification is not a passing release tuple")
    if (
        release_surfaces.get("schema") != RELEASE_SURFACES_SCHEMA
        or release_surfaces.get("outcome") != "verified"
        or release_surfaces.get("version") != release_tag
        or release_surfaces.get("source_commit") != release_commit
    ):
        raise EvidenceError("release surfaces do not bind the exact release tag and source")

    return {
        "schema": SCHEMA,
        "schema_version": 2,
        "repository": repository,
        "workflow_run": {
            "name": workflow_name,
            "path": workflow_path,
            "event": event,
            "run_id": run_id,
            "run_attempt": run_attempt,
            "run_url": run_url,
            "head_sha": head_sha,
        },
        "release": {"tag": release_tag, "source_commit": release_commit},
        "qualification": dict(qualification),
        "release_surfaces": dict(release_surfaces),
    }


def main(arguments: Sequence[str] | None = None) -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--qualification", type=Path, required=True)
    parser.add_argument("--release-surfaces", type=Path, required=True)
    parser.add_argument("--output-dir", type=Path, required=True)
    parser.add_argument("--repository", required=True)
    parser.add_argument("--workflow-name", required=True)
    parser.add_argument("--workflow-path", required=True)
    parser.add_argument("--event", required=True)
    parser.add_argument("--run-id", type=int, required=True)
    parser.add_argument("--run-attempt", type=int, required=True)
    parser.add_argument("--run-url", required=True)
    parser.add_argument("--head-sha", required=True)
    parser.add_argument("--release-tag", required=True)
    parser.add_argument("--release-commit", required=True)
    args = parser.parse_args(arguments)

    evidence = build(
        load_json(args.qualification, "exact Composer qualification"),
        load_json(args.release_surfaces, "release surfaces evidence"),
        repository=args.repository,
        workflow_name=args.workflow_name,
        workflow_path=args.workflow_path,
        event=args.event,
        run_id=args.run_id,
        run_attempt=args.run_attempt,
        run_url=args.run_url,
        head_sha=args.head_sha,
        release_tag=args.release_tag.removeprefix("v"),
        release_commit=args.release_commit,
    )
    args.output_dir.mkdir(parents=True, exist_ok=True)
    output = args.output_dir / asset_name(args.run_id, args.run_attempt)
    output.write_text(f"{json.dumps(evidence, indent=2)}\n", encoding="utf-8")
    print(output)
    return 0


if __name__ == "__main__":
    try:
        raise SystemExit(main())
    except EvidenceError as error:
        print(f"release qualification asset failed: {error}")
        raise SystemExit(1) from error
