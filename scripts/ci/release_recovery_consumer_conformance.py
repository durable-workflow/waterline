#!/usr/bin/env python3
"""Run the versioned release-recovery consumer conformance contract."""

from __future__ import annotations

import argparse
import copy
import datetime as dt
import hashlib
import importlib.util
import io
import json
import os
import re
import ssl
import subprocess
import sys
import tempfile
import urllib.error
import urllib.parse
import urllib.request
from pathlib import Path, PurePosixPath
from types import ModuleType
from typing import Any
from unittest import mock

CONTRACT_SCHEMA = "durable-workflow.release-recovery-consumer-conformance/v1"
ADAPTER_SCHEMA = "durable-workflow.release-recovery-consumer-adapter/v1"
EVIDENCE_SCHEMA = "durable-workflow.release-recovery-consumer-conformance-evidence/v1"
VERSION_PATTERN = re.compile(
    r"(?P<major>0|[1-9][0-9]*)\.(?P<minor>0|[1-9][0-9]*)\.(?P<patch>0|[1-9][0-9]*)"
    r"(?:-(?P<prerelease>(?:0|[1-9][0-9]*|[0-9]*[A-Za-z-][0-9A-Za-z-]*)"
    r"(?:\.(?:0|[1-9][0-9]*|[0-9]*[A-Za-z-][0-9A-Za-z-]*))*))?"
    r"(?:\+(?P<build>[0-9A-Za-z-]+(?:\.[0-9A-Za-z-]+)*))?"
)
COMMIT_PATTERN = re.compile(r"[0-9a-f]{40}")
SHA256_PATTERN = re.compile(r"[0-9a-f]{64}")
CURRENT_PLAN_SCHEMA = "durable-workflow.release-plan/v2"
HISTORICAL_PLAN_SCHEMA = "durable-workflow.release-plan/v1"
EXPECTED_LEGACY_PLAN_DIGESTS = frozenset(
    {
        "0be354d5ea603170b6aef8ae0d9861886c4ccc0f75e6acb763239b30dd5d8ba3",
        "295a3f654716ea8cd8dc693c1cd15a4b487737e5f01184bad7363fbde6717c40",
        "486d9ef7c5a7f4443a89566cab33d7f2bccc518254ab6698d918a431d6a1c9ce",
        "498804a2c7fd5b0e34f93ef080bea3073bc98e420e8bf84a98ca4cdb94729973",
        "7bd737c92f139eec33026bc88a6491dc635d819a87a61c985e14e06aca645582",
        "80e88698fa37b6d738d111dd2be3e3c145607973f8147c54cc25e5d91d415b17",
        "9c0a5879652a2d5f4806a9167399687328c1764fa10dbc8d76215b43ac83b9d6",
        "db90616c98f305c61d7eb2fb9ed03cc28f06963e9ca020c8ef6d7c6a8557f7bc",
        "e1fc6e20c9d2ded0b5e7ac4d6be75ba861d31fc4b2db651dc0272dca623f2c7f",
    }
)
REQUIRED_CASES = (
    "immutable-plan-enumeration",
    "current-plan-schema",
    "completed-plan-lifecycle",
    "superseded-plan-lifecycle",
    "exact-successor-identity",
    "malformed-authority-rejection",
    "continuity-ambiguity-rejection",
    "explicit-terminal-plan-rejection",
    "bounded-authority-convergence",
    "release-candidate-beta-qualification",
    "authoritative-rc-foundation",
    "scheduled-empty-no-op",
    "trusted-github-api-transport",
    "transport-fail-closed-publication",
)
CONSUMERS = (
    {
        "component": "workflow",
        "repository": "durable-workflow/workflow",
        "target_branch": "v2",
    },
    {
        "component": "waterline",
        "repository": "durable-workflow/waterline",
        "target_branch": "v2",
    },
    {
        "component": "server",
        "repository": "durable-workflow/server",
        "target_branch": "main",
    },
    {
        "component": "cli",
        "repository": "durable-workflow/cli",
        "target_branch": "main",
    },
    {
        "component": "sdk-php",
        "repository": "durable-workflow/sdk-php",
        "target_branch": "main",
    },
    {
        "component": "sdk-python",
        "repository": "durable-workflow/sdk-python",
        "target_branch": "main",
    },
    {
        "component": "sdk-rust",
        "repository": "durable-workflow/sdk-rust",
        "target_branch": "main",
    },
)


class ConformanceError(RuntimeError):
    """The contract, adapter, or consumer does not conform."""


def canonical_json(value: Any) -> bytes:
    return (json.dumps(value, indent=2, sort_keys=True, ensure_ascii=True) + "\n").encode()


def sha256_bytes(value: bytes) -> str:
    return hashlib.sha256(value).hexdigest()


def load_json_object(path: Path, label: str) -> tuple[dict[str, Any], bytes]:
    try:
        raw = path.read_bytes()
        value = json.loads(raw)
    except (OSError, json.JSONDecodeError) as error:
        raise ConformanceError(f"{label} is not readable canonical JSON: {path}") from error
    if not isinstance(value, dict):
        raise ConformanceError(f"{label} must be a JSON object: {path}")
    if raw != canonical_json(value):
        raise ConformanceError(f"{label} must use canonical sorted JSON formatting: {path}")
    return value, raw


def relative_file(root: Path, value: Any, label: str) -> Path:
    if not isinstance(value, str) or not value:
        raise ConformanceError(f"{label} must be a non-empty repository-relative path")
    path = PurePosixPath(value)
    if path.is_absolute() or ".." in path.parts:
        raise ConformanceError(f"{label} must stay within the repository")
    repository_root = root.resolve()
    resolved = root.joinpath(*path.parts).resolve()
    try:
        resolved.relative_to(repository_root)
    except ValueError as error:
        raise ConformanceError(f"{label} must stay within the repository") from error
    if not resolved.is_file():
        raise ConformanceError(f"{label} does not exist: {value}")
    return resolved


def validate_contract(
    contract: dict[str, Any],
    contract_raw: bytes,
    suite_path: Path,
) -> str:
    expected_keys = {"cases", "consumers", "schema", "suite", "version"}
    if set(contract) != expected_keys or contract.get("schema") != CONTRACT_SCHEMA:
        raise ConformanceError("shared contract does not satisfy the v1 document shape")
    version = contract.get("version")
    if not isinstance(version, str) or VERSION_PATTERN.fullmatch(version) is None:
        raise ConformanceError("shared contract version must be exact SemVer")
    if contract.get("consumers") != list(CONSUMERS):
        raise ConformanceError("shared contract must declare the exact seven-consumer target topology")
    suite = contract.get("suite")
    if (
        not isinstance(suite, dict)
        or set(suite) != {"sha256"}
        or not isinstance(suite.get("sha256"), str)
        or SHA256_PATTERN.fullmatch(suite["sha256"]) is None
    ):
        raise ConformanceError("shared contract suite must contain one exact SHA-256")
    actual_suite_sha256 = sha256_bytes(suite_path.read_bytes())
    if suite["sha256"] != actual_suite_sha256:
        raise ConformanceError("shared conformance runner differs from the suite digest declared by the contract")
    cases = contract.get("cases")
    if not isinstance(cases, list):
        raise ConformanceError("shared contract cases must be a JSON array")
    case_ids: list[str] = []
    for case in cases:
        if (
            not isinstance(case, dict)
            or set(case) != {"id", "requirement"}
            or not isinstance(case.get("id"), str)
            or not isinstance(case.get("requirement"), str)
            or not case["requirement"]
        ):
            raise ConformanceError("every shared contract case needs exactly an id and requirement")
        case_ids.append(case["id"])
    if tuple(case_ids) != REQUIRED_CASES:
        raise ConformanceError("shared contract omits or reorders required authority cases")
    return sha256_bytes(contract_raw)


def parse_semver(
    version: Any,
    label: str,
) -> tuple[tuple[int, int, int], tuple[str, ...] | None]:
    if not isinstance(version, str):
        raise ConformanceError(f"{label} must be exact SemVer")
    match = VERSION_PATTERN.fullmatch(version)
    if match is None:
        raise ConformanceError(f"{label} must be exact SemVer")
    core = tuple(int(match.group(field)) for field in ("major", "minor", "patch"))
    prerelease = match.group("prerelease")
    return core, None if prerelease is None else tuple(prerelease.split("."))


