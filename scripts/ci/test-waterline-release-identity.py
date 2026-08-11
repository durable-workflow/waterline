#!/usr/bin/env python3
"""Regression coverage for fail-closed Waterline publication identities."""

from __future__ import annotations

import importlib.util
import sys
import unittest
from pathlib import Path

import yaml


SCRIPT = Path(__file__).with_name("waterline_release_identity.py")
ROOT = Path(__file__).parents[2]
SPEC = importlib.util.spec_from_file_location("waterline_release_identity", SCRIPT)
if SPEC is None or SPEC.loader is None:
    raise RuntimeError(f"cannot load {SCRIPT}")
identity = importlib.util.module_from_spec(SPEC)
sys.modules[SPEC.name] = identity
SPEC.loader.exec_module(identity)


VERSIONS = {
    "waterline": "2.0.0-rc.16",
    "workflow": "2.0.0-rc.14",
    "sdk-php": "2.0.0-rc.14",
}


def approved() -> dict:
    return {"schema": identity.SCHEMA, "versions": VERSIONS}


def manifest() -> dict:
    return {
        "name": identity.PACKAGE,
        "require": {identity.SDK_PACKAGE: VERSIONS["sdk-php"]},
        "require-dev": {identity.WORKFLOW_PACKAGE: VERSIONS["workflow"]},
        "extra": {"durable-workflow": {"product-train": VERSIONS["waterline"]}},
    }


def standalone() -> dict:
    return {"require": {identity.SDK_PACKAGE: VERSIONS["sdk-php"]}}


def lock() -> dict:
    return {
        "packages": [{"name": identity.SDK_PACKAGE, "version": VERSIONS["sdk-php"]}]
    }


def validate(
    candidate_manifest: dict | None = None,
    candidate_standalone: dict | None = None,
    candidate_lock: dict | None = None,
    *,
    release_version: str | None = VERSIONS["waterline"],
) -> dict:
    return identity.validate(
        approved(),
        candidate_manifest or manifest(),
        b"waterline-composer-source",
        candidate_standalone or standalone(),
        b"standalone-composer-source",
        candidate_lock or lock(),
        b"standalone-lock-source",
        release_version=release_version,
    )


def workflow(name: str) -> dict:
    value = yaml.load(
        (ROOT / ".github" / "workflows" / name).read_text(encoding="utf-8"),
        Loader=yaml.BaseLoader,
    )
    if not isinstance(value, dict):
        raise RuntimeError(f"workflow {name} must be a mapping")
    return value


class WaterlineReleaseIdentityTest(unittest.TestCase):
    def test_approved_current_source_is_publishable(self) -> None:
        evidence = validate()

        self.assertEqual("verified", evidence["outcome"])
        self.assertEqual(VERSIONS, evidence["versions"])

    def test_deliberately_stale_php_sdk_pin_is_rejected(self) -> None:
        stale = manifest()
        stale["require"][identity.SDK_PACKAGE] = "2.0.0-rc.11"

        with self.assertRaisesRegex(
            identity.IdentityError, "do not match the approved current product tuple"
        ):
            validate(candidate_manifest=stale)

    def test_deliberately_stale_workflow_pin_is_rejected(self) -> None:
        stale = manifest()
        stale["require-dev"][identity.WORKFLOW_PACKAGE] = "2.0.0-rc.13"

        with self.assertRaisesRegex(
            identity.IdentityError, "do not match the approved current product tuple"
        ):
            validate(candidate_manifest=stale)

    def test_stale_service_image_sdk_source_is_rejected(self) -> None:
        stale_manifest = standalone()
        stale_manifest["require"][identity.SDK_PACKAGE] = "2.0.0-rc.11"

        with self.assertRaisesRegex(
            identity.IdentityError, "standalone service PHP SDK"
        ):
            validate(candidate_standalone=stale_manifest)

        stale_lock = lock()
        stale_lock["packages"][0]["version"] = "2.0.0-rc.11"
        with self.assertRaisesRegex(identity.IdentityError, "standalone service lock"):
            validate(candidate_lock=stale_lock)

    def test_stale_or_unapproved_release_tag_is_rejected(self) -> None:
        with self.assertRaisesRegex(identity.IdentityError, "does not match approved"):
            validate(release_version="2.0.0-rc.14")

    def test_every_branch_route_runs_current_identity_qualification(self) -> None:
        document = workflow("php.yml")
        job = document["jobs"]["release-contracts"]
        matching = [
            step
            for step in job["steps"]
            if "--approved-tuple release/current-product-tuple.json"
            in step.get("run", "")
        ]

        self.assertNotIn("if", job)
        self.assertEqual(1, len(matching))
        self.assertIn("release/current-product-tuple.json", matching[0]["run"])
        self.assertIn("composer.json", matching[0]["run"])
        self.assertIn("standalone/composer.lock", matching[0]["run"])

    def test_service_image_publication_needs_tag_source_qualification(self) -> None:
        jobs = workflow("service-image.yml")["jobs"]
        qualification = jobs["qualify-release-source"]
        matching = [
            step
            for step in qualification["steps"]
            if "scripts/ci/waterline_release_identity.py" in step.get("run", "")
        ]

        self.assertEqual(1, len(matching))
        self.assertIn('--release-version "$GITHUB_REF_NAME"', matching[0]["run"])
        self.assertEqual("qualify-release-source", jobs["smoke"]["needs"])
        self.assertEqual(["qualify-release-source", "smoke"], jobs["publish"]["needs"])

    def test_service_image_recovery_qualifies_exact_planned_source_before_push(
        self,
    ) -> None:
        steps = workflow("service-image-recovery.yml")["jobs"]["publish"]["steps"]
        matching = [
            (index, step)
            for index, step in enumerate(steps)
            if "scripts/ci/waterline_release_identity.py" in step.get("run", "")
        ]

        self.assertEqual(1, len(matching))
        qualification_index, qualification = matching[0]
        command = qualification["run"]
        self.assertIn("--approved-tuple release/current-product-tuple.json", command)
        self.assertIn("--waterline-composer release-source/composer.json", command)
        self.assertIn(
            "--standalone-composer release-source/standalone/composer.json", command
        )
        self.assertIn(
            "--standalone-lock release-source/standalone/composer.lock", command
        )
        self.assertIn(
            '--release-version "${{ needs.discover.outputs.version }}"', command
        )

        checkout_index = next(
            index
            for index, step in enumerate(steps)
            if step.get("name")
            == "Check out the exact planned source without running its workflow code"
        )
        login_index = next(
            index
            for index, step in enumerate(steps)
            if step.get("uses", "").startswith("docker/login-action@")
        )
        publish_index = next(
            index
            for index, step in enumerate(steps)
            if step.get("uses", "").startswith("docker/build-push-action@")
        )
        self.assertLess(checkout_index, qualification_index)
        self.assertLess(qualification_index, login_index)
        self.assertLess(qualification_index, publish_index)


if __name__ == "__main__":
    unittest.main()
