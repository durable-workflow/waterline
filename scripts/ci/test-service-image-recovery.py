#!/usr/bin/env python3
"""Focused executable coverage for Waterline service-image recovery."""

from __future__ import annotations

import argparse
import importlib.util
import json
import os
import tempfile
import unittest
import urllib.parse
from pathlib import Path
from typing import Any

SCRIPT = Path(__file__).with_name("service-image-recovery.py")
WORKFLOW = (
    Path(__file__).parents[2] / ".github" / "workflows" / "service-image-recovery.yml"
)
SPEC = importlib.util.spec_from_file_location("service_image_recovery", SCRIPT)
assert SPEC is not None and SPEC.loader is not None
recovery = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(recovery)

PLAN_COMMIT = "a" * 40
SOURCE_COMMIT = "b" * 40
WORKFLOW_COMMIT = "c" * 40
VERSION = "2.0.0-beta.14"
PLAN_TAG = "release-plan/beta.14"


def raw_json(value: Any) -> bytes:
    return json.dumps(value, sort_keys=True, separators=(",", ":")).encode()


def plan() -> dict[str, Any]:
    versions = {
        "workflow": "2.0.0-beta.14",
        "waterline": VERSION,
        "server": "2.0.0-beta.14",
        "cli": "2.0.0-beta.14",
        "sdk-php": "2.0.0-beta.14",
        "sdk-python": "2.0.0-beta.14",
        "sdk-rust": "2.0.0-beta.14",
    }
    commits = {
        "workflow": "1" * 40,
        "waterline": SOURCE_COMMIT,
        "server": "3" * 40,
        "cli": "4" * 40,
        "sdk-php": "5" * 40,
        "sdk-python": "6" * 40,
        "sdk-rust": "7" * 40,
    }
    return {
        "schema": recovery.PLAN_SCHEMA,
        "plan": "beta.14",
        "channel": "beta",
        "foundation": recovery.FOUNDATION,
        "components": {
            name: {"version": versions[name], "commit": commits[name]}
            for name in sorted(recovery.COMPONENTS)
        },
        "beta_authorization": {
            "tag": "beta-authorization/beta.14",
            "commit": "8" * 40,
        },
    }


def source_recovery_evidence(release_plan: dict[str, Any]) -> dict[str, Any]:
    identity = release_plan["components"]["waterline"]
    return {
        "schema": recovery.RECOVERY_SCHEMA,
        "component": "waterline",
        "release_plan_tag": PLAN_TAG,
        "plan": "beta.14",
        "channel": "beta",
        "plan_record_commit": PLAN_COMMIT,
        "phase": "complete",
        "outcome": "verified",
        "declared_identity": identity,
        "source_tag": {"status": "present", "commit": SOURCE_COMMIT},
        "public_evidence": {
            "version": VERSION,
            "commit": SOURCE_COMMIT,
            "distribution": {"kind": "composer"},
            "github_release": {"id": 7},
        },
    }


def digest_headers(raw: bytes) -> dict[str, str]:
    return {"docker-content-digest": recovery.sha256_digest(raw)}


