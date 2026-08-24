#!/usr/bin/env python3
"""Resolve the current qualified Waterline prerelease onboarding tuple."""

from __future__ import annotations

import argparse
import hashlib
import json
import re
import sys
import urllib.request
from dataclasses import dataclass
from typing import Any, Callable, Mapping, Sequence


AUTHORITY_URL = (
    "https://durable-workflow.com/public-component-release-qualifications.json"
)
AUTHORITY_SCHEMA = "durable-workflow.docs.public-component-release-qualifications"
ASSET_SCHEMA = "durable-workflow.waterline.release-qualification-evidence"
QUALIFICATION_SCHEMA = "durable-workflow.exact-current-composer-qualification/v1"
SURFACES_SCHEMA = "durable-workflow.waterline-release-surfaces/v1"
REPOSITORY = "durable-workflow/waterline"
REPOSITORY_URL = f"https://github.com/{REPOSITORY}"
WORKFLOW_NAME = "Release Docs Audit"
WORKFLOW_PATH = ".github/workflows/release-docs-audit.yml"
SERVICE_IMAGE = "durableworkflow/waterline"
MAX_BYTES = 1024 * 1024
VERSION = re.compile(r"^2\.0\.0-rc\.[1-9][0-9]*$")
SHA = re.compile(r"^[0-9a-f]{40}$")
DIGEST = re.compile(r"^sha256:[0-9a-f]{64}$")
ASSET_NAME = re.compile(
    r"^waterline-exact-composer-qualification-([1-9][0-9]*)-"
    r"([1-9][0-9]*)\.json$"
)


class ResolutionError(RuntimeError):
    """The public qualification authority cannot select a safe tuple."""


@dataclass(frozen=True)
class QualifiedPrerelease:
    version: str
    packages: Mapping[str, str]
    image: str
    image_digest: str
    qualification_url: str
    qualification_digest: str

    def public_value(self) -> dict[str, object]:
        return {
            "schema": "durable-workflow.waterline.onboarding-prerelease/v1",
            "authority": AUTHORITY_URL,
            "release": self.version,
            "packages": dict(self.packages),
            "service_image": {
                "tag": self.image,
                "digest": self.image_digest,
                "pull": f"{SERVICE_IMAGE}@{self.image_digest}",
            },
            "qualification": {
                "url": self.qualification_url,
                "digest": self.qualification_digest,
            },
        }


def require_mapping(value: object, label: str) -> Mapping[str, Any]:
    if not isinstance(value, dict):
        raise ResolutionError(f"{label} must be an object")
    return value


def require_string(value: object, label: str) -> str:
    if not isinstance(value, str) or not value:
        raise ResolutionError(f"{label} must be a non-empty string")
    return value


def require_positive_integer(value: object, label: str) -> int:
    if type(value) is not int or value < 1:
        raise ResolutionError(f"{label} must be a positive integer")
    return value


def decode_json(source: bytes, label: str) -> Mapping[str, Any]:
    if len(source) > MAX_BYTES:
        raise ResolutionError(f"{label} exceeds the 1 MiB safety bound")
    try:
        return require_mapping(json.loads(source), label)
    except (UnicodeDecodeError, json.JSONDecodeError) as error:
        raise ResolutionError(f"{label} is not valid JSON: {error}") from error


def public_bytes(url: str) -> bytes:
    request = urllib.request.Request(
        url,
        headers={
            "Accept": "application/json",
            "User-Agent": "durable-workflow-waterline-onboarding",
        },
    )
    try:
        with urllib.request.urlopen(request, timeout=20) as response:
            source = response.read(MAX_BYTES + 1)
    except OSError as error:
        raise ResolutionError(f"unable to read {url}: {error}") from error
    if len(source) > MAX_BYTES:
        raise ResolutionError(f"{url} exceeds the 1 MiB safety bound")
    return source


