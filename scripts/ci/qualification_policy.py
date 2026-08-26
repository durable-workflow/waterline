#!/usr/bin/env python3
"""Classify Waterline changes and enforce the selected qualification route."""

from __future__ import annotations

import argparse
import json
import os
import re
import subprocess
import sys
import time
from dataclasses import dataclass
from pathlib import Path, PurePosixPath
from typing import Iterable, Mapping, Sequence


COMPLETE = "complete"
CONFORMANCE = "conformance"
NON_RUNTIME = "non-runtime"
RELEASE = "release"
QUALIFICATION_CLASSES = frozenset({COMPLETE, CONFORMANCE, NON_RUNTIME, RELEASE})
SHA_PATTERN = re.compile(r"^[0-9a-f]{40}$")
NON_RUNTIME_DOCUMENTATION_SUFFIXES = frozenset(
    {".gif", ".jpeg", ".jpg", ".md", ".mdx", ".png", ".svg", ".webp"}
)

# These focused code routes are deliberately exact allowlists. A new path
# receives complete qualification until it is reviewed and intentionally
# added here.
RELEASE_ONLY_PATHS = frozenset(
    {
        "scripts/ci/check-docs-release-audit.sh",
        "scripts/ci/check-packagist-release.sh",
        "scripts/ci/cli-release-plan-recovery.fixture.yml",
        "scripts/ci/cli_release_verifier_contract.py",
        "scripts/ci/component-release-recovery.py",
        "scripts/ci/fixtures/sdk-rust-release-plan-recovery.yml",
        "scripts/ci/publish-planned-tag.py",
        "scripts/ci/release_example_contract.py",
        "scripts/ci/release-recovery-consumer-adapter.json",
        "scripts/ci/release-recovery-consumer-contract.json",
        "scripts/ci/release_recovery_consumer_conformance.py",
        "scripts/ci/recovery_workflow_authority.py",
        "scripts/ci/service-image-recovery.py",
        "scripts/ci/standalone_lock_contract.py",
        "scripts/ci/test-component-release-recovery.py",
        "scripts/ci/test-publish-planned-tag.py",
        "scripts/ci/test-release-example-contract.py",
        "scripts/ci/test-service-image-recovery.py",
        "scripts/ci/test-standalone-lock-contract.py",
        "scripts/ci/test-waterline-release-identity.py",
        "scripts/ci/waterline_release_identity.py",
    }
)

CONFORMANCE_ONLY_PATHS = frozenset(
    {
        "scripts/conformance/worker-status-network.mjs",
        "scripts/conformance/worker-status-published-artifacts.mjs",
        "scripts/conformance/worker-status-published-artifacts.sh",
        "scripts/conformance/worker-status-runner-lifecycle.mjs",
        "scripts/conformance/worker-status-shared-isolation.mjs",
        "scripts/conformance/worker-status-shared-topology.mjs",
        "scripts/conformance/worker-status-version.mjs",
        "tests/Unit/WorkerStatusConformanceRunnerTest.php",
        "tests/Unit/WorkerStatusNetworkTest.mjs",
        "tests/Unit/WorkerStatusRunnerLifecycleTest.mjs",
        "tests/Unit/WorkerStatusSharedIsolationTest.mjs",
        "tests/Unit/WorkerStatusSharedTopologyTest.mjs",
        "tests/Unit/WorkerStatusVersionTest.mjs",
    }
)