class FakePublicClient:
    def __init__(
        self,
        release_plan: dict[str, Any],
        *,
        image_present: bool = True,
        token_present: bool = True,
        protected: bool = True,
        branch_commit: str = WORKFLOW_COMMIT,
        source_tag_commit: str = SOURCE_COMMIT,
        release_label: str = VERSION,
        revision_label: str = SOURCE_COMMIT,
        include_arm64: bool = True,
        corrupt_index_digest: bool = False,
    ) -> None:
        self.release_plan = release_plan
        self.image_present = image_present
        self.token_present = token_present
        self.protected = protected
        self.branch_commit = branch_commit
        self.source_tag_commit = source_tag_commit
        self.requests: list[str] = []
        self.token_url = (
            "https://auth.docker.io/token?service=registry.docker.io&scope="
            + urllib.parse.quote(f"repository:{recovery.IMAGE_REPOSITORY}:pull")
        )
        self.image_url = f"{recovery.IMAGE_REGISTRY}/v2/{recovery.IMAGE_REPOSITORY}/manifests/{VERSION}"
        self.responses: dict[str, tuple[bytes, dict[str, str]]] = {}
        descriptors: list[dict[str, Any]] = []
        for architecture in ("amd64", "arm64"):
            if architecture == "arm64" and not include_arm64:
                continue
            config_raw = raw_json(
                {
                    "architecture": architecture,
                    "os": "linux",
                    "config": {
                        "Labels": {
                            "org.opencontainers.image.revision": revision_label,
                            "dev.durable-workflow.release.tag": release_label,
                        }
                    },
                }
            )
            config_digest = recovery.sha256_digest(config_raw)
            manifest_raw = raw_json(
                {
                    "schemaVersion": 2,
                    "config": {
                        "mediaType": "application/vnd.oci.image.config.v1+json",
                        "digest": config_digest,
                    },
                    "layers": [],
                }
            )
            manifest_digest = recovery.sha256_digest(manifest_raw)
            descriptors.append(
                {
                    "mediaType": "application/vnd.oci.image.manifest.v1+json",
                    "digest": manifest_digest,
                    "platform": {"os": "linux", "architecture": architecture},
                }
            )
            self.responses[
                f"{recovery.IMAGE_REGISTRY}/v2/{recovery.IMAGE_REPOSITORY}/manifests/{manifest_digest}"
            ] = (manifest_raw, digest_headers(manifest_raw))
            self.responses[
                f"{recovery.IMAGE_REGISTRY}/v2/{recovery.IMAGE_REPOSITORY}/blobs/{config_digest}"
            ] = (config_raw, {})
        index_raw = raw_json(
            {
                "schemaVersion": 2,
                "mediaType": "application/vnd.oci.image.index.v1+json",
                "manifests": descriptors,
            }
        )
        index_headers = digest_headers(index_raw)
        if corrupt_index_digest:
            index_headers["docker-content-digest"] = f"sha256:{'f' * 64}"
        self.responses[self.image_url] = (index_raw, index_headers)

    def json(
        self,
        url: str,
        *,
        accept: str | None = None,
        headers: dict[str, str] | None = None,
    ) -> Any:
        del accept, headers
        self.requests.append(url)
        if url == self.token_url:
            if not self.token_present:
                raise recovery.NotFound("Docker Hub token endpoint is absent")
            return {"token": "anonymous-public-token"}
        if url == f"https://api.github.com/repos/{recovery.WATERLINE_REPOSITORY}":
            return {"default_branch": recovery.PROTECTED_BRANCH}
        if url == (
            f"https://api.github.com/repos/{recovery.WATERLINE_REPOSITORY}"
            f"/branches/{recovery.PROTECTED_BRANCH}"
        ):
            return {
                "name": recovery.PROTECTED_BRANCH,
                "protected": self.protected,
                "commit": {"sha": self.branch_commit},
            }
        encoded_plan = urllib.parse.quote(PLAN_TAG, safe="")
        if (
            url
            == f"https://api.github.com/repos/{recovery.CONTROL_REPOSITORY}/git/ref/tags/{encoded_plan}"
        ):
            return {"object": {"type": "commit", "sha": PLAN_COMMIT}}
        if url == (
            f"https://api.github.com/repos/{recovery.CONTROL_REPOSITORY}"
            f"/releases/tags/{encoded_plan}"
        ):
            return {"tag_name": PLAN_TAG, "draft": False}
        encoded_version = urllib.parse.quote(VERSION, safe="")
        if url == (
            f"https://api.github.com/repos/{recovery.WATERLINE_REPOSITORY}"
            f"/git/ref/tags/{encoded_version}"
        ):
            return {"object": {"type": "commit", "sha": self.source_tag_commit}}
        raise AssertionError(f"unexpected JSON request: {url}")

    def bytes(
        self,
        url: str,
        *,
        accept: str | None = None,
        headers: dict[str, str] | None = None,
    ) -> tuple[bytes, dict[str, str]]:
        del accept, headers
        self.requests.append(url)
        if url == (
            f"https://api.github.com/repos/{recovery.CONTROL_REPOSITORY}"
            f"/contents/release-plan.json?ref={PLAN_COMMIT}"
        ):
            return raw_json(self.release_plan), {}
        if url == self.image_url and not self.image_present:
            raise recovery.NotFound("image tag is absent")
        try:
            return self.responses[url]
        except KeyError as error:
            raise AssertionError(f"unexpected byte request: {url}") from error