def current_record(authority: Mapping[str, Any]) -> Mapping[str, Any]:
    if (
        authority.get("schema") != AUTHORITY_SCHEMA
        or authority.get("schema_version") != 1
        or authority.get("outcome") != "pass"
    ):
        raise ResolutionError("public qualification authority is not passing schema v1")

    current_id = require_string(
        authority.get("current_qualification_id"),
        "current qualification id",
    )
    release = require_mapping(authority.get("current_release"), "current release")
    version = require_string(release.get("version"), "current release version")
    if (
        release.get("artifact") != "waterline"
        or VERSION.fullmatch(version) is None
        or current_id != f"waterline-{version}-composer"
    ):
        raise ResolutionError("current release is not a Waterline 2.0 RC")

    records = authority.get("qualifications")
    if not isinstance(records, list):
        raise ResolutionError("public qualification records must be an array")
    matches = [
        require_mapping(record, "qualification record")
        for record in records
        if isinstance(record, dict) and record.get("id") == current_id
    ]
    if len(matches) != 1:
        raise ResolutionError("current qualification id must select exactly one record")

    record = matches[0]
    component = require_mapping(record.get("component"), "qualified component")
    if (
        record.get("evidence_role") != "current"
        or component.get("artifact") != "waterline"
        or component.get("version") != version
    ):
        raise ResolutionError("current qualification does not bind the current release")
    return record


def qualified_packages(record: Mapping[str, Any]) -> dict[str, str]:
    qualification = require_mapping(
        record.get("qualification"), "exact Composer qualification"
    )
    packages = require_mapping(qualification.get("packages"), "qualified packages")
    expected = {"sdk-php", "waterline", "workflow"}
    if (
        qualification.get("schema") != QUALIFICATION_SCHEMA
        or qualification.get("outcome") != "pass"
        or set(packages) != expected
    ):
        raise ResolutionError("current exact Composer qualification is not passing")

    normalized = {
        name: require_string(packages.get(name), f"qualified {name} version")
        for name in sorted(expected)
    }
    if any(VERSION.fullmatch(version) is None for version in normalized.values()):
        raise ResolutionError(
            "qualified packages must use exact Waterline 2.0 RC identities"
        )
    return normalized


def qualification_asset(
    record: Mapping[str, Any],
) -> tuple[str, str, Mapping[str, Any]]:
    source = require_mapping(record.get("source"), "qualification source")
    run = require_mapping(source.get("workflow_run"), "qualification workflow run")
    artifact = require_mapping(source.get("artifact"), "qualification artifact")
    release_tag = require_string(source.get("release_tag"), "qualification release tag")
    release_commit = require_string(
        source.get("release_commit"), "qualification release commit"
    )
    run_id = require_positive_integer(run.get("run_id"), "qualification run id")
    run_attempt = require_positive_integer(
        run.get("run_attempt"), "qualification run attempt"
    )
    require_positive_integer(artifact.get("artifact_id"), "qualification artifact id")
    name = require_string(artifact.get("name"), "qualification asset name")
    match = ASSET_NAME.fullmatch(name)
    expected_run_url = f"{REPOSITORY_URL}/actions/runs/{run_id}"
    if (
        source.get("repository_url") != REPOSITORY_URL
        or VERSION.fullmatch(release_tag) is None
        or SHA.fullmatch(release_commit) is None
        or run.get("name") != WORKFLOW_NAME
        or run.get("path") != WORKFLOW_PATH
        or run.get("event") != "repository_dispatch"
        or run.get("run_url") != expected_run_url
        or SHA.fullmatch(str(run.get("head_sha", ""))) is None
        or run.get("run_conclusion") != "success"
        or run.get("qualification_outcome") != "pass"
        or match is None
        or int(match.group(1)) != run_id
        or int(match.group(2)) != run_attempt
    ):
        raise ResolutionError(
            "qualification source identity is incomplete or inconsistent"
        )

    url = require_string(artifact.get("url"), "qualification asset URL")
    expected_url = f"{REPOSITORY_URL}/releases/download/{release_tag}/{name}"
    digest = require_string(artifact.get("digest"), "qualification asset digest")
    if url != expected_url or DIGEST.fullmatch(digest) is None:
        raise ResolutionError("qualification asset URL or digest is not immutable")
    return url, digest, source


