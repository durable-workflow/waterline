#!/usr/bin/env python3
"""Behavior tests for Waterline qualification and workflow trust policy."""

from __future__ import annotations

import importlib.util
import subprocess
import sys
import tempfile
import unittest
from pathlib import Path

import yaml


ROOT = Path(__file__).parents[2]


def load_module(name: str, path: Path):
    specification = importlib.util.spec_from_file_location(name, path)
    if specification is None or specification.loader is None:
        raise RuntimeError(f"unable to load {path}")
    module = importlib.util.module_from_spec(specification)
    sys.modules[name] = module
    specification.loader.exec_module(module)
    return module


qualification = load_module(
    "qualification_policy",
    ROOT / "scripts" / "ci" / "qualification_policy.py",
)
trust = load_module(
    "workflow_trust_policy",
    ROOT / "scripts" / "ci" / "workflow_trust_policy.py",
)


class ChangeClassificationTest(unittest.TestCase):
    def test_release_authority_change_selects_release_qualification(self) -> None:
        result = qualification.classify_paths(
            [
                "scripts/ci/cli_release_verifier_contract.py",
                "scripts/ci/component-release-recovery.py",
                "scripts/ci/recovery_workflow_authority.py",
                "scripts/ci/test-component-release-recovery.py",
            ]
        )

        self.assertEqual(qualification.RELEASE, result.name)
        self.assertEqual("release-paths-only", result.reason)

    def test_release_workflows_and_contracts_select_release_qualification(self) -> None:
        result = qualification.classify_paths(qualification.RELEASE_ONLY_PATHS)

        self.assertEqual(qualification.RELEASE, result.name)

    def test_standalone_lock_repair_selects_release_qualification(self) -> None:
        result = qualification.classify_paths(
            [
                "standalone/composer.lock",
                "scripts/ci/standalone_lock_contract.py",
                "scripts/ci/test-standalone-lock-contract.py",
            ]
        )

        self.assertEqual(qualification.RELEASE, result.name)
        self.assertEqual("release-paths-only", result.reason)

    def test_behavioral_inputs_select_complete_qualification(self) -> None:
        complete_paths = {
            "php-source": "app/Support/RuntimeConfiguration.php",
            "dependency": "composer.json",
            "standalone-dependency": "standalone/composer.json",
            "migration": "database/migrations/2026_04_09_000000_create_waterline_saved_views_table.php",
            "database": "phpunit-mssql.xml",
            "runtime": "Dockerfile",
            "sqlserver-runtime": "scripts/ci/install-sqlserver-odbc.sh",
            "dependency-matrix": ".github/laravel-matrix.json",
            "workflow": ".github/workflows/php.yml",
            "classifier": "scripts/ci/qualification_policy.py",
            "test-source": "tests/Feature/ServiceModeBackendTest.php",
        }

        for surface, path in complete_paths.items():
            with self.subTest(surface=surface, path=path):
                result = qualification.classify_paths([path])
                self.assertEqual(qualification.COMPLETE, result.name)
                self.assertEqual("complete-path-present", result.reason)

    def test_mixed_change_selects_complete_qualification(self) -> None:
        for complete_path in (
            "config/waterline.php",
            "standalone/composer.json",
            "Dockerfile",
        ):
            with self.subTest(complete_path=complete_path):
                result = qualification.classify_paths(
                    [
                        "standalone/composer.lock",
                        "scripts/ci/standalone_lock_contract.py",
                        complete_path,
                    ]
                )

                self.assertEqual(qualification.COMPLETE, result.name)
                self.assertEqual("complete-path-present", result.reason)

    def test_classifier_and_build_workflow_cannot_select_release(self) -> None:
        for path in (
            ".github/workflows/php.yml",
            "scripts/ci/qualification_policy.py",
            "scripts/ci/workflow_trust_policy.py",
            "scripts/ci/test-qualification-policy.py",
        ):
            with self.subTest(path=path):
                self.assertEqual(
                    qualification.COMPLETE,
                    qualification.classify_paths([path]).name,
                )

    def test_empty_or_unsafe_change_sets_fail_closed_class(self) -> None:
        for paths in ([], ["../composer.json"], ["/tmp/composer.json"], [""]):
            with self.subTest(paths=paths):
                self.assertEqual(
                    qualification.COMPLETE,
                    qualification.classify_paths(paths).name,
                )

    def test_non_change_events_select_complete_qualification(self) -> None:
        result = qualification.classify_event(
            ROOT,
            "workflow_dispatch",
            "",
            "1" * 40,
        )

        self.assertEqual(qualification.COMPLETE, result.name)
        self.assertEqual("non-change-event", result.reason)

    def test_git_range_classification_uses_committed_paths(self) -> None:
        with tempfile.TemporaryDirectory() as directory:
            repository = Path(directory)
            subprocess.run(["git", "init", "-q", repository], check=True)
            subprocess.run(
                ["git", "-C", repository, "config", "user.name", "Qualification Test"],
                check=True,
            )
            subprocess.run(
                [
                    "git",
                    "-C",
                    repository,
                    "config",
                    "user.email",
                    "qualification@example.invalid",
                ],
                check=True,
            )
            release_path = (
                repository / "scripts" / "ci" / "component-release-recovery.py"
            )
            release_path.parent.mkdir(parents=True)
            release_path.write_text("baseline = True\n")
            subprocess.run(["git", "-C", repository, "add", "."], check=True)
            subprocess.run(
                ["git", "-C", repository, "commit", "-qm", "baseline"],
                check=True,
            )
            base = subprocess.check_output(
                ["git", "-C", repository, "rev-parse", "HEAD"],
                text=True,
            ).strip()

            release_path.write_text("baseline = False\n")
            subprocess.run(["git", "-C", repository, "add", "."], check=True)
            subprocess.run(
                ["git", "-C", repository, "commit", "-qm", "release"],
                check=True,
            )
            release_head = subprocess.check_output(
                ["git", "-C", repository, "rev-parse", "HEAD"],
                text=True,
            ).strip()
            self.assertEqual(
                qualification.RELEASE,
                qualification.classify_event(
                    repository,
                    "push",
                    base,
                    release_head,
                ).name,
            )

            runtime_path = repository / "app" / "Support" / "RuntimeConfiguration.php"
            runtime_path.parent.mkdir(parents=True)
            runtime_path.write_text("<?php\n")
            subprocess.run(["git", "-C", repository, "add", "."], check=True)
            subprocess.run(
                ["git", "-C", repository, "commit", "-qm", "runtime"],
                check=True,
            )
            runtime_head = subprocess.check_output(
                ["git", "-C", repository, "rev-parse", "HEAD"],
                text=True,
            ).strip()
            self.assertEqual(
                qualification.COMPLETE,
                qualification.classify_event(
                    repository,
                    "push",
                    release_head,
                    runtime_head,
                ).name,
            )


