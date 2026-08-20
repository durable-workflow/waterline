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
PRERELEASE = re.compile(r"^2\.0\.0-(?:alpha|beta|rc)\.[1-9][0-9]*$")


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
    if not isinstance(value, str) or PRERELEASE.fullmatch(value) is None:
        raise IdentityError(f"{label} must be an exact supported 2.0 prerelease")
    return value


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


def locked_version(lock: Mapping[str, Any], package: str) -> str:
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
    return exact_version(
        matches[0].get("version"), f"standalone Composer lock {package} version"
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
    candidate_dependencies = {
        name: candidate[name] for name in ("workflow", "sdk-php")
    }
    published_dependencies = {
        name: published[name] for name in ("workflow", "sdk-php")
    }
    if candidate_dependencies != published_dependencies:
        raise IdentityError(
            "candidate source dependency pins do not match the current public "
            f"dependency selection: expected {published_dependencies}, "
            f"observed {candidate_dependencies}"
        )

    standalone_sdk = required_version(
        standalone, "require", SDK_PACKAGE, "standalone/composer.json"
    )
    if standalone_sdk != published["sdk-php"]:
        raise IdentityError(
            "standalone service PHP SDK identity does not match the current public "
            "dependency selection"
        )
    if locked_version(lock, SDK_PACKAGE) != published["sdk-php"]:
        raise IdentityError(
            "standalone service lock PHP SDK identity does not match the current "
            "public dependency selection"
        )

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
        f"Verified {subject} with current public dependencies Workflow "
        f"{published['workflow']} and PHP SDK {published['sdk-php']}; current "
        f"published Waterline is {published['waterline']}"
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
