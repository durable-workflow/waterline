#!/usr/bin/env python3
"""Validate public install examples against the declared release tuple."""

from __future__ import annotations

import json
import re
import shlex
import sys
from dataclasses import dataclass
from pathlib import Path
from typing import Any, Mapping, Sequence


ROOT = Path(__file__).parents[2]
WATERLINE_PACKAGE = "durable-workflow/waterline"
WORKFLOW_PACKAGE = "durable-workflow/workflow"
SDK_PACKAGE = "durable-workflow/sdk"
SERVICE_IMAGE = "durableworkflow/waterline"
EXACT_VERSION = re.compile(
    r"^[0-9]+\.[0-9]+\.[0-9]+(?:-[0-9A-Za-z]+(?:\.[0-9A-Za-z]+)*)?$"
)
FENCE = re.compile(r"^(?:`{3,}|~{3,})")
COMPOSER_REQUIRE = re.compile(r"^(?:\$\s+)?composer\s+require(?:\s|$)")
IMAGE_REFERENCE = re.compile(
    rf"(?<![0-9A-Za-z_./-]){re.escape(SERVICE_IMAGE)}:"
    r"(?P<tag>[0-9A-Za-z][0-9A-Za-z_.-]*)"
)


class ContractError(RuntimeError):
    """A public release example does not match the declared release tuple."""


@dataclass(frozen=True)
class ReleaseTuple:
    waterline: str
    workflow: str
    sdk: str

    @property
    def packages(self) -> Mapping[str, str]:
        return {
            WATERLINE_PACKAGE: self.waterline,
            WORKFLOW_PACKAGE: self.workflow,
            SDK_PACKAGE: self.sdk,
        }


@dataclass(frozen=True)
class ExampleCounts:
    install: int
    upgrade: int

    @property
    def total(self) -> int:
        return self.install + self.upgrade


@dataclass(frozen=True)
class RepositoryValidation:
    release: ReleaseTuple
    readme_examples: ExampleCounts
    service_examples: ExampleCounts
    documented_images: int
    default_image: str


def read_json(path: Path) -> Mapping[str, Any]:
    try:
        value = json.loads(path.read_text(encoding="utf-8"))
    except (OSError, json.JSONDecodeError) as error:
        raise ContractError(f"unable to read JSON from {path}: {error}") from error

    if not isinstance(value, dict):
        raise ContractError(f"{path} must contain a JSON object")

    return value


def read_text(path: Path) -> str:
    try:
        return path.read_text(encoding="utf-8")
    except OSError as error:
        raise ContractError(f"unable to read {path}: {error}") from error


def nested_value(
    document: Mapping[str, Any],
    path: Sequence[str],
) -> object:
    value: object = document
    for key in path:
        if not isinstance(value, dict) or key not in value:
            raise ContractError(
                f"composer.json must declare release identity {'.'.join(path)}"
            )
        value = value[key]
    return value


def exact_version(document: Mapping[str, Any], path: Sequence[str]) -> str:
    value = nested_value(document, path)
    if not isinstance(value, str) or EXACT_VERSION.fullmatch(value) is None:
        raise ContractError(
            f"composer.json {'.'.join(path)} must be an exact release version"
        )
    return value


def declared_release_tuple(manifest: Mapping[str, Any]) -> ReleaseTuple:
    if manifest.get("name") != WATERLINE_PACKAGE:
        raise ContractError(f"composer.json must identify {WATERLINE_PACKAGE}")

    return ReleaseTuple(
        waterline=exact_version(
            manifest,
            ("extra", "durable-workflow", "product-train"),
        ),
        workflow=exact_version(
            manifest,
            ("require-dev", WORKFLOW_PACKAGE),
        ),
        sdk=exact_version(manifest, ("require", SDK_PACKAGE)),
    )


def stability_flag(version: str) -> str:
    prerelease = version.lower().split("-", maxsplit=1)
    if len(prerelease) == 1:
        return ""

    channel = prerelease[1].split(".", maxsplit=1)[0]
    flags = {
        "dev": "@dev",
        "alpha": "@alpha",
        "beta": "@beta",
        "rc": "@RC",
    }
    flag = flags.get(channel)
    if flag is None:
        raise ContractError(
            f"release version {version!r} has an unsupported Composer stability"
        )
    return flag


def example_pin(version: str) -> str:
    return version + stability_flag(version)


def composer_require_commands(document: str) -> tuple[str, ...]:
    commands: list[str] = []
    continuation = ""

    for line in document.splitlines():
        stripped = line.strip()
        if not stripped or FENCE.match(stripped):
            if continuation:
                continuation = ""
            continue

        candidate = f"{continuation} {stripped}".strip()
        if candidate.endswith("\\"):
            continuation = candidate[:-1].rstrip()
            continue

        if COMPOSER_REQUIRE.match(candidate):
            commands.append(candidate)
        continuation = ""

    return tuple(commands)