class QualificationGateTest(unittest.TestCase):
    def test_release_route_requires_matrix_jobs_to_be_skipped(self) -> None:
        observed = dict(qualification.expected_results(qualification.RELEASE))
        self.assertEqual((), qualification.evaluate_results("release", observed))

        observed["database"] = "success"
        self.assertNotEqual((), qualification.evaluate_results("release", observed))

    def test_complete_route_requires_every_matrix_job(self) -> None:
        observed = dict(qualification.expected_results(qualification.COMPLETE))
        self.assertEqual((), qualification.evaluate_results("complete", observed))

        observed["laravel-compatibility"] = "skipped"
        self.assertNotEqual((), qualification.evaluate_results("complete", observed))

    def test_unknown_route_is_rejected(self) -> None:
        self.assertNotEqual(
            (),
            qualification.evaluate_results("unexpected", {}),
        )

    def test_recorded_benchmark_is_arithmetically_consistent(self) -> None:
        benchmark = qualification.load_benchmark(
            ROOT / ".github" / "qualification-benchmark.json"
        )

        baseline = benchmark["baseline"]
        improved = benchmark["improved_release_path"]
        self.assertEqual(
            baseline["elapsed_seconds"] - improved["projected_elapsed_seconds"],
            improved["projected_saved_seconds"],
        )
        self.assertEqual(
            improved["projected_elapsed_seconds"],
            sum(improved["projection_components_seconds"].values()),
        )


class DatabaseQualificationWorkflowContractTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls) -> None:
        cls.workflow = yaml.load(
            (ROOT / ".github" / "workflows" / "php.yml").read_text(),
            Loader=yaml.BaseLoader,
        )
        if not isinstance(cls.workflow, dict):
            raise RuntimeError("build workflow must be a mapping")

    def qualification_job(self) -> dict:
        job = self.workflow["jobs"]["qualification"]
        self.assertIsInstance(job, dict)
        return job

    def named_step(self, name: str) -> dict:
        steps = self.qualification_job()["steps"]
        matches = [
            step
            for step in steps
            if isinstance(step, dict) and step.get("name") == name
        ]
        self.assertEqual(1, len(matches), f"expected one workflow step named {name}")
        return matches[0]

    def test_sql_server_cell_installs_only_the_required_odbc_runtime(self) -> None:
        rows = self.qualification_job()["strategy"]["matrix"]["include"]
        sql_server_rows = [
            row
            for row in rows
            if isinstance(row, dict) and row.get("database") == "mssql"
        ]
        self.assertEqual(1, len(sql_server_rows))
        self.assertEqual("pdo_sqlsrv", sql_server_rows[0].get("extension"))
        self.assertEqual("test-mssql", sql_server_rows[0].get("composer-script"))

        step = self.named_step("Install SQL Server ODBC runtime")
        self.assertEqual("${{ matrix.database == 'mssql' }}", step.get("if"))
        self.assertEqual("scripts/ci/install-sqlserver-odbc.sh", step.get("run"))

        setup = (ROOT / step["run"]).read_text()
        install_commands = [
            line.strip() for line in setup.splitlines() if "apt-get install" in line
        ]
        self.assertEqual(
            [
                "sudo ACCEPT_EULA=Y apt-get install --yes --no-install-recommends msodbcsql18 unixodbc"
            ],
            install_commands,
        )
        self.assertIn(
            'odbcinst -q -d -n "ODBC Driver 18 for SQL Server"',
            setup,
        )
        self.assertIn("GITHUB_STEP_SUMMARY", setup)
        for command_line_package in ("mssql-tools", "mssql-tools18", "sqlcmd"):
            with self.subTest(command_line_package=command_line_package):
                self.assertNotIn(command_line_package, install_commands[0])

    def test_sql_server_database_creation_and_probe_remain_pdo_only(self) -> None:
        step = self.named_step("Prepare and verify database connections")
        self.assertEqual(
            'php scripts/ci/preflight-databases.php "${{ matrix.database }}" "$DB_HOST"',
            step.get("run"),
        )

        preflight = (ROOT / "scripts" / "ci" / "preflight-databases.php").read_text()
        self.assertNotIn("sqlcmd", preflight)
        self.assertIn("$adminConnection = new PDO(", preflight)
        self.assertIn(
            "IF DB_ID(N'testing') IS NULL CREATE DATABASE [testing];", preflight
        )
        self.assertIn("$connection = new PDO(", preflight)
        self.assertGreaterEqual(preflight.count("->query('SELECT 1')"), 2)


