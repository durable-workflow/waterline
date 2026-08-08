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
    def test_published_artifact_conformance_change_selects_conformance_qualification(
        self,
    ) -> None:
        result = qualification.classify_paths(
            [
                "scripts/conformance/worker-status-published-artifacts.mjs",
                "scripts/conformance/worker-status-shared-topology.mjs",
                "tests/Unit/WorkerStatusConformanceRunnerTest.php",
                "tests/Unit/WorkerStatusSharedTopologyTest.mjs",
            ]
        )

        self.assertEqual(qualification.CONFORMANCE, result.name)
        self.assertEqual("conformance-paths-only", result.reason)

    def test_all_reviewed_conformance_paths_select_conformance_qualification(
        self,
    ) -> None:
        result = qualification.classify_paths(
            qualification.CONFORMANCE_ONLY_PATHS
        )

        self.assertEqual(qualification.CONFORMANCE, result.name)
        self.assertEqual("conformance-paths-only", result.reason)

    def test_release_authority_change_selects_release_qualification(self) -> None:
        result = qualification.classify_paths(
            [
                "scripts/ci/cli_release_verifier_contract.py",
                "scripts/ci/component-release-recovery.py",
                "scripts/ci/release-recovery-consumer-adapter.json",
                "scripts/ci/release-recovery-consumer-contract.json",
                "scripts/ci/release_recovery_consumer_conformance.py",
                "scripts/ci/recovery_workflow_authority.py",
                "scripts/ci/test-component-release-recovery.py",
            ]
        )

        self.assertEqual(qualification.RELEASE, result.name)
        self.assertEqual("release-paths-only", result.reason)

    def test_release_contracts_select_release_qualification(self) -> None:
        result = qualification.classify_paths(qualification.RELEASE_ONLY_PATHS)

        self.assertEqual(qualification.RELEASE, result.name)

    def test_non_runtime_documentation_selects_non_runtime_qualification(
        self,
    ) -> None:
        paths = (
            "CHANGELOG.md",
            "CONFORMANCE.md",
            "INTEGRATION_GUIDE.md",
            "INTEGRATION_TEST_README.md",
            "LICENSE",
            "README.md",
            "SERVICE_MODE.md",
            "docs/screenshots/dashboard.png",
            "docs/screenshots/workflow-detail.png",
        )

        result = qualification.classify_paths(paths)

        self.assertEqual(qualification.NON_RUNTIME, result.name)
        self.assertEqual("non-runtime-paths-only", result.reason)
        self.assertEqual(tuple(sorted(paths)), result.changed_paths)

    def test_standalone_dependency_changes_require_security_audit(self) -> None:
        cases = {
            "standalone/composer.json": qualification.COMPLETE,
            "standalone/composer.lock": qualification.COMPLETE,
        }

        for path, expected_class in cases.items():
            with self.subTest(path=path):
                result = qualification.classify_paths([path])

                self.assertEqual(expected_class, result.name)
                self.assertEqual(
                    "success",
                    qualification.expected_results(result.name)["release-contracts"],
                )
                self.assertIn(
                    "standalone-locked-composer-audit",
                    qualification.focused_checks(result.name),
                )

    def test_standalone_lock_repair_requires_complete_qualification(self) -> None:
        result = qualification.classify_paths(
            [
                "standalone/composer.lock",
                "scripts/ci/standalone_lock_contract.py",
                "scripts/ci/test-standalone-lock-contract.py",
            ]
        )

        self.assertEqual(qualification.COMPLETE, result.name)
        self.assertEqual("complete-path-present", result.reason)

    def test_behavioral_inputs_select_complete_qualification(self) -> None:
        complete_paths = {
            "php-source": "app/Support/RuntimeConfiguration.php",
            "dependency": "composer.json",
            "standalone-dependency": "standalone/composer.json",
            "standalone-lock": "standalone/composer.lock",
            "migration": "database/migrations/2026_04_09_000000_create_waterline_saved_views_table.php",
            "database": "phpunit-mssql.xml",
            "runtime": "Dockerfile",
            "sqlserver-runtime": "scripts/ci/install-sqlserver-odbc.sh",
            "dependency-matrix": ".github/laravel-matrix.json",
            "workbench": "workbench/app/Providers/WorkbenchServiceProvider.php",
            "frontend-dependency": "package.json",
            "frontend-lock": "package-lock.json",
            "frontend-runtime": "resources/js/components/WorkflowList.vue",
            "generated-runtime-asset": "public/app.js",
            "docs-executable": "docs/generate-screenshots.py",
            "workflow": ".github/workflows/php.yml",
            "release-workflow": ".github/workflows/release-docs-audit.yml",
            "container-definition": "deploy/docker-compose.service.yml",
            "classifier": "scripts/ci/qualification_policy.py",
            "qualification-sharding": "scripts/ci/qualification_shards.py",
            "test-source": "tests/Feature/ServiceModeBackendTest.php",
            "unreviewed-conformance-runner": "scripts/conformance/new-runner.mjs",
            "unreviewed-conformance-test": "tests/Unit/NewConformanceTest.mjs",
        }

        for surface, path in complete_paths.items():
            with self.subTest(surface=surface, path=path):
                result = qualification.classify_paths([path])
                self.assertEqual(qualification.COMPLETE, result.name)
                self.assertEqual("complete-path-present", result.reason)

    def test_non_runtime_documentation_mixed_with_sensitive_input_fails_closed(
        self,
    ) -> None:
        complete_paths = (
            "app/Support/RuntimeConfiguration.php",
            "composer.json",
            "standalone/composer.lock",
            "package-lock.json",
            "public/app.js",
            ".github/workflows/release-docs-audit.yml",
            "Dockerfile",
            "deploy/docker-compose.service.yml",
            "scripts/ci/qualification_policy.py",
        )

        for complete_path in complete_paths:
            with self.subTest(complete_path=complete_path):
                result = qualification.classify_paths(
                    ["CHANGELOG.md", "docs/screenshots/dashboard.png", complete_path]
                )

                self.assertEqual(qualification.COMPLETE, result.name)
                self.assertEqual("complete-path-present", result.reason)

    def test_mixed_change_selects_complete_qualification(self) -> None:
        for complete_path in (
            "config/waterline.php",
            "standalone/composer.json",
            "Dockerfile",
            "database/migrations/2026_04_09_000000_create_waterline_saved_views_table.php",
            "workbench/app/Providers/WorkbenchServiceProvider.php",
            "resources/js/components/WorkflowList.vue",
        ):
            with self.subTest(complete_path=complete_path):
                result = qualification.classify_paths(
                    [
                        "scripts/conformance/worker-status-published-artifacts.mjs",
                        "tests/Unit/WorkerStatusConformanceRunnerTest.php",
                        complete_path,
                    ]
                )

                self.assertEqual(qualification.COMPLETE, result.name)
                self.assertEqual("complete-path-present", result.reason)

    def test_release_and_conformance_paths_mixed_together_fail_closed(self) -> None:
        result = qualification.classify_paths(
            [
                "scripts/ci/component-release-recovery.py",
                "scripts/conformance/worker-status-published-artifacts.mjs",
            ]
        )

        self.assertEqual(qualification.COMPLETE, result.name)
        self.assertEqual("complete-path-present", result.reason)

    def test_release_mixed_with_runtime_change_still_fails_closed(self) -> None:
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

    def test_classifier_and_build_workflow_cannot_select_focused_route(self) -> None:
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

            documentation_path = repository / "CHANGELOG.md"
            documentation_path.write_text("# Release notes\n")
            subprocess.run(["git", "-C", repository, "add", "."], check=True)
            subprocess.run(
                ["git", "-C", repository, "commit", "-qm", "documentation"],
                check=True,
            )
            documentation_head = subprocess.check_output(
                ["git", "-C", repository, "rev-parse", "HEAD"],
                text=True,
            ).strip()
            self.assertEqual(
                qualification.NON_RUNTIME,
                qualification.classify_event(
                    repository,
                    "push",
                    release_head,
                    documentation_head,
                ).name,
            )

            conformance_path = (
                repository
                / "scripts"
                / "conformance"
                / "worker-status-published-artifacts.mjs"
            )
            conformance_path.parent.mkdir(parents=True)
            conformance_path.write_text("export const baseline = false;\n")
            subprocess.run(["git", "-C", repository, "add", "."], check=True)
            subprocess.run(
                ["git", "-C", repository, "commit", "-qm", "conformance"],
                check=True,
            )
            conformance_head = subprocess.check_output(
                ["git", "-C", repository, "rev-parse", "HEAD"],
                text=True,
            ).strip()
            self.assertEqual(
                qualification.CONFORMANCE,
                qualification.classify_event(
                    repository,
                    "push",
                    documentation_head,
                    conformance_head,
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
                    conformance_head,
                    runtime_head,
                ).name,
            )


