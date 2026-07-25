#!/usr/bin/env python3
"""Publish concise timing and JUnit evidence for a database qualification cell."""

from __future__ import annotations

import argparse
import os
import sys
import xml.etree.ElementTree as ElementTree
from dataclasses import dataclass
from pathlib import Path
from typing import Sequence


@dataclass(frozen=True)
class JUnitStats:
    tests: int
    assertions: int
    errors: int
    failures: int
    skipped: int
    test_seconds: float


def integer_attribute(element: ElementTree.Element, name: str) -> int:
    value = element.get(name, "0")
    parsed = int(value)
    if parsed < 0:
        raise ValueError(f"JUnit {name} must not be negative")
    return parsed


def float_attribute(element: ElementTree.Element, name: str) -> float:
    value = float(element.get(name, "0"))
    if value < 0:
        raise ValueError(f"JUnit {name} must not be negative")
    return value


def read_junit(path: Path) -> JUnitStats:
    root = ElementTree.parse(path).getroot()
    suites = (
        [root]
        if root.tag.endswith("testsuite")
        else root.findall("./{*}testsuite")
    )
    if not suites:
        raise ValueError("JUnit report contains no test suites")

    return JUnitStats(
        tests=sum(integer_attribute(suite, "tests") for suite in suites),
        assertions=sum(integer_attribute(suite, "assertions") for suite in suites),
        errors=sum(integer_attribute(suite, "errors") for suite in suites),
        failures=sum(integer_attribute(suite, "failures") for suite in suites),
        skipped=sum(integer_attribute(suite, "skipped") for suite in suites),
        test_seconds=sum(float_attribute(suite, "time") for suite in suites),
    )


def command_result(status: int) -> str:
    if status in {124, 137}:
        return "timeout"
    return "success" if status == 0 else "failure"


def summary_lines(
    arguments: argparse.Namespace,
    stats: JUnitStats | None,
) -> tuple[str, ...]:
    completed_at = max(arguments.completed_at, arguments.test_started_at)
    runner_steps_seconds = max(0, completed_at - arguments.started_at)
    test_wall_seconds = max(0, completed_at - arguments.test_started_at)
    target = "not configured"
    if arguments.target_seconds > 0:
        target = (
            "within target"
            if runner_steps_seconds <= arguments.target_seconds
            else "over target"
        )

    lines = [
        f"## Database qualification: {arguments.database} ({arguments.shard_label})",
        "",
        f"- Result: `{command_result(arguments.status)}`",
        (
            f"- Selected scope: {arguments.expected_tests} discovered tests "
            f"across {arguments.selected_classes} classes"
        ),
        f"- Test command wall time: {test_wall_seconds}s",
        f"- Measured runner-steps time: {runner_steps_seconds}s",
    ]
    if arguments.target_seconds > 0:
        lines.append(
            f"- {arguments.target_seconds}s qualification target: `{target}`"
        )
    if stats is None:
        lines.append("- JUnit result: unavailable")
    else:
        lines.extend(
            [
                (
                    f"- JUnit: {stats.tests} tests, {stats.assertions} assertions, "
                    f"{stats.failures} failures, {stats.errors} errors, "
                    f"{stats.skipped} skipped"
                ),
                f"- Accumulated PHPUnit time: {stats.test_seconds:.3f}s",
            ]
        )

    return tuple(lines)


def append_summary(path: str, lines: Sequence[str]) -> None:
    if not path:
        return
    with Path(path).open("a", encoding="utf-8") as summary:
        summary.write("\n".join(lines))
        summary.write("\n")


def coverage_failure(
    status: int,
    expected_tests: int,
    stats: JUnitStats | None,
) -> str | None:
    if status != 0:
        return None
    if stats is None:
        return (
            "qualification coverage mismatch: "
            f"expected {expected_tests} tests, observed unavailable"
        )
    if stats.tests != expected_tests:
        return (
            "qualification coverage mismatch: "
            f"expected {expected_tests} tests, observed {stats.tests}"
        )
    return None


def main() -> int:
    argument_parser = argparse.ArgumentParser()
    argument_parser.add_argument("--database", required=True)
    argument_parser.add_argument("--shard-label", required=True)
    argument_parser.add_argument("--expected-tests", required=True, type=int)
    argument_parser.add_argument("--selected-classes", required=True, type=int)
    argument_parser.add_argument("--started-at", required=True, type=int)
    argument_parser.add_argument("--test-started-at", required=True, type=int)
    argument_parser.add_argument("--completed-at", required=True, type=int)
    argument_parser.add_argument("--target-seconds", required=True, type=int)
    argument_parser.add_argument("--status", required=True, type=int)
    argument_parser.add_argument("--junit", required=True)
    argument_parser.add_argument(
        "--github-step-summary",
        default=os.environ.get("GITHUB_STEP_SUMMARY", ""),
    )
    arguments = argument_parser.parse_args()

    stats = None
    try:
        stats = read_junit(Path(arguments.junit))
    except (OSError, ValueError, ElementTree.ParseError) as error:
        print(f"qualification JUnit evidence unavailable: {error}", file=sys.stderr)

    append_summary(
        arguments.github_step_summary,
        summary_lines(arguments, stats),
    )

    failure = coverage_failure(arguments.status, arguments.expected_tests, stats)
    if failure is not None:
        print(failure, file=sys.stderr)
        return 1

    return 0


if __name__ == "__main__":
    raise SystemExit(main())