class WorkflowTrustPolicyTest(unittest.TestCase):
    def parse(self, source: str):
        document = yaml.load(source, Loader=yaml.BaseLoader)
        self.assertIsInstance(document, dict)
        return document

    def codes(self, source: str) -> set[str]:
        return {
            violation.code
            for violation in trust.validate_document("fixture.yml", self.parse(source))
        }

    def test_repository_workflows_satisfy_policy(self) -> None:
        self.assertEqual((), trust.audit_repository(ROOT))

    def test_required_focused_workflow_cannot_be_removed(self) -> None:
        with tempfile.TemporaryDirectory() as directory:
            repository = Path(directory)
            workflows = repository / ".github" / "workflows"
            workflows.mkdir(parents=True)
            (workflows / "public-boundary.yml").write_text(
                """
on: [pull_request, push]
permissions:
  contents: read
jobs: {}
"""
            )

            codes = {violation.code for violation in trust.audit_repository(repository)}

        self.assertIn("missing-required-workflow", codes)

    def test_required_focused_workflow_cannot_filter_release_changes(self) -> None:
        document = self.parse(
            """
on:
  pull_request:
    paths:
      - app/**
  push:
    branches: [main]
permissions:
  contents: read
jobs:
  smoke:
    runs-on: ubuntu-latest
    steps:
      - run: scripts/ci/service-mode-image-smoke.sh
"""
        )
        codes = {
            violation.code
            for violation in trust.validate_document(
                "service-image-smoke.yml",
                document,
            )
        }

        self.assertIn("filtered-focused-trigger", codes)
        self.assertIn("focused-trigger-misses-v2", codes)

    def test_untrusted_job_cannot_receive_secrets_or_write_permission(self) -> None:
        codes = self.codes(
            """
on: [pull_request]
permissions:
  contents: write
jobs:
  unsafe:
    runs-on: ubuntu-latest
    steps:
      - run: echo "$TOKEN"
        env:
          TOKEN: ${{ secrets.RELEASE_TOKEN }}
"""
        )

        self.assertIn("pr-write-permission", codes)
        self.assertIn("pr-secret", codes)
        self.assertIn("privileged-untrusted-trigger", codes)

    def test_untrusted_cache_requires_event_isolation(self) -> None:
        codes = self.codes(
            """
on: [pull_request]
permissions:
  contents: read
jobs:
  unsafe:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/cache@1111111111111111111111111111111111111111
        with:
          path: vendor
          key: shared-${{ hashFiles('composer.json') }}
          restore-keys: shared-
"""
        )

        self.assertIn("cache-cross-trust-boundary", codes)

    def test_untrusted_artifact_handoff_is_rejected(self) -> None:
        codes = self.codes(
            """
on: [pull_request]
permissions:
  contents: read
jobs:
  unsafe:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/upload-artifact@1111111111111111111111111111111111111111
        with:
          name: shared
          path: output
"""
        )

        self.assertIn("pr-artifact", codes)

    def test_privileged_download_requires_bound_identity_and_digest(self) -> None:
        codes = self.codes(
            """
on: [workflow_dispatch]
permissions:
  contents: read
jobs:
  publish:
    environment: release
    permissions:
      contents: write
    runs-on: ubuntu-latest
    steps:
      - uses: actions/download-artifact@1111111111111111111111111111111111111111
        with:
          name: mutable
"""
        )

        self.assertIn("unbound-artifact-download", codes)

    def test_action_references_must_be_immutable(self) -> None:
        codes = self.codes(
            """
on: [push]
permissions:
  contents: read
jobs:
  test:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v6
"""
        )

        self.assertIn("floating-action", codes)


if __name__ == "__main__":
    unittest.main()
