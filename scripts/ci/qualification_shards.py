#!/usr/bin/env python3
"""Select deterministic PHPUnit class shards for database qualification."""

from __future__ import annotations

import argparse
import os
import re
import subprocess
import sys
import tempfile
import xml.etree.ElementTree as ElementTree
from dataclasses import dataclass
from pathlib import Path
from typing import Mapping, Sequence


PHPUNIT_CLASS = re.compile(
    r"^[A-Za-z_][A-Za-z0-9_]*(?:\\[A-Za-z_][A-Za-z0-9_]*)+$"
)

# Creating a real Laravel application and resolving its Composer graph is
# intentionally much more expensive than an ordinary database-backed test.
# The floor keeps that class from landing on an otherwise full shard.
CLASS_WEIGHT_FLOORS: Mapping[str, int] = {
    (
        "Waterline\\Tests\\Feature\\"
        "RealComposerLaravelPackageHostWorkerVersioningTest"
    ): 120,
}


@dataclass(frozen=True)
class TestClass:
    name: str
    test_cases: int

    @property
    def estimated_weight(self) -> int:
        return max(self.test_cases, CLASS_WEIGHT_FLOORS.get(self.name, 0))


@dataclass(frozen=True)
class Shard:
    index: int
    classes: tuple[TestClass, ...]

    @property
    def test_cases(self) -> int:
        return sum(item.test_cases for item in self.classes)

    @property
    def estimated_weight(self) -> int:
        return sum(item.estimated_weight for item in self.classes)


def read_inventory(path: Path) -> tuple[TestClass, ...]:
    root = ElementTree.parse(path).getroot()
    inventory: list[TestClass] = []
    observed: set[str] = set()

    for element in root.findall(".//{*}testClass"):
        name = element.get("name", "")
        test_cases = len(element.findall("./{*}testMethod"))
        if (
            not PHPUNIT_CLASS.fullmatch(name)
            or test_cases < 1
            or name in observed
        ):
            raise ValueError("PHPUnit produced an invalid test-class inventory")
        observed.add(name)
        inventory.append(TestClass(name, test_cases))

    if not inventory:
        raise ValueError("PHPUnit produced an empty test-class inventory")

    return tuple(sorted(inventory, key=lambda item: item.name))


def plan_shards(
    inventory: Sequence[TestClass],
    shard_count: int,
) -> tuple[Shard, ...]:
    if shard_count < 1 or shard_count > len(inventory):
        raise ValueError("shard count must cover at least one test class per shard")
    if len({item.name for item in inventory}) != len(inventory):
        raise ValueError("test-class inventory contains duplicates")

    assignments: list[list[TestClass]] = [[] for _ in range(shard_count)]
    weights = [0] * shard_count
    case_counts = [0] * shard_count

    ordered = sorted(
        inventory,
        key=lambda item: (-item.estimated_weight, -item.test_cases, item.name),
    )
    for test_class in ordered:
        selected = min(
            range(shard_count),
            key=lambda index: (weights[index], case_counts[index], index),
        )
        assignments[selected].append(test_class)
        weights[selected] += test_class.estimated_weight
        case_counts[selected] += test_class.test_cases

    return tuple(
        Shard(
            index,
            tuple(sorted(classes, key=lambda item: item.name)),
        )
        for index, classes in enumerate(assignments)
    )


def pcre_quote(value: str) -> str:
    special = frozenset("\\.^$|()[]*+?{}~-")
    return "".join(
        f"\\{character}" if character in special else character
        for character in value
    )


def test_filter(shard: Shard, shard_count: int) -> str:
    if shard_count == 1:
        return ""
    alternatives = "|".join(pcre_quote(item.name) for item in shard.classes)
    return f"~^(?:{alternatives})::~"


def discover_inventory(
    phpunit: Path,
    configuration: Path,
) -> tuple[TestClass, ...]:
    with tempfile.NamedTemporaryFile(suffix=".xml") as inventory_file:
        result = subprocess.run(
            [
                str(phpunit),
                f"--configuration={configuration}",
                f"--list-tests-xml={inventory_file.name}",
            ],
            check=False,
            capture_output=True,
            text=True,
        )
        if result.returncode != 0:
            sys.stderr.write(result.stdout)
            sys.stderr.write(result.stderr)
            raise RuntimeError("PHPUnit test discovery failed")
        return read_inventory(Path(inventory_file.name))


def write_github_output(path: str, values: Mapping[str, str | int]) -> None:
    if not path:
        return
    with Path(path).open("a", encoding="utf-8") as output:
        for key, value in values.items():
            output.write(f"{key}={value}\n")


def select_command(arguments: argparse.Namespace) -> int:
    inventory = discover_inventory(
        Path(arguments.phpunit),
        Path(arguments.configuration),
    )
    shards = plan_shards(inventory, arguments.shard_count)
    if arguments.shard_index < 0 or arguments.shard_index >= len(shards):
        raise ValueError("shard index is outside the configured shard range")

    selected = shards[arguments.shard_index]
    values: dict[str, str | int] = {
        "test-filter": test_filter(selected, arguments.shard_count),
        "expected-tests": selected.test_cases,
        "selected-classes": len(selected.classes),
        "estimated-weight": selected.estimated_weight,
        "total-tests": sum(item.test_cases for item in inventory),
        "total-classes": len(inventory),
        "shard-label": (
            "all"
            if arguments.shard_count == 1
            else f"{arguments.shard_index + 1}/{arguments.shard_count}"
        ),
    }
    write_github_output(arguments.github_output, values)
    for key, value in values.items():
        print(f"{key}={value}")
    return 0


def parser() -> argparse.ArgumentParser:
    command_parser = argparse.ArgumentParser()
    commands = command_parser.add_subparsers(dest="command", required=True)

    select = commands.add_parser("select")
    select.add_argument("--phpunit", default="vendor/bin/phpunit")
    select.add_argument("--configuration", required=True)
    select.add_argument("--shard-index", required=True, type=int)
    select.add_argument("--shard-count", required=True, type=int)
    select.add_argument(
        "--github-output",
        default=os.environ.get("GITHUB_OUTPUT", ""),
    )
    select.set_defaults(handler=select_command)

    return command_parser


def main() -> int:
    arguments = parser().parse_args()
    try:
        return arguments.handler(arguments)
    except (OSError, RuntimeError, ValueError, ElementTree.ParseError) as error:
        print(f"qualification shard selection failed: {error}", file=sys.stderr)
        return 1


if __name__ == "__main__":
    raise SystemExit(main())
