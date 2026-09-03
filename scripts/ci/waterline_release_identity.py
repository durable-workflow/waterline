#!/usr/bin/env python3
"""Validate candidate source separately from current published artifacts."""

from __future__ import annotations

import argparse
import hashlib
import json
import re
import sys
from pathlib import Path
from typing import Any, Mapping, Sequence


SCHEMA = "durable-workflow.waterline-current-product-tuple/v1"
PACKAGE = "durable-workflow/waterline"
SDK_PACKAGE = "durable-workflow/sdk"
WORKFLOW_PACKAGE = "durable-workflow/workflow"
SUPPORTED_2_0 = re.compile(
    r"^2\.0\.(?P<patch>0|[1-9][0-9]*)(?:-(?P<stage>alpha|beta|rc)\.(?P<number>[1-9][0-9]*))?$"
)
PUBLIC_COMMIT = re.compile(r"^[0-9a-f]{40}$")


class IdentityError(RuntimeError):
    """The Waterline source or published dependency selection is invalid."""


def load_json(path: Path, label: str) -> tuple[dict[str, Any], bytes]:
    try:
        raw = path.read_bytes()
        value = json.loads(raw)
    except (OSError, json.JSONDecodeError) as error:
        raise IdentityError(f"cannot load {label} {path}: {error}") from error
    if len(raw) > 1024 * 1024 or not isinstance(value, dict):
        raise IdentityError(f"{label} must be a JSON object no larger than 1 MiB")
    return value, raw


def exact_version(value: object, label: str) -> str:
    if not isinstance(value, str) or SUPPORTED_2_0.fullmatch(value) is None:
        raise IdentityError(f"{label} must be an exact supported 2.0 release")
    return value


def version_order(value: str) -> tuple[int, int, int]:
    match = SUPPORTED_2_0.fullmatch(value)
    if match is None:
        raise IdentityError(f"cannot order unsupported release {value!r}")
    stage = match.group("stage")
    stage_order = {"alpha": 0, "beta": 1, "rc": 2, None: 3}
    return (
        int(match.group("patch")),
        stage_order[stage],
        int(match.group("number") or 0),
    )


def approved_versions(approved: Mapping[str, Any]) -> dict[str, str]:
    if set(approved) != {"schema", "versions"} or approved.get("schema") != SCHEMA:
        raise IdentityError("approved current product tuple has an invalid shape")
    versions = approved.get("versions")
    if not isinstance(versions, dict) or set(versions) != {
        "waterline",
        "workflow",
        "sdk-php",
    }:
        raise IdentityError(
            "approved current product tuple lacks exact component identities"
        )
    return {
        name: exact_version(versions[name], f"approved {name} version")
        for name in ("waterline", "workflow", "sdk-php")
    }


def required_version(
    manifest: Mapping[str, Any], table: str, package: str, label: str
) -> str:
    requirements = manifest.get(table)
    requirement = requirements.get(package) if isinstance(requirements, dict) else None
    return exact_version(requirement, f"{label} {table}.{package}")


def locked_package(lock: Mapping[str, Any], package: str) -> Mapping[str, Any]:
    packages = lock.get("packages")
    if not isinstance(packages, list):
        raise IdentityError("standalone Composer lock packages must be a list")
    matches = [
        candidate
        for candidate in packages
        if isinstance(candidate, dict) and candidate.get("name") == package
    ]
    if len(matches) != 1:
        raise IdentityError(
            f"standalone Composer lock must contain exactly one {package}"
        )
    return matches[0]


def locked_version(lock: Mapping[str, Any], package: str) -> str:
    candidate = locked_package(lock, package)
    return exact_version(
        candidate.get("version"), f"standalone Composer lock {package} version"
    )


def require_locked_public_route(lock: Mapping[str, Any], package: str) -> None:
    candidate = locked_package(lock, package)
    source = candidate.get("source")
    dist = candidate.get("dist")
    reference = source.get("reference") if isinstance(source, dict) else None
    if not isinstance(reference, str) or PUBLIC_COMMIT.fullmatch(reference) is None:
        raise IdentityError(
            f"standalone Composer lock {package} must use an immutable public "
            "release route"
        )
    expected_source = {
        "type": "git",
        "url": "https://github.com/durable-workflow/sdk-php.git",
        "reference": reference,
    }
    expected_dist = {
        "type": "zip",
        "url": (
            "https://api.github.com/repos/durable-workflow/sdk-php/zipball/"
            f"{reference}"
        ),
        "reference": reference,
        "shasum": "",
    }
    if source != expected_source or dist != expected_dist:
        raise IdentityError(
            f"standalone Composer lock {package} must use an immutable public "
            "release route"
        )


