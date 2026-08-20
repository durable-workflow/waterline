#!/usr/bin/env python3
"""Regression coverage for public Waterline release qualification evidence."""

from __future__ import annotations

import importlib.util
import sys
import unittest
from pathlib import Path

import yaml


ROOT = Path(__file__).parents[2]
SCRIPT = Path(__file__).with_name("release_qualification_asset.py")
SPEC = importlib.util.spec_from_file_location("release_qualification_asset", SCRIPT)
if SPEC is None or SPEC.loader is None:
    raise RuntimeError(f"cannot load {SCRIPT}")
asset = importlib.util.module_from_spec(SPEC)
sys.modules[SPEC.name] = asset
SPEC.loader.exec_module(asset)

VERSION = "2.0.0-rc.21"
RELEASE_COMMIT = "a" * 40
WORKFLOW_COMMIT = "b" * 40
RUN_ID = 123456789
RUN_ATTEMPT = 1


def qualification() -> dict:
    return {
        "schema": asset.QUALIFICATION_SCHEMA,
        "outcome": "pass",
        "packages": {
            "waterline": VERSION,
            "sdk-php": "2.0.0-rc.14",
            "workflow": "2.0.0-rc.14",
        },
        "package_metadata": {"name": "durable-workflow/waterline"},
        "composer_graphs": {"embedded": {}, "service": {}},
    }


def release_surfaces() -> dict:
    return {
        "schema": asset.RELEASE_SURFACES_SCHEMA,
        "outcome": "verified",
        "version": VERSION,
        "source_commit": RELEASE_COMMIT,
        "github_release": {"tag": VERSION},
        "packagist": {"version": VERSION},
        "service_image": {"tag": VERSION},
    }


def build(
    candidate_qualification: dict | None = None,
    candidate_surfaces: dict | None = None,
    **overrides: object,
) -> dict:
    arguments: dict[str, object] = {
        "repository": asset.REPOSITORY,
        "workflow_name": asset.WORKFLOW_NAME,
        "workflow_path": asset.WORKFLOW_PATH,
        "event": asset.EVENT,
        "run_id": RUN_ID,
        "run_attempt": RUN_ATTEMPT,
        "run_url": f"https://github.com/{asset.REPOSITORY}/actions/runs/{RUN_ID}",
        "head_sha": WORKFLOW_COMMIT,
        "release_tag": VERSION,
        "release_commit": RELEASE_COMMIT,
    }
    arguments.update(overrides)
    return asset.build(
        candidate_qualification or qualification(),
        candidate_surfaces or release_surfaces(),
        **arguments,
    )


