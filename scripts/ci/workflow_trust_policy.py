#!/usr/bin/env python3
"""Validate the small, public security contract for GitHub Actions."""

from __future__ import annotations

import re
import sys
from dataclasses import dataclass
from pathlib import Path
from typing import Iterator, Mapping

import yaml


PINNED_ACTION = re.compile(
    r"^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+(?:/[A-Za-z0-9_./-]+)?@[0-9a-f]{40}$"
)
UNTRUSTED_TRIGGERS = frozenset({"pull_request", "pull_request_target"})
WRITE_PERMISSIONS = frozenset({"write", "write-all"})
REQUIRED_FOCUSED_WORKFLOWS = {
    "public-boundary.yml": {
        "triggers": frozenset({"pull_request", "push"}),
        "command": "scripts/check-public-boundary.sh",
    },
    "service-image-smoke.yml": {
        "triggers": frozenset({"pull_request", "push"}),
        "command": "scripts/ci/service-mode-image-smoke.sh",
    },
    "service-capacity-tuple.yml": {
        "triggers": frozenset({"pull_request", "push"}),
        "command": "scripts/ci/service-capacity-tuple.sh",
    },
}


@dataclass(frozen=True, order=True)
class Violation:
    workflow: str
    code: str
    job: str = ""


def scalar_values(value: object) -> Iterator[str]:
    if isinstance(value, str):
        yield value
    elif isinstance(value, Mapping):
        for key, item in value.items():
            yield from scalar_values(key)
            yield from scalar_values(item)
    elif isinstance(value, list):
        for item in value:
            yield from scalar_values(item)


def workflow_triggers(document: Mapping[str, object]) -> frozenset[str]:
    configured = document.get("on")
    if isinstance(configured, str):
        return frozenset({configured})
    if isinstance(configured, list):
        return frozenset(str(item) for item in configured)
    if isinstance(configured, Mapping):
        return frozenset(str(item) for item in configured)
    return frozenset()


def permission_writes(value: object) -> bool:
    if isinstance(value, str):
        return value in WRITE_PERMISSIONS
    if isinstance(value, Mapping):
        return any(
            isinstance(permission, str) and permission in WRITE_PERMISSIONS
            for permission in value.values()
        )
    return False


def steps_for(job: Mapping[str, object]) -> Iterator[Mapping[str, object]]:
    steps = job.get("steps", [])
    if not isinstance(steps, list):
        return
    for step in steps:
        if isinstance(step, Mapping):
            yield step


def action_name(step: Mapping[str, object]) -> str:
    uses = step.get("uses", "")
    return uses if isinstance(uses, str) else ""


def references_secret(value: object) -> bool:
    return any("secrets." in item.lower() for item in scalar_values(value))


def validate_focused_workflow(
    workflow_name: str,
    document: Mapping[str, object],
) -> tuple[Violation, ...]:
    contract = REQUIRED_FOCUSED_WORKFLOWS.get(workflow_name)
    if contract is None:
        return ()

    violations: set[Violation] = set()
    triggers = workflow_triggers(document)
    expected_triggers = contract["triggers"]
    if not expected_triggers.issubset(triggers):
        violations.add(Violation(workflow_name, "required-focused-trigger"))

    configured = document.get("on")
    if isinstance(configured, Mapping):
        for trigger in expected_triggers:
            trigger_configuration = configured.get(trigger)
            if not isinstance(trigger_configuration, Mapping):
                continue
            if {"paths", "paths-ignore", "branches-ignore"}.intersection(
                trigger_configuration
            ):
                violations.add(Violation(workflow_name, "filtered-focused-trigger"))
            branches = trigger_configuration.get("branches")
            if isinstance(branches, str) and branches != "main":
                violations.add(Violation(workflow_name, "focused-trigger-misses-main"))
            if isinstance(branches, list) and "main" not in branches:
                violations.add(Violation(workflow_name, "focused-trigger-misses-main"))

    command = str(contract["command"])
    jobs = document.get("jobs", {})
    command_found = False
    if isinstance(jobs, Mapping):
        for job in jobs.values():
            if not isinstance(job, Mapping):
                continue
            for step in steps_for(job):
                run = step.get("run", "")
                if isinstance(run, str) and command in run:
                    command_found = True
    if not command_found:
        violations.add(Violation(workflow_name, "missing-focused-command"))

    return tuple(sorted(violations))


