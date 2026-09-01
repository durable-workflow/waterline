#!/usr/bin/env python3
"""Focused tests for Waterline qualification and Actions trust policy."""

from __future__ import annotations

import importlib.util
import sys
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


def workflow(name: str) -> dict[str, object]:
    value = yaml.load(
        (ROOT / ".github" / "workflows" / name).read_text(encoding="utf-8"),
        Loader=yaml.BaseLoader,
    )
    if not isinstance(value, dict):
        raise RuntimeError(f"{name} must contain a workflow mapping")
    return value


class ChangeClassificationTest(unittest.TestCase):
    def test_stable_release_files_use_the_focused_release_route(self) -> None:
        result = qualification.classify_paths(qualification.RELEASE_ONLY_PATHS)

        self.assertEqual(qualification.RELEASE, result.name)
        self.assertEqual("release-paths-only", result.reason)

    def test_conformance_files_use_the_focused_conformance_route(self) -> None:
        result = qualification.classify_paths(qualification.CONFORMANCE_ONLY_PATHS)

        self.assertEqual(qualification.CONFORMANCE, result.name)
        self.assertEqual("conformance-paths-only", result.reason)

    def test_documentation_files_do_not_select_runtime_matrices(self) -> None:
        result = qualification.classify_paths(
            ["README.md", "docs/screenshots/dashboard.png"]
        )

        self.assertEqual(qualification.NON_RUNTIME, result.name)

    def test_runtime_or_mixed_changes_fail_closed_to_complete(self) -> None:
        for paths in (
            ["app/Http/Controllers/WorkflowsController.php"],
            [
                "scripts/ci/waterline_release_identity.py",
                "config/waterline.php",
            ],
            [
                "scripts/conformance/worker-status-network.mjs",
                "standalone/composer.json",
            ],
        ):
            with self.subTest(paths=paths):
                self.assertEqual(
                    qualification.COMPLETE,
                    qualification.classify_paths(paths).name,
                )

    def test_unsafe_or_unavailable_changes_fail_closed_to_complete(self) -> None:
        for paths in ([], ["../composer.json"], ["/tmp/composer.json"], [""]):
            with self.subTest(paths=paths):
                self.assertEqual(
                    qualification.COMPLETE,
                    qualification.classify_paths(paths).name,
                )

    def test_visual_surfaces_are_selected_from_changed_product_files(self) -> None:
        dialog = qualification.classify_paths(["resources/js/dialogs.mjs"])
        detail = qualification.classify_paths(
            ["resources/js/screens/flows/flow.vue"]
        )
        shared = qualification.classify_paths(["resources/sass/base.scss"])

        self.assertTrue(qualification.requires_dialog_visual(dialog))
        self.assertFalse(qualification.requires_run_detail_visual(dialog))
        self.assertFalse(qualification.requires_dialog_visual(detail))
        self.assertTrue(qualification.requires_run_detail_visual(detail))
        self.assertEqual(
            frozenset({"dialog", "run-detail"}),
            qualification.visual_surfaces(shared),
        )

    def test_gate_contract_accepts_each_expected_route(self) -> None:
        for classification in (
            qualification.COMPLETE,
            qualification.CONFORMANCE,
            qualification.NON_RUNTIME,
            qualification.RELEASE,
        ):
            with self.subTest(classification=classification):
                expected = qualification.expected_results(classification)
                self.assertEqual(
                    (),
                    qualification.evaluate_results(classification, expected),
                )


class WorkflowContractTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls) -> None:
        cls.build = workflow("php.yml")

    def named_step(self, name: str) -> dict[str, object]:
        for job in self.build["jobs"].values():
            if not isinstance(job, dict):
                continue
            for step in job.get("steps", []):
                if isinstance(step, dict) and step.get("name") == name:
                    return step
        self.fail(f"workflow step not found: {name}")

    def test_stable_release_contract_has_no_prerelease_recovery_consumer(self) -> None:
        job = self.build["jobs"]["release-contracts"]
        self.assertEqual("Stable release contracts", job["name"])
        commands = "\n".join(
            str(step.get("run", ""))
            for step in job["steps"]
            if isinstance(step, dict)
        )

        for required in (
            "scripts/ci/waterline_release_identity.py",
            "scripts/ci/release_example_contract.py",
            "scripts/ci/standalone_lock_contract.py",
            "scripts/ci/check-onboarding-composer.sh",
        ):
            self.assertIn(required, commands)

    def test_public_service_smoke_reads_the_stable_product_tuple(self) -> None:
        document = workflow("service-image-smoke.yml")
        commands = "\n".join(
            str(step.get("run", ""))
            for step in document["jobs"]["smoke"]["steps"]
            if isinstance(step, dict)
        )

        self.assertIn("release/current-product-tuple.json", commands)
        self.assertIn("durableworkflow/waterline:${EXPECTED_WATERLINE_VERSION}", commands)

    def test_capacity_tuple_has_one_public_server_checkout(self) -> None:
        document = workflow("service-capacity-tuple.yml")
        steps = document["jobs"]["live-tuple"]["steps"]
        server_checkouts = [
            step
            for step in steps
            if isinstance(step, dict)
            and step.get("with", {}).get("repository")
            == "${{ github.repository_owner }}/server"
        ]

        self.assertEqual(1, len(server_checkouts))
        self.assertNotIn("if", server_checkouts[0])

    def test_final_gate_requires_the_selected_jobs(self) -> None:
        gate = self.build["jobs"]["target-branch-qualification"]

        self.assertEqual("${{ always() }}", gate["if"])
        self.assertIn("release-contracts", gate["needs"])
        self.assertIn("classification", qualification.expected_results("release"))


class WorkflowTrustPolicyTest(unittest.TestCase):
    def test_repository_workflows_satisfy_policy(self) -> None:
        self.assertEqual((), trust.audit_repository(ROOT))

    def test_untrusted_workflow_cannot_use_target_event_secrets_or_writes(self) -> None:
        document = yaml.load(
            """
on: [pull_request_target]
permissions:
  contents: write
jobs:
  unsafe:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v6
      - run: echo ${{ secrets.DEPLOY_TOKEN }}
""",
            Loader=yaml.BaseLoader,
        )
        codes = {
            violation.code
            for violation in trust.validate_document("unsafe.yml", document)
        }

        self.assertIn("pull-request-target", codes)
        self.assertIn("untrusted-root-write", codes)
        self.assertIn("untrusted-secret", codes)
        self.assertIn("unpinned-action", codes)

    def test_untrusted_workflow_isolated_from_credentials_and_cache(self) -> None:
        document = yaml.load(
            """
on: [pull_request]
permissions:
  contents: read
jobs:
  unsafe:
    runs-on: ubuntu-latest
    environment: release
    env:
      TOKEN: ${{ secrets.DEPLOY_TOKEN }}
    steps:
      - uses: actions/checkout@d23441a48e516b6c34aea4fa41551a30e30af803
      - uses: actions/cache@caa296126883cff596d87d8935842f9db880ef25
        with:
          path: vendor
          key: composer-${{ hashFiles('composer.lock') }}
""",
            Loader=yaml.BaseLoader,
        )
        codes = {
            violation.code
            for violation in trust.validate_document("unsafe.yml", document)
        }

        self.assertIn("untrusted-environment", codes)
        self.assertIn("untrusted-secret", codes)
        self.assertIn("untrusted-persisted-checkout", codes)
        self.assertIn("cache-cross-trust-boundary", codes)

    def test_required_focused_workflow_cannot_disappear(self) -> None:
        with self.subTest("missing command"):
            document = yaml.load(
                """
on: [pull_request, push]
permissions:
  contents: read
jobs:
  check:
    runs-on: ubuntu-latest
    steps:
      - run: echo missing
""",
                Loader=yaml.BaseLoader,
            )
            codes = {
                violation.code
                for violation in trust.validate_document(
                    "public-boundary.yml", document
                )
            }
            self.assertIn("missing-focused-command", codes)


if __name__ == "__main__":
    unittest.main()
