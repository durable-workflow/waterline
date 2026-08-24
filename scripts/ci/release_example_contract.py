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
from urllib.parse import unquote, urlsplit


ROOT = Path(__file__).parents[2]
WATERLINE_PACKAGE = "durable-workflow/waterline"
WORKFLOW_PACKAGE = "durable-workflow/workflow"
SDK_PACKAGE = "durable-workflow/sdk"
SERVICE_IMAGE = "durableworkflow/waterline"
ONBOARDING_CONSTRAINT = "^2.0@RC"
PRERELEASE_RESOLVER = "scripts/resolve-current-prerelease.py"
EXACT_VERSION = re.compile(
    r"^[0-9]+\.[0-9]+\.[0-9]+(?:-[0-9A-Za-z]+(?:\.[0-9A-Za-z]+)*)?$"
)
FENCE = re.compile(r"^(?:`{3,}|~{3,})")
COMPOSER_REQUIRE = re.compile(r"^(?:\$\s+)?composer\s+require(?:\s|$)")
EXACT_PRERELEASE = re.compile(
    r"^2\.0\.0-(?:alpha|beta|rc)\.(?:0|[1-9][0-9]*)(?:@(?:alpha|beta|rc))?$",
    re.IGNORECASE,
)
MARKDOWN_LINK = re.compile(r"!?\[[^\]]*\]\((?P<target><[^>]+>|[^)\s]+)(?:\s+[^)]*)?\)")


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

    @property
    def composer_graphs(self) -> Mapping[str, Mapping[str, str]]:
        return {
            "embedded": {
                WATERLINE_PACKAGE: self.waterline,
                WORKFLOW_PACKAGE: self.workflow,
            },
            "service": {
                WATERLINE_PACKAGE: self.waterline,
                SDK_PACKAGE: self.sdk,
            },
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
    resolved_images: int
    local_documentation_links: int
    compose_image: str


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
        sdk=exact_version(manifest, ("require-dev", SDK_PACKAGE)),
    )


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
    required_graphs: Sequence[str] = (),
) -> ExampleCounts:
    install = 0
    upgrade = 0
    observed_graphs: set[str] = set()

    for command in composer_require_commands(document):
        tokens = command_tokens(command, source)
        constraints = {
            package: package_constraint(tokens, package)
            for package in release.packages
        }
        if all(constraint is None for constraint in constraints.values()):
            continue

        present_packages = {
            package
            for package, constraint in constraints.items()
            if constraint is not None
        }
        matching_graphs = [
            name
            for name, packages in release.composer_graphs.items()
            if present_packages == set(packages)
        ]
        if len(matching_graphs) != 1:
            raise ContractError(
                f"{source} Composer example must select exactly the embedded "
                f"({WATERLINE_PACKAGE} + {WORKFLOW_PACKAGE}) or service "
                f"({WATERLINE_PACKAGE} + {SDK_PACKAGE}) package graph"
            )

        graph = matching_graphs[0]
        observed_graphs.add(graph)
        for package in release.composer_graphs[graph]:
            observed = constraints[package]
            if isinstance(observed, str) and EXACT_PRERELEASE.fullmatch(observed):
                raise ContractError(
                    f"{source} Composer example copies an exact prerelease pin "
                    f"for {package}: {observed!r}"
                )
            if observed != ONBOARDING_CONSTRAINT:
                raise ContractError(
                    f"{source} Composer example selects {package} with {observed!r}; "
                    f"expected the tested {ONBOARDING_CONSTRAINT!r} channel"
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
    missing_graphs = set(required_graphs) - observed_graphs
    if missing_graphs:
        raise ContractError(
            f"{source} is missing Composer examples for package graphs "
            f"{sorted(missing_graphs)!r}"
        )

    return ExampleCounts(install=install, upgrade=upgrade)


def shell_commands(document: str) -> tuple[str, ...]:
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

        commands.append(candidate)
        continuation = ""

    return tuple(commands)


def validate_documented_images(
    source: str,
    document: str,
    *,
    minimum: int,
) -> int:
    commands = [
        command
        for command in shell_commands(document)
        if re.match(r"^(?:\$\s+)?docker\s+(?:pull|run)(?:\s|$)", command)
    ]
    resolved = 0
    for command in commands:
        tokens = command_tokens(command, source)
        if len(tokens) > 1 and tokens[1] == "run" and "$WATERLINE_IMAGE" in tokens:
            resolved += 1
        if any(
            token == SERVICE_IMAGE
            or token.startswith(f"{SERVICE_IMAGE}:")
            or token.startswith(f"{SERVICE_IMAGE}@")
            for token in tokens
        ):
            raise ContractError(
                f"{source} copies a service image reference; use the "
                f"machine-owned {PRERELEASE_RESOLVER} output"
            )

    if resolved < minimum:
        raise ContractError(
            f"{source} must contain at least {minimum} Docker example using "
            "$WATERLINE_IMAGE"
        )
    resolver_commands = [
        command
        for command in shell_commands(document)
        if PRERELEASE_RESOLVER in command and command.endswith(' image)"')
    ]
    if not resolver_commands:
        raise ContractError(
            f"{source} must obtain WATERLINE_IMAGE from {PRERELEASE_RESOLVER}"
        )
    return resolved


def validate_default_image(
    source: str,
    compose: str,
) -> str:
    image_values = re.findall(r"(?m)^\s*image:\s*(.+?)\s*$", compose)
    if len(image_values) != 1:
        raise ContractError(f"{source} must declare exactly one service image")

    value = image_values[0].strip("'\"")
    if re.fullmatch(r"\$\{WATERLINE_IMAGE:\?[^}]+\}", value) is None:
        raise ContractError(
            f"{source} must require WATERLINE_IMAGE without a stable or latest fallback"
        )
    return value


def validate_markdown_links(source: Path, document: str, root: Path) -> int:
    repository = root.resolve()
    source_directory = (repository / source).parent
    local_links = 0

    for match in MARKDOWN_LINK.finditer(document):
        target = match.group("target").strip("<>")
        parsed = urlsplit(target)
        if (
            parsed.scheme
            or parsed.netloc
            or not parsed.path
            or parsed.path.startswith("/")
        ):
            continue

        destination = (source_directory / unquote(parsed.path)).resolve()
        try:
            destination.relative_to(repository)
        except ValueError as error:
            raise ContractError(
                f"{source.as_posix()} contains a local link outside the repository: "
                f"{target}"
            ) from error

        if not destination.is_file():
            raise ContractError(
                f"{source.as_posix()} contains a missing local link: {target}"
            )
        local_links += 1

    return local_links


def validate_documentation_links(root: Path) -> int:
    documents = sorted(root.glob("*.md"))
    documents.extend(sorted(root.glob("*.mdx")))
    docs = root / "docs"
    if docs.is_dir():
        documents.extend(sorted(docs.rglob("*.md")))
        documents.extend(sorted(docs.rglob("*.mdx")))

    return sum(
        validate_markdown_links(path.relative_to(root), read_text(path), root)
        for path in documents
    )


def validate_repository(root: Path = ROOT) -> RepositoryValidation:
    release = declared_release_tuple(read_json(root / "composer.json"))
    readme_examples = validate_composer_examples(
        "README.md",
        read_text(root / "README.md"),
        release,
        minimum_install=1,
        minimum_upgrade=1,
        required_graphs=("embedded",),
    )
    service_document = read_text(root / "SERVICE_MODE.md")
    service_examples = validate_composer_examples(
        "SERVICE_MODE.md",
        service_document,
        release,
        minimum_install=1,
        minimum_upgrade=0,
        required_graphs=("embedded", "service"),
    )
    documented_images = validate_documented_images(
        "SERVICE_MODE.md",
        service_document,
        minimum=1,
    )
    local_documentation_links = validate_documentation_links(root)
    default_image = validate_default_image(
        "deploy/docker-compose.service.yml",
        read_text(root / "deploy" / "docker-compose.service.yml"),
    )
    return RepositoryValidation(
        release=release,
        readme_examples=readme_examples,
        service_examples=service_examples,
        resolved_images=documented_images,
        local_documentation_links=local_documentation_links,
        compose_image=default_image,
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
        f"resolved_images={result.resolved_images}, "
        f"local_documentation_links={result.local_documentation_links}, "
        f"compose_image={result.compose_image}"
    )
    return 0


if __name__ == "__main__":
    try:
        raise SystemExit(main(sys.argv[1:]))
    except ContractError as error:
        print(f"release example contract failed: {error}", file=sys.stderr)
        raise SystemExit(1)