def compare_semver_precedence(left: str, right: str) -> int:
    left_core, left_prerelease = parse_semver(left, "left version")
    right_core, right_prerelease = parse_semver(right, "right version")
    if left_core != right_core:
        return 1 if left_core > right_core else -1
    if left_prerelease is None or right_prerelease is None:
        if left_prerelease == right_prerelease:
            return 0
        return 1 if left_prerelease is None else -1
    for left_identifier, right_identifier in zip(left_prerelease, right_prerelease, strict=False):
        if left_identifier == right_identifier:
            continue
        left_numeric = left_identifier.isdigit()
        right_numeric = right_identifier.isdigit()
        if left_numeric and right_numeric:
            return 1 if int(left_identifier) > int(right_identifier) else -1
        if left_numeric != right_numeric:
            return -1 if left_numeric else 1
        return 1 if left_identifier > right_identifier else -1
    if len(left_prerelease) == len(right_prerelease):
        return 0
    return 1 if len(left_prerelease) > len(right_prerelease) else -1


def validate_adapter(
    adapter: dict[str, Any],
    contract: dict[str, Any],
    contract_sha256: str,
    repository_root: Path,
    current_suite: Path,
    current_contract: Path,
) -> tuple[Path, list[str]]:
    expected_keys = {
        "component",
        "consumer",
        "contract",
        "distribution_verification",
        "repository",
        "schema",
        "suite",
        "target_branch",
    }
    if set(adapter) != expected_keys or adapter.get("schema") != ADAPTER_SCHEMA:
        raise ConformanceError("consumer adapter does not satisfy the v1 document shape")
    identity = {
        "component": adapter.get("component"),
        "repository": adapter.get("repository"),
        "target_branch": adapter.get("target_branch"),
    }
    if identity not in CONSUMERS or identity not in contract["consumers"]:
        raise ConformanceError("consumer adapter is not in the contract target topology")
    contract_pin = adapter.get("contract")
    if not isinstance(contract_pin, dict) or set(contract_pin) != {"path", "sha256", "version"}:
        raise ConformanceError("consumer adapter does not declare the exact contract pin shape")
    adapter_contract = relative_file(repository_root, contract_pin["path"], "adapter contract")
    invoked_contract = current_contract.resolve()
    if adapter_contract != invoked_contract:
        raise ConformanceError("the invoked contract is not the adapter's declared contract")
    declared_contract, declared_contract_raw = load_json_object(adapter_contract, "adapter contract")
    if declared_contract.get("version") != contract_pin.get("version") or sha256_bytes(
        declared_contract_raw
    ) != contract_pin.get("sha256"):
        raise ConformanceError("the adapter's declared contract does not match its version and digest pins")
    if contract_pin.get("version") != contract["version"] or contract_pin.get("sha256") != contract_sha256:
        raise ConformanceError("consumer adapter does not pin the exact invoked contract version and digest")
    suite_pin = adapter.get("suite")
    if (
        not isinstance(suite_pin, dict)
        or set(suite_pin) != {"path", "sha256"}
        or suite_pin.get("sha256") != contract["suite"]["sha256"]
    ):
        raise ConformanceError("consumer adapter does not pin the exact shared suite digest")
    adapter_suite = relative_file(repository_root, suite_pin["path"], "adapter suite")
    if adapter_suite.resolve() != current_suite.resolve():
        raise ConformanceError("the invoked suite is not the adapter's declared suite")
    consumer = relative_file(repository_root, adapter.get("consumer"), "adapter consumer")
    distribution = adapter.get("distribution_verification")
    if (
        not isinstance(distribution, dict)
        or set(distribution) != {"command"}
        or not isinstance(distribution.get("command"), list)
        or len(distribution["command"]) < 2
        or distribution["command"][0] != "{python}"
        or not all(isinstance(item, str) and item for item in distribution["command"])
    ):
        raise ConformanceError("distribution verification must declare a local Python command")
    relative_file(
        repository_root,
        distribution["command"][1],
        "distribution verification entry point",
    )
    return consumer, distribution["command"]


def previous_contract(
    repository_root: Path,
    contract_path: Path,
    previous_ref: str | None,
) -> dict[str, Any] | None:
    if previous_ref is None:
        return None
    if COMMIT_PATTERN.fullmatch(previous_ref) is None or previous_ref == "0" * 40:
        raise ConformanceError("previous contract ref must be an exact nonzero commit")
    relative = contract_path.resolve().relative_to(repository_root.resolve()).as_posix()
    commit = subprocess.run(
        ["git", "cat-file", "-e", f"{previous_ref}^{{commit}}"],
        cwd=repository_root,
        check=False,
        capture_output=True,
        text=False,
    )
    if commit.returncode != 0:
        raise ConformanceError("previous contract commit is unavailable")
    tree = subprocess.run(
        ["git", "ls-tree", "--name-only", "-z", previous_ref, "--", relative],
        cwd=repository_root,
        check=False,
        capture_output=True,
        text=False,
    )
    if tree.returncode != 0:
        raise ConformanceError("previous contract commit cannot be inspected")
    if tree.stdout == b"":
        return None
    if tree.stdout != f"{relative}\0".encode():
        raise ConformanceError("previous contract path could not be resolved exactly")
    result = subprocess.run(
        ["git", "show", f"{previous_ref}:{relative}"],
        cwd=repository_root,
        check=False,
        capture_output=True,
        text=False,
    )
    if result.returncode != 0:
        raise ConformanceError("previous shared contract is unreadable")
    try:
        value = json.loads(result.stdout)
    except json.JSONDecodeError as error:
        raise ConformanceError("previous shared contract is not valid JSON") from error
    if not isinstance(value, dict):
        raise ConformanceError("previous shared contract is not a JSON object")
    return value


def require_versioned_contract_change(
    previous: dict[str, Any] | None,
    current: dict[str, Any],
) -> None:
    if previous is None or previous == current:
        return
    previous_version = previous.get("version")
    current_version = current.get("version")
    parse_semver(previous_version, "previous shared contract version")
    parse_semver(current_version, "current shared contract version")
    if compare_semver_precedence(current_version, previous_version) <= 0:
        raise ConformanceError("shared contract content changed without a strictly advancing SemVer version")


def load_consumer(path: Path) -> ModuleType:
    parent = str(path.parent)
    if parent not in sys.path:
        sys.path.insert(0, parent)
    module_name = f"release_recovery_consumer_{sha256_bytes(str(path).encode())[:12]}"
    spec = importlib.util.spec_from_file_location(module_name, path)
    if spec is None or spec.loader is None:
        raise ConformanceError(f"cannot load recovery consumer: {path}")
    module = importlib.util.module_from_spec(spec)
    sys.modules[module_name] = module
    try:
        spec.loader.exec_module(module)
    except Exception as error:
        raise ConformanceError(f"cannot import recovery consumer: {path}") from error
    return module


def plan(module: ModuleType, identity: str = "conformance") -> dict[str, Any]:
    components: dict[str, dict[str, str]] = {}
    for index, name in enumerate(module.COMPONENTS):
        components[name] = {
            "version": (f"2.0.0-beta.{index + 1}" if name in {"workflow", "waterline"} else f"1.{index}.0"),
            "commit": f"{index + 1:040x}",
        }
    return {
        "schema": module.SCHEMA,
        "plan": identity,
        "channel": "beta",
        "foundation": {
            "tag": module.FOUNDATION_TAG,
            "commit": module.FOUNDATION_COMMIT,
        },
        "components": components,
        "beta_authorization": {
            "tag": f"beta-authorization/{identity}",
            "commit": "f" * 40,
        },
    }