def declared_waterline_version(manifest: Mapping[str, Any]) -> str:
    extra = manifest.get("extra")
    durable = extra.get("durable-workflow") if isinstance(extra, dict) else None
    version = durable.get("product-train") if isinstance(durable, dict) else None
    return exact_version(version, "release source Waterline version")


def validate(
    approved: Mapping[str, Any],
    manifest: Mapping[str, Any],
    manifest_raw: bytes,
    standalone: Mapping[str, Any],
    standalone_raw: bytes,
    lock: Mapping[str, Any],
    lock_raw: bytes,
    *,
    release_version: str | None = None,
) -> dict[str, Any]:
    published = approved_versions(approved)
    if manifest.get("name") != PACKAGE:
        raise IdentityError(f"release source composer.json must identify {PACKAGE}")

    candidate = {
        "waterline": declared_waterline_version(manifest),
        "workflow": required_version(
            manifest, "require-dev", WORKFLOW_PACKAGE, "release source composer.json"
        ),
        "sdk-php": required_version(
            manifest, "require-dev", SDK_PACKAGE, "release source composer.json"
        ),
    }
    for name in ("workflow", "sdk-php"):
        if version_order(candidate[name]) < version_order(published[name]):
            raise IdentityError(
                f"candidate source {name} dependency {candidate[name]} precedes "
                f"the current public dependency {published[name]}"
            )

    standalone_sdk = required_version(
        standalone, "require", SDK_PACKAGE, "standalone/composer.json"
    )
    if standalone_sdk != candidate["sdk-php"]:
        raise IdentityError(
            "standalone service PHP SDK identity does not match the candidate "
            "source dependency selection"
        )
    locked_sdk = locked_version(lock, SDK_PACKAGE)
    if locked_sdk != candidate["sdk-php"]:
        raise IdentityError(
            "standalone service lock PHP SDK identity does not match the candidate "
            "source dependency selection"
        )
    require_locked_public_route(lock, SDK_PACKAGE)

    normalized_release = release_version.removeprefix("v") if release_version else None
    if normalized_release is not None and normalized_release != candidate["waterline"]:
        raise IdentityError(
            f"release tag {release_version!r} does not match source-declared Waterline "
            f"identity {candidate['waterline']!r}"
        )

    return {
        "schema": "durable-workflow.waterline-source-identity-qualification/v2",
        "outcome": "verified",
        "current_public_artifacts": published,
        "candidate_source": candidate,
        "release_tag": normalized_release,
        "source_sha256": {
            "composer": hashlib.sha256(manifest_raw).hexdigest(),
            "standalone_composer": hashlib.sha256(standalone_raw).hexdigest(),
            "standalone_lock": hashlib.sha256(lock_raw).hexdigest(),
        },
    }


def main(arguments: Sequence[str] | None = None) -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--approved-tuple", required=True, type=Path)
    parser.add_argument("--waterline-composer", required=True, type=Path)
    parser.add_argument("--standalone-composer", required=True, type=Path)
    parser.add_argument("--standalone-lock", required=True, type=Path)
    mode = parser.add_mutually_exclusive_group(required=True)
    mode.add_argument("--candidate-source", action="store_true")
    mode.add_argument("--release-version")
    parser.add_argument("--evidence", required=True, type=Path)
    args = parser.parse_args(arguments)

    approved, _ = load_json(args.approved_tuple, "approved current product tuple")
    manifest, manifest_raw = load_json(args.waterline_composer, "Waterline manifest")
    standalone, standalone_raw = load_json(
        args.standalone_composer, "standalone service manifest"
    )
    lock, lock_raw = load_json(args.standalone_lock, "standalone service lock")
    evidence = validate(
        approved,
        manifest,
        manifest_raw,
        standalone,
        standalone_raw,
        lock,
        lock_raw,
        release_version=args.release_version,
    )
    args.evidence.write_text(
        json.dumps(evidence, indent=2, sort_keys=True, ensure_ascii=True) + "\n",
        encoding="utf-8",
    )
    published = evidence["current_public_artifacts"]
    candidate = evidence["candidate_source"]
    subject = (
        f"release tag {evidence['release_tag']}"
        if evidence["release_tag"] is not None
        else f"candidate Waterline source {candidate['waterline']}"
    )
    print(
        f"Verified {subject} with candidate dependencies Workflow "
        f"{candidate['workflow']} and PHP SDK {candidate['sdk-php']}; current "
        f"public tuple is Waterline {published['waterline']}, Workflow "
        f"{published['workflow']}, and PHP SDK {published['sdk-php']}"
    )
    return 0


if __name__ == "__main__":
    try:
        raise SystemExit(main())
    except IdentityError as error:
        print(
            f"Waterline release identity qualification failed: {error}", file=sys.stderr
        )
        raise SystemExit(1)
