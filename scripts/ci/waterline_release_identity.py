#!/usr/bin/env python3
"""Validate candidate source separately from current published artifacts."""

from __future__ import annotations

import argparse
import hashlib
import json
import math
import os
import re
import stat
import sys
from pathlib import Path
from typing import Any, Mapping, Sequence


SCHEMA = "durable-workflow.waterline-current-product-tuple/v1"
PACKAGE = "durable-workflow/waterline"
SDK_PACKAGE = "durable-workflow/sdk"
WORKFLOW_PACKAGE = "durable-workflow/workflow"
SUPPORTED_2_X = re.compile(
    r"^2\.(?P<minor>0|[1-9][0-9]*)\.(?P<patch>0|[1-9][0-9]*)(?:-(?P<stage>alpha|beta|rc)\.(?P<number>[1-9][0-9]*))?$"
)
SUPPORTED_SDK = re.compile(
    r"^(?P<major>[23])\.(?P<minor>0|[1-9][0-9]*)\.(?P<patch>0|[1-9][0-9]*)(?:-(?P<stage>alpha|beta|rc)\.(?P<number>[1-9][0-9]*))?$"
)
PUBLIC_COMMIT = re.compile(r"^[0-9a-f]{40}$")
MAX_JSON_BYTES = 1024 * 1024
SOURCE_ROOT = Path(__file__).resolve().parents[2]
GENERATED_ROUTE_NAME = re.compile(r"generated::[a-zA-Z\d]{16}", re.ASCII)
ROUTE_FIELDS = frozenset({
    "methods", "uri", "domain", "name", "action", "middleware", "wheres",
    "defaults", "binding_fields", "scoped_bindings",
})


class IdentityError(RuntimeError):
    """The Waterline source or published dependency selection is invalid."""


def unique_json_object(pairs: list[tuple[str, Any]]) -> dict[str, Any]:
    value = {}
    for key, item in pairs:
        if key in value:
            raise IdentityError("duplicate JSON object key")
        value[key] = item
    return value


def refuse_json_constant(value: str) -> None:
    raise IdentityError(f"non-finite JSON constant {value}")


def finite_json_float(value: str) -> float:
    parsed = float(value)
    if not math.isfinite(parsed):
        raise IdentityError("JSON number exceeds finite range")
    return parsed


def decode_json(raw: bytes, label: str) -> dict[str, Any]:
    """Validate captured bytes; this does not establish their collection origin."""
    if not isinstance(raw, bytes) or len(raw) > MAX_JSON_BYTES:
        raise IdentityError(f"{label} must be a JSON object no larger than 1 MiB")
    try:
        value = json.loads(
            raw.decode("utf-8"), object_pairs_hook=unique_json_object,
            parse_constant=refuse_json_constant, parse_float=finite_json_float,
        )
    except (ValueError, RecursionError) as error:
        raise IdentityError(f"invalid {label} JSON: {error}") from error
    if not isinstance(value, dict):
        raise IdentityError(f"{label} must be a JSON object no larger than 1 MiB")
    return value


def file_state(value: os.stat_result) -> tuple[int, ...]:
    return tuple(getattr(value, name) for name in (
        "st_dev", "st_ino", "st_mode", "st_uid", "st_gid", "st_nlink",
        "st_size", "st_mtime_ns", "st_ctime_ns",
    ))


def open_json_input(path: Path, root: Path) -> int:
    """Walk beneath a caller-admitted root without following path components."""
    anchor = root.resolve(strict=True)
    supplied = path if path.is_absolute() else Path.cwd() / path
    try:
        parts = supplied.relative_to(anchor).parts
    except ValueError as error:
        raise IdentityError("JSON input escapes its admitted root") from error
    if not parts or any(part in (".", "..") for part in parts):
        raise IdentityError("JSON input must name a file beneath its admitted root")
    directory_flags = os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW | os.O_CLOEXEC
    parent = os.open(anchor, directory_flags)
    try:
        for part in parts[:-1]:
            child = os.open(part, directory_flags, dir_fd=parent)
            os.close(parent)
            parent = child
        return os.open(parts[-1], os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK | os.O_CLOEXEC,
                       dir_fd=parent)
    finally:
        os.close(parent)