def legacy_beta_one_plan() -> dict[str, Any]:
    return {
        "schema": HISTORICAL_PLAN_SCHEMA,
        "plan": "beta-1-e743e3760000",
        "channel": "beta",
        "foundation": {
            "tag": "beta-candidate/beta-continuity-foundation",
            "commit": "4995052410bd4301c5796ffba54e0b6d2f490ed1",
        },
        "components": {
            "workflow": {
                "version": "2.0.0-beta.1",
                "commit": "22bbf2a1469f4a38b1a6e1006ca8e46835c2fea4",
            },
            "waterline": {
                "version": "2.0.0-beta.1",
                "commit": "0fb3caaba1e8a77f9bfa63ba3dcb2bcbaa825c31",
            },
            "server": {
                "version": "0.2.699",
                "commit": "d6e8fb6c76c1d71cc7d3a1d38bdebd324150acad",
            },
            "cli": {
                "version": "0.1.95",
                "commit": "bc036e94604329612b65a2a9effe2e929f91f4e1",
            },
            "sdk-php": {
                "version": "0.1.16",
                "commit": "3b79813b1bbcb811277cc30d8dcfc359ea53f65c",
            },
            "sdk-python": {
                "version": "0.4.106",
                "commit": "13037ddcb1f55d72c24256591e346b991ad64273",
            },
            "sdk-rust": {
                "version": "0.1.22",
                "commit": "6fa98425c8ec7690ef96f8296a21407aa8d03067",
            },
        },
        "beta_authorization": {
            "tag": "beta-authorization/beta-1-e743e3760000",
            "commit": "bef98bfd61b604d48459c15e968e3ace8e5124b0",
        },
    }


def authority(
    module: ModuleType,
    candidate: dict[str, Any],
    lifecycle: str,
    successor: dict[str, Any] | None = None,
) -> dict[str, Any]:
    return {
        "tag": f"{module.PLAN_TAG_PREFIX}{candidate['plan']}",
        "commit": "a" * 40,
        "recorded_at": dt.datetime(2026, 7, 25, tzinfo=dt.UTC),
        "plan": candidate,
        "preparation": None,
        "lifecycle": lifecycle,
        "successor": successor,
    }


def expect_recovery_error(module: ModuleType, action: Any, message: str) -> None:
    try:
        action()
    except module.RecoveryError:
        return
    raise ConformanceError(message)


def continuity_resolution_qualification(module: ModuleType) -> dict[str, Any]:
    return {
        "repository": module.CONTROL_REPOSITORY,
        "workflow": module.CONTINUITY_RESOLUTION_QUALIFICATION_WORKFLOW,
        "event": module.CONTINUITY_RESOLUTION_QUALIFICATION_EVENT,
        "head_branch": module.CONTINUITY_RESOLUTION_QUALIFICATION_BRANCH,
        "head_sha": "9" * 40,
        "run_id": 987,
        "run_attempt": 2,
        "status": "completed",
        "conclusion": "success",
    }


def continuity_resolution_qualification_run(
    module: ModuleType,
    qualification: dict[str, Any],
) -> dict[str, Any]:
    return {
        "id": qualification["run_id"],
        "run_attempt": qualification["run_attempt"],
        "repository": {"full_name": module.CONTROL_REPOSITORY},
        "head_repository": {"full_name": module.CONTROL_REPOSITORY},
        "path": (
            f"{module.CONTINUITY_RESOLUTION_QUALIFICATION_WORKFLOW}"
            f"@{module.CONTINUITY_RESOLUTION_QUALIFICATION_BRANCH}"
        ),
        "event": qualification["event"],
        "head_branch": qualification["head_branch"],
        "head_sha": qualification["head_sha"],
        "status": qualification["status"],
        "conclusion": qualification["conclusion"],
    }


def continuity_resolution_fixture(
    module: ModuleType,
) -> tuple[dict[str, Any], list[dict[str, Any]], dict[str, Any], dict[str, Any]]:
    interrupted_plan = {"plan": "interrupted-conformance"}
    interrupted = {
        "tag": "release-plan/interrupted-conformance",
        "commit": "a" * 40,
        "plan": interrupted_plan,
    }
    interruption = {
        "tag": "beta-continuity/interrupted-conformance/interrupted",
        "commit": "b" * 40,
        "evidence_sha256": "c" * 64,
    }
    successors: list[dict[str, Any]] = []
    for index, name in enumerate(("first-successor", "second-successor"), start=1):
        successors.append(
            {
                "tag": f"release-plan/{name}",
                "supersession": {
                    **interruption,
                    "continuity_claim": {
                        "plan": {
                            "tag": f"release-plan/{name}",
                            "commit": str(index) * 40,
                            "sha256": str(index + 2) * 64,
                        },
                        "acceptance": {
                            "tag": f"beta-continuity/{name}/accepted",
                            "commit": str(index + 4) * 40,
                            "sha256": str(index + 6) * 64,
                        },
                    },
                },
            }
        )
    claims = sorted(
        (successor["supersession"]["continuity_claim"] for successor in successors),
        key=lambda claim: claim["plan"]["tag"],
    )
    qualification = continuity_resolution_qualification(module)
    resolution = {
        "schema": module.CONTINUITY_RESOLUTION_SCHEMA,
        "qualification": qualification,
        "interruption": {
            "plan": {
                "tag": interrupted["tag"],
                "commit": interrupted["commit"],
                "sha256": module.manifest_digest(interrupted_plan),
            },
            "evidence": {
                "tag": interruption["tag"],
                "commit": interruption["commit"],
                "sha256": interruption["evidence_sha256"],
            },
        },
        "successor_claims": claims,
        "selected_successor": claims[1]["plan"],
    }
    return (
        interrupted,
        successors,
        resolution,
        continuity_resolution_qualification_run(module, qualification),
    )


def continuity_resolution_tag(
    module: ModuleType,
    interrupted: dict[str, Any],
    resolution: dict[str, Any],
) -> str:
    return (
        f"{module.CONTINUITY_RESOLUTION_TAG_PREFIX}{interrupted['plan']['plan']}/"
        f"{module.manifest_digest(resolution)}"
    )


def exercise_continuity_resolution(
    module: ModuleType,
    interrupted: dict[str, Any],
    successors: list[dict[str, Any]],
    resolution: Any,
    resolution_tags: list[str],
    resolution_commit: str | None,
    qualification_run: Any,
) -> str:
    client = mock.Mock()
    resolution_tag = resolution_tags[0] if len(resolution_tags) == 1 else None
    qualification = resolution.get("qualification") if isinstance(resolution, dict) else None
    resolution_prefix = (
        f"{module.CONTINUITY_RESOLUTION_TAG_PREFIX}{interrupted['plan']['plan']}/"
    )
    registry_url = (
        f"https://api.github.com/repos/{module.CONTROL_REPOSITORY}"
        f"/git/matching-refs/tags/{resolution_prefix}"
    )
    tag_url = (
        (
            f"https://api.github.com/repos/{module.CONTROL_REPOSITORY}/git/ref/tags/"
            f"{urllib.parse.quote(resolution_tag, safe='')}"
        )
        if resolution_tag is not None
        else None
    )
    record_url = (
        (
            f"https://api.github.com/repos/{module.CONTROL_REPOSITORY}/contents/"
            f"continuity-successor-resolution.json?ref={resolution_commit}"
        )
        if resolution_tag is not None and resolution_commit is not None
        else None
    )
    qualification_url = (
        (
            f"https://api.github.com/repos/{module.CONTROL_REPOSITORY}/actions/runs/"
            f"{qualification['run_id']}/attempts/{qualification['run_attempt']}"
        )
        if (
            isinstance(qualification, dict)
            and isinstance(qualification.get("repository"), str)
            and type(qualification.get("run_id")) is int
            and type(qualification.get("run_attempt")) is int
        )
        else None
    )
    json_urls: list[str] = []
    bytes_urls: list[str] = []

    def read_json(url: Any, **kwargs: Any) -> Any:
        if not isinstance(url, str) or kwargs:
            raise ConformanceError(
                f"consumer used invalid JSON transport arguments for continuity authority: {url!r}"
            )
        json_urls.append(url)
        if url == registry_url:
            return [{"ref": f"refs/tags/{tag}"} for tag in resolution_tags]
        if tag_url is not None and url == tag_url:
            if resolution_commit is None:
                raise module.NotFound(
                    f"continuity resolution tag is absent: {resolution_tag}",
                    "plan-discovery",
                )
            return {"object": {"sha": resolution_commit, "type": "commit"}}
        if qualification_url is not None and url == qualification_url:
            return qualification_run
        raise ConformanceError(
            f"consumer queried an undeclared continuity JSON authority: {url}"
        )

    def read_bytes(url: Any, **kwargs: Any) -> bytes:
        if (
            not isinstance(url, str)
            or url != record_url
            or kwargs != {"accept": "application/vnd.github.raw+json"}
        ):
            raise ConformanceError(
                f"consumer queried an undeclared continuity record authority: {url!r}"
            )
        bytes_urls.append(url)
        return canonical_json(resolution)

    client.json.side_effect = read_json
    client.bytes.side_effect = read_bytes
    selected = module.resolve_continuity_successor_fork(client, interrupted, successors)
    required_json_urls = {registry_url, tag_url, qualification_url}
    missing_json_urls = {
        url for url in required_json_urls if url is not None and url not in json_urls
    }
    if missing_json_urls or record_url is None or record_url not in bytes_urls:
        raise ConformanceError(
            "consumer returned a continuity successor without reading every exact declared authority"
        )
    return selected


