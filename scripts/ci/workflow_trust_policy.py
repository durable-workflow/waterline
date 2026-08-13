#!/usr/bin/env python3
"""Validate syntax and executable trust boundaries for Actions workflows."""

from __future__ import annotations

import argparse
import re
import sys
from dataclasses import dataclass
from pathlib import Path, PurePosixPath
from typing import Iterator, Mapping

import yaml


PINNED_ACTION = re.compile(
    r"^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+(?:/[A-Za-z0-9_./-]+)?@[0-9a-f]{40}$"
)
UNTRUSTED_TRIGGERS = frozenset({"pull_request", "pull_request_target"})
PRIVILEGED_INPUT_TRIGGERS = frozenset(
    {"pull_request", "pull_request_target", "workflow_run"}
)
WRITE_PERMISSIONS = frozenset({"write", "write-all"})
ARTIFACT_ACTIONS = (
    "actions/upload-artifact@",
    "actions/download-artifact@",
)
RUN_ID_EXPRESSION = "${{ github.run_id }}"
RUN_ATTEMPT_EXPRESSION = "${{ github.run_attempt }}"
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
SERVICE_CAPACITY_SERVER_TAG = "2.0.0-rc.32"
SERVICE_CAPACITY_SERVER_COMMIT = "62344aadb8bc554ad3914ecaf68b974fec8b405c"
SERVICE_CAPACITY_SERVER_REPOSITORY = "https://github.com/durable-workflow/server.git"
GITHUB_PROVIDER_CONDITION = "${{ github.server_url == 'https://github.com' }}"
ADMISSION_PROVIDER_CONDITION = "${{ github.server_url != 'https://github.com' }}"
ADMISSION_FETCH_COMMANDS = (
    'git clone --branch "$SERVER_RELEASE_TAG" --depth 1',
    '"$SERVER_REPOSITORY_URL" server',
    'test "$(git -C server rev-parse HEAD)" = "$SERVER_RELEASE_COMMIT"',
)


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


def is_cache_action(uses: str) -> bool:
    return uses.startswith("actions/cache@") or uses.startswith("actions/cache/")


def is_run_bound_dialog_evidence_upload(
    uses: str,
    configuration: object,
) -> bool:
    if not uses.startswith("actions/upload-artifact@") or not isinstance(
        configuration, Mapping
    ):
        return False

    name = configuration.get("name")
    configured_path = configuration.get("path")
    retention = configuration.get("retention-days")
    if not isinstance(name, str) or not isinstance(configured_path, str):
        return False
    if RUN_ID_EXPRESSION not in name or RUN_ATTEMPT_EXPRESSION not in name:
        return False
    if configuration.get("if-no-files-found") != "error" or retention != "30":
        return False
    if any(character in configured_path for character in ("\0", "\n", "\r", "*")):
        return False

    candidate = PurePosixPath(configured_path.rstrip("/"))

    return (
        not candidate.is_absolute()
        and ".." not in candidate.parts
        and candidate.as_posix() == "sample-app/dialog-evidence"
    )


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
            if isinstance(trigger_configuration, Mapping):
                if {"paths", "paths-ignore", "branches-ignore"}.intersection(
                    trigger_configuration
                ):
                    violations.add(Violation(workflow_name, "filtered-focused-trigger"))
                branches = trigger_configuration.get("branches")
                if isinstance(branches, str) and branches != "v2":
                    violations.add(
                        Violation(workflow_name, "focused-trigger-misses-v2")
                    )
                if isinstance(branches, list) and "v2" not in branches:
                    violations.add(
                        Violation(workflow_name, "focused-trigger-misses-v2")
                    )

    jobs = document.get("jobs", {})
    command_found = False
    public_server_checkout_found = workflow_name != "service-capacity-tuple.yml"
    admission_server_checkout_found = workflow_name != "service-capacity-tuple.yml"
    if isinstance(jobs, Mapping):
        for job_name, job in jobs.items():
            if not isinstance(job, Mapping):
                continue
            if "if" in job:
                violations.add(
                    Violation(
                        workflow_name,
                        "conditional-focused-job",
                        str(job_name),
                    )
                )
            for step in steps_for(job):
                run = step.get("run", "")
                if isinstance(run, str) and contract["command"] in run:
                    command_found = True
                uses = action_name(step)
                configuration = step.get("with", {})
                environment = step.get("env", {})
                if (
                    workflow_name == "service-capacity-tuple.yml"
                    and uses.startswith("actions/checkout@")
                    and isinstance(configuration, Mapping)
                    and configuration.get("repository")
                    == "${{ github.repository_owner }}/server"
                ):
                    if (
                        configuration.get("ref") == SERVICE_CAPACITY_SERVER_TAG
                        and step.get("if") == GITHUB_PROVIDER_CONDITION
                    ):
                        public_server_checkout_found = True
                if (
                    workflow_name == "service-capacity-tuple.yml"
                    and isinstance(run, str)
                    and isinstance(environment, Mapping)
                    and step.get("if") == ADMISSION_PROVIDER_CONDITION
                    and environment.get("SERVER_REPOSITORY_URL")
                    == SERVICE_CAPACITY_SERVER_REPOSITORY
                    and environment.get("SERVER_RELEASE_TAG")
                    == SERVICE_CAPACITY_SERVER_TAG
                    and environment.get("SERVER_RELEASE_COMMIT")
                    == SERVICE_CAPACITY_SERVER_COMMIT
                    and all(command in run for command in ADMISSION_FETCH_COMMANDS)
                ):
                    admission_server_checkout_found = True
    if not command_found:
        violations.add(Violation(workflow_name, "missing-focused-command"))
    if not public_server_checkout_found:
        violations.add(
            Violation(workflow_name, "missing-public-server-release-checkout")
        )
    if not admission_server_checkout_found:
        violations.add(
            Violation(workflow_name, "missing-admission-server-source-checkout")
        )

    return tuple(sorted(violations))