def validate_document(
    workflow_name: str,
    document: Mapping[str, object],
) -> tuple[Violation, ...]:
    violations: set[Violation] = set(validate_focused_workflow(workflow_name, document))
    triggers = workflow_triggers(document)
    untrusted = bool(triggers & UNTRUSTED_TRIGGERS)

    if "pull_request_target" in triggers:
        violations.add(Violation(workflow_name, "pull-request-target"))
    if "permissions" not in document:
        violations.add(Violation(workflow_name, "missing-root-permissions"))
    elif untrusted and permission_writes(document.get("permissions")):
        violations.add(Violation(workflow_name, "untrusted-root-write"))

    root_permissions = document.get("permissions")
    root_secret = references_secret(document.get("env", {}))

    jobs = document.get("jobs", {})
    if not isinstance(jobs, Mapping):
        return tuple(sorted(violations | {Violation(workflow_name, "invalid-jobs")}))

    for job_name, job in jobs.items():
        if not isinstance(job, Mapping):
            violations.add(Violation(workflow_name, "invalid-job", str(job_name)))
            continue
        job_identifier = str(job_name)
        job_permissions = job.get("permissions", root_permissions)
        if untrusted and permission_writes(job_permissions):
            violations.add(
                Violation(workflow_name, "untrusted-job-write", job_identifier)
            )
        if untrusted and (root_secret or references_secret(job.get("env", {}))):
            violations.add(
                Violation(workflow_name, "untrusted-secret", job_identifier)
            )
        if untrusted and "environment" in job:
            violations.add(
                Violation(workflow_name, "untrusted-environment", job_identifier)
            )
        for step in steps_for(job):
            uses = action_name(step)
            configuration = step.get("with", {})
            if uses and not uses.startswith("./"):
                if PINNED_ACTION.fullmatch(uses) is None:
                    violations.add(
                        Violation(workflow_name, "unpinned-action", job_identifier)
                    )
            if untrusted and references_secret(step):
                violations.add(
                    Violation(workflow_name, "untrusted-secret", job_identifier)
                )
            if (
                untrusted
                and uses.startswith("actions/checkout@")
                and (
                    not isinstance(configuration, Mapping)
                    or configuration.get("persist-credentials") != "false"
                )
            ):
                violations.add(
                    Violation(
                        workflow_name,
                        "untrusted-persisted-checkout",
                        job_identifier,
                    )
                )
            if untrusted and uses.startswith("actions/cache@"):
                key = (
                    configuration.get("key", "")
                    if isinstance(configuration, Mapping)
                    else ""
                )
                restore = (
                    configuration.get("restore-keys", "")
                    if isinstance(configuration, Mapping)
                    else ""
                )
                if "${{ github.event_name }}" not in str(key) or (
                    restore and "${{ github.event_name }}" not in str(restore)
                ):
                    violations.add(
                        Violation(
                            workflow_name,
                            "cache-cross-trust-boundary",
                            job_identifier,
                        )
                    )

    return tuple(sorted(violations))


def load_document(path: Path) -> Mapping[str, object]:
    document = yaml.load(path.read_text(encoding="utf-8"), Loader=yaml.BaseLoader)
    if not isinstance(document, Mapping):
        raise ValueError(f"{path} must contain a workflow mapping")
    return document


def audit_repository(root: Path) -> tuple[Violation, ...]:
    workflow_directory = root / ".github" / "workflows"
    violations: set[Violation] = set()
    present = {path.name for path in workflow_directory.glob("*.yml")}
    present.update(path.name for path in workflow_directory.glob("*.yaml"))
    for required in REQUIRED_FOCUSED_WORKFLOWS:
        if required not in present:
            violations.add(Violation(required, "missing-required-workflow"))
    for path in sorted((*workflow_directory.glob("*.yml"), *workflow_directory.glob("*.yaml"))):
        violations.update(validate_document(path.name, load_document(path)))
    return tuple(sorted(violations))


def main() -> int:
    violations = audit_repository(Path(__file__).parents[2])
    for violation in violations:
        suffix = f" ({violation.job})" if violation.job else ""
        print(f"{violation.workflow}: {violation.code}{suffix}", file=sys.stderr)
    return 1 if violations else 0


if __name__ == "__main__":
    raise SystemExit(main())