def expect_continuity_transport_rejection(
    module: ModuleType,
    action: Any,
    message: str,
) -> None:
    try:
        action()
    except ConformanceError:
        return
    except module.RecoveryError as error:
        raise ConformanceError(
            f"{message}; the focused mutant did not reach the strict transport"
        ) from error
    raise ConformanceError(message)


def assert_continuity_transport_mutants_rejected(
    module: ModuleType,
    interrupted: dict[str, Any],
    successors: list[dict[str, Any]],
    resolution: dict[str, Any],
    resolution_tag: str,
    resolution_commit: str,
    qualification_run: dict[str, Any],
) -> None:
    def exercise() -> str:
        return exercise_continuity_resolution(
            module,
            interrupted,
            successors,
            resolution,
            [resolution_tag],
            resolution_commit,
            qualification_run,
        )

    def wrong_registry_route(client: Any, interrupted_plan: str) -> list[str]:
        prefix = f"{module.CONTINUITY_RESOLUTION_TAG_PREFIX}{interrupted_plan}/"
        client.json(
            f"https://api.github.com/repos/{module.CONTROL_REPOSITORY}"
            f"/git/matching-refs/heads/{prefix}"
        )
        return [resolution_tag]

    with mock.patch.object(
        module,
        "list_continuity_resolution_tags",
        side_effect=wrong_registry_route,
    ):
        expect_continuity_transport_rejection(
            module,
            exercise,
            "shared conformance accepted a wrong continuity registry route",
        )

    resolve_tag = module.resolve_tag
    tag_mutants = (
        (
            lambda client, _repository, tag: resolve_tag(
                client,
                "durable-workflow/unrelated",
                tag,
            ),
            "shared conformance accepted a continuity tag lookup in the wrong repository",
        ),
        (
            lambda client, repository, _tag: resolve_tag(
                client,
                repository,
                "main",
            ),
            "shared conformance accepted a mutable continuity tag ref",
        ),
    )
    for mutant, message in tag_mutants:
        with mock.patch.object(module, "resolve_tag", side_effect=mutant):
            expect_continuity_transport_rejection(module, exercise, message)

    def record_ref_mutant(ref: str) -> Any:
        def read_record(
            client: Any,
            _tag: str,
            _commit: str,
            filename: str,
        ) -> Any:
            encoded_filename = urllib.parse.quote(filename, safe="/")
            raw = client.bytes(
                f"https://api.github.com/repos/{module.CONTROL_REPOSITORY}/contents/"
                f"{encoded_filename}?ref={ref}",
                accept="application/vnd.github.raw+json",
            )
            return json.loads(raw)

        return read_record

    record_ref_mutants = (
        (
            "main",
            "shared conformance accepted a mutable continuity record ref",
        ),
        (
            "e" * 40,
            "shared conformance accepted an unrelated continuity record ref",
        ),
    )
    for ref, message in record_ref_mutants:
        with mock.patch.object(
            module,
            "read_record",
            side_effect=record_ref_mutant(ref),
        ):
            expect_continuity_transport_rejection(module, exercise, message)

    def qualification_route_mutant(
        repository: str,
        run_id: int,
    ) -> Any:
        def validate(qualification: dict[str, Any], client: Any) -> dict[str, Any]:
            client.json(
                f"https://api.github.com/repos/{repository}/actions/runs/{run_id}"
                f"/attempts/{qualification['run_attempt']}"
            )
            return qualification

        return validate

    qualification = resolution["qualification"]
    qualification_route_mutants = (
        (
            "durable-workflow/unrelated",
            qualification["run_id"],
            "shared conformance accepted a qualification lookup in the wrong repository",
        ),
        (
            module.CONTROL_REPOSITORY,
            qualification["run_id"] + 1,
            "shared conformance accepted the wrong qualification run lookup",
        ),
    )
    for repository, run_id, message in qualification_route_mutants:
        with mock.patch.object(
            module,
            "validate_continuity_resolution_qualification",
            side_effect=qualification_route_mutant(repository, run_id),
        ):
            expect_continuity_transport_rejection(module, exercise, message)


def case_immutable_plan_enumeration(module: ModuleType) -> None:
    tags = ["release-plan/conformance-a", "release-plan/conformance-b"]
    client = mock.Mock()
    client.json.return_value = [{"ref": f"refs/tags/{tag}"} for tag in tags]
    if module.list_release_plan_tags(client) != tags:
        raise ConformanceError("consumer did not enumerate the complete immutable tag registry")
    client.json.return_value.append({"ref": f"refs/tags/{tags[0]}"})
    expect_recovery_error(
        module,
        lambda: module.list_release_plan_tags(client),
        "consumer accepted duplicate immutable plan authority",
    )
    client.json.return_value = []
    expect_recovery_error(
        module,
        lambda: module.list_release_plan_tags(client),
        "consumer accepted a missing immutable plan registry",
    )
    malformed_registry_entries = (
        None,
        {},
        {"ref": 7},
        {"ref": f"refs/heads/{tags[0]}"},
        {"ref": "refs/tags/release-plan/"},
        {"ref": "refs/tags/release-plan/Invalid"},
    )
    for malformed in malformed_registry_entries:
        client.json.return_value = [malformed]
        expect_recovery_error(
            module,
            lambda: module.list_release_plan_tags(client),
            f"consumer accepted malformed immutable plan authority: {malformed!r}",
        )


def case_current_plan_schema(module: ModuleType) -> None:
    if getattr(module, "SCHEMA", None) != CURRENT_PLAN_SCHEMA:
        raise ConformanceError("consumer does not accept the current release-plan schema")
    if getattr(module, "LEGACY_SCHEMA", None) != HISTORICAL_PLAN_SCHEMA:
        raise ConformanceError("consumer does not identify the historical release-plan schema")
    if getattr(module, "LEGACY_PLAN_DIGESTS", None) != EXPECTED_LEGACY_PLAN_DIGESTS:
        raise ConformanceError("consumer does not pin the exact historical release-plan authorities")

    current = plan(module, "current-schema-conformance")
    module.validate_plan(current)

    historical = legacy_beta_one_plan()
    if module.manifest_digest(historical) != "e1fc6e20c9d2ded0b5e7ac4d6be75ba861d31fc4b2db651dc0272dca623f2c7f":
        raise ConformanceError("shared historical release-plan fixture has an unexpected digest")
    module.validate_plan(historical)

    unrecorded = copy.deepcopy(historical)
    unrecorded["plan"] = "beta-1-replacement"
    expect_recovery_error(
        module,
        lambda: module.validate_plan(unrecorded),
        "consumer accepted an unrecorded historical release plan",
    )

    unsupported = copy.deepcopy(current)
    unsupported["schema"] = "durable-workflow.release-plan/v3"
    expect_recovery_error(
        module,
        lambda: module.validate_plan(unsupported),
        "consumer accepted an unsupported current release-plan schema",
    )


