#!/usr/bin/env python3
"""Focused regression coverage for release recovery workflow verification."""

from __future__ import annotations

import hashlib
import importlib.util
import sys
import unittest
from pathlib import Path

SCRIPT = Path(__file__).with_name("component-release-recovery.py")
SPEC = importlib.util.spec_from_file_location("component_release_recovery", SCRIPT)
assert SPEC is not None and SPEC.loader is not None
recovery = importlib.util.module_from_spec(SPEC)
sys.modules[SPEC.name] = recovery
SPEC.loader.exec_module(recovery)

REPOSITORY_ROOT = Path(__file__).resolve().parents[2]
WATERLINE_WORKFLOW_PATH = REPOSITORY_ROOT / ".github" / "workflows" / "release-plan-recovery.yml"
WATERLINE_WORKFLOW_BYTES = WATERLINE_WORKFLOW_PATH.read_bytes()
WATERLINE_WORKFLOW = WATERLINE_WORKFLOW_BYTES.decode("utf-8")
RUST_WORKFLOW_PATH = Path(__file__).with_name("fixtures") / "sdk-rust-release-plan-recovery.yml"
RUST_WORKFLOW_BYTES = RUST_WORKFLOW_PATH.read_bytes()
RUST_WORKFLOW = RUST_WORKFLOW_BYTES.decode("utf-8")

SERVER_WORKFLOW = """name: Release plan recovery

on:
  schedule:
  workflow_dispatch:

jobs:
  recover:
    steps:
      - run: |
          gh api --method POST repos/example/git/refs \\
            -f ref="refs/tags/$RELEASE_TAG" -f sha="$RELEASE_COMMIT"
          select-publication-run
          gh run list --json databaseId,displayTitle,headBranch,headSha,status,conclusion
          python recovery.py --release-tag "$RELEASE_TAG" --release-commit "$RELEASE_COMMIT"
          gh workflow run release.yml --ref "$RELEASE_TAG" -f tag="$RELEASE_TAG"
"""