COMMON_FOCUSED_CHECKS = (
    "workflow-syntax-and-trust",
    "public-boundary",
    "standalone-locked-composer-audit",
    "approved-current-release-tuple",
    "release-and-recovery-contracts",
)
CONFORMANCE_FOCUSED_CHECKS = (
    "phpunit:tests/Unit/WorkerStatusConformanceRunnerTest.php",
    "node:test:tests/Unit/WorkerStatus*Test.mjs",
)
NON_RUNTIME_FOCUSED_CHECKS = ("documentation-links-and-assets",)
SHARED_VISUAL_PATH_PREFIXES = (
    "public/",
    "resources/js/components/",
    "resources/sass/",
    "resources/views/",
)
DIALOG_VISUAL_PATHS = frozenset(
    {
        "resources/js/dialogs.mjs",
        "resources/js/screens/flows/index.vue",
        "scripts/ci/workflow-list-dialog-visual.mjs",
    }
)
RUN_DETAIL_VISUAL_PATHS = frozenset(
    {
        "app/Http/Controllers/DashboardController.php",
        "app/Http/Controllers/Remote/RemoteWorkflowsController.php",
        "app/Http/Controllers/WorkflowsController.php",
        "app/Http/Resources/V2StoredWorkflowResource.php",
        "app/Support/BackendConfiguration.php",
        "app/Support/Remote/RemoteBackend.php",
        "app/Support/WorkflowStreamPresenter.php",
        "resources/js/bootstrap-config.mjs",
        "resources/js/screens/flows/flow.vue",
        "resources/js/workflow-streams.mjs",
        "scripts/ci/run-detail-visual.mjs",
    }
)
SHARED_VISUAL_PATHS = frozenset(
    {
        ".github/workflows/php.yml",
        ".github/workflows/screenshots.yml",
        "package-lock.json",
        "package.json",
        "resources/js/WaterlineApp.vue",
        "resources/js/app.js",
        "resources/js/routes.js",
        "scripts/ci/qualification_policy.py",
        "scripts/ci/test-qualification-policy.py",
        "scripts/ci/workflow_trust_policy.py",
        "vite.config.mjs",
    }
)


@dataclass(frozen=True)
class Classification:
    name: str
    reason: str
    changed_paths: tuple[str, ...] = ()


def normalize_paths(paths: Iterable[str]) -> tuple[str, ...] | None:
    normalized: set[str] = set()

    for path in paths:
        if (
            not isinstance(path, str)
            or not path
            or "\0" in path
            or "\n" in path
            or "\r" in path
        ):
            return None

        candidate = PurePosixPath(path)
        if (
            candidate.is_absolute()
            or ".." in candidate.parts
            or candidate.as_posix() != path
        ):
            return None

        normalized.add(path)

    return tuple(sorted(normalized))


def is_non_runtime_path(path: str) -> bool:
    candidate = PurePosixPath(path)
    return (
        path == "LICENSE"
        or (
            candidate.parent == PurePosixPath(".")
            and candidate.suffix in {".md", ".mdx"}
        )
        or (
            path.startswith("docs/")
            and candidate.suffix.lower() in NON_RUNTIME_DOCUMENTATION_SUFFIXES
        )
    )


def classify_paths(paths: Iterable[str]) -> Classification:
    normalized = normalize_paths(paths)
    if normalized is None:
        return Classification(COMPLETE, "unsafe-changed-path")
    if not normalized:
        return Classification(COMPLETE, "no-changed-paths")
    if all(path in RELEASE_ONLY_PATHS for path in normalized):
        return Classification(RELEASE, "release-paths-only", normalized)
    if all(path in CONFORMANCE_ONLY_PATHS for path in normalized):
        return Classification(CONFORMANCE, "conformance-paths-only", normalized)
    if all(is_non_runtime_path(path) for path in normalized):
        return Classification(NON_RUNTIME, "non-runtime-paths-only", normalized)

    return Classification(COMPLETE, "complete-path-present", normalized)


def git_changed_paths(repository: Path, base: str, head: str) -> Classification:
    if (
        not SHA_PATTERN.fullmatch(base)
        or not SHA_PATTERN.fullmatch(head)
        or set(base) == {"0"}
    ):
        return Classification(COMPLETE, "unavailable-git-range")

    for revision in (base, head):
        verified = subprocess.run(
            ["git", "cat-file", "-e", f"{revision}^{{commit}}"],
            cwd=repository,
            check=False,
            capture_output=True,
        )
        if verified.returncode != 0:
            return Classification(COMPLETE, "unavailable-git-range")

    changed = subprocess.run(
        [
            "git",
            "diff",
            "--name-only",
            "--no-renames",
            "--diff-filter=ACDMRT",
            "-z",
            base,
            head,
        ],
        cwd=repository,
        check=False,
        capture_output=True,
    )
    if changed.returncode != 0:
        return Classification(COMPLETE, "unavailable-git-range")

    try:
        paths = [
            item.decode("utf-8", errors="strict")
            for item in changed.stdout.split(b"\0")
            if item
        ]
    except UnicodeDecodeError:
        return Classification(COMPLETE, "unsafe-changed-path")

    return classify_paths(paths)