class ReleaseQualificationAssetTest(unittest.TestCase):
    def test_asset_binds_later_workflow_run_to_exact_release_and_package_tuple(self) -> None:
        evidence = build()

        self.assertEqual(asset.SCHEMA, evidence["schema"])
        self.assertEqual(2, evidence["schema_version"])
        self.assertEqual(WORKFLOW_COMMIT, evidence["workflow_run"]["head_sha"])
        self.assertEqual(RELEASE_COMMIT, evidence["release"]["source_commit"])
        self.assertNotEqual(
            evidence["workflow_run"]["head_sha"],
            evidence["release"]["source_commit"],
            "a workflow landed after the current tag must still qualify that tag",
        )
        self.assertEqual(qualification()["packages"], evidence["qualification"]["packages"])
        self.assertEqual(
            f"waterline-exact-composer-qualification-{RUN_ID}-{RUN_ATTEMPT}.json",
            asset.asset_name(RUN_ID, RUN_ATTEMPT),
        )

    def test_untrusted_or_mismatched_evidence_fails_closed(self) -> None:
        cases: list[tuple[str, dict | None, dict | None, dict[str, object]]] = []

        stale_tuple = qualification()
        stale_tuple["packages"]["waterline"] = "2.0.0-rc.20"
        cases.append(("tuple", stale_tuple, None, {}))

        stale_surfaces = release_surfaces()
        stale_surfaces["source_commit"] = "b" * 40
        cases.append(("surfaces", None, stale_surfaces, {}))

        cases.append(("repository", None, None, {"repository": "attacker/waterline"}))
        cases.append(("workflow", None, None, {"workflow_path": "untrusted.yml"}))
        cases.append(("event", None, None, {"event": "pull_request"}))
        cases.append(("run commit", None, None, {"head_sha": "not-a-commit"}))
        cases.append(
            ("release commit", None, None, {"release_commit": "not-a-commit"})
        )

        for name, candidate_qualification, candidate_surfaces, overrides in cases:
            with self.subTest(name=name), self.assertRaises(asset.EvidenceError):
                build(candidate_qualification, candidate_surfaces, **overrides)

    def test_workflow_publishes_only_source_bound_repository_dispatch_evidence(self) -> None:
        document = yaml.load(
            (ROOT / ".github" / "workflows" / "release-docs-audit.yml").read_text(),
            Loader=yaml.BaseLoader,
        )
        audit = document["jobs"]["docs-release-audit"]
        publish = document["jobs"]["publish-qualification-evidence"]

        self.assertEqual(
            "${{ steps.qualification-ready.outputs.value }}",
            audit["outputs"]["qualification-ready"],
        )
        self.assertEqual("write", publish["permissions"]["contents"])
        self.assertEqual("read", publish["permissions"]["actions"])
        self.assertIn("github.event_name == 'repository_dispatch'", publish["if"])
        self.assertIn("github.repository == 'durable-workflow/waterline'", publish["if"])
        self.assertIn(
            "needs.docs-release-audit.outputs.qualification-ready == 'true'",
            publish["if"],
        )

        steps = publish["steps"]
        download = next(
            step
            for step in steps
            if str(step.get("uses", "")).startswith("actions/download-artifact@")
        )
        self.assertEqual(
            "${{ needs.docs-release-audit.outputs.qualification-artifact-id }}",
            download["with"]["artifact-ids"],
        )
        self.assertEqual("error", download["with"]["digest-mismatch"])
        self.assertEqual("${{ github.run_id }}", download["with"]["run-id"])
        self.assertEqual("${{ github.repository }}", download["with"]["repository"])

        build_step = next(
            step
            for step in steps
            if "scripts/ci/release_qualification_asset.py" in step.get("run", "")
        )
        self.assertIn("--release-tag", build_step["run"])
        self.assertIn("--release-commit", build_step["run"])
        self.assertIn("--head-sha", build_step["run"])

        audit_steps = audit["steps"]
        bind = next(
            step
            for step in audit_steps
            if step.get("name") == "Bind release tag to exact source commit"
        )
        self.assertEqual("release-source", bind["id"])
        self.assertIn("git -C .release-source rev-parse HEAD", bind["run"])
        self.assertIn("DECLARED_RELEASE_COMMIT", bind["run"])
        self.assertEqual(
            "${{ steps.release-source.outputs.commit }}",
            next(
                step
                for step in audit_steps
                if step.get("name")
                == "Require source-bound GitHub, Packagist, and image surfaces"
            )["env"]["EXPECTED_SOURCE_COMMIT"],
        )

        composer_step = next(
            step
            for step in audit_steps
            if step.get("run") == "scripts/ci/check-exact-current-composer.sh"
        )
        self.assertEqual(
            ".release-source/composer.json",
            composer_step["env"]["EXACT_CURRENT_COMPOSER_MANIFEST"],
        )
        self.assertEqual(
            ".release-source/standalone/composer.json",
            composer_step["env"]["EXACT_CURRENT_COMPOSER_SERVICE_MANIFEST"],
        )

        self.assertEqual(
            "${{ steps.release-source.outputs.commit }}",
            next(
                step
                for step in audit_steps
                if step.get("name") == "Mark exact Composer qualification ready"
            )["env"]["RELEASE_COMMIT"],
        )

        retain = next(
            step
            for step in steps
            if step.get("name") == "Create or verify immutable public release evidence"
        )
        self.assertIn("cmp --silent", retain["run"])
        self.assertIn("gh release upload", retain["run"])
        self.assertNotIn("--clobber", retain["run"])


if __name__ == "__main__":
    unittest.main()