def validate_asset(
    asset: Mapping[str, Any],
    source: Mapping[str, Any],
    packages: Mapping[str, str],
) -> tuple[str, str]:
    release_tag = require_string(source.get("release_tag"), "qualification release tag")
    release_commit = require_string(
        source.get("release_commit"), "qualification release commit"
    )
    source_run = require_mapping(
        source.get("workflow_run"), "qualification workflow run"
    )
    run = require_mapping(asset.get("workflow_run"), "asset workflow run")
    release = require_mapping(asset.get("release"), "asset release")
    qualification = require_mapping(asset.get("qualification"), "asset qualification")
    asset_packages = require_mapping(
        qualification.get("packages"), "asset qualified packages"
    )
    surfaces = require_mapping(asset.get("release_surfaces"), "asset release surfaces")
    image = require_mapping(surfaces.get("service_image"), "qualified service image")

    run_fields = (
        "name",
        "path",
        "event",
        "run_id",
        "run_attempt",
        "run_url",
        "head_sha",
    )
    if (
        asset.get("schema") != ASSET_SCHEMA
        or asset.get("schema_version") != 2
        or asset.get("repository") != REPOSITORY
        or any(run.get(field) != source_run.get(field) for field in run_fields)
        or release.get("tag") != release_tag
        or release.get("source_commit") != release_commit
        or release_tag != packages.get("waterline")
        or qualification.get("schema") != QUALIFICATION_SCHEMA
        or qualification.get("outcome") != "pass"
        or dict(asset_packages) != dict(packages)
        or surfaces.get("schema") != SURFACES_SCHEMA
        or surfaces.get("outcome") != "verified"
        or surfaces.get("version") != release_tag
        or surfaces.get("source_commit") != release_commit
    ):
        raise ResolutionError(
            "qualification asset does not bind the selected release tuple"
        )

    qualified_image = f"docker.io/{SERVICE_IMAGE}:{release_tag}"
    digest = require_string(image.get("digest"), "qualified image digest")
    if (
        image.get("kind") != "oci"
        or image.get("image") != qualified_image
        or DIGEST.fullmatch(digest) is None
        or image.get("platforms") != ["linux/amd64", "linux/arm64"]
    ):
        raise ResolutionError(
            "qualification asset does not bind the required service image"
        )
    return qualified_image, digest


def resolve(fetch: Callable[[str], bytes] = public_bytes) -> QualifiedPrerelease:
    authority = decode_json(fetch(AUTHORITY_URL), "public qualification authority")
    record = current_record(authority)
    packages = qualified_packages(record)
    component = require_mapping(record.get("component"), "qualified component")
    version = require_string(component.get("version"), "qualified Waterline version")
    if packages["waterline"] != version:
        raise ResolutionError("qualified Waterline package does not match its release")

    asset_url, asset_digest, source = qualification_asset(record)
    asset_source = fetch(asset_url)
    observed_digest = f"sha256:{hashlib.sha256(asset_source).hexdigest()}"
    if observed_digest != asset_digest:
        raise ResolutionError("qualification asset digest does not match its authority")
    asset = decode_json(asset_source, "qualification asset")
    image, image_digest = validate_asset(asset, source, packages)
    return QualifiedPrerelease(
        version=version,
        packages=packages,
        image=image,
        image_digest=image_digest,
        qualification_url=asset_url,
        qualification_digest=asset_digest,
    )


def main(arguments: Sequence[str] | None = None) -> int:
    parser = argparse.ArgumentParser(
        description="Resolve the retained, qualified Waterline prerelease tuple."
    )
    parser.add_argument(
        "format",
        choices=("image", "json"),
        help="Print the immutable service image reference or the full tuple.",
    )
    args = parser.parse_args(arguments)
    selected = resolve()
    if args.format == "image":
        print(f"{SERVICE_IMAGE}@{selected.image_digest}")
    else:
        print(json.dumps(selected.public_value(), indent=2, sort_keys=True))
    return 0


if __name__ == "__main__":
    try:
        raise SystemExit(main())
    except ResolutionError as error:
        print(f"Waterline prerelease resolution failed: {error}", file=sys.stderr)
        raise SystemExit(1) from error