def classify_event(
    repository: Path,
    event_name: str,
    base: str,
    head: str,
) -> Classification:
    if event_name not in {"pull_request", "push"}:
        return Classification(COMPLETE, "non-change-event")

    return git_changed_paths(repository, base, head)


def visual_surfaces(result: Classification) -> frozenset[str]:
    if result.reason in {
        "no-changed-paths",
        "non-change-event",
        "unavailable-git-range",
        "unsafe-changed-path",
    }:
        return frozenset({"dialog", "run-detail"})

    surfaces: set[str] = set()

    for path in result.changed_paths:
        if path in DIALOG_VISUAL_PATHS:
            surfaces.add("dialog")
        if path in RUN_DETAIL_VISUAL_PATHS:
            surfaces.add("run-detail")
        if path in SHARED_VISUAL_PATHS or path.startswith(SHARED_VISUAL_PATH_PREFIXES):
            surfaces.update({"dialog", "run-detail"})

    return frozenset(surfaces)


def requires_dialog_visual(result: Classification) -> bool:
    return "dialog" in visual_surfaces(result)


def requires_run_detail_visual(result: Classification) -> bool:
    return "run-detail" in visual_surfaces(result)


def expected_results(
    classification: str,
    dialog_visual_required: bool = False,
    run_detail_visual_required: bool = False,
) -> Mapping[str, str]:
    if classification == COMPLETE:
        matrix_result = "success"
        frontend_result = "success"
        conformance_result = "skipped"
    elif classification in {CONFORMANCE, NON_RUNTIME, RELEASE}:
        matrix_result = "skipped"
        frontend_result = "skipped"
        conformance_result = "success" if classification == CONFORMANCE else "skipped"
    else:
        raise ValueError(f"unknown qualification class: {classification}")

    return {
        "classification": "success",
        "release-contracts": "success",
        "conformance-contracts": conformance_result,
        "dialog-visual": "success" if dialog_visual_required else "skipped",
        "run-detail-visual": "success" if run_detail_visual_required else "skipped",
        "frontend": frontend_result,
        "build": matrix_result,
        "laravel-matrix": matrix_result,
        "laravel-compatibility": matrix_result,
        "database": matrix_result,
    }


def focused_checks(classification: str) -> tuple[str, ...]:
    if classification == CONFORMANCE:
        return COMMON_FOCUSED_CHECKS + CONFORMANCE_FOCUSED_CHECKS
    if classification == RELEASE:
        return COMMON_FOCUSED_CHECKS
    if classification == NON_RUNTIME:
        return COMMON_FOCUSED_CHECKS + NON_RUNTIME_FOCUSED_CHECKS
    if classification == COMPLETE:
        return COMMON_FOCUSED_CHECKS + (
            "frontend-production-build",
            "laravel-compatibility-matrix",
            "complete-database-matrix",
        )
    raise ValueError(f"unknown qualification class: {classification}")


def evaluate_results(
    classification: str,
    observed: Mapping[str, str],
    dialog_visual_required: bool = False,
    run_detail_visual_required: bool = False,
) -> tuple[str, ...]:
    try:
        expected = expected_results(
            classification,
            dialog_visual_required,
            run_detail_visual_required,
        )
    except ValueError:
        return ("qualification-class:invalid",)

    failures = [
        f"{job}:expected-{result}:observed-{observed.get(job, 'missing')}"
        for job, result in expected.items()
        if observed.get(job) != result
    ]
    return tuple(failures)