def validate_document(
    workflow_name: str,
    document: Mapping[str, object],
) -> tuple[Violation, ...]:
    violations: set[Violation] = set()
    triggers = workflow_triggers(document)
    if "pull_request_target" in triggers:
        violations.add(Violation(workflow_name, "pull-request-target"))

    violations.update(validate_focused_workflow(workflow_name, document))

    root_permissions = document.get("permissions")
    if "permissions" not in document:
        violations.add(Violation(workflow_name, "missing-root-permissions"))
    root_has_secret = any(
        "${{ secrets." in value for value in scalar_values(document.get("env", {}))
    )
    jobs = document.get("jobs", {})
    if not isinstance(jobs, Mapping):
        return (Violation(workflow_name, "invalid-jobs"),)

    for job_name, untyped_job in jobs.items():
        if not isinstance(untyped_job, Mapping):
            violations.add(Violation(workflow_name, "invalid-job", str(job_name)))
            continue
        job = untyped_job
        job_identifier = str(job_name)
        job_permissions = job.get("permissions", root_permissions)
        values = tuple(scalar_values(job))
        has_secret = root_has_secret or any("${{ secrets." in value for value in values)
        privileged = (
            permission_writes(job_permissions) or "environment" in job or has_secret
        )

        for step in steps_for(job):
            uses = action_name(step)
            if uses and not uses.startswith("./") and not PINNED_ACTION.fullmatch(uses):
                violations.add(
                    Violation(workflow_name, "floating-action", job_identifier)
                )

            if uses.startswith("actions/download-artifact@") and privileged:
                configuration = step.get("with", {})
                required = {
                    "artifact-ids",
                    "digest-mismatch",
                    "repository",
                    "run-id",
                }
                if (
                    not isinstance(configuration, Mapping)
                    or not required.issubset(configuration)
                    or configuration.get("digest-mismatch") != "error"
                ):
                    violations.add(
                        Violation(
                            workflow_name,
                            "unbound-artifact-download",
                            job_identifier,
                        )
                    )

            if privileged and is_cache_action(uses):
                violations.add(
                    Violation(workflow_name, "privileged-cache", job_identifier)
                )

        if privileged and triggers.intersection(PRIVILEGED_INPUT_TRIGGERS):
            violations.add(
                Violation(workflow_name, "privileged-untrusted-trigger", job_identifier)
            )

        if not triggers.intersection(UNTRUSTED_TRIGGERS):
            continue

        if permission_writes(job_permissions):
            violations.add(
                Violation(workflow_name, "pr-write-permission", job_identifier)
            )
        if has_secret:
            violations.add(Violation(workflow_name, "pr-secret", job_identifier))
        if "environment" in job:
            violations.add(Violation(workflow_name, "pr-environment", job_identifier))

        for step in steps_for(job):
            uses = action_name(step)
            configuration = step.get("with", {})
            if uses.startswith("actions/checkout@") and (
                not isinstance(configuration, Mapping)
                or configuration.get("persist-credentials") != "false"
            ):
                violations.add(
                    Violation(workflow_name, "pr-persisted-checkout", job_identifier)
                )
            if uses.startswith(
                ARTIFACT_ACTIONS
            ) and not is_run_bound_dialog_evidence_upload(
                uses,
                configuration,
            ):
                violations.add(Violation(workflow_name, "pr-artifact", job_identifier))
            if is_cache_action(uses):
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
    document = yaml.load(path.read_text(), Loader=yaml.BaseLoader)
    if not isinstance(document, Mapping):
        raise ValueError("workflow document must be a mapping")
    return document


def audit_repository(root: Path) -> tuple[Violation, ...]:
    violations: list[Violation] = []
    workflows = root / ".github" / "workflows"
    paths = sorted(workflows.glob("*.yml"))
    observed_names = {path.name for path in paths}
    for required_name in REQUIRED_FOCUSED_WORKFLOWS.keys() - observed_names:
        violations.append(Violation(required_name, "missing-required-workflow"))

    for path in paths:
        try:
            document = load_document(path)
        except (OSError, ValueError, yaml.YAMLError):
            violations.append(Violation(path.name, "invalid-yaml"))
            continue
        violations.extend(validate_document(path.name, document))
    return tuple(sorted(violations))


def main() -> int:
    argument_parser = argparse.ArgumentParser()
    argument_parser.add_argument("--repository", default=".")
    arguments = argument_parser.parse_args()

    violations = audit_repository(Path(arguments.repository).resolve())
    if violations:
        for violation in violations:
            location = (
                f"{violation.workflow}:{violation.job}"
                if violation.job
                else violation.workflow
            )
            print(f"{location}: {violation.code}", file=sys.stderr)
        return 1

    print("workflow-syntax=valid")
    print("workflow-trust-policy=valid")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