def case_completed_plan_lifecycle(module: ModuleType) -> None:
    completed = authority(module, plan(module, "completed-conformance"), "completed")
    with mock.patch.object(module, "classify_plan_authorities", return_value=[completed]):
        selected, snapshot = module.classify_implicit_plan_authority(mock.Mock())
    if selected != completed or snapshot != [completed]:
        raise ConformanceError("consumer did not select the completed current plan for verification")

    preparation = {
        "components": {
            "sdk-php": {
                "release_notes": {
                    "release_date": "2026-07-25",
                    "sha256": "c" * 64,
                    "source": {},
                }
            }
        }
    }
    completed["preparation"] = preparation
    implicit_authority = {
        **completed,
        "selection": "implicit",
        "authority_snapshot": [completed],
    }
    component = module.COMPONENTS["sdk-php"]
    with (
        mock.patch.object(module, "verify_plan_authority", return_value=({}, {})),
        mock.patch.object(module, "validate_release_preparation"),
        mock.patch.object(module, "resolve_tag", return_value=None),
        mock.patch.object(
            module,
            "classify_implicit_plan_authority",
            return_value=(completed, [completed]),
        ),
        mock.patch.object(
            module,
            "continuity_authority_snapshot",
            return_value={
                "accepted": {"tag": None, "commit": None},
                "resumed": {"tag": None, "commit": None},
            },
            create=True,
        ),
        mock.patch.object(
            module,
            "scheduled_continuity_pause",
            return_value=None,
            create=True,
        ),
        mock.patch.dict(
            module.VERIFIERS,
            {component.distribution: mock.Mock(side_effect=module.NotFound("not published"))},
        ),
    ):
        expect_recovery_error(
            module,
            lambda: module.resolve_component(
                mock.Mock(),
                "sdk-php",
                completed["tag"],
                completed["commit"],
                completed["plan"],
                preparation,
                implicit_authority,
            ),
            "consumer returned publication-ready for an implicitly selected completed plan",
        )


def supersession_pair(module: ModuleType) -> tuple[dict[str, Any], dict[str, Any]]:
    predecessor_plan = plan(module, "superseded-conformance")
    successor_plan = copy.deepcopy(predecessor_plan)
    successor_plan["plan"] = "successor-conformance"
    predecessor_plan["components"]["server"]["version"] = "3.0.0"
    predecessor_plan["components"]["cli"]["version"] = "3.0.1"
    successor_plan["components"]["server"]["version"] = "3.0.1"
    successor_plan["components"]["cli"]["version"] = "3.0.0"
    successor = authority(module, successor_plan, "actionable")
    predecessor = authority(
        module,
        predecessor_plan,
        "superseded",
        {
            "tag": successor["tag"],
            "sha256": module.manifest_digest(successor_plan),
            "plan": successor_plan,
        },
    )
    predecessor["commit"] = "b" * 40
    predecessor["recorded_at"] = dt.datetime(2026, 7, 24, tzinfo=dt.UTC)
    return predecessor, successor


def case_superseded_plan_lifecycle(module: ModuleType) -> None:
    predecessor, successor = supersession_pair(module)
    selected = module.current_product_train_authorities([predecessor, successor])
    if [item["tag"] for item in selected] != [successor["tag"]]:
        raise ConformanceError("consumer did not resolve a superseded plan to its successor")


def case_exact_successor_identity(module: ModuleType) -> None:
    predecessor, successor = supersession_pair(module)
    predecessor["successor"] = {**predecessor["successor"], "sha256": "0" * 64}
    expect_recovery_error(
        module,
        lambda: module.current_product_train_authorities([predecessor, successor]),
        "consumer accepted an inexact successor digest",
    )

    predecessor, successor = supersession_pair(module)
    predecessor["successor"] = {
        **predecessor["successor"],
        "tag": "release-plan/wrong-successor-conformance",
    }
    expect_recovery_error(
        module,
        lambda: module.current_product_train_authorities([predecessor, successor]),
        "consumer accepted an inexact successor tag",
    )

    predecessor, successor = supersession_pair(module)
    mismatched_plan = copy.deepcopy(successor["plan"])
    mismatched_plan["components"]["sdk-rust"]["commit"] = "c" * 40
    predecessor["successor"] = {
        **predecessor["successor"],
        "plan": mismatched_plan,
    }
    expect_recovery_error(
        module,
        lambda: module.current_product_train_authorities([predecessor, successor]),
        "consumer accepted an inexact successor plan document",
    )


def case_malformed_authority_rejection(module: ModuleType) -> None:
    for malformed in ("01.0.0", "1.0.0-alpha.01", "1.0.0-alpha..1", 100):
        candidate = plan(module, "malformed-conformance")
        candidate["components"]["server"]["version"] = malformed
        expect_recovery_error(
            module,
            lambda candidate=candidate: module.validate_plan(candidate),
            f"consumer accepted malformed authority value: {malformed!r}",
        )


def case_continuity_ambiguity_rejection(module: ModuleType) -> None:
    interrupted, successors, resolution, qualification_run = continuity_resolution_fixture(module)
    resolution_tag = continuity_resolution_tag(module, interrupted, resolution)
    expected_selected = resolution["selected_successor"]["tag"]

    for ordering in (successors, list(reversed(successors))):
        selected = exercise_continuity_resolution(
            module,
            interrupted,
            ordering,
            resolution,
            [resolution_tag],
            "f" * 40,
            qualification_run,
        )
        if selected != expected_selected:
            raise ConformanceError(
                "consumer did not select the exact digest-bound continuity successor independent of enumeration order"
            )

    assert_continuity_transport_mutants_rejected(
        module,
        interrupted,
        successors,
        resolution,
        resolution_tag,
        "f" * 40,
        qualification_run,
    )

    absent_authorities = (
        ([], "f" * 40, "consumer accepted continuity successors without a resolution authority"),
        (
            [resolution_tag],
            None,
            "consumer accepted a continuity resolution authority without an immutable record",
        ),
        (
            [
                resolution_tag,
                (
                    f"{module.CONTINUITY_RESOLUTION_TAG_PREFIX}{interrupted['plan']['plan']}/"
                    f"{'e' * 64}"
                ),
            ],
            "f" * 40,
            "consumer accepted multiple continuity resolution authorities",
        ),
    )
    for resolution_tags, resolution_commit, message in absent_authorities:
        expect_recovery_error(
            module,
            lambda resolution_tags=resolution_tags, resolution_commit=resolution_commit: exercise_continuity_resolution(
                module,
                interrupted,
                successors,
                resolution,
                resolution_tags,
                resolution_commit,
                qualification_run,
            ),
            message,
        )

    malformed_records: tuple[Any, ...] = (
        None,
        {**resolution, "unexpected": True},
    )
    for malformed in malformed_records:
        malformed_tag = (
            resolution_tag
            if not isinstance(malformed, dict)
            else continuity_resolution_tag(module, interrupted, malformed)
        )
        expect_recovery_error(
            module,
            lambda malformed=malformed, malformed_tag=malformed_tag: exercise_continuity_resolution(
                module,
                interrupted,
                successors,
                malformed,
                [malformed_tag],
                "f" * 40,
                qualification_run,
            ),
            "consumer accepted a malformed continuity resolution record",
        )

    interruption_mismatch = copy.deepcopy(resolution)
    interruption_mismatch["interruption"]["plan"]["commit"] = "e" * 40
    claim_set_mismatch = copy.deepcopy(resolution)
    claim_set_mismatch["successor_claims"][0]["acceptance"]["sha256"] = "e" * 64
    selected_outside_claim_set = copy.deepcopy(resolution)
    selected_outside_claim_set["selected_successor"] = {
        "tag": "release-plan/outside-claim-set",
        "commit": "e" * 40,
        "sha256": "e" * 64,
    }
    invalid_qualification = copy.deepcopy(resolution)
    invalid_qualification["qualification"]["repository"] = "durable-workflow/untrusted"
    semantic_mismatches = (
        (interruption_mismatch, "consumer accepted a continuity resolution for another interruption"),
        (claim_set_mismatch, "consumer accepted a continuity resolution for another claim set"),
        (selected_outside_claim_set, "consumer accepted a successor outside the exact claim set"),
        (invalid_qualification, "consumer accepted an invalid qualification identity"),
    )
    for mismatched, message in semantic_mismatches:
        mismatched_tag = continuity_resolution_tag(module, interrupted, mismatched)
        expect_recovery_error(
            module,
            lambda mismatched=mismatched, mismatched_tag=mismatched_tag: exercise_continuity_resolution(
                module,
                interrupted,
                successors,
                mismatched,
                [mismatched_tag],
                "f" * 40,
                qualification_run,
            ),
            message,
        )

    digest_mismatch_tag = (
        f"{module.CONTINUITY_RESOLUTION_TAG_PREFIX}{interrupted['plan']['plan']}/"
        f"{'0' * 64}"
    )
    expect_recovery_error(
        module,
        lambda: exercise_continuity_resolution(
            module,
            interrupted,
            successors,
            resolution,
            [digest_mismatch_tag],
            "f" * 40,
            qualification_run,
        ),
        "consumer accepted a continuity resolution with the wrong immutable digest",
    )

    mismatched_run = {**qualification_run, "head_sha": "8" * 40}
    expect_recovery_error(
        module,
        lambda: exercise_continuity_resolution(
            module,
            interrupted,
            successors,
            resolution,
            [resolution_tag],
            "f" * 40,
            mismatched_run,
        ),
        "consumer accepted qualification evidence for another source identity",
    )