def load_benchmark(path: Path) -> dict[str, object]:
    benchmark = json.loads(path.read_text())
    if (
        benchmark.get("schema")
        != "durable-workflow.waterline.qualification-benchmark/v1"
    ):
        raise ValueError("qualification benchmark has an unsupported schema")

    baseline = benchmark.get("baseline")
    conformance_baseline = benchmark.get("conformance_change_baseline")
    improved = benchmark.get("improved_release_path")
    if (
        not isinstance(baseline, dict)
        or not isinstance(conformance_baseline, dict)
        or not isinstance(improved, dict)
    ):
        raise ValueError("qualification benchmark is incomplete")

    baseline_seconds = baseline.get("elapsed_seconds")
    improved_seconds = improved.get("projected_elapsed_seconds")
    saved_seconds = improved.get("projected_saved_seconds")
    reduction_basis_points = improved.get("projected_reduction_basis_points")
    components = improved.get("projection_components_seconds")
    if (
        not isinstance(baseline_seconds, int)
        or not isinstance(improved_seconds, int)
        or not isinstance(saved_seconds, int)
        or not isinstance(reduction_basis_points, int)
        or not isinstance(components, dict)
        or not all(isinstance(value, int) for value in components.values())
        or sum(components.values()) != improved_seconds
        or baseline_seconds <= improved_seconds
        or saved_seconds != baseline_seconds - improved_seconds
        or reduction_basis_points != round(saved_seconds / baseline_seconds * 10_000)
    ):
        raise ValueError("qualification benchmark timing is inconsistent")

    if (
        conformance_baseline.get("event") != "push"
        or conformance_baseline.get("head_branch") != "v2"
        or conformance_baseline.get("selected_class") != COMPLETE
        or not isinstance(conformance_baseline.get("run_id"), int)
        or not isinstance(conformance_baseline.get("elapsed_seconds"), int)
        or conformance_baseline["run_id"] <= 0
        or conformance_baseline["elapsed_seconds"] <= 0
    ):
        raise ValueError("conformance change benchmark is inconsistent")

    return benchmark


def write_github_output(path: str, values: Mapping[str, str | int]) -> None:
    if not path:
        return
    with Path(path).open("a", encoding="utf-8") as output:
        for key, value in values.items():
            output.write(f"{key}={value}\n")


def append_summary(path: str, lines: Sequence[str]) -> None:
    if not path:
        return
    with Path(path).open("a", encoding="utf-8") as summary:
        summary.write("\n".join(lines))
        summary.write("\n")


def classify_command(arguments: argparse.Namespace) -> int:
    result = classify_event(
        Path(arguments.repository).resolve(),
        arguments.event_name,
        arguments.base,
        arguments.head,
    )
    values: dict[str, str | int] = {
        "qualification-class": result.name,
        "qualification-reason": result.reason,
        "changed-path-count": len(result.changed_paths),
        "dialog-visual-required": str(requires_dialog_visual(result)).lower(),
        "run-detail-visual-required": str(requires_run_detail_visual(result)).lower(),
        "visual-surfaces": json.dumps(sorted(visual_surfaces(result)), separators=(",", ":")),
        "focused-checks": json.dumps(
            focused_checks(result.name),
            separators=(",", ":"),
        ),
    }
    write_github_output(arguments.github_output, values)
    append_summary(
        arguments.github_step_summary,
        (
            "## Target-branch qualification",
            "",
            f"- Selected class: `{result.name}`",
            f"- Classification basis: `{result.reason}`",
            f"- Changed paths considered: {len(result.changed_paths)}",
            "- Responsive dialog visual required: "
            f"`{str(requires_dialog_visual(result)).lower()}`",
            "- Responsive run-detail visual required: "
            f"`{str(requires_run_detail_visual(result)).lower()}`",
            "- Focused checks: "
            + ", ".join(f"`{check}`" for check in focused_checks(result.name)),
        ),
    )
    for key, value in values.items():
        print(f"{key}={value}")
    return 0


