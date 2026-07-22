#!/usr/bin/env python3
"""Focused regression coverage for release recovery workflow verification."""

from __future__ import annotations

import hashlib
import importlib.util
import io
import json
import sys
import unittest
import urllib.error
from pathlib import Path
from unittest import mock

from cli_release_verifier_contract import (  # noqa: F401
    CliRecoveryWorkflowSourceTest,
    CliReleaseAuthorityTest,
)

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
SCREENSHOTS_WORKFLOW_PATH = (
    REPOSITORY_ROOT / ".github" / "workflows" / "screenshots.yml"
)
SCREENSHOTS_WORKFLOW = SCREENSHOTS_WORKFLOW_PATH.read_text(encoding="utf-8")
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
          python recovery.py resolve --preparation-output release-preparation.json
          gh api --method POST repos/example/git/refs \\
            -f ref="refs/tags/$RELEASE_TAG" -f sha="$RELEASE_COMMIT"
          select-publication-run
          gh run list --json databaseId,displayTitle,headBranch,headSha,status,conclusion
          python recovery.py --release-tag "$RELEASE_TAG" --release-commit "$RELEASE_COMMIT"
          gh workflow run release.yml --ref "$RELEASE_TAG" -f tag="$RELEASE_TAG"
"""


def github_http_error(status: int, body: bytes = b"error", **headers: str) -> urllib.error.HTTPError:
    return urllib.error.HTTPError(
        "https://api.github.com/repos/durable-workflow/.github/releases",
        status,
        "request failed",
        headers,
        io.BytesIO(body),
    )


def load_recovery_for_retry_tests():
    loaded = globals().get("recovery")
    if loaded is not None:
        return loaded
    loader = globals().get("load_recovery_module")
    if not callable(loader):
        raise RuntimeError("release recovery module loader is unavailable")
    return loader()


class ContinuityGateTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls) -> None:
        cls.recovery = load_recovery_for_retry_tests()

    def test_scheduled_recovery_pauses_until_remote_resume(self) -> None:
        plan = {"plan": "workspace-unavailable-test"}
        with (
            mock.patch.object(
                self.recovery,
                "resolve_tag",
                side_effect=["a" * 40, None],
            ),
            mock.patch.object(self.recovery, "read_record", return_value=plan),
            mock.patch.object(self.recovery, "validate_plan"),
        ):
            paused = self.recovery.scheduled_continuity_pause(mock.Mock(), plan)

        self.assertEqual(
            "beta-continuity/workspace-unavailable-test/resumed",
            paused["resumed_tag"],
        )
        with (
            mock.patch.object(
                self.recovery,
                "resolve_tag",
                side_effect=["a" * 40, "b" * 40],
            ),
            mock.patch.object(self.recovery, "read_record", return_value=plan),
            mock.patch.object(self.recovery, "validate_plan"),
        ):
            self.assertIsNone(self.recovery.scheduled_continuity_pause(mock.Mock(), plan))


class PublicClientRetryTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls) -> None:
        cls.recovery = load_recovery_for_retry_tests()

    def test_retries_service_failures_connection_resets_and_timeouts(self) -> None:
        failures = (
            ("service", github_http_error(503, **{"Retry-After": "4"}), 4),
            ("connection-reset", urllib.error.URLError(ConnectionResetError("reset")), 1),
            ("timeout", urllib.error.URLError(TimeoutError("timed out")), 1),
        )

        for label, failure, expected_delay in failures:
            with self.subTest(label=label):
                sleeps: list[float] = []
                client = self.recovery.PublicClient(
                    max_attempts=2,
                    retry_base_seconds=1,
                    sleep=sleeps.append,
                )
                with mock.patch.object(
                    self.recovery.urllib.request,
                    "urlopen",
                    side_effect=[failure, io.BytesIO(b"[]")],
                ) as open_url:
                    self.assertEqual(
                        [],
                        client.json(
                            "https://api.github.com/repos/durable-workflow/.github/releases?per_page=100"
                        ),
                    )

                self.assertEqual([expected_delay], sleeps)
                self.assertEqual(2, open_url.call_count)

    def test_authentication_is_terminal_even_with_rate_limit_guidance(self) -> None:
        sleeps: list[float] = []
        client = self.recovery.PublicClient(max_attempts=3, sleep=sleeps.append)
        error = github_http_error(
            401,
            b"Bad credentials: API rate limit exceeded",
            **{"Retry-After": "20", "X-RateLimit-Remaining": "0"},
        )

        with (
            mock.patch.object(self.recovery.urllib.request, "urlopen", side_effect=error) as open_url,
            self.assertRaisesRegex(self.recovery.RecoveryError, r"public request failed \(401\)"),
        ):
            client.json("https://api.github.com/repos/durable-workflow/.github/releases?per_page=100")

        self.assertEqual([], sleeps)
        self.assertEqual(1, open_url.call_count)

    def test_authorization_requires_explicit_rate_limit_guidance(self) -> None:
        client = self.recovery.PublicClient(
            max_attempts=2,
            sleep=lambda _delay: self.fail("ordinary authorization failure was retried"),
        )
        with (
            mock.patch.object(
                self.recovery.urllib.request,
                "urlopen",
                side_effect=github_http_error(403, b"Resource not accessible"),
            ) as open_url,
            self.assertRaisesRegex(self.recovery.RecoveryError, r"public request failed \(403\)"),
        ):
            client.json("https://api.github.com/repos/durable-workflow/.github/releases?per_page=100")
        self.assertEqual(1, open_url.call_count)

        sleeps: list[float] = []
        client = self.recovery.PublicClient(max_attempts=2, retry_base_seconds=1, sleep=sleeps.append)
        with mock.patch.object(
            self.recovery.urllib.request,
            "urlopen",
            side_effect=[
                github_http_error(
                    403,
                    b"API rate limit exceeded",
                    **{"X-RateLimit-Remaining": "0"},
                ),
                io.BytesIO(b"[]"),
            ],
        ) as open_url:
            self.assertEqual(
                [],
                client.json("https://api.github.com/repos/durable-workflow/.github/releases?per_page=100"),
            )
        self.assertEqual([1], sleeps)
        self.assertEqual(2, open_url.call_count)

    def test_retry_exhaustion_has_a_distinct_infrastructure_classification(self) -> None:
        client = self.recovery.PublicClient(max_attempts=2, retry_base_seconds=1, sleep=lambda _delay: None)
        with (
            mock.patch.object(
                self.recovery.urllib.request,
                "urlopen",
                side_effect=[github_http_error(503), github_http_error(502)],
            ) as open_url,
            self.assertRaisesRegex(
                self.recovery.PublicInfrastructureError,
                r"classification=github-read-transient, endpoint_class=releases-api, "
                r"attempts=2, reason=retry-exhausted, status=502",
            ),
        ):
            client.json("https://api.github.com/repos/durable-workflow/.github/releases?per_page=100")
        self.assertEqual(2, open_url.call_count)


class ReleasePreparationRecoveryTest(unittest.TestCase):
    def candidate(self) -> dict[str, object]:
        return {
            "plan": "missing-preparation",
            "channel": "alpha",
            "components": {"workflow": {"version": "2.0.0-alpha.1", "commit": "a" * 40}},
        }

    def test_source_product_train_is_bound_to_the_planned_identity(self) -> None:
        identity = {"version": "2.0.0-beta.3", "commit": "a" * 40}
        client = mock.Mock()
        client.bytes.return_value = json.dumps(
            {
                "name": "durable-workflow/waterline",
                "extra": {"durable-workflow": {"product-train": identity["version"]}},
            }
        ).encode()

        evidence = recovery.source_product_train_evidence(client, "waterline", identity)

        self.assertEqual(identity["version"], evidence["product_train"])
        self.assertEqual(identity["commit"], evidence["source_commit"])
        client.bytes.assert_called_once_with(
            "https://api.github.com/repos/durable-workflow/waterline/contents/composer.json?ref="
            + identity["commit"],
            accept="application/vnd.github.raw+json",
        )

        client.bytes.return_value = client.bytes.return_value.replace(b"beta.3", b"beta.2")
        with self.assertRaisesRegex(recovery.RecoveryError, "not planned version 2.0.0-beta.3"):
            recovery.source_product_train_evidence(client, "waterline", identity)

    def test_discovery_rejects_missing_preparation_for_an_incomplete_release(self) -> None:
        candidate = self.candidate()
        tag = "release-plan/missing-preparation"
        record_commit = "b" * 40
        client = mock.Mock()
        client.json.return_value = {
            "tag_name": tag,
            "draft": False,
            "assets": [
                {
                    "name": "release-plan.json",
                    "browser_download_url": "https://example.invalid/release-plan.json",
                }
            ],
        }
        client.bytes.return_value = recovery.canonical_json(candidate)
        with (
            mock.patch.object(recovery, "validate_plan"),
            mock.patch.object(recovery, "resolve_tag", return_value=record_commit),
            mock.patch.object(
                recovery,
                "read_record",
                side_effect=[candidate, recovery.NotFound("missing preparation")],
            ),
            mock.patch.object(
                recovery,
                "verify_component",
                side_effect=recovery.NotFound("release is incomplete"),
            ),
            self.assertRaisesRegex(recovery.RecoveryError, "only completed legacy releases"),
        ):
            recovery.discover_plan(client, tag, "workflow")

    def test_missing_preparation_cannot_resolve_to_publish(self) -> None:
        candidate = self.candidate()
        with (
            mock.patch.object(recovery, "verify_plan_authority", return_value=({}, {})),
            mock.patch.object(recovery, "resolve_tag", return_value=None),
            self.assertRaisesRegex(
                recovery.RecoveryError,
                "release preparation required before publishing workflow",
            ),
        ):
            recovery.resolve_component(
                mock.Mock(),
                "workflow",
                "release-plan/missing-preparation",
                "b" * 40,
                candidate,
                None,
            )

    def test_completed_legacy_release_still_resolves_to_skip(self) -> None:
        candidate = self.candidate()
        identity = candidate["components"]["workflow"]
        public_evidence = {"version": identity["version"], "commit": identity["commit"]}
        with (
            mock.patch.object(recovery, "verify_plan_authority", return_value=({}, {})),
            mock.patch.object(recovery, "resolve_tag", return_value=identity["commit"]),
            mock.patch.object(recovery, "verify_component", return_value=public_evidence),
        ):
            state, outputs = recovery.resolve_component(
                mock.Mock(),
                "workflow",
                "release-plan/missing-preparation",
                "b" * 40,
                candidate,
                None,
            )

        self.assertEqual("skip", outputs["action"])
        self.assertEqual("complete", state["phase"])
        self.assertNotIn("release_preparation", state)


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
                "    if: >-\n"
                "      github.ref == 'refs/heads/v2' &&\n"
                "      needs.discover.outputs.action == 'publish'",
                "    if: always()",
            ),
            "missing protected ref": self.waterline_mutation(
                "    if: >-\n"
                "      github.ref == 'refs/heads/v2' &&\n"
                "      needs.discover.outputs.action == 'publish'",
                "    if: needs.discover.outputs.action == 'publish'",
            ),
            "wrong protected ref": self.waterline_mutation(
                "github.ref == 'refs/heads/v2' &&",
                "github.ref == 'refs/heads/main' &&",
            ),
            "protected ref OR bypass": self.waterline_mutation(
                "github.ref == 'refs/heads/v2' &&",
                "github.ref == 'refs/heads/v2' ||",
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


class PrivilegedWorkflowBoundaryTest(unittest.TestCase):
    def test_native_publishers_gate_the_exact_v2_ref_before_authority(self) -> None:
        publishers = {
            "release recovery": WATERLINE_WORKFLOW.split("\n  publish:\n", 1)[1],
            "screenshots": SCREENSHOTS_WORKFLOW.split("\n  publish:\n", 1)[1],
        }
        exact_guard = "    if: >-\n      github.ref == 'refs/heads/v2' &&"
        authority_markers = {
            "release recovery": (
                "    environment: release-plan-publication",
                "    permissions:\n      contents: write",
                "    steps:",
            ),
            "screenshots": (
                "    permissions:\n      contents: write",
                "    steps:",
            ),
        }

        for name, publisher in publishers.items():
            with self.subTest(name=name):
                guard_at = publisher.index(exact_guard)
                self.assertEqual(publisher.count("github.ref == 'refs/heads/v2'"), 1)
                for marker in authority_markers[name]:
                    self.assertLess(guard_at, publisher.index(marker))

    def test_screenshot_generator_is_read_only_and_drops_checkout_credentials(self) -> None:
        generator = SCREENSHOTS_WORKFLOW.split("\n  publish:\n", 1)[0]

        self.assertIn("    permissions:\n      contents: read", generator)
        self.assertNotIn("contents: write", generator)
        self.assertNotIn("${{ secrets.", generator)
        self.assertEqual(generator.count("          persist-credentials: false"), 3)

    def test_screenshot_publisher_accepts_only_protected_source_inputs(self) -> None:
        publisher = SCREENSHOTS_WORKFLOW.split("\n  publish:\n", 1)[1]

        self.assertIn(
            "    if: >-\n"
            "      github.ref == 'refs/heads/v2' &&\n"
            "      (github.event_name == 'schedule' ||\n"
            "       (github.event_name == 'workflow_dispatch' &&\n"
            "        inputs.publish_readme_assets != false &&\n"
            "        (inputs.waterline_ref == '' || inputs.waterline_ref == 'v2') &&\n"
            "        (inputs.sample_app_ref == '' || inputs.sample_app_ref == 'main') &&\n"
            "        (inputs.workflow_ref == '' || inputs.workflow_ref == 'v2')))\n"
            "    runs-on: ubuntu-latest",
            publisher,
        )
        self.assertIn("          ref: v2", publisher)


class ScreenshotArtifactIdentityTest(unittest.TestCase):
    def setUp(self) -> None:
        self.generator, self.publisher = SCREENSHOTS_WORKFLOW.split(
            "\n  publish:\n", 1
        )
        self.upload = self.generator.split("      - name: Upload screenshots\n", 1)[1]
        self.upload = self.upload.split("\n      - name:", 1)[0]
        self.restore = self.publisher.split(
            "      - name: Restore the exact generated screenshots\n", 1
        )[1]
        self.restore = self.restore.split("\n      - name:", 1)[0]

    def test_selective_publisher_retry_uses_the_retained_producer_artifact(
        self,
    ) -> None:
        self.assertIn(
            "    outputs:\n"
            "      screenshot_artifact_id: "
            "${{ steps.upload_screenshots.outputs.artifact-id }}",
            self.generator,
        )
        self.assertIn("        id: upload_screenshots", self.upload)
        self.assertIn(
            "          artifact-ids: "
            "${{ needs.screenshots.outputs.screenshot_artifact_id }}\n"
            "          path: published-screenshots\n"
            "          merge-multiple: true",
            self.restore,
        )
        self.assertNotIn("github.run_attempt", self.restore)
        self.assertNotIn("github-token:", self.restore)
        self.assertNotIn("run-id:", self.restore)

    def test_full_rerun_uploads_a_fresh_attempt_qualified_artifact(self) -> None:
        template = (
            "waterline-screenshots-${{ github.run_id }}-${{ github.run_attempt }}"
        )

        self.assertIn(f"          name: {template}", self.upload)
        first_attempt = template.replace("${{ github.run_id }}", "1234").replace(
            "${{ github.run_attempt }}", "1"
        )
        full_rerun = template.replace("${{ github.run_id }}", "1234").replace(
            "${{ github.run_attempt }}", "2"
        )
        self.assertEqual("waterline-screenshots-1234-1", first_attempt)
        self.assertEqual("waterline-screenshots-1234-2", full_rerun)
        self.assertNotEqual(first_attempt, full_rerun)


if __name__ == "__main__":
    unittest.main()