def load_json(path: Path, label: str, *, root: Path = SOURCE_ROOT) -> tuple[dict[str, Any], bytes]:
    try:
        descriptor = open_json_input(path, root)
        with os.fdopen(descriptor, "rb") as stream:
            before = os.fstat(stream.fileno())
            if not stat.S_ISREG(before.st_mode) or before.st_size > MAX_JSON_BYTES:
                raise IdentityError(f"{label} must be a regular file no larger than 1 MiB")
            raw = stream.read(MAX_JSON_BYTES + 1)
            if file_state(before) != file_state(os.fstat(stream.fileno())):
                raise IdentityError(f"{label} changed while being read")
    except (OSError, ValueError) as error:
        raise IdentityError(f"cannot load {label} {path}: {error}") from error
    return decode_json(raw, label), raw


def exact_version(value: object, label: str, *, sdk: bool = False) -> str:
    supported = SUPPORTED_SDK if sdk else SUPPORTED_2_X
    if not isinstance(value, str) or supported.fullmatch(value) is None:
        line = "2.x or 3.x" if sdk else "2.x"
        raise IdentityError(f"{label} must be an exact supported {line} release")
    return value


def version_order(value: str) -> tuple[int, int, int, int, int]:
    match = SUPPORTED_SDK.fullmatch(value)
    if match is None:
        raise IdentityError(f"cannot order unsupported release {value!r}")
    stage = match.group("stage")
    stage_order = {"alpha": 0, "beta": 1, "rc": 2, None: 3}
    return (
        int(match.group("major")),
        int(match.group("minor")),
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
        name: exact_version(versions[name], f"approved {name} version", sdk=name == "sdk-php")
        for name in ("waterline", "workflow", "sdk-php")
    }


def required_version(
    manifest: Mapping[str, Any], table: str, package: str, label: str
) -> str:
    requirements = manifest.get(table)
    requirement = requirements.get(package) if isinstance(requirements, dict) else None
    return exact_version(requirement, f"{label} {table}.{package}", sdk=package == SDK_PACKAGE)


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
        candidate.get("version"),
        f"standalone Composer lock {package} version",
        sdk=package == SDK_PACKAGE,
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


def production_graph(packages: object) -> dict[str, dict[str, str]]:
    if not isinstance(packages, list) or not packages:
        raise IdentityError("production package inventory must be a nonempty list")
    graph = {}
    for package in packages:
        if not isinstance(package, dict):
            raise IdentityError("production package identity must be an object")
        name = package.get("name")
        version = package.get("version")
        source = package.get("source")
        reference = source.get("reference") if isinstance(source, dict) else None
        if (not isinstance(name, str) or not name or name in graph
                or not isinstance(version, str) or not version
                or not isinstance(reference, str) or PUBLIC_COMMIT.fullmatch(reference) is None):
            raise IdentityError("missing, duplicate or nonimmutable production package identity")
        if name == WORKFLOW_PACKAGE or package.get("dev_requirement", False) is not False:
            raise IdentityError("standalone production graph cannot contain Core or development packages")
        graph[name] = {"version": version.removeprefix("v"), "source_reference": reference}
    return dict(sorted(graph.items()))


def validate_installed_graph(lock: Mapping[str, Any], installed: Mapping[str, Any]) -> dict[str, Any]:
    """Compare inert Composer metadata; collection and image binding are separate."""
    if installed.get("dev") is not False or installed.get("dev-package-names") != []:
        raise IdentityError("installed graph must explicitly exclude development packages")
    expected = production_graph(lock.get("packages"))
    actual = production_graph(installed.get("packages"))
    if actual != expected:
        raise IdentityError("installed production graph differs from the reviewed source lock")
    if package_authorities(lock["packages"]) != package_authorities(installed["packages"]):
        raise IdentityError("installed source/dist authorities differ from the reviewed source lock")
    return {"outcome": "verified", "package_count": len(actual), "packages": actual}