def case_explicit_terminal_plan_rejection(module: ModuleType) -> None:
    candidate = plan(module, "terminal-conformance")
    completed = authority(module, candidate, "completed")
    with mock.patch.object(module, "classify_plan_authorities", return_value=[completed]):
        selected = module.select_explicit_plan_authority(
            mock.Mock(),
            completed["tag"],
            completed["commit"],
            candidate,
            None,
        )
    if selected != {**completed, "selection": "explicit"}:
        raise ConformanceError("consumer did not select an explicitly requested completed plan")

    superseded = authority(module, candidate, "superseded")
    with mock.patch.object(module, "classify_plan_authorities", return_value=[superseded]):
        expect_recovery_error(
            module,
            lambda: module.select_explicit_plan_authority(
                mock.Mock(),
                superseded["tag"],
                superseded["commit"],
                candidate,
                None,
            ),
            "consumer accepted an explicitly selected superseded plan",
        )


def case_bounded_authority_convergence(module: ModuleType) -> None:
    candidate = authority(module, plan(module, "convergence-conformance"), "actionable")
    with (
        mock.patch.object(
            module,
            "classify_implicit_plan_authority",
            return_value=(candidate, [candidate]),
        ) as classify,
        mock.patch.object(
            module,
            "implicit_plan_authority_converged",
            return_value=False,
        ) as converged,
    ):
        expect_recovery_error(
            module,
            lambda: module.select_implicit_plan_authority(mock.Mock()),
            "consumer did not fail closed after bounded authority churn",
        )
    expected = module.IMPLICIT_AUTHORITY_MAX_ATTEMPTS
    if classify.call_count != expected or converged.call_count != expected:
        raise ConformanceError("consumer did not enforce the declared convergence attempt bound")


def case_release_candidate_beta_qualification(module: ModuleType) -> None:
    candidate = plan(module, "release-candidate-conformance")
    candidate["channel"] = "rc"
    for identity in candidate["components"].values():
        identity["version"] = "2.0.0-rc.1"
    module.validate_plan(candidate)

    beta_components = copy.deepcopy(candidate["components"])
    for identity in beta_components.values():
        identity["version"] = "2.0.0-beta.21"
    record = {
        "schema": "durable-workflow.beta-authorization/v1",
        "channel": "beta",
        "candidate": "coherent-beta-qualification",
        "components": beta_components,
    }
    candidate["beta_authorization"]["tag"] = "beta-authorization/coherent-beta-qualification"
    if not module.beta_authorization_matches_plan(candidate, candidate["beta_authorization"], record):
        raise ConformanceError("consumer rejected coherent beta qualification for a release-candidate plan")

    record["components"]["server"]["version"] = "2.0.0-rc.1"
    if module.beta_authorization_matches_plan(candidate, candidate["beta_authorization"], record):
        raise ConformanceError("consumer accepted non-beta qualification for a release-candidate plan")


def aggregate_rc_plan(module: ModuleType) -> dict[str, Any]:
    candidate = plan(module, "authoritative-rc-conformance")
    candidate["channel"] = "rc"
    for identity in candidate["components"].values():
        identity["version"] = "2.0.0-rc.5"
    candidate["foundation"] = {
        "tag": f"beta-candidate/rc-{candidate['plan']}",
        "commit": "e" * 40,
    }
    candidate["beta_authorization"] = None
    return candidate


def aggregate_rc_verification(module: ModuleType, candidate: dict[str, Any]) -> dict[str, Any]:
    foundation = {
        "schema": "durable-workflow.beta-candidate/v2",
        "candidate": f"rc-{candidate['plan']}",
        "components": candidate["components"],
    }
    return {
        "schema": "durable-workflow.beta-candidate-verification/v2",
        "candidate": foundation["candidate"],
        "manifest_sha256": module.manifest_digest(foundation),
        "outcome": "verified",
        "components": {
            name: {
                "version": identity["version"],
                "commit": identity["commit"],
                "outcome": "verified",
            }
            for name, identity in candidate["components"].items()
        },
    }


def case_authoritative_rc_foundation(module: ModuleType) -> None:
    candidate = aggregate_rc_plan(module)
    module.validate_plan(candidate)

    malformed_tag = copy.deepcopy(candidate)
    malformed_tag["foundation"]["tag"] = "beta-candidate/rc-substitution"
    expect_recovery_error(
        module,
        lambda: module.validate_plan(malformed_tag),
        "consumer accepted an aggregate foundation for a different release plan",
    )

    malformed_commit = copy.deepcopy(candidate)
    malformed_commit["foundation"]["commit"] = "not-a-commit"
    expect_recovery_error(
        module,
        lambda: module.validate_plan(malformed_commit),
        "consumer accepted a malformed aggregate foundation commit",
    )

    unapproved = copy.deepcopy(candidate)
    unapproved["beta_authorization"] = {
        "tag": f"beta-authorization/{candidate['plan']}",
        "commit": "f" * 40,
    }
    expect_recovery_error(
        module,
        lambda: module.validate_plan(unapproved),
        "consumer accepted conflicting authority for an aggregate foundation",
    )

    foundation = {
        "schema": "durable-workflow.beta-candidate/v2",
        "candidate": f"rc-{candidate['plan']}",
        "components": candidate["components"],
    }
    verification = aggregate_rc_verification(module, candidate)

    class FoundationAccepted(RuntimeError):
        pass

    with (
        mock.patch.object(
            module,
            "resolve_tag",
            return_value=candidate["foundation"]["commit"],
        ),
        mock.patch.object(
            module,
            "read_record",
            side_effect=(foundation, verification),
        ),
        mock.patch.object(
            module,
            "load_recovery_workflow_authority",
            side_effect=FoundationAccepted,
        ),
    ):
        try:
            module.verify_plan_authority(mock.Mock(), candidate)
        except FoundationAccepted:
            pass
        else:
            raise ConformanceError(
                "consumer did not continue after verifying the exact aggregate foundation"
            )

    with (
        mock.patch.object(module, "resolve_tag", return_value="d" * 40),
        mock.patch.object(module, "read_record") as read_record,
    ):
        expect_recovery_error(
            module,
            lambda: module.verify_plan_authority(mock.Mock(), candidate),
            "consumer accepted a moved aggregate foundation tag",
        )
        read_record.assert_not_called()

    substituted_foundation = copy.deepcopy(foundation)
    substituted_foundation["components"]["server"]["commit"] = "d" * 40
    with (
        mock.patch.object(
            module,
            "resolve_tag",
            return_value=candidate["foundation"]["commit"],
        ),
        mock.patch.object(
            module,
            "read_record",
            return_value=substituted_foundation,
        ),
    ):
        expect_recovery_error(
            module,
            lambda: module.verify_plan_authority(mock.Mock(), candidate),
            "consumer accepted a substituted aggregate component tuple",
        )

    malformed_verification = copy.deepcopy(verification)
    malformed_verification["components"]["server"]["outcome"] = "failed"
    with (
        mock.patch.object(
            module,
            "resolve_tag",
            return_value=candidate["foundation"]["commit"],
        ),
        mock.patch.object(
            module,
            "read_record",
            side_effect=(foundation, malformed_verification),
        ),
    ):
        expect_recovery_error(
            module,
            lambda: module.verify_plan_authority(mock.Mock(), candidate),
            "consumer accepted aggregate foundation evidence that was not verified",
        )