class RecoveryWorkflowVerificationTest(unittest.TestCase):
    def waterline_mutation(self, old: str, new: str, *, count: int = 1) -> str:
        self.assertIn(old, WATERLINE_WORKFLOW)
        changed = WATERLINE_WORKFLOW.replace(old, new, count)
        self.assertNotEqual(changed, WATERLINE_WORKFLOW)
        return changed

    def mutation(self, old: str, new: str, *, count: int = 1) -> str:
        self.assertIn(old, RUST_WORKFLOW)
        changed = RUST_WORKFLOW.replace(old, new, count)
        self.assertNotEqual(changed, RUST_WORKFLOW)
        return changed

    def assert_rust_rejected(self, source: str) -> None:
        self.assertNotEqual(source, RUST_WORKFLOW)
        with self.assertRaises(recovery.RecoveryError) as caught:
            recovery.verify_recovery_workflow_source("sdk-rust", source)
        self.assertEqual(caught.exception.phase, "default-branch-preflight")

    def assert_waterline_rejected(self, source: str) -> None:
        self.assertNotEqual(source, WATERLINE_WORKFLOW)
        with self.assertRaises(recovery.RecoveryError) as caught:
            recovery.verify_recovery_workflow_source("waterline", source)
        self.assertEqual(caught.exception.phase, "default-branch-preflight")

    def test_accepts_only_the_canonical_protected_waterline_workflow_identity(self) -> None:
        self.assertEqual(
            hashlib.sha256(WATERLINE_WORKFLOW_BYTES).hexdigest(),
            recovery.WATERLINE_RECOVERY_WORKFLOW_SHA256,
        )
        recovery.verify_recovery_workflow_source("waterline", WATERLINE_WORKFLOW)
        recovery.verify_recovery_workflow_source("waterline", WATERLINE_WORKFLOW.replace("\n", "\r\n"))

    def test_rejects_waterline_protection_and_authority_mutations(self) -> None:
        variants = {
            "unprotected environment": self.waterline_mutation(
                "    environment: release-plan-publication",
                "    environment: unprotected",
            ),
            "wrong deploy key": self.waterline_mutation("RELEASE_PLAN_DEPLOY_KEY", "OTHER_DEPLOY_KEY"),
            "broad discovery token": self.waterline_mutation(
                "permissions:\n  attestations: read\n  contents: read",
                "permissions:\n  attestations: read\n  contents: write",
            ),
            "repository-token tag creation": self.waterline_mutation(
                "          python scripts/ci/publish-planned-tag.py \\",
                "          gh api --method POST repos/$GITHUB_REPOSITORY/git/refs\n"
                "          python scripts/ci/publish-planned-tag.py \\",
            ),
            "publication bypass": self.waterline_mutation(
                "    if: needs.discover.outputs.action == 'publish'",
                "    if: always()",
            ),
        }
        for label, source in variants.items():
            with self.subTest(label=label):
                self.assert_waterline_rejected(source)

    def test_rejects_waterline_plan_and_source_binding_mutations(self) -> None:
        variants = {
            "mutable plan artifact": self.waterline_mutation(
                "name: waterline-release-recovery-${{ needs.discover.outputs.plan }}",
                "name: waterline-release-recovery-${{ github.run_id }}",
            ),
            "wrong plan binding": self.waterline_mutation(
                "          PLAN_TAG: ${{ needs.discover.outputs.plan_tag }}",
                "          PLAN_TAG: ${{ github.ref_name }}",
            ),
            "wrong release tag binding": self.waterline_mutation(
                "          RELEASE_TAG: ${{ needs.discover.outputs.version }}",
                "          RELEASE_TAG: ${{ github.ref_name }}",
            ),
            "wrong commit binding": self.waterline_mutation(
                "          RELEASE_COMMIT: ${{ needs.discover.outputs.commit }}",
                "          RELEASE_COMMIT: ${{ github.sha }}",
            ),
            "wrong publisher argument": self.waterline_mutation(
                '--commit "$RELEASE_COMMIT"',
                '--commit "$GITHUB_SHA"',
            ),
            "publisher moved after registry wait": self.waterline_mutation(
                "      - name: Create or verify the exact planned source tag",
                "      - name: Wait for Packagist before creating the exact planned source tag",
            ),
        }
        for label, source in variants.items():
            with self.subTest(label=label):
                self.assert_waterline_rejected(source)

    def test_rejects_incomplete_or_nonblocking_waterline_public_verification(self) -> None:
        variants = {
            "registry verification skipped": self.waterline_mutation(
                "      - name: Wait for Packagist source identity\n",
                "      - name: Wait for Packagist source identity\n        if: false\n",
            ),
            "registry verification ignored": self.waterline_mutation(
                "--attempts 30 --sleep 20 --evidence registry-publication-evidence.json",
                "--attempts 30 --sleep 20 --evidence registry-publication-evidence.json || true",
            ),
            "GitHub release omitted": self.waterline_mutation("--prerelease", "--draft"),
            "completion verification nonblocking": self.waterline_mutation(
                "      - name: Verify completed public release\n",
                "      - name: Verify completed public release\n        continue-on-error: true\n",
            ),
            "registry-only completion": self.waterline_mutation(
                "--attempts 3 --sleep 5 --evidence release-completion-evidence.json",
                "--registry-only --attempts 3 --sleep 5 --evidence release-completion-evidence.json",
            ),
        }
        for label, source in variants.items():
            with self.subTest(label=label):
                self.assert_waterline_rejected(source)

    def test_requires_the_planned_waterline_github_release_to_be_a_prerelease(self) -> None:
        class ReleaseClient:
            def __init__(self, prerelease: bool) -> None:
                self.prerelease = prerelease

            def json(self, _url: str) -> dict[str, object]:
                return {
                    "id": 136,
                    "html_url": "https://github.com/durable-workflow/waterline/releases/tag/2.0.0-alpha.136",
                    "tag_name": "2.0.0-alpha.136",
                    "draft": False,
                    "prerelease": self.prerelease,
                }

        verified = recovery.verify_github_release(ReleaseClient(True), "waterline", "2.0.0-alpha.136")
        self.assertTrue(verified["prerelease"])
        with self.assertRaises(recovery.RecoveryError) as caught:
            recovery.verify_github_release(ReleaseClient(False), "waterline", "2.0.0-alpha.136")
        self.assertEqual(caught.exception.phase, "github-release")

    def test_accepts_only_the_canonical_public_rust_workflow_identity(self) -> None:
        self.assertEqual(
            hashlib.sha256(RUST_WORKFLOW_BYTES).hexdigest(),
            recovery.SDK_RUST_RECOVERY_WORKFLOW_SHA256,
        )
        recovery.verify_recovery_workflow_source("sdk-rust", RUST_WORKFLOW)
        recovery.verify_recovery_workflow_source("sdk-rust", RUST_WORKFLOW.replace("\n", "\r\n"))

    def test_rejects_one_byte_and_line_mutations(self) -> None:
        variants = {
            "one byte": self.mutation("name: Release plan recovery", "name: Release plan recoverx"),
            "one line": self.mutation("    - cron: '47 * * * *'", "    - cron: '48 * * * *'"),
            "bare carriage return": RUST_WORKFLOW.replace("\n", "\r", 1),
        }
        for label, source in variants.items():
            with self.subTest(label=label):
                self.assert_rust_rejected(source)

    def test_rejects_missing_protection_or_tag_authority(self) -> None:
        misplaced_deploy_key = self.mutation(
            "          ssh-key: ${{ secrets.RELEASE_PLAN_DEPLOY_KEY }}\n",
            "",
        ).replace(
            "        env:\n          PLAN_TAG: ${{ needs.discover.outputs.plan_tag }}",
            "        env:\n"
            "          RELEASE_PLAN_DEPLOY_KEY: ${{ secrets.RELEASE_PLAN_DEPLOY_KEY }}\n"
            "          PLAN_TAG: ${{ needs.discover.outputs.plan_tag }}",
            1,
        )
        uninvoked_publisher = self.mutation(
            "          python scripts/ci/publish-planned-tag.py \\\n"
            "            --tag \"$RELEASE_TAG\" --commit \"$RELEASE_COMMIT\" --plan-tag \"$PLAN_TAG\" \\\n"
            "            --evidence release-tag-publication-evidence.json",
            "          publish_planned_tag() {\n"
            "            python scripts/ci/publish-planned-tag.py \\\n"
            "              --tag \"$RELEASE_TAG\" --commit \"$RELEASE_COMMIT\" --plan-tag \"$PLAN_TAG\" \\\n"
            "              --evidence release-tag-publication-evidence.json\n"
            "          }",
        )
        variants = {
            "unprotected environment": self.mutation(
                "    environment: release-plan-publication",
                "    environment: unprotected",
            ),
            "wrong deploy key": self.mutation("RELEASE_PLAN_DEPLOY_KEY", "OTHER_DEPLOY_KEY"),
            "deploy key moved to unrelated env": misplaced_deploy_key,
            "broad repository token": self.mutation(
                "    permissions:\n      actions: write\n      contents: read",
                "    permissions:\n      actions: write\n      contents: write",
            ),
            "repository-token tag creation": self.mutation(
                "            --evidence release-tag-publication-evidence.json",
                "            --evidence release-tag-publication-evidence.json\n"
                "          gh api --method POST repos/$GITHUB_REPOSITORY/git/refs "
                "-f ref=refs/tags/$RELEASE_TAG -f sha=$RELEASE_COMMIT",
            ),
            "publisher skipped": self.mutation(
                "      - name: Create or verify the exact planned source tag\n",
                "      - name: Create or verify the exact planned source tag\n        if: false\n",
            ),
            "publisher non-blocking": self.mutation(
                "      - name: Create or verify the exact planned source tag\n",
                "      - name: Create or verify the exact planned source tag\n        continue-on-error: true\n",
            ),
            "publisher defined but not invoked": uninvoked_publisher,
        }
        for label, source in variants.items():
            with self.subTest(label=label):
                self.assert_rust_rejected(source)

    def test_rejects_mutable_or_mismatched_release_bindings(self) -> None:
        wrong_step_bindings = self.mutation(
            "          RELEASE_TAG: ${{ needs.discover.outputs.version }}\n"
            "          RELEASE_COMMIT: ${{ needs.discover.outputs.commit }}",
            "          RELEASE_TAG: ${{ github.ref_name }}\n"
            "          RELEASE_COMMIT: ${{ github.sha }}",
        )
        wrong_step_bindings = wrong_step_bindings.replace(
            "      - name: Restore the immutable release plan\n",
            "      - name: Restore the immutable release plan\n"
            "        env:\n"
            "          RELEASE_TAG: ${{ needs.discover.outputs.version }}\n"
            "          RELEASE_COMMIT: ${{ needs.discover.outputs.commit }}\n",
            1,
        )
        variants = {
            "mutable publisher tag": self.mutation(
                "          RELEASE_TAG: ${{ needs.discover.outputs.version }}",
                "          RELEASE_TAG: ${{ github.ref_name }}",
            ),
            "mismatched publisher commit": self.mutation(
                "          RELEASE_COMMIT: ${{ needs.discover.outputs.commit }}",
                "          RELEASE_COMMIT: ${{ github.sha }}",
            ),
            "wrong publisher tag argument": self.mutation('--tag "$RELEASE_TAG"', '--tag "$PLAN_TAG"'),
            "wrong publisher commit argument": self.mutation(
                '--commit "$RELEASE_COMMIT"',
                '--commit "$GITHUB_SHA"',
            ),
            "exact bindings moved to unrelated step": wrong_step_bindings,
            "readarray mutates tag": self.mutation(
                "          set -euo pipefail\n",
                "          set -euo pipefail\n"
                "          readarray -t RELEASE_TAG < <(printf '%s\\n' 2.0.0-alpha.999)\n",
            ),
            "assignment mutates tag": self.mutation(
                "          set -euo pipefail\n",
                "          set -euo pipefail\n          RELEASE_TAG=2.0.0-alpha.999\n",
            ),
        }
        for label, source in variants.items():
            with self.subTest(label=label):
                self.assert_rust_rejected(source)

    def test_rejects_publication_control_flow_and_identity_mutations(self) -> None:
        variants = {
            "tag publication after selection": self.mutation(
                "          python scripts/ci/publish-planned-tag.py \\",
                "          python scripts/ci/component-release-recovery.py select-publication-run && "
                "python scripts/ci/publish-planned-tag.py \\",
            ),
            "different listed workflow": self.mutation(
                "gh run list --workflow release.yml",
                "gh run list --workflow other.yml",
            ),
            "different dispatched workflow": self.mutation(
                "gh workflow run release.yml",
                "gh workflow run other.yml",
            ),
            "appended run identity": self.mutation(
                "databaseId,displayTitle,headBranch,headSha,status,conclusion",
                "databaseId,displayTitle,headBranch,headSha,status,conclusion,event",
            ),
            "wrong selector tag": self.mutation(
                '--release-tag "$RELEASE_TAG"',
                '--release-tag "$PLAN_TAG"',
            ),
            "wrong selector commit": self.mutation(
                '--release-commit "$RELEASE_COMMIT"',
                '--release-commit "$GITHUB_SHA"',
            ),
            "wrong dispatch tag": self.mutation(
                '-f release_tag="$RELEASE_TAG"',
                '-f release_tag="$PLAN_TAG"',
            ),
            "successful early exit": self.mutation(
                "          set -euo pipefail\n",
                "          set -euo pipefail\n          exit 0\n",
            ),
            "shadowed gh command": self.mutation(
                "          set -euo pipefail\n",
                "          set -euo pipefail\n\n"
                "          gh() {\n"
                "            command gh workflow run other.yml \"$@\"\n"
                "          }\n",
            ),
        }
        for label, source in variants.items():
            with self.subTest(label=label):
                self.assert_rust_rejected(source)

    def test_rejects_incomplete_or_nonblocking_public_release_verification(self) -> None:
        variants = {
            "registry-only verification": self.mutation(
                "--component sdk-rust --plan recovery-input/release-plan.json",
                "--component sdk-rust --plan recovery-input/release-plan.json --registry-only",
            ),
            "verification skipped": self.mutation(
                "      - name: Verify crates.io source identity and the GitHub Release\n",
                "      - name: Verify crates.io source identity and the GitHub Release\n        if: false\n",
            ),
            "verification non-blocking key": self.mutation(
                "      - name: Verify crates.io source identity and the GitHub Release\n",
                "      - name: Verify crates.io source identity and the GitHub Release\n"
                "        continue-on-error: ${{ always() }}\n",
            ),
            "verification ignores failure": self.mutation(
                "--attempts 6 --sleep 10 --evidence release-completion-evidence.json",
                "--attempts 6 --sleep 10 --evidence release-completion-evidence.json || true",
            ),
        }
        for label, source in variants.items():
            with self.subTest(label=label):
                self.assert_rust_rejected(source)

    def test_preserves_strict_contents_api_path_for_other_components(self) -> None:
        recovery.verify_recovery_workflow_source("server", SERVER_WORKFLOW)
        without_exact_ref = SERVER_WORKFLOW.replace(
            '-f ref="refs/tags/$RELEASE_TAG"',
            '-f ref="refs/tags/latest"',
        )
        with self.assertRaises(recovery.RecoveryError):
            recovery.verify_recovery_workflow_source("server", without_exact_ref)


if __name__ == "__main__":
    unittest.main()