def command_tokens(command: str, source: str) -> tuple[str, ...]:
    if command.startswith("$ "):
        command = command[2:]
    try:
        return tuple(shlex.split(command, comments=True, posix=True))
    except ValueError as error:
        raise ContractError(
            f"{source} contains an invalid shell command: {error}"
        ) from error


def package_constraint(tokens: Sequence[str], package: str) -> str | None:
    matches = [
        token
        for token in tokens
        if token == package or token.startswith(package + ":")
    ]
    if len(matches) > 1:
        raise ContractError(f"Composer example repeats package {package}")
    if not matches:
        return None
    if matches[0] == package:
        return ""
    return matches[0].split(":", maxsplit=1)[1]


def validate_composer_examples(
    source: str,
    document: str,
    release: ReleaseTuple,
    *,
    minimum_install: int,
    minimum_upgrade: int,
) -> ExampleCounts:
    install = 0
    upgrade = 0

    for command in composer_require_commands(document):
        tokens = command_tokens(command, source)
        constraints = {
            package: package_constraint(tokens, package)
            for package in release.packages
        }
        if all(constraint is None for constraint in constraints.values()):
            continue

        for package, version in release.packages.items():
            expected = example_pin(version)
            observed = constraints[package]
            if observed != expected:
                raise ContractError(
                    f"{source} Composer example pins {package} to {observed!r}; "
                    f"expected {expected!r} from composer.json"
                )

        if "--with-all-dependencies" in tokens or "-W" in tokens:
            upgrade += 1
        else:
            install += 1

    if install < minimum_install:
        raise ContractError(
            f"{source} must contain at least {minimum_install} embedded install "
            "Composer example"
        )
    if upgrade < minimum_upgrade:
        raise ContractError(
            f"{source} must contain at least {minimum_upgrade} embedded upgrade "
            "Composer example using -W or --with-all-dependencies"
        )

    return ExampleCounts(install=install, upgrade=upgrade)


def validate_documented_images(
    source: str,
    document: str,
    release: ReleaseTuple,
    *,
    minimum: int,
) -> int:
    tags = [match.group("tag") for match in IMAGE_REFERENCE.finditer(document)]
    if len(tags) < minimum:
        raise ContractError(
            f"{source} must contain at least {minimum} versioned "
            f"{SERVICE_IMAGE} example"
        )

    stale = [tag for tag in tags if tag != release.waterline]
    if stale:
        raise ContractError(
            f"{source} uses {SERVICE_IMAGE} tags {stale!r}; "
            f"expected {release.waterline!r} from composer.json"
        )
    return len(tags)


def validate_default_image(
    source: str,
    compose: str,
    release: ReleaseTuple,
) -> str:
    image_values = re.findall(r"(?m)^\s*image:\s*(\S+)\s*$", compose)
    if len(image_values) != 1:
        raise ContractError(f"{source} must declare exactly one service image")

    value = image_values[0].strip("'\"")
    environment_default = re.fullmatch(
        r"\$\{WATERLINE_IMAGE:-([^}]+)\}",
        value,
    )
    default_image = environment_default.group(1) if environment_default else value
    expected = f"{SERVICE_IMAGE}:{release.waterline}"
    if default_image != expected:
        raise ContractError(
            f"{source} defaults to image {default_image!r}; "
            f"expected {expected!r} from composer.json"
        )
    return default_image


def validate_repository(root: Path = ROOT) -> RepositoryValidation:
    release = declared_release_tuple(read_json(root / "composer.json"))
    readme_examples = validate_composer_examples(
        "README.md",
        read_text(root / "README.md"),
        release,
        minimum_install=1,
        minimum_upgrade=1,
    )
    service_document = read_text(root / "SERVICE_MODE.md")
    service_examples = validate_composer_examples(
        "SERVICE_MODE.md",
        service_document,
        release,
        minimum_install=1,
        minimum_upgrade=0,
    )
    documented_images = validate_documented_images(
        "SERVICE_MODE.md",
        service_document,
        release,
        minimum=1,
    )
    default_image = validate_default_image(
        "deploy/docker-compose.service.yml",
        read_text(root / "deploy" / "docker-compose.service.yml"),
        release,
    )
    return RepositoryValidation(
        release=release,
        readme_examples=readme_examples,
        service_examples=service_examples,
        documented_images=documented_images,
        default_image=default_image,
    )


def main(arguments: Sequence[str] | None = None) -> int:
    if arguments:
        raise ContractError("release example verification does not accept arguments")

    result = validate_repository()
    print(
        "release examples match independent tuple "
        f"waterline={result.release.waterline}, "
        f"workflow={result.release.workflow}, sdk={result.release.sdk}; "
        "composer_examples="
        f"{result.readme_examples.total + result.service_examples.total}, "
        f"documented_images={result.documented_images}, "
        f"default_image={result.default_image}"
    )
    return 0


if __name__ == "__main__":
    try:
        raise SystemExit(main(sys.argv[1:]))
    except ContractError as error:
        print(f"release example contract failed: {error}", file=sys.stderr)
        raise SystemExit(1)