class ServiceImageRecoveryTest(unittest.TestCase):
    def setUp(self) -> None:
        self.temporary = tempfile.TemporaryDirectory(prefix="waterline-image-recovery-")
        self.root = Path(self.temporary.name)
        self.plan = plan()
        self.plan_path = self.root / "release-plan.json"
        self.recovery_path = self.root / "release-recovery-evidence.json"
        self.evidence_path = self.root / "service-image-recovery-evidence.json"
        self.output_path = self.root / "github-output"
        self.plan_path.write_bytes(raw_json(self.plan))
        self.recovery_path.write_bytes(raw_json(source_recovery_evidence(self.plan)))

    def tearDown(self) -> None:
        self.temporary.cleanup()

    def args(self, **overrides: Any) -> argparse.Namespace:
        values = {
            "plan": self.plan_path,
            "recovery_evidence": self.recovery_path,
            "plan_tag": PLAN_TAG,
            "plan_commit": PLAN_COMMIT,
            "github_server_url": "https://github.com",
            "github_repository": recovery.WATERLINE_REPOSITORY,
            "github_ref": recovery.PROTECTED_REF,
            "github_sha": WORKFLOW_COMMIT,
            "github_event_name": "workflow_dispatch",
            "evidence": self.evidence_path,
            "github_output": self.output_path,
            "require_present": False,
            "attempts": 1,
            "sleep": 0,
        }
        values.update(overrides)
        return argparse.Namespace(**values)

    def evidence(self) -> dict[str, Any]:
        return json.loads(self.evidence_path.read_bytes())

    def test_public_noop_rehearsal_binds_digest_platforms_and_labels_to_plan(
        self,
    ) -> None:
        client = FakePublicClient(self.plan)

        self.assertEqual(0, recovery.run(self.args(), client))

        evidence = self.evidence()
        self.assertEqual("noop", evidence["action"])
        self.assertEqual("public-read", evidence["rehearsal"]["kind"])
        self.assertEqual("none", evidence["publication_credential_use"])
        self.assertRegex(evidence["image"]["digest"], r"^sha256:[0-9a-f]{64}$")
        self.assertEqual(
            list(recovery.REQUIRED_PLATFORMS), list(evidence["image"]["platforms"])
        )
        self.assertEqual(SOURCE_COMMIT, evidence["source_release"]["commit"])
        self.assertEqual(WORKFLOW_COMMIT, evidence["protected_source"]["commit"])
        self.assertIn("action=noop", self.output_path.read_text())

    def test_only_an_absent_top_level_image_tag_requests_publication(self) -> None:
        client = FakePublicClient(self.plan, image_present=False)

        self.assertEqual(0, recovery.run(self.args(), client))

        evidence = self.evidence()
        self.assertEqual("publish", evidence["action"])
        self.assertEqual("missing", evidence["outcome"])
        self.assertIsNone(evidence["image"]["digest"])
        self.assertIn("action=publish", self.output_path.read_text())

    def test_other_missing_registry_resources_do_not_authorize_publication(
        self,
    ) -> None:
        client = FakePublicClient(self.plan, token_present=False)

        self.assertEqual(1, recovery.run(self.args(), client))

        self.assertEqual("reject", self.evidence()["action"])
        self.assertFalse(self.output_path.exists())

    def test_recovered_image_must_become_a_verified_noop(self) -> None:
        client = FakePublicClient(self.plan, image_present=False)

        self.assertEqual(
            1,
            recovery.run(
                self.args(require_present=True, attempts=2),
                client,
            ),
        )

        self.assertEqual("reject", self.evidence()["action"])
        self.assertEqual("registry-inspection", self.evidence()["phase"])

    def test_mismatched_or_incomplete_existing_images_fail_closed(self) -> None:
        variants = {
            "wrong release label": {"release_label": "2.0.0-beta.5"},
            "wrong source revision": {"revision_label": "d" * 40},
            "missing arm64": {"include_arm64": False},
            "unbound index digest": {"corrupt_index_digest": True},
        }
        for label, options in variants.items():
            with self.subTest(label=label):
                client = FakePublicClient(self.plan, **options)
                self.output_path.unlink(missing_ok=True)

                self.assertEqual(1, recovery.run(self.args(), client))

                evidence = self.evidence()
                self.assertEqual("reject", evidence["action"])
                self.assertEqual("registry-inspection", evidence["phase"])
                self.assertFalse(self.output_path.exists())

    def test_wrong_or_unprotected_inputs_are_rejected_before_registry_access(
        self,
    ) -> None:
        variants = {
            "non-GitHub runner": (
                FakePublicClient(self.plan),
                {"github_server_url": "https://forge.example"},
            ),
            "wrong repository": (
                FakePublicClient(self.plan),
                {"github_repository": "someone/waterline"},
            ),
            "contributor ref": (
                FakePublicClient(self.plan),
                {"github_ref": "refs/heads/contribution"},
            ),
            "source tag": (
                FakePublicClient(self.plan, source_tag_commit="d" * 40),
                {},
            ),
            "unprotected v2": (
                FakePublicClient(self.plan, protected=False),
                {},
            ),
            "stale v2 commit": (
                FakePublicClient(self.plan, branch_commit="d" * 40),
                {},
            ),
        }
        for label, (client, overrides) in variants.items():
            with self.subTest(label=label):
                self.output_path.unlink(missing_ok=True)

                self.assertEqual(1, recovery.run(self.args(**overrides), client))

                self.assertEqual("reject", self.evidence()["action"])
                self.assertNotIn(client.token_url, client.requests)
                self.assertFalse(self.output_path.exists())

    def test_plan_tag_and_commit_must_match_the_verified_handoff(self) -> None:
        variants = {
            "wrong tag": {"plan_tag": "release-plan/another"},
            "wrong commit": {"plan_commit": "d" * 40},
        }
        for label, overrides in variants.items():
            with self.subTest(label=label):
                client = FakePublicClient(self.plan)
                self.output_path.unlink(missing_ok=True)

                self.assertEqual(1, recovery.run(self.args(**overrides), client))

                self.assertNotIn(client.token_url, client.requests)
                self.assertFalse(self.output_path.exists())


class ServiceImageRecoveryWorkflowTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls) -> None:
        cls.workflow = WORKFLOW.read_text()

    def test_recovery_is_scheduled_and_manual_from_protected_v2_only(self) -> None:
        self.assertIn("  schedule:\n", self.workflow)
        self.assertIn("  workflow_dispatch:\n", self.workflow)
        self.assertNotIn("  pull_request:\n", self.workflow)
        self.assertNotIn("  push:\n", self.workflow)
        self.assertIn('test "$SERVER_URL" = https://github.com', self.workflow)
        self.assertIn('test "$REQUEST_REF" = refs/heads/v2', self.workflow)
        self.assertIn("ref: ${{ github.sha }}", self.workflow)
        self.assertNotIn("ref: ${{ needs.discover.outputs.version }}", self.workflow)
        self.assertNotIn("publish-planned-tag.py", self.workflow)

    def test_scheduled_recovery_uses_shared_implicit_plan_discovery(self) -> None:
        discovery = self.workflow.split(
            "      - name: Resolve the immutable public release plan\n",
            1,
        )[1].split("      - name:", 1)[0]
        schedule_branch = discovery.split(
            '          if [ "$GITHUB_EVENT_NAME" = schedule ]; then\n',
            1,
        )[1].split("          elif ", 1)[0]

        self.assertIn("            resolve\n", discovery)
        self.assertIn(
            '          python scripts/ci/component-release-recovery.py "${arguments[@]}"',
            discovery,
        )
        self.assertIn("arguments+=(--allow-empty)", schedule_branch)
        self.assertNotIn("--plan-tag", schedule_branch)

    def test_public_decision_and_handoff_validation_precede_credentials(self) -> None:
        publisher = self.workflow.split("\n  publish:\n", 1)[1]
        rehearsal = self.workflow.index(
            "name: Rehearse the publication-credential-free public recovery decision"
        )
        environment = self.workflow.index("environment: release-plan-publication")
        revalidate = self.workflow.index(
            "name: Revalidate protected source and immutable public inputs"
        )
        source = self.workflow.index("name: Verify the isolated build source")
        login = self.workflow.index("docker/login-action@")
        publish = self.workflow.index("name: Republish the immutable planned image tag")

        self.assertLess(rehearsal, environment)
        self.assertLess(environment, revalidate)
        self.assertLess(revalidate, source)
        self.assertLess(source, login)
        self.assertLess(login, publish)
        self.assertIn("digest-mismatch: error", self.workflow)
        self.assertNotIn("contents: write", self.workflow)
        self.assertIn(
            "    permissions:\n      actions: read\n      contents: read",
            publisher,
        )

    def test_republication_uses_exact_plan_identity_and_smokes_public_image(
        self,
    ) -> None:
        self.assertIn("context: release-source", self.workflow)
        self.assertIn(
            "WATERLINE_VERSION=${{ needs.discover.outputs.version }}", self.workflow
        )
        self.assertIn(
            "SOURCE_COMMIT=${{ needs.discover.outputs.commit }}", self.workflow
        )
        self.assertIn("platforms: linux/amd64,linux/arm64", self.workflow)
        self.assertIn(
            "EXPECTED_BUILD_DIGEST: ${{ steps.publish.outputs.digest }}", self.workflow
        )
        self.assertIn("--require-present", self.workflow)
        self.assertIn(
            "EXPECTED_WATERLINE_VERSION: ${{ needs.discover.outputs.version }}",
            self.workflow,
        )
        self.assertIn(
            "EXPECTED_SOURCE_COMMIT: ${{ needs.discover.outputs.commit }}",
            self.workflow,
        )
        self.assertIn("run: scripts/ci/service-mode-image-smoke.sh", self.workflow)


