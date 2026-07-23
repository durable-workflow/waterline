#!/usr/bin/env python3
"""Verify the standalone service lock against published Composer metadata."""

from __future__ import annotations

import json
import re
import subprocess
import sys
from pathlib import Path
from typing import Any, Mapping, Sequence


ROOT = Path(__file__).parents[2]
STANDALONE = ROOT / "standalone"
PACKAGE_NAME = "durable-workflow/sdk"
SHA_PATTERN = re.compile(r"^[0-9a-f]{40}$")


class ContractError(RuntimeError):
    """The standalone dependency lock does not match its public package."""


def read_json(path: Path) -> Mapping[str, Any]:
    try:
        value = json.loads(path.read_text(encoding="utf-8"))
    except (OSError, json.JSONDecodeError) as error:
        raise ContractError(f"unable to read JSON from {path}: {error}") from error

    if not isinstance(value, dict):
        raise ContractError(f"{path} must contain a JSON object")

    return value


def locked_package(
    lock: Mapping[str, Any],
    package_name: str,
) -> Mapping[str, Any]:
    packages = lock.get("packages")
    if not isinstance(packages, list):
        raise ContractError("standalone/composer.lock packages must be a list")

    matches = [
        package
        for package in packages
        if isinstance(package, dict) and package.get("name") == package_name
    ]
    if len(matches) != 1:
        raise ContractError(
            f"standalone/composer.lock must contain exactly one {package_name} package"
        )

    return matches[0]


def public_package_metadata(
    package_name: str,
    version: str,
    *,
    composer: str = "composer",
) -> Mapping[str, Any]:
    process = subprocess.run(
        [
            composer,
            f"--working-dir={STANDALONE}",
            "show",
            package_name,
            version,
            "--all",
            "--format=json",
            "--no-interaction",
        ],
        check=False,
        text=True,
        stdout=subprocess.PIPE,
        stderr=subprocess.PIPE,
    )
    if process.returncode != 0:
        diagnostic = process.stderr.strip() or process.stdout.strip()
        raise ContractError(
            f"Composer could not resolve public metadata for {package_name} "
            f"{version}: {diagnostic}"
        )

    try:
        metadata = json.loads(process.stdout)
    except json.JSONDecodeError as error:
        raise ContractError(
            f"Composer returned invalid public metadata for {package_name} {version}"
        ) from error

    if not isinstance(metadata, dict):
        raise ContractError(
            f"Composer returned an invalid public identity for {package_name} {version}"
        )

    return metadata


def require_mapping(
    value: object,
    description: str,
) -> Mapping[str, Any]:
    if not isinstance(value, dict):
        raise ContractError(f"{description} must be an object")

    return value


def validate_identity(
    manifest: Mapping[str, Any],
    lock: Mapping[str, Any],
    published: Mapping[str, Any],
    package_name: str = PACKAGE_NAME,
) -> str:
    requirements = require_mapping(
        manifest.get("require"),
        "standalone/composer.json require",
    )
    version = requirements.get(package_name)
    if not isinstance(version, str) or not version:
        raise ContractError(
            f"standalone/composer.json must exactly require {package_name}"
        )

    if published.get("name") != package_name:
        raise ContractError(
            f"public package metadata is for {published.get('name')!r}, "
            f"not {package_name}"
        )

    package = locked_package(lock, package_name)
    if package.get("version") != version:
        raise ContractError(
            f"standalone/composer.lock has {package_name} "
            f"{package.get('version')!r}, expected exact pin {version}"
        )

    public_versions = published.get("versions")
    if not isinstance(public_versions, list) or version not in public_versions:
        raise ContractError(
            f"public package metadata does not identify {package_name} {version}"
        )

    for distribution in ("source", "dist"):
        locked_distribution = require_mapping(
            package.get(distribution),
            f"locked {package_name} {distribution}",
        )
        public_distribution = require_mapping(
            published.get(distribution),
            f"public {package_name} {distribution}",
        )
        for field in ("type", "url", "reference"):
            locked_value = locked_distribution.get(field)
            public_value = public_distribution.get(field)
            if locked_value != public_value:
                raise ContractError(
                    f"standalone/composer.lock {package_name} "
                    f"{distribution}.{field} is {locked_value!r}, "
                    f"but the public {version} package uses {public_value!r}"
                )

    source = require_mapping(package.get("source"), f"locked {package_name} source")
    dist = require_mapping(package.get("dist"), f"locked {package_name} dist")
    reference = source.get("reference")
    if not isinstance(reference, str) or SHA_PATTERN.fullmatch(reference) is None:
        raise ContractError(
            f"standalone/composer.lock {package_name} source reference "
            "must be an immutable commit"
        )
    if dist.get("reference") != reference:
        raise ContractError(
            f"standalone/composer.lock {package_name} source and dist "
            "references must identify the same commit"
        )

    return version


def main(arguments: Sequence[str] | None = None) -> int:
    if arguments:
        raise ContractError("standalone lock verification does not accept arguments")

    manifest = read_json(STANDALONE / "composer.json")
    lock = read_json(STANDALONE / "composer.lock")
    requirements = require_mapping(
        manifest.get("require"),
        "standalone/composer.json require",
    )
    version = requirements.get(PACKAGE_NAME)
    if not isinstance(version, str) or not version:
        raise ContractError(
            f"standalone/composer.json must exactly require {PACKAGE_NAME}"
        )

    published = public_package_metadata(PACKAGE_NAME, version)
    validated_version = validate_identity(manifest, lock, published)
    reference = locked_package(lock, PACKAGE_NAME)["source"]["reference"]
    print(
        f"standalone lock matches public {PACKAGE_NAME} "
        f"{validated_version} at {reference}"
    )
    return 0


if __name__ == "__main__":
    try:
        raise SystemExit(main(sys.argv[1:]))
    except ContractError as error:
        print(f"standalone lock contract failed: {error}", file=sys.stderr)
        raise SystemExit(1)
