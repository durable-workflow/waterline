#!/usr/bin/env python3
"""Decide whether an immutable Waterline service image needs recovery."""

from __future__ import annotations

import argparse
import datetime as dt
import hashlib
import json
import os
import re
import sys
import time
import urllib.error
import urllib.parse
import urllib.request
from collections.abc import Mapping
from pathlib import Path
from typing import Any

SCHEMA = "durable-workflow.waterline.service-image-recovery/v1"
PLAN_SCHEMA = "durable-workflow.release-plan/v2"
LEGACY_PLAN_SCHEMA = "durable-workflow.release-plan/v1"
CANDIDATE_SCHEMA = "durable-workflow.beta-candidate/v2"
CANDIDATE_VERIFICATION_SCHEMA = "durable-workflow.beta-candidate-verification/v2"
LEGACY_PLAN_DIGESTS = frozenset(
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
RECOVERY_SCHEMA = "durable-workflow.component-release-recovery/v1"
CONTROL_REPOSITORY = "durable-workflow/.github"
WATERLINE_REPOSITORY = "durable-workflow/waterline"
PROTECTED_REF = "refs/heads/v2"
PROTECTED_BRANCH = "v2"
IMAGE_REPOSITORY = "durableworkflow/waterline"
IMAGE_REGISTRY = "https://registry-1.docker.io"
IMAGE_REFERENCE = f"docker.io/{IMAGE_REPOSITORY}"
REQUIRED_PLATFORMS = ("linux/amd64", "linux/arm64")
FOUNDATION = {
    "tag": "beta-candidate/beta-continuity-foundation",
    "commit": "4995052410bd4301c5796ffba54e0b6d2f490ed1",
}
COMPONENTS = {
    "workflow",
    "waterline",
    "server",
    "cli",
    "sdk-php",
    "sdk-python",
    "sdk-rust",
}
COMMIT_PATTERN = re.compile(r"^[0-9a-f]{40}$")
DIGEST_PATTERN = re.compile(r"^sha256:[0-9a-f]{64}$")
PLAN_PATTERN = re.compile(r"^[a-z0-9][a-z0-9._-]{0,55}$")
VERSION_PATTERN = re.compile(
    r"^[0-9]+\.[0-9]+\.[0-9]+(?:[-+][0-9A-Za-z][0-9A-Za-z.-]*)?$"
)
WATERLINE_VERSION_PATTERN = re.compile(r"^2\.0\.0-(?:alpha|beta|rc)\.[1-9][0-9]*$")
RC_VERSION_PATTERN = re.compile(r"^2\.0\.0-rc\.[1-9][0-9]*$")
VERIFIED_AT_PATTERN = re.compile(
    r"^[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}Z$"
)
MANIFEST_ACCEPT = ", ".join(
    (
        "application/vnd.oci.image.index.v1+json",
        "application/vnd.docker.distribution.manifest.list.v2+json",
        "application/vnd.oci.image.manifest.v1+json",
        "application/vnd.docker.distribution.manifest.v2+json",
    )
)


class RecoveryError(RuntimeError):
    """The service image cannot be recovered safely."""

    def __init__(self, message: str, phase: str = "preflight") -> None:
        super().__init__(message)
        self.phase = phase


class NotFound(RecoveryError):
    """One public resource is absent."""


class ImageNotFound(RecoveryError):
    """The exact top-level Docker Hub image tag is absent."""


class PublicClient:
    """Small public HTTP client with explicit response-byte access."""

    def __init__(self, github_token: str = "") -> None:
        self.github_token = github_token

    def bytes(
        self,
        url: str,
        *,
        accept: str | None = None,
        headers: Mapping[str, str] | None = None,
    ) -> tuple[bytes, dict[str, str]]:
        request_headers = {
            "User-Agent": "durable-workflow-waterline-service-image-recovery",
        }
        if accept:
            request_headers["Accept"] = accept
        if url.startswith("https://api.github.com/") and self.github_token:
            request_headers["Authorization"] = f"Bearer {self.github_token}"
            request_headers["X-GitHub-Api-Version"] = "2022-11-28"
        if headers:
            request_headers.update(headers)
        request = urllib.request.Request(url, headers=request_headers)
        try:
            with urllib.request.urlopen(request, timeout=30) as response:
                return response.read(), {
                    name.lower(): value for name, value in response.headers.items()
                }
        except urllib.error.HTTPError as error:
            if error.code == 404:
                raise NotFound(f"public resource is absent: {url}") from error
            raise RecoveryError(
                f"public request failed with HTTP {error.code}: {url}", "public-read"
            ) from error
        except urllib.error.URLError as error:
            raise RecoveryError(
                f"public request failed: {url}: {error.reason}", "public-read"
            ) from error

    def json(
        self,
        url: str,
        *,
        accept: str | None = "application/vnd.github+json",
        headers: Mapping[str, str] | None = None,
    ) -> Any:
        raw, _ = self.bytes(url, accept=accept, headers=headers)
        return decode_json(raw, url)


def decode_json(raw: bytes, source: str) -> Any:
    try:
        return json.loads(raw)
    except (UnicodeDecodeError, json.JSONDecodeError) as error:
        raise RecoveryError(
            f"public JSON is malformed: {source}", "public-read"
        ) from error


def canonical_json(value: Any) -> bytes:
    return (
        json.dumps(value, indent=2, sort_keys=True, ensure_ascii=True) + "\n"
    ).encode()


def sha256_digest(raw: bytes) -> str:
    return f"sha256:{hashlib.sha256(raw).hexdigest()}"


def manifest_digest(value: Any) -> str:
    return hashlib.sha256(canonical_json(value)).hexdigest()


def require_commit(value: Any, field: str) -> str:
    if not isinstance(value, str) or not COMMIT_PATTERN.fullmatch(value):
        raise RecoveryError(f"{field} must be an exact lowercase source commit")
    return value


def require_digest(value: Any, field: str) -> str:
    if not isinstance(value, str) or not DIGEST_PATTERN.fullmatch(value):
        raise RecoveryError(
            f"{field} must be an exact SHA-256 digest", "registry-inspection"
        )
    return value


def read_json(path: Path, label: str) -> Any:
    try:
        return json.loads(path.read_bytes())
    except (OSError, UnicodeDecodeError, json.JSONDecodeError) as error:
        raise RecoveryError(f"{label} is not readable JSON: {error}") from error


def validate_plan(
    plan: Any,
    recovery: Any,
    plan_tag: str,
    plan_commit: str,
) -> tuple[str, str]:
    if not isinstance(plan, dict) or set(plan) != {
        "schema",
        "plan",
        "channel",
        "foundation",
        "components",
        "beta_authorization",
    }:
        raise RecoveryError("release plan has an invalid top-level shape")
    if plan.get("schema") not in {PLAN_SCHEMA, LEGACY_PLAN_SCHEMA}:
        raise RecoveryError("release plan has an unsupported schema")
    if (
        plan["schema"] == LEGACY_PLAN_SCHEMA
        and hashlib.sha256(canonical_json(plan)).hexdigest()
        not in LEGACY_PLAN_DIGESTS
    ):
        raise RecoveryError(
            "legacy release plan is not an exact recorded historical contract"
        )
    plan_name = plan.get("plan")
    if not isinstance(plan_name, str) or not PLAN_PATTERN.fullmatch(plan_name):
        raise RecoveryError("release plan has an invalid identity")
    if plan_tag != f"release-plan/{plan_name}":
        raise RecoveryError(
            "release plan tag does not match the immutable plan identity"
        )
    require_commit(plan_commit, "release plan commit")
    channel = plan.get("channel")
    if channel not in {"alpha", "beta", "rc"}:
        raise RecoveryError("release plan has an invalid channel")
    foundation = plan.get("foundation")
    aggregate_rc_foundation = (
        channel == "rc"
        and isinstance(foundation, dict)
        and set(foundation) == {"tag", "commit"}
        and foundation.get("tag") == f"beta-candidate/rc-{plan_name}"
        and COMMIT_PATTERN.fullmatch(str(foundation.get("commit", ""))) is not None
    )
    if foundation != FOUNDATION and not aggregate_rc_foundation:
        raise RecoveryError(
            "release plan does not name its proven immutable candidate foundation"
        )

    components = plan.get("components")
    if not isinstance(components, dict) or set(components) != COMPONENTS:
        raise RecoveryError(
            "release plan does not contain the complete component tuple"
        )
    for name, identity in components.items():
        if (
            not isinstance(identity, dict)
            or set(identity) != {"version", "commit"}
            or not isinstance(identity.get("version"), str)
            or not VERSION_PATTERN.fullmatch(identity["version"])
        ):
            raise RecoveryError(
                f"release plan component {name} has an invalid identity"
            )
        require_commit(identity["commit"], f"release plan component {name} commit")
        if aggregate_rc_foundation and not RC_VERSION_PATTERN.fullmatch(
            identity["version"]
        ):
            raise RecoveryError(
                f"release plan component {name} does not have an exact 2.0.0-rc.N identity"
            )

    identity = components["waterline"]
    version = identity["version"]
    source_commit = identity["commit"]
    if (
        not WATERLINE_VERSION_PATTERN.fullmatch(version)
        or f"-{channel}." not in version
    ):
        raise RecoveryError("release plan names an invalid Waterline 2.x version")

    authorization = plan.get("beta_authorization")
    if channel == "alpha" and authorization is not None:
        raise RecoveryError("alpha release plan unexpectedly has beta authorization")
    if aggregate_rc_foundation and authorization is not None:
        raise RecoveryError(
            "aggregate release-candidate plan cannot claim beta qualification"
        )
    if (
        channel in {"beta", "rc"}
        and not aggregate_rc_foundation
        and (
            not isinstance(authorization, dict)
            or set(authorization) != {"tag", "commit"}
            or not isinstance(authorization.get("tag"), str)
            or not authorization["tag"].startswith("beta-authorization/")
            or not COMMIT_PATTERN.fullmatch(str(authorization.get("commit", "")))
        )
    ):
        raise RecoveryError("prerelease plan lacks exact immutable beta qualification")

    expected_recovery = {
        "schema": RECOVERY_SCHEMA,
        "component": "waterline",
        "release_plan_tag": plan_tag,
        "plan": plan_name,
        "channel": channel,
        "plan_record_commit": plan_commit,
        "phase": "complete",
        "outcome": "verified",
        "declared_identity": identity,
        "source_tag": {"status": "present", "commit": source_commit},
    }
    if not isinstance(recovery, dict):
        raise RecoveryError("source release recovery evidence is malformed")
    for field, expected in expected_recovery.items():
        if recovery.get(field) != expected:
            raise RecoveryError(
                f"source release recovery evidence has a mismatched {field}"
            )
    public_evidence = recovery.get("public_evidence")
    if (
        not isinstance(public_evidence, dict)
        or public_evidence.get("version") != version
        or public_evidence.get("commit") != source_commit
        or not isinstance(public_evidence.get("distribution"), dict)
        or not isinstance(public_evidence.get("github_release"), dict)
    ):
        raise RecoveryError("source release recovery evidence is incomplete")
    return version, source_commit


def resolve_tag(client: Any, repository: str, tag: str) -> str:
    encoded = urllib.parse.quote(tag, safe="")
    try:
        ref = client.json(
            f"https://api.github.com/repos/{repository}/git/ref/tags/{encoded}"
        )
    except NotFound as error:
        raise RecoveryError(
            f"required immutable tag is absent: {repository}@{tag}"
        ) from error
    target = ref.get("object") if isinstance(ref, dict) else None
    seen: set[str] = set()
    while isinstance(target, dict) and target.get("type") == "tag":
        sha = target.get("sha")
        if not isinstance(sha, str) or sha in seen:
            raise RecoveryError(
                f"tag has an invalid annotated chain: {repository}@{tag}"
            )
        seen.add(sha)
        record = client.json(
            f"https://api.github.com/repos/{repository}/git/tags/{sha}"
        )
        target = record.get("object") if isinstance(record, dict) else None
    if (
        not isinstance(target, dict)
        or target.get("type") != "commit"
        or not isinstance(target.get("sha"), str)
        or not COMMIT_PATTERN.fullmatch(target["sha"])
    ):
        raise RecoveryError(
            f"tag does not resolve to an exact commit: {repository}@{tag}"
        )
    return str(target["sha"])


def read_foundation_record(
    client: Any,
    commit: str,
    filename: str,
) -> Any:
    encoded_filename = urllib.parse.quote(filename, safe="/")
    raw, _ = client.bytes(
        f"https://api.github.com/repos/{CONTROL_REPOSITORY}/contents/"
        f"{encoded_filename}?ref={commit}",
        accept="application/vnd.github.raw+json",
    )
    return decode_json(raw, f"{commit}:{filename}")


def verify_foundation_authority(
    client: Any,
    plan: dict[str, Any],
) -> dict[str, Any]:
    identity = plan["foundation"]
    if identity == FOUNDATION:
        return {
            "kind": "legacy-beta-continuity",
            "tag": identity["tag"],
            "commit": identity["commit"],
        }

    if resolve_tag(client, CONTROL_REPOSITORY, identity["tag"]) != identity["commit"]:
        raise RecoveryError(
            "aggregate candidate foundation tag does not match its pinned commit"
        )
    candidate = read_foundation_record(client, identity["commit"], "candidate.json")
    expected_candidate = {
        "schema": CANDIDATE_SCHEMA,
        "candidate": f"rc-{plan['plan']}",
        "components": plan["components"],
    }
    if candidate != expected_candidate:
        raise RecoveryError(
            "aggregate release-candidate foundation names a different exact tuple"
        )

    verification = read_foundation_record(
        client,
        identity["commit"],
        "verification.json",
    )
    verification_components = (
        verification.get("components") if isinstance(verification, dict) else None
    )
    if (
        not isinstance(verification, dict)
        or set(verification)
        != {
            "schema",
            "candidate",
            "manifest_sha256",
            "verified_at",
            "outcome",
            "components",
        }
        or verification.get("schema") != CANDIDATE_VERIFICATION_SCHEMA
        or verification.get("candidate") != candidate["candidate"]
        or verification.get("manifest_sha256") != manifest_digest(candidate)
        or verification.get("outcome") != "verified"
        or not isinstance(verification.get("verified_at"), str)
        or VERIFIED_AT_PATTERN.fullmatch(verification["verified_at"]) is None
        or not isinstance(verification_components, dict)
        or set(verification_components) != COMPONENTS
        or any(
            not isinstance(result, dict) for result in verification_components.values()
        )
        or any(
            result.get("version") != plan["components"][name]["version"]
            or result.get("commit") != plan["components"][name]["commit"]
            or result.get("outcome") != "verified"
            for name, result in verification_components.items()
        )
    ):
        raise RecoveryError(
            "aggregate release-candidate foundation lacks exact verification evidence"
        )

    return {
        "kind": "aggregate-release-candidate",
        "tag": identity["tag"],
        "commit": identity["commit"],
        "candidate": candidate["candidate"],
        "manifest_sha256": manifest_digest(candidate),
        "verification_sha256": manifest_digest(verification),
        "verified_at": verification["verified_at"],
        "outcome": verification["outcome"],
    }


def validate_public_authority(
    client: Any,
    plan: dict[str, Any],
    plan_tag: str,
    plan_commit: str,
    version: str,
    source_commit: str,
    *,
    github_server_url: str,
    github_repository: str,
    github_ref: str,
    github_sha: str,
    github_event_name: str,
) -> tuple[dict[str, Any], dict[str, Any]]:
    if github_server_url != "https://github.com":
        raise RecoveryError(
            "service image recovery is restricted to GitHub", "protected-source"
        )
    if github_repository != WATERLINE_REPOSITORY:
        raise RecoveryError(
            "recovery was requested from an unexpected repository", "protected-source"
        )
    if github_ref != PROTECTED_REF:
        raise RecoveryError(
            "recovery must run from the protected v2 branch", "protected-source"
        )
    require_commit(github_sha, "protected workflow commit")
    if github_event_name not in {"schedule", "workflow_dispatch"}:
        raise RecoveryError(
            "recovery event is not scheduled or manual", "protected-source"
        )

    repository = client.json(f"https://api.github.com/repos/{WATERLINE_REPOSITORY}")
    if (
        not isinstance(repository, dict)
        or repository.get("default_branch") != PROTECTED_BRANCH
    ):
        raise RecoveryError(
            "Waterline does not expose v2 as its protected default branch",
            "protected-source",
        )
    branch = client.json(
        f"https://api.github.com/repos/{WATERLINE_REPOSITORY}/branches/{PROTECTED_BRANCH}"
    )
    if (
        not isinstance(branch, dict)
        or branch.get("name") != PROTECTED_BRANCH
        or branch.get("protected") is not True
        or not isinstance(branch.get("commit"), dict)
        or branch["commit"].get("sha") != github_sha
    ):
        raise RecoveryError(
            "recovery workflow commit is not the current protected v2 head",
            "protected-source",
        )

    if resolve_tag(client, CONTROL_REPOSITORY, plan_tag) != plan_commit:
        raise RecoveryError("release plan tag moved away from its verified commit")
    encoded_plan_tag = urllib.parse.quote(plan_tag, safe="")
    plan_release = client.json(
        f"https://api.github.com/repos/{CONTROL_REPOSITORY}/releases/tags/{encoded_plan_tag}"
    )
    if (
        not isinstance(plan_release, dict)
        or plan_release.get("tag_name") != plan_tag
        or plan_release.get("draft") is True
    ):
        raise RecoveryError("release plan is not an immutable public GitHub Release")
    public_plan_raw, _ = client.bytes(
        f"https://api.github.com/repos/{CONTROL_REPOSITORY}/contents/release-plan.json"
        f"?ref={plan_commit}",
        accept="application/vnd.github.raw+json",
    )
    public_plan = decode_json(public_plan_raw, f"{plan_tag}:release-plan.json")
    if public_plan != plan:
        raise RecoveryError(
            "release plan handoff differs from immutable public Git authority"
        )
    foundation = verify_foundation_authority(client, plan)
    if resolve_tag(client, WATERLINE_REPOSITORY, version) != source_commit:
        raise RecoveryError(
            "Waterline source tag does not resolve to the planned source commit"
        )

    return (
        {
            "repository": github_repository,
            "server_url": github_server_url,
            "ref": github_ref,
            "commit": github_sha,
            "event": github_event_name,
            "branch_protected": True,
        },
        foundation,
    )


def registry_token(client: Any) -> str:
    scope = urllib.parse.quote(f"repository:{IMAGE_REPOSITORY}:pull")
    payload = client.json(
        f"https://auth.docker.io/token?service=registry.docker.io&scope={scope}",
        accept="application/json",
    )
    token = payload.get("token") if isinstance(payload, dict) else None
    if not isinstance(token, str) or not token:
        raise RecoveryError(
            "Docker Hub did not grant anonymous public pull access",
            "registry-inspection",
        )
    return token


def registry_bytes(
    client: Any,
    url: str,
    token: str,
    *,
    accept: str,
) -> tuple[bytes, Mapping[str, str]]:
    return client.bytes(
        url,
        accept=accept,
        headers={"Authorization": f"Bearer {token}"},
    )


def verified_manifest(
    client: Any,
    url: str,
    token: str,
    expected_digest: str | None = None,
) -> tuple[dict[str, Any], str]:
    raw, headers = registry_bytes(client, url, token, accept=MANIFEST_ACCEPT)
    header_digest = require_digest(
        headers.get("docker-content-digest"), "Docker Hub manifest digest"
    )
    calculated_digest = sha256_digest(raw)
    if header_digest != calculated_digest:
        raise RecoveryError(
            "Docker Hub manifest digest does not match the returned manifest bytes",
            "registry-inspection",
        )
    if expected_digest is not None and header_digest != expected_digest:
        raise RecoveryError(
            "Docker Hub platform digest does not match the immutable index descriptor",
            "registry-inspection",
        )
    value = decode_json(raw, url)
    if not isinstance(value, dict):
        raise RecoveryError(
            "Docker Hub manifest is not a JSON object", "registry-inspection"
        )
    return value, header_digest


def inspect_service_image(
    client: Any,
    version: str,
    source_commit: str,
) -> dict[str, Any]:
    token = registry_token(client)
    manifest_url = (
        f"{IMAGE_REGISTRY}/v2/{IMAGE_REPOSITORY}/manifests/"
        f"{urllib.parse.quote(version, safe='')}"
    )
    try:
        manifest, digest = verified_manifest(client, manifest_url, token)
    except NotFound as error:
        raise ImageNotFound(
            f"Docker Hub image is absent: {IMAGE_REFERENCE}:{version}",
            "registry-inspection",
        ) from error
    descriptors = manifest.get("manifests")
    if not isinstance(descriptors, list):
        raise RecoveryError(
            "Docker Hub image is not a multi-platform index", "registry-inspection"
        )

    selected: dict[str, dict[str, Any]] = {}
    for descriptor in descriptors:
        if not isinstance(descriptor, dict) or not isinstance(
            descriptor.get("platform"), dict
        ):
            continue
        platform = descriptor["platform"]
        label = f"{platform.get('os')}/{platform.get('architecture')}"
        if label not in REQUIRED_PLATFORMS:
            continue
        if label in selected:
            raise RecoveryError(
                f"Docker Hub image repeats {label}", "registry-inspection"
            )
        descriptor_digest = require_digest(
            descriptor.get("digest"),
            f"Docker Hub {label} descriptor digest",
        )
        try:
            child, child_digest = verified_manifest(
                client,
                f"{IMAGE_REGISTRY}/v2/{IMAGE_REPOSITORY}/manifests/{descriptor_digest}",
                token,
                descriptor_digest,
            )
        except NotFound as error:
            raise RecoveryError(
                f"Docker Hub {label} manifest is absent",
                "registry-inspection",
            ) from error
        config = child.get("config")
        config_digest = require_digest(
            config.get("digest") if isinstance(config, dict) else None,
            f"Docker Hub {label} config digest",
        )
        try:
            config_raw, _ = registry_bytes(
                client,
                f"{IMAGE_REGISTRY}/v2/{IMAGE_REPOSITORY}/blobs/{config_digest}",
                token,
                accept="application/vnd.oci.image.config.v1+json",
            )
        except NotFound as error:
            raise RecoveryError(
                f"Docker Hub {label} config is absent",
                "registry-inspection",
            ) from error
        if sha256_digest(config_raw) != config_digest:
            raise RecoveryError(
                f"Docker Hub {label} config digest does not match its bytes",
                "registry-inspection",
            )
        config_value = decode_json(
            config_raw, f"{IMAGE_REFERENCE}:{version} {label} config"
        )
        if (
            not isinstance(config_value, dict)
            or config_value.get("os") != platform.get("os")
            or config_value.get("architecture") != platform.get("architecture")
        ):
            raise RecoveryError(
                f"Docker Hub {label} config names a different platform",
                "registry-inspection",
            )
        image_config = (
            config_value.get("config") if isinstance(config_value, dict) else None
        )
        labels = image_config.get("Labels") if isinstance(image_config, dict) else None
        if not isinstance(labels, dict):
            raise RecoveryError(
                f"Docker Hub {label} image has no labels", "registry-inspection"
            )
        if labels.get("org.opencontainers.image.revision") != source_commit:
            raise RecoveryError(
                f"Docker Hub {label} image names a different source commit",
                "registry-inspection",
            )
        if labels.get("dev.durable-workflow.release.tag") != version:
            raise RecoveryError(
                f"Docker Hub {label} image names a different release tag",
                "registry-inspection",
            )
        selected[label] = {
            "manifest_digest": child_digest,
            "config_digest": config_digest,
            "source_revision": source_commit,
            "release_tag": version,
        }

    if set(selected) != set(REQUIRED_PLATFORMS):
        raise RecoveryError(
            "Docker Hub image does not contain both required Linux platforms",
            "registry-inspection",
        )
    return {
        "reference": f"{IMAGE_REFERENCE}:{version}",
        "digest": digest,
        "platforms": {name: selected[name] for name in REQUIRED_PLATFORMS},
    }


def evidence_base(
    plan: dict[str, Any] | None,
    plan_tag: str,
    plan_commit: str,
) -> dict[str, Any]:
    return {
        "schema": SCHEMA,
        "observed_at": dt.datetime.now(dt.UTC)
        .replace(microsecond=0)
        .isoformat()
        .replace("+00:00", "Z"),
        "release_plan": {
            "tag": plan_tag,
            "commit": plan_commit,
            "sha256": hashlib.sha256(canonical_json(plan)).hexdigest()
            if isinstance(plan, dict)
            else None,
        },
        "publication_credential_use": "none",
    }


def write_evidence(path: Path, value: dict[str, Any]) -> None:
    path.write_bytes(canonical_json(value))


def write_output(path: Path | None, values: Mapping[str, str]) -> None:
    if path is None:
        return
    with path.open("a", encoding="utf-8") as output:
        for key, value in values.items():
            output.write(f"{key}={value}\n")


def parse_args(argv: list[str]) -> argparse.Namespace:
    parser = argparse.ArgumentParser()
    parser.add_argument("--plan", type=Path, required=True)
    parser.add_argument("--recovery-evidence", type=Path, required=True)
    parser.add_argument("--plan-tag", required=True)
    parser.add_argument("--plan-commit", required=True)
    parser.add_argument("--github-server-url", required=True)
    parser.add_argument("--github-repository", required=True)
    parser.add_argument("--github-ref", required=True)
    parser.add_argument("--github-sha", required=True)
    parser.add_argument("--github-event-name", required=True)
    parser.add_argument("--evidence", type=Path, required=True)
    parser.add_argument("--github-output", type=Path)
    parser.add_argument("--require-present", action="store_true")
    parser.add_argument("--attempts", type=int, default=1)
    parser.add_argument("--sleep", type=float, default=0)
    args = parser.parse_args(argv)
    if args.attempts < 1 or args.attempts > 60:
        parser.error("--attempts must be between 1 and 60")
    if args.sleep < 0 or args.sleep > 60:
        parser.error("--sleep must be between 0 and 60")
    return args


def run(args: argparse.Namespace, client: Any) -> int:
    plan: dict[str, Any] | None = None
    try:
        plan_value = read_json(args.plan, "release plan")
        recovery = read_json(args.recovery_evidence, "source release recovery evidence")
        if not isinstance(plan_value, dict):
            raise RecoveryError("release plan is not a JSON object")
        plan = plan_value
        version, source_commit = validate_plan(
            plan, recovery, args.plan_tag, args.plan_commit
        )
        protected_source, foundation = validate_public_authority(
            client,
            plan,
            args.plan_tag,
            args.plan_commit,
            version,
            source_commit,
            github_server_url=args.github_server_url,
            github_repository=args.github_repository,
            github_ref=args.github_ref,
            github_sha=args.github_sha,
            github_event_name=args.github_event_name,
        )
        image = None
        for attempt in range(1, args.attempts + 1):
            try:
                image = inspect_service_image(client, version, source_commit)
                break
            except ImageNotFound:
                if attempt < args.attempts:
                    time.sleep(args.sleep)
        action = "noop" if image is not None else "publish"
        if args.require_present and image is None:
            raise RecoveryError(
                "Docker Hub did not expose the recovered image before the verification deadline",
                "registry-inspection",
            )
        evidence = evidence_base(plan, args.plan_tag, args.plan_commit)
        evidence.update(
            {
                "action": action,
                "outcome": "verified" if image is not None else "missing",
                "protected_source": protected_source,
                "release_candidate_foundation": foundation,
                "source_release": {"tag": version, "commit": source_commit},
                "image": image
                or {
                    "reference": f"{IMAGE_REFERENCE}:{version}",
                    "digest": None,
                    "platforms": {},
                },
                "rehearsal": {
                    "kind": "public-read",
                    "result": action,
                    "registry": "Docker Hub",
                },
            }
        )
        write_evidence(args.evidence, evidence)
        write_output(
            args.github_output,
            {
                "action": action,
                "version": version,
                "commit": source_commit,
                "image": f"{IMAGE_REFERENCE}:{version}",
                "digest": image["digest"] if image is not None else "",
            },
        )
        return 0
    except RecoveryError as error:
        evidence = evidence_base(plan, args.plan_tag, args.plan_commit)
        evidence.update(
            {
                "action": "reject",
                "outcome": "failed",
                "phase": error.phase,
                "reason": str(error),
            }
        )
        write_evidence(args.evidence, evidence)
        print(str(error), file=sys.stderr)
        return 1


def main(argv: list[str] | None = None) -> int:
    args = parse_args(sys.argv[1:] if argv is None else argv)
    return run(args, PublicClient(os.environ.get("GITHUB_TOKEN", "")))


if __name__ == "__main__":
    raise SystemExit(main())