def case_scheduled_empty_no_op(module: ModuleType) -> None:
    with tempfile.TemporaryDirectory() as directory:
        root = Path(directory)
        evidence = root / "release-recovery-evidence.json"
        github_output = root / "github-output"
        component = next(iter(module.COMPONENTS))
        arguments = [
            "component-release-recovery.py",
            "resolve",
            "--component",
            component,
            "--plan-output",
            str(root / "release-plan.json"),
            "--preparation-output",
            str(root / "release-preparation.json"),
            "--evidence",
            str(evidence),
            "--github-output",
            str(github_output),
            "--allow-empty",
        ]
        with (
            mock.patch.object(sys, "argv", arguments),
            mock.patch.object(
                module,
                "discover_plan",
                side_effect=module.RecoveryError(
                    "no public release plan is available",
                    "plan-discovery",
                ),
            ),
        ):
            result = module.main()

        state = json.loads(evidence.read_bytes())
        if (
            result != 0
            or state.get("phase") != "plan-discovery"
            or state.get("outcome") != "no-op"
            or github_output.read_text(encoding="utf-8") != "action=none\n"
        ):
            raise ConformanceError(
                "scheduled recovery without eligible work did not record a neutral no-op"
            )

        failure_evidence = root / "release-recovery-failure-evidence.json"
        failure_output = root / "failure-github-output"
        failure_arguments = [
            *arguments[: arguments.index("--evidence")],
            "--evidence",
            str(failure_evidence),
            "--github-output",
            str(failure_output),
            "--allow-empty",
        ]
        with (
            mock.patch.object(sys, "argv", failure_arguments),
            mock.patch.object(
                module,
                "discover_plan",
                side_effect=module.RecoveryError(
                    "release plan registry is malformed",
                    "plan-discovery",
                ),
            ),
        ):
            failure_result = module.main()

        failure_state = json.loads(failure_evidence.read_bytes())
        if (
            failure_result != 1
            or failure_state.get("outcome") != "failed"
            or failure_output.exists()
        ):
            raise ConformanceError(
                "scheduled empty handling weakened unrelated plan-discovery failures"
            )


def certificate_failure() -> urllib.error.URLError:
    return urllib.error.URLError(
        ssl.SSLCertVerificationError(
            1,
            "certificate verify failed: self-signed certificate",
        )
    )


def case_trusted_github_api_transport(module: ModuleType) -> None:
    url = "https://api.github.com/repos/durable-workflow/.github/releases"
    sleeps: list[float] = []
    client = module.PublicClient(
        max_attempts=2,
        retry_base_seconds=1,
        sleep=sleeps.append,
    )
    with mock.patch.object(
        module.urllib.request,
        "urlopen",
        side_effect=(certificate_failure(), io.BytesIO(b"[]")),
    ) as open_url:
        result = client.json(url)
    context = open_url.call_args.kwargs.get("context")
    if (
        result != []
        or sleeps != [1]
        or context is not client.ssl_context
        or not context.check_hostname
        or context.verify_mode != ssl.CERT_REQUIRED
    ):
        raise ConformanceError(
            "consumer did not retry transient certificate failure through verified default trust"
        )

    persistent = module.PublicClient(
        max_attempts=2,
        retry_base_seconds=1,
        sleep=lambda _delay: None,
    )
    with mock.patch.object(
        module.urllib.request,
        "urlopen",
        side_effect=certificate_failure(),
    ) as open_url:
        try:
            persistent.json(url)
        except module.PublicInfrastructureError as error:
            expected = {
                "classification": "github-read-transient",
                "endpoint_class": "releases-api",
                "attempts": 2,
                "reason": "retry-exhausted",
                "failure": "transport=tls-certificate-verification",
            }
            if getattr(error, "evidence", None) != expected:
                raise ConformanceError(
                    "persistent certificate failure did not retain structured transport evidence"
                ) from error
        else:
            raise ConformanceError("persistent certificate failure did not fail closed")
    if open_url.call_count != 2:
        raise ConformanceError("persistent certificate failure escaped the retry bound")

    api_error = urllib.error.HTTPError(
        url,
        422,
        "unprocessable entity",
        {},
        io.BytesIO(b'{"message":"invalid release authority"}'),
    )

    def fail_on_sleep(_delay: float) -> None:
        raise ConformanceError("deterministic API failure was retried")

    deterministic = module.PublicClient(max_attempts=3, sleep=fail_on_sleep)
    with mock.patch.object(
        module.urllib.request,
        "urlopen",
        side_effect=api_error,
    ) as open_url:
        expect_recovery_error(
            module,
            lambda: deterministic.json(url),
            "ordinary GitHub API failure was accepted",
        )
    if open_url.call_count != 1:
        raise ConformanceError("ordinary GitHub API failure was retried")


def case_transport_fail_closed_publication(module: ModuleType) -> None:
    with tempfile.TemporaryDirectory() as directory:
        root = Path(directory)
        evidence = root / "release-recovery-evidence.json"
        github_output = root / "github-output"
        arguments = [
            "component-release-recovery.py",
            "resolve",
            "--component",
            "workflow",
            "--plan-output",
            str(root / "release-plan.json"),
            "--preparation-output",
            str(root / "release-preparation.json"),
            "--evidence",
            str(evidence),
            "--github-output",
            str(github_output),
            "--allow-empty",
        ]
        unavailable = module.PublicInfrastructureError(
            "releases-api",
            5,
            reason="retry-exhausted",
            failure="transport=tls-certificate-verification",
        )
        with (
            mock.patch.object(sys, "argv", arguments),
            mock.patch.object(module, "discover_plan", side_effect=unavailable),
        ):
            result = module.main()
        state = json.loads(evidence.read_bytes())
        if (
            result != module.INFRASTRUCTURE_EXIT_CODE
            or state.get("phase") != "runner-transport"
            or state.get("outcome") != "runner-transport"
            or state.get("transport") != unavailable.evidence
            or github_output.read_text(encoding="utf-8") != "action=none\n"
        ):
            raise ConformanceError(
                "persistent transport failure did not suppress publication with runner evidence"
            )

    candidate = plan(module, "authorized-publication-conformance")
    preparation = {
        "components": {
            "workflow": {
                "release_notes": {
                    "release_date": "2026-08-12",
                    "sha256": "a" * 64,
                    "source": {},
                }
            }
        }
    }
    component = module.COMPONENTS["workflow"]
    verifier = mock.Mock(side_effect=module.NotFound("not published"))
    authority = mock.Mock(return_value=({}, {}))
    with (
        mock.patch.object(module, "verify_plan_authority", authority),
        mock.patch.object(module, "validate_release_preparation"),
        mock.patch.object(
            module,
            "source_product_train_evidence",
            return_value={},
            create=True,
        ),
        mock.patch.object(module, "resolve_tag", return_value=None),
        mock.patch.dict(module.VERIFIERS, {component.distribution: verifier}),
    ):
        state, outputs = module.resolve_component(
            mock.Mock(),
            "workflow",
            f"release-plan/{candidate['plan']}",
            "b" * 40,
            candidate,
            preparation,
        )
    if (
        outputs.get("action") != "publish"
        or state.get("outcome") != "ready"
        or authority.call_count != 1
        or verifier.call_count != 1
    ):
        raise ConformanceError(
            "authorized publication path did not remain available after transport hardening"
        )