class QualificationGateTest(unittest.TestCase):
    def test_conformance_route_requires_focused_job_and_skipped_matrices(
        self,
    ) -> None:
        observed = dict(qualification.expected_results(qualification.CONFORMANCE))
        self.assertEqual(
            (),
            qualification.evaluate_results("conformance", observed),
        )

        for job, result in (
            ("conformance-contracts", "skipped"),
            ("frontend", "success"),
            ("laravel-matrix", "success"),
            ("database", "success"),
        ):
            with self.subTest(job=job, result=result):
                invalid = {**observed, job: result}
                self.assertNotEqual(
                    (),
                    qualification.evaluate_results("conformance", invalid),
                )

        self.assertEqual(
            qualification.COMMON_FOCUSED_CHECKS
            + qualification.CONFORMANCE_FOCUSED_CHECKS,
            qualification.focused_checks(qualification.CONFORMANCE),
        )

    def test_every_route_requires_standalone_security_audit(self) -> None:
        for classification in qualification.QUALIFICATION_CLASSES:
            with self.subTest(classification=classification):
                self.assertEqual(
                    "success",
                    qualification.expected_results(classification)[
                        "release-contracts"
                    ],
                )
                self.assertIn(
                    "standalone-locked-composer-audit",
                    qualification.focused_checks(classification),
                )

    def test_release_route_requires_matrix_jobs_to_be_skipped(self) -> None:
        observed = dict(qualification.expected_results(qualification.RELEASE))
        self.assertEqual((), qualification.evaluate_results("release", observed))

        observed["database"] = "success"
        self.assertNotEqual((), qualification.evaluate_results("release", observed))

        observed = dict(qualification.expected_results(qualification.RELEASE))
        observed["conformance-contracts"] = "success"
        self.assertNotEqual((), qualification.evaluate_results("release", observed))

    def test_non_runtime_route_requires_focused_checks_and_skipped_matrices(
        self,
    ) -> None:
        observed = dict(qualification.expected_results(qualification.NON_RUNTIME))
        self.assertEqual((), qualification.evaluate_results("non-runtime", observed))

        for job in (
            "frontend",
            "build",
            "laravel-matrix",
            "laravel-compatibility",
            "database",
        ):
            with self.subTest(job=job):
                invalid = {**observed, job: "success"}
                self.assertNotEqual(
                    (),
                    qualification.evaluate_results("non-runtime", invalid),
                )

        self.assertEqual(
            qualification.COMMON_FOCUSED_CHECKS
            + qualification.NON_RUNTIME_FOCUSED_CHECKS,
            qualification.focused_checks(qualification.NON_RUNTIME),
        )

    def test_complete_route_requires_every_matrix_job(self) -> None:
        observed = dict(qualification.expected_results(qualification.COMPLETE))
        self.assertEqual((), qualification.evaluate_results("complete", observed))

        for job, result in (
            ("frontend", "skipped"),
            ("conformance-contracts", "success"),
            ("laravel-compatibility", "skipped"),
        ):
            with self.subTest(job=job, result=result):
                invalid = {**observed, job: result}
                self.assertNotEqual(
                    (),
                    qualification.evaluate_results("complete", invalid),
                )

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
        conformance_baseline = benchmark["conformance_change_baseline"]
        improved = benchmark["improved_release_path"]
        self.assertEqual("push", conformance_baseline["event"])
        self.assertEqual("v2", conformance_baseline["head_branch"])
        self.assertEqual("complete", conformance_baseline["selected_class"])
        self.assertGreater(conformance_baseline["elapsed_seconds"], 0)
        self.assertEqual(
            baseline["elapsed_seconds"] - improved["projected_elapsed_seconds"],
            improved["projected_saved_seconds"],
        )
        self.assertEqual(
            improved["projected_elapsed_seconds"],
            sum(improved["projection_components_seconds"].values()),
        )


class ConformanceQualificationWorkflowContractTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls) -> None:
        cls.workflow = yaml.load(
            (ROOT / ".github" / "workflows" / "php.yml").read_text(),
            Loader=yaml.BaseLoader,
        )
        if not isinstance(cls.workflow, dict):
            raise RuntimeError("build workflow must be a mapping")

    def conformance_job(self) -> dict:
        job = self.workflow["jobs"]["conformance-contracts"]
        self.assertIsInstance(job, dict)
        return job

    def named_step(self, name: str) -> dict:
        steps = self.conformance_job()["steps"]
        matches = [
            step
            for step in steps
            if isinstance(step, dict) and step.get("name") == name
        ]
        self.assertEqual(1, len(matches), f"expected one workflow step named {name}")
        return matches[0]

    def test_focused_job_is_restricted_to_public_conformance_qualification(
        self,
    ) -> None:
        self.assertEqual(
            "${{ github.server_url == 'https://github.com' && needs.classify.outputs.qualification-class == 'conformance' }}",
            self.conformance_job()["if"],
        )

    def test_focused_job_runs_exact_php_and_node_regressions(self) -> None:
        php_command = self.named_step(
            "Run focused PHP conformance regression"
        )["run"].split()
        self.assertEqual(
            [
                "vendor/bin/phpunit",
                "--configuration=phpunit-sqlite.xml",
                "tests/Unit/WorkerStatusConformanceRunnerTest.php",
            ],
            php_command,
        )

        node_command = self.named_step(
            "Run focused Node conformance regressions"
        )["run"].split()
        self.assertEqual(
            [
                "node",
                "--test",
                "tests/Unit/WorkerStatusNetworkTest.mjs",
                "tests/Unit/WorkerStatusRunnerLifecycleTest.mjs",
                "tests/Unit/WorkerStatusSharedIsolationTest.mjs",
                "tests/Unit/WorkerStatusSharedTopologyTest.mjs",
                "tests/Unit/WorkerStatusVersionTest.mjs",
            ],
            node_command,
        )

    def test_target_gate_observes_focused_and_frontend_jobs(self) -> None:
        gate = self.workflow["jobs"]["target-branch-qualification"]
        self.assertIn("conformance-contracts", gate["needs"])
        self.assertIn("frontend", gate["needs"])
        command = self.named_gate_step(gate)["run"]
        self.assertIn(
            "--conformance-contracts-result=\"$CONFORMANCE_CONTRACTS_RESULT\"",
            command,
        )
        self.assertIn("--frontend-result=\"$FRONTEND_RESULT\"", command)

    def named_gate_step(self, gate: dict) -> dict:
        matches = [
            step
            for step in gate["steps"]
            if isinstance(step, dict)
            and step.get("name") == "Enforce selected qualification class"
        ]
        self.assertEqual(1, len(matches))
        return matches[0]


class NonRuntimeQualificationWorkflowContractTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls) -> None:
        cls.workflow = yaml.load(
            (ROOT / ".github" / "workflows" / "php.yml").read_text(),
            Loader=yaml.BaseLoader,
        )
        if not isinstance(cls.workflow, dict):
            raise RuntimeError("build workflow must be a mapping")

    def test_non_runtime_route_retains_boundary_policy_and_release_checks(
        self,
    ) -> None:
        jobs = self.workflow["jobs"]
        classify_steps = jobs["classify"]["steps"]
        classify_commands = "\n".join(
            step.get("run", "") for step in classify_steps if isinstance(step, dict)
        )

        self.assertIn("scripts/ci/workflow_trust_policy.py", classify_commands)
        self.assertIn("scripts/check-public-boundary.sh", classify_commands)
        self.assertNotIn("if", jobs["release-contracts"])
        release_commands = "\n".join(
            step.get("run", "")
            for step in jobs["release-contracts"]["steps"]
            if isinstance(step, dict)
        )
        self.assertIn("scripts/ci/release_example_contract.py", release_commands)

    def test_every_runtime_job_explicitly_requires_complete_classification(
        self,
    ) -> None:
        jobs = self.workflow["jobs"]
        complete_condition = "needs.classify.outputs.qualification-class == 'complete'"

        for job_name in (
            "frontend",
            "build",
            "laravel-matrix",
            "laravel-compatibility",
            "qualification",
        ):
            with self.subTest(job_name=job_name):
                job = jobs[job_name]
                self.assertIn(complete_condition, job["if"])
                needs = job["needs"]
                if isinstance(needs, str):
                    needs = [needs]
                self.assertIn("classify", needs)


class StandaloneComposerAuditWorkflowContractTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls) -> None:
        cls.workflow = yaml.load(
            (ROOT / ".github" / "workflows" / "php.yml").read_text(),
            Loader=yaml.BaseLoader,
        )
        if not isinstance(cls.workflow, dict):
            raise RuntimeError("build workflow must be a mapping")

    def release_contracts_job(self) -> dict:
        job = self.workflow["jobs"]["release-contracts"]
        self.assertIsInstance(job, dict)
        return job

    def named_step(self, name: str) -> dict:
        matches = [
            step
            for step in self.release_contracts_job()["steps"]
            if isinstance(step, dict) and step.get("name") == name
        ]
        self.assertEqual(1, len(matches), f"expected one workflow step named {name}")
        return matches[0]

    def test_audit_reads_the_locked_production_service_graph(self) -> None:
        command = self.named_step(
            "Audit the locked standalone Composer graph"
        )["run"].split()

        self.assertEqual(
            [
                "composer",
                "audit",
                "--working-dir=standalone",
                "--locked",
                "--no-dev",
                "--no-interaction",
            ],
            command,
        )

    def test_audit_job_is_required_for_every_qualification_route(self) -> None:
        job = self.release_contracts_job()
        gate = self.workflow["jobs"]["target-branch-qualification"]

        self.assertNotIn("if", job)
        self.assertIn("release-contracts", gate["needs"])
        for classification in qualification.QUALIFICATION_CLASSES:
            with self.subTest(classification=classification):
                self.assertEqual(
                    "success",
                    qualification.expected_results(classification)[
                        "release-contracts"
                    ],
                )

    def test_lock_identity_contract_remains_required(self) -> None:
        release_validation = self.named_step(
            "Validate release and recovery tooling"
        )["run"]

        self.assertIn(
            '"$policy_python" scripts/ci/standalone_lock_contract.py',
            release_validation,
        )
        for path in (
            ".github/workflows/service-image-smoke.yml",
            "scripts/ci/service-mode-image-smoke.sh",
            "standalone/composer.lock",
        ):
            with self.subTest(path=path):
                self.assertNotIn(path, qualification.RELEASE_ONLY_PATHS)
                self.assertEqual(
                    qualification.COMPLETE,
                    qualification.classify_paths([path]).name,
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

    def matrix_rows(self, database: str) -> list[dict]:
        return [
            row
            for row in self.qualification_job()["strategy"]["matrix"]["include"]
            if isinstance(row, dict) and row.get("database") == database
        ]

    def test_mysql_suite_is_complete_deterministic_and_parallel(self) -> None:
        rows = self.matrix_rows("mysql")

        self.assertEqual(4, len(rows))
        self.assertEqual(["0", "1", "2", "3"], [row["shard-index"] for row in rows])
        self.assertEqual({"4"}, {row["shard-count"] for row in rows})
        self.assertEqual({"phpunit-mysql.xml"}, {row["configuration"] for row in rows})
        self.assertEqual({"test-mysql"}, {row["composer-script"] for row in rows})
        self.assertEqual({"480"}, {row["target-seconds"] for row in rows})
        self.assertEqual({"7m"}, {row["command_timeout"] for row in rows})
        self.assertEqual(
            {
                "mysql shard 1/4",
                "mysql shard 2/4",
                "mysql shard 3/4",
                "mysql shard 4/4",
            },
            {row["label"] for row in rows},
        )

        selection = self.named_step("Select deterministic test scope")["run"]
        self.assertIn("scripts/ci/qualification_shards.py select", selection)
        self.assertIn('--shard-index="${{ matrix.shard-index }}"', selection)
        self.assertIn('--shard-count="${{ matrix.shard-count }}"', selection)

        runner = self.named_step(
            "Run ${{ matrix.label }} complete test scope"
        )["run"]
        self.assertIn('--filter "$QUALIFICATION_FILTER"', runner)
        self.assertIn('--log-junit "$junit_report"', runner)
        self.assertIn("scripts/ci/qualification_timing.py", runner)
        self.assertIn("timeout --foreground", runner)

    def test_other_database_cells_still_run_the_complete_inventory(self) -> None:
        expected = {
            "mssql": ("phpunit-mssql.xml", "test-mssql"),
            "pgsql": ("phpunit-pgsql.xml", "test-pgsql"),
            "sqlite": ("phpunit-sqlite.xml", "test-sqlite"),
        }

        for database, (configuration, composer_script) in expected.items():
            with self.subTest(database=database):
                rows = self.matrix_rows(database)
                self.assertEqual(1, len(rows))
                self.assertEqual("0", rows[0]["shard-index"])
                self.assertEqual("1", rows[0]["shard-count"])
                self.assertEqual(configuration, rows[0]["configuration"])
                self.assertEqual(composer_script, rows[0]["composer-script"])

    def test_aggregate_gate_requires_every_database_matrix_cell(self) -> None:
        gate = self.workflow["jobs"]["target-branch-qualification"]

        self.assertIn("qualification", gate["needs"])
        command = [
            step["run"]
            for step in gate["steps"]
            if isinstance(step, dict)
            and step.get("name") == "Enforce selected qualification class"
        ]
        self.assertEqual(1, len(command))
        self.assertIn('--database-result="$DATABASE_RESULT"', command[0])

    def test_sql_server_cell_installs_only_the_required_odbc_runtime(self) -> None:
        sql_server_rows = self.matrix_rows("mssql")
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

    def test_sql_server_preflight_and_laravel_share_test_tls_policy(self) -> None:
        policy_path = ROOT / "scripts" / "ci" / "SqlServerQualificationTls.php"
        self.assertTrue(policy_path.is_file())
        preflight = (ROOT / "scripts" / "ci" / "preflight-databases.php").read_text()
        laravel_test_case = (ROOT / "tests" / "TestCase.php").read_text()
        self.assertIn("SqlServerQualificationTls::odbcDsnAttributes()", preflight)
        self.assertIn(
            "SqlServerQualificationTls::laravelConfiguration()",
            laravel_test_case,
        )


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