def package_authorities(packages: list[dict[str, Any]]) -> dict[str, Any]:
    """Keep source and dist objects distinct, including missing/null/empty values."""
    result = {}
    for package in packages:
        authorities = {}
        for kind, fields in (("source", {"type", "url", "reference"}),
                             ("dist", {"type", "url", "reference", "shasum"})):
            authority = package.get(kind)
            if not isinstance(authority, dict) or set(authority) != fields:
                raise IdentityError(f"incomplete {kind} authority for {package['name']}")
            if any(value is not None and not isinstance(value, str) for value in authority.values()):
                raise IdentityError(f"invalid {kind} authority for {package['name']}")
            if any(not isinstance(authority[key], str) or not authority[key] for key in ("type", "url")):
                raise IdentityError(f"missing {kind} type or URL for {package['name']}")
            authorities[kind] = dict(authority)
        result[package["name"]] = authorities
    return result


def route_string_map(value: object) -> bool:
    return isinstance(value, dict) and all(
        isinstance(key, str) and isinstance(item, str) for key, item in value.items()
    )


def route_string_list(value: object) -> bool:
    return isinstance(value, list) and all(isinstance(item, str) and item for item in value)


def canonical_json(value: object) -> str:
    try:
        return json.dumps(value, sort_keys=True, separators=(",", ":"), allow_nan=False)
    except (ValueError, TypeError, RecursionError) as error:
        raise IdentityError("route identities must be finite JSON") from error


def validate_route_record(route: object) -> dict[str, Any]:
    if not isinstance(route, dict) or set(route) != ROUTE_FIELDS:
        raise IdentityError("incomplete route identity")
    if not all(route_string_list(route[field]) for field in ("methods", "middleware")):
        raise IdentityError("invalid route methods or middleware")
    if not route["methods"] or len(set(route["methods"])) != len(route["methods"]):
        raise IdentityError("missing or duplicate route methods")
    if not all(isinstance(route[field], str) and route[field] for field in ("uri", "action")):
        raise IdentityError("invalid route URI or action")
    if not all(route[field] is None or isinstance(route[field], str) for field in ("domain", "name")):
        raise IdentityError("invalid route domain or name")
    if not all(route_string_map(route[field]) for field in ("wheres", "binding_fields")):
        raise IdentityError("invalid route parameter constraints")
    if not isinstance(route["defaults"], dict) or type(route["scoped_bindings"]) is not bool:
        raise IdentityError("invalid route defaults or binding scope")
    canonical_json(route["defaults"])
    return route


def validate_route_inventory(routes: object) -> list[dict[str, Any]]:
    if not isinstance(routes, list) or not 1 <= len(routes) <= 2048:
        raise IdentityError("route inventory must contain between 1 and 2048 records")
    names = set()
    identities = set()
    for value in routes:
        route = validate_route_record(value)
        name = route["name"]
        if name:
            if name in names:
                raise IdentityError("route name collision")
            names.add(name)
        for method in route["methods"]:
            identity = (method, route["domain"], route["uri"])
            if identity in identities:
                raise IdentityError("route method/domain/URI collision")
            identities.add(identity)
    return routes


def validate_route_cache_routes(before: object, cached: object, cleared: object) -> dict[str, Any]:
    """Compare inert phase data; collection, cache effects and origin stay separate."""
    original = validate_route_inventory(before)
    compiled = validate_route_inventory(cached)
    restored = validate_route_inventory(cleared)
    if canonical_json(restored) != canonical_json(original) or len(compiled) != len(original):
        raise IdentityError("route count, order or clear-after identity changed")
    for route, cached_route in zip(original, compiled, strict=True):
        comparison = dict(cached_route)
        cached_name = comparison["name"]
        if route["name"] in (None, "") and isinstance(cached_name, str):
            if GENERATED_ROUTE_NAME.fullmatch(cached_name):
                comparison["name"] = route["name"]
        if canonical_json(comparison) != canonical_json(route):
            raise IdentityError("cache changed a stable route identity")
    return {"outcome": "matched", "route_count": len(original)}


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
    parser.add_argument("--installed-json", type=Path,
                        help="optional inert Composer installed.json from separately admitted qualification")
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
    if args.installed_json is not None:
        installed, installed_raw = load_json(args.installed_json, "installed production graph")
        evidence["installed_graph"] = validate_installed_graph(lock, installed)
        evidence["source_sha256"]["installed_json"] = hashlib.sha256(installed_raw).hexdigest()
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