CASE_RUNNERS = {
    "immutable-plan-enumeration": case_immutable_plan_enumeration,
    "current-plan-schema": case_current_plan_schema,
    "completed-plan-lifecycle": case_completed_plan_lifecycle,
    "superseded-plan-lifecycle": case_superseded_plan_lifecycle,
    "exact-successor-identity": case_exact_successor_identity,
    "malformed-authority-rejection": case_malformed_authority_rejection,
    "continuity-ambiguity-rejection": case_continuity_ambiguity_rejection,
    "explicit-terminal-plan-rejection": case_explicit_terminal_plan_rejection,
    "bounded-authority-convergence": case_bounded_authority_convergence,
    "release-candidate-beta-qualification": case_release_candidate_beta_qualification,
    "authoritative-rc-foundation": case_authoritative_rc_foundation,
    "scheduled-empty-no-op": case_scheduled_empty_no_op,
    "trusted-github-api-transport": case_trusted_github_api_transport,
    "transport-fail-closed-publication": case_transport_fail_closed_publication,
}


def run_cases(module: ModuleType) -> tuple[list[dict[str, str]], list[str]]:
    results: list[dict[str, str]] = []
    failures: list[str] = []
    for case_id in REQUIRED_CASES:
        try:
            CASE_RUNNERS[case_id](module)
        except Exception as error:
            results.append({"id": case_id, "status": "fail"})
            failures.append(f"{case_id}: {error}")
        else:
            results.append({"id": case_id, "status": "pass"})
    return results, failures


def run_distribution(command: list[str], repository_root: Path) -> tuple[dict[str, Any], str | None]:
    resolved = [sys.executable if item == "{python}" else item for item in command]
    result = subprocess.run(resolved, cwd=repository_root, check=False)
    evidence = {
        "command": resolved,
        "status": "pass" if result.returncode == 0 else "fail",
    }
    failure = None
    if result.returncode != 0:
        failure = f"distribution verification exited with status {result.returncode}"
    return evidence, failure


def fetch_public(url: str) -> bytes:
    request = urllib.request.Request(
        url,
        headers={"Accept": "application/vnd.github.raw+json", "User-Agent": "release-recovery-conformance"},
    )
    try:
        with urllib.request.urlopen(request, timeout=20) as response:
            return response.read()
    except (OSError, urllib.error.HTTPError) as error:
        raise ConformanceError(f"cannot read public conformance target: {url}") from error


def audit_public_targets(contract: dict[str, Any], contract_raw: bytes) -> list[dict[str, str]]:
    results: list[dict[str, str]] = []
    for consumer in contract["consumers"]:
        repository = consumer["repository"].removeprefix("durable-workflow/")
        branch = consumer["target_branch"]
        base = f"https://raw.githubusercontent.com/durable-workflow/{repository}/{branch}"
        remote_contract = fetch_public(f"{base}/scripts/ci/release-recovery-consumer-contract.json")
        remote_adapter_raw = fetch_public(f"{base}/scripts/ci/release-recovery-consumer-adapter.json")
        remote_suite = fetch_public(f"{base}/scripts/ci/release_recovery_consumer_conformance.py")
        try:
            remote_adapter = json.loads(remote_adapter_raw)
        except json.JSONDecodeError as error:
            raise ConformanceError(f"{consumer['component']} adapter is not valid JSON") from error
        if remote_contract != contract_raw:
            raise ConformanceError(f"{consumer['component']} does not carry the current shared contract")
        if not isinstance(remote_adapter, dict):
            raise ConformanceError(f"{consumer['component']} adapter is not a JSON object")
        expected_identity = {
            "component": consumer["component"],
            "repository": consumer["repository"],
            "target_branch": consumer["target_branch"],
        }
        if any(remote_adapter.get(field) != value for field, value in expected_identity.items()):
            raise ConformanceError(f"{consumer['component']} adapter has the wrong target identity")
        if remote_adapter.get("contract") != {
            "path": "scripts/ci/release-recovery-consumer-contract.json",
            "sha256": sha256_bytes(contract_raw),
            "version": contract["version"],
        }:
            raise ConformanceError(f"{consumer['component']} adapter does not pin the current contract")
        if sha256_bytes(remote_suite) != contract["suite"]["sha256"]:
            raise ConformanceError(f"{consumer['component']} does not carry the current shared suite")
        results.append(
            {
                "component": consumer["component"],
                "status": "pass",
                "target_branch": consumer["target_branch"],
            }
        )
    return results


def source_commit(repository_root: Path) -> str:
    github_sha = os.environ.get("GITHUB_SHA", "")
    if COMMIT_PATTERN.fullmatch(github_sha):
        return github_sha
    result = subprocess.run(
        ["git", "rev-parse", "HEAD"],
        cwd=repository_root,
        check=False,
        capture_output=True,
        text=True,
    )
    commit = result.stdout.strip()
    return commit if result.returncode == 0 and COMMIT_PATTERN.fullmatch(commit) else "unknown"


def write_evidence(path: Path, evidence: dict[str, Any]) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_bytes(canonical_json(evidence))


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser()
    parser.add_argument("--contract", required=True, type=Path)
    parser.add_argument("--adapter", type=Path)
    parser.add_argument("--evidence", type=Path)
    parser.add_argument("--previous-ref")
    parser.add_argument("--shared-only", action="store_true")
    parser.add_argument("--audit-public-targets", action="store_true")
    return parser.parse_args()


def main() -> int:
    args = parse_args()
    suite_path = Path(__file__).resolve()
    contract_path = args.contract.resolve()
    repository_root = Path.cwd().resolve()
    contract, contract_raw = load_json_object(contract_path, "shared contract")
    contract_sha256 = validate_contract(contract, contract_raw, suite_path)
    previous = previous_contract(repository_root, contract_path, args.previous_ref)
    require_versioned_contract_change(previous, contract)

    if args.audit_public_targets:
        targets = audit_public_targets(contract, contract_raw)
        evidence = {
            "schema": EVIDENCE_SCHEMA,
            "contract": {
                "sha256": contract_sha256,
                "suite_sha256": contract["suite"]["sha256"],
                "version": contract["version"],
            },
            "generated_at": dt.datetime.now(dt.UTC).isoformat(),
            "outcome": "pass",
            "source_commit": source_commit(repository_root),
            "targets": targets,
        }
        if args.evidence is not None:
            write_evidence(args.evidence, evidence)
        print(f"release-recovery consumer contract {contract['version']} passed for {len(targets)} public targets")
        return 0

    if args.adapter is None:
        print(f"release-recovery consumer contract {contract['version']} is valid ({contract_sha256})")
        return 0

    adapter, _adapter_raw = load_json_object(args.adapter.resolve(), "consumer adapter")
    consumer_path, distribution_command = validate_adapter(
        adapter,
        contract,
        contract_sha256,
        repository_root,
        suite_path,
        contract_path,
    )
    module = load_consumer(consumer_path)
    cases, failures = run_cases(module)
    distribution: dict[str, Any] = {"command": distribution_command, "status": "not-run"}
    if not failures and not args.shared_only:
        distribution, distribution_failure = run_distribution(distribution_command, repository_root)
        if distribution_failure is not None:
            failures.append(distribution_failure)
    evidence = {
        "schema": EVIDENCE_SCHEMA,
        "component": adapter["component"],
        "contract": {
            "sha256": contract_sha256,
            "suite_sha256": contract["suite"]["sha256"],
            "version": contract["version"],
        },
        "cases": cases,
        "distribution_verification": distribution,
        "generated_at": dt.datetime.now(dt.UTC).isoformat(),
        "outcome": "fail" if failures else "pass",
        "repository": adapter["repository"],
        "source_commit": source_commit(repository_root),
        "target_branch": adapter["target_branch"],
    }
    if failures:
        evidence["failures"] = failures
    if args.evidence is not None:
        write_evidence(args.evidence, evidence)
    if failures:
        for failure in failures:
            print(f"FAIL: {failure}", file=sys.stderr)
        return 1
    print(
        f"{adapter['component']} satisfies release-recovery consumer contract {contract['version']} ({contract_sha256})"
    )
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