def shared_qualification_root() -> Path | None:
    configured = os.environ.get("SHARED_TARGET_QUALIFICATION_ROOT")
    if not configured:
        return None

    candidate = Path(configured)
    if not (candidate / "scripts" / "qualification_policy.py").is_file():
        raise RuntimeError(
            "configured shared target qualification policy is unavailable"
        )
    return candidate


SHARED_QUALIFICATION_ROOT = shared_qualification_root()


@unittest.skipUnless(
    SHARED_QUALIFICATION_ROOT,
    "shared target qualification policy is not available",
)
class SharedTargetQualificationTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls) -> None:
        assert SHARED_QUALIFICATION_ROOT is not None
        specification = importlib.util.spec_from_file_location(
            "shared_target_qualification_policy",
            SHARED_QUALIFICATION_ROOT / "scripts" / "qualification_policy.py",
        )
        if specification is None or specification.loader is None:
            raise RuntimeError("unable to load the shared target qualification policy")
        cls.qualification = importlib.util.module_from_spec(specification)
        specification.loader.exec_module(cls.qualification)
        cls.policy = cls.qualification.load_policy(
            SHARED_QUALIFICATION_ROOT / "qualification" / "policy.json"
        )
        cls.workflow = WORKFLOW.read_text()

    def scan(self, source: str) -> dict[str, dict[str, object]]:
        return self.qualification.scan_workflow_sources(
            self.policy,
            "waterline",
            {".github/workflows/service-image-recovery.yml": source},
        )

    def replace_once(self, original: str, replacement: str) -> str:
        self.assertEqual(1, self.workflow.count(original))
        return self.workflow.replace(original, replacement, 1)

    def test_checked_in_recovery_workflow_satisfies_shared_policy(self) -> None:
        evidence = self.scan(self.workflow)

        self.assertEqual(
            ["publish"],
            evidence[".github/workflows/service-image-recovery.yml"]["privileged_jobs"],
        )

    def test_weakening_any_artifact_handoff_binding_is_rejected(self) -> None:
        cases = {
            "artifact ID": self.replace_once(
                "artifact-id: ${{ steps.privileged-handoff.outputs.artifact-id }}",
                "artifact-id: ${{ steps.privileged-handoff.outputs.artifact-digest }}",
            ),
            "run ID": self.replace_once(
                "run-id: ${{ needs.discover.outputs.source-run-id }}",
                "run-id: ${{ needs.discover.outputs.source-run-attempt }}",
            ),
            "run attempt": self.replace_once(
                "EXPECTED_SOURCE_RUN_ATTEMPT: ${{ needs.discover.outputs.source-run-attempt }}",
                "EXPECTED_SOURCE_RUN_ATTEMPT: ${{ needs.discover.outputs.source-run-id }}",
            ),
            "digest": self.replace_once(
                "EXPECTED_ARTIFACT_DIGEST: ${{ needs.discover.outputs.artifact-digest }}",
                "EXPECTED_ARTIFACT_DIGEST: ${{ needs.discover.outputs.artifact-id }}",
            ),
            "safe directory": self.replace_once(
                """          if [[ ! "$ARTIFACT_DIRECTORY" =~ ^isolated-[a-z0-9][a-z0-9._-]*$ ]]; then
            printf 'artifact validation directory is unsafe\\n' >&2
            exit 1
          fi
""",
                "",
            ),
            "reviewed checkout": self.replace_once(
                "          fetch-depth: 0\n",
                "          fetch-depth: 1\n",
            ),
            "validation order": self.replace_once(
                "      - name: Validate the exact producer artifact before use\n",
                "      - run: tar -tf isolated-image-recovery/service-image-recovery-handoff.tar\n"
                "      - name: Validate the exact producer artifact before use\n",
            ),
        }

        for name, candidate in cases.items():
            with (
                self.subTest(name=name),
                self.assertRaisesRegex(
                    self.qualification.PolicyError,
                    "exact producer, immutable artifact identity, and pre-use digest validation",
                ),
            ):
                self.scan(candidate)


if __name__ == "__main__":
    unittest.main()