def gate_command(arguments: argparse.Namespace) -> int:
    observed = {
        "classification": arguments.classification_result,
        "release-contracts": arguments.release_contracts_result,
        "conformance-contracts": arguments.conformance_contracts_result,
        "dialog-visual": arguments.dialog_visual_result,
        "run-detail-visual": arguments.run_detail_visual_result,
        "frontend": arguments.frontend_result,
        "build": arguments.build_result,
        "laravel-matrix": arguments.laravel_matrix_result,
        "laravel-compatibility": arguments.laravel_compatibility_result,
        "database": arguments.database_result,
    }
    failures = evaluate_results(
        arguments.classification,
        observed,
        arguments.dialog_visual_required == "true",
        arguments.run_detail_visual_required == "true",
    )
    selected_checks = (
        focused_checks(arguments.classification)
        if arguments.classification in QUALIFICATION_CLASSES
        else ("invalid-qualification-class",)
    )

    try:
        benchmark = load_benchmark(Path(arguments.benchmark))
    except (OSError, ValueError, json.JSONDecodeError) as error:
        failures += (f"benchmark:{type(error).__name__}",)
        benchmark = {}

    elapsed_seconds = max(0, int(time.time()) - arguments.started_at)
    baseline = benchmark.get("baseline", {})
    conformance_baseline = benchmark.get("conformance_change_baseline", {})
    improved = benchmark.get("improved_release_path", {})
    baseline_seconds = baseline.get("elapsed_seconds", "unavailable")
    conformance_baseline_seconds = conformance_baseline.get(
        "elapsed_seconds",
        "unavailable",
    )
    projected_seconds = improved.get("projected_elapsed_seconds", "unavailable")

    append_summary(
        arguments.github_step_summary,
        (
            "## Target-branch qualification result",
            "",
            f"- Selected class: `{arguments.classification}`",
            "- Focused checks: "
            + ", ".join(f"`{check}`" for check in selected_checks),
            f"- Current build-workflow elapsed time: {elapsed_seconds}s",
            f"- Recorded complete-path baseline: {baseline_seconds}s",
            "- Recorded conformance-change complete-path baseline: "
            f"{conformance_baseline_seconds}s",
            f"- Recorded release-path projection: {projected_seconds}s",
            f"- Gate result: `{'failure' if failures else 'success'}`",
        ),
    )
    print(f"qualification-class={arguments.classification}")
    print(f"qualification-elapsed-seconds={elapsed_seconds}")

    if failures:
        for failure in failures:
            print(f"qualification gate rejected {failure}", file=sys.stderr)
        return 1

    return 0


def parser() -> argparse.ArgumentParser:
    command_parser = argparse.ArgumentParser()
    commands = command_parser.add_subparsers(dest="command", required=True)

    classify = commands.add_parser("classify")
    classify.add_argument("--repository", default=".")
    classify.add_argument("--event-name", required=True)
    classify.add_argument("--base", default="")
    classify.add_argument("--head", required=True)
    classify.add_argument(
        "--github-output",
        default=os.environ.get("GITHUB_OUTPUT", ""),
    )
    classify.add_argument(
        "--github-step-summary",
        default=os.environ.get("GITHUB_STEP_SUMMARY", ""),
    )
    classify.set_defaults(handler=classify_command)

    gate = commands.add_parser("gate")
    gate.add_argument("--classification", required=True)
    gate.add_argument("--classification-result", required=True)
    gate.add_argument("--release-contracts-result", required=True)
    gate.add_argument("--conformance-contracts-result", required=True)
    gate.add_argument(
        "--dialog-visual-required",
        required=True,
        choices=("true", "false"),
    )
    gate.add_argument("--dialog-visual-result", required=True)
    gate.add_argument(
        "--run-detail-visual-required",
        required=True,
        choices=("true", "false"),
    )
    gate.add_argument("--run-detail-visual-result", required=True)
    gate.add_argument("--frontend-result", required=True)
    gate.add_argument("--build-result", required=True)
    gate.add_argument("--laravel-matrix-result", required=True)
    gate.add_argument("--laravel-compatibility-result", required=True)
    gate.add_argument("--database-result", required=True)
    gate.add_argument("--started-at", required=True, type=int)
    gate.add_argument("--benchmark", required=True)
    gate.add_argument(
        "--github-step-summary",
        default=os.environ.get("GITHUB_STEP_SUMMARY", ""),
    )
    gate.set_defaults(handler=gate_command)

    return command_parser


def main() -> int:
    arguments = parser().parse_args()
    return arguments.handler(arguments)


if __name__ == "__main__":
    raise SystemExit(main())
