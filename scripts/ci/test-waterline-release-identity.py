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


CURRENT_PUBLIC = {
    "waterline": "2.0.0-rc.35",
    "workflow": "2.0.1",
    "sdk-php": "2.0.0",
}
CANDIDATE_WATERLINE = "2.0.0"
PUBLIC_SDK_REFERENCE = "0123456789abcdef0123456789abcdef01234567"


def approved() -> dict:
    return {"schema": identity.SCHEMA, "versions": CURRENT_PUBLIC}


def manifest() -> dict:
    return {
        "name": identity.PACKAGE,
        "require": {},
        "require-dev": {
            identity.SDK_PACKAGE: CURRENT_PUBLIC["sdk-php"],
            identity.WORKFLOW_PACKAGE: CURRENT_PUBLIC["workflow"],
        },
        "extra": {"durable-workflow": {"product-train": CANDIDATE_WATERLINE}},
    }


def standalone() -> dict:
    return {"require": {identity.SDK_PACKAGE: CURRENT_PUBLIC["sdk-php"]}}


def lock() -> dict:
    version = CURRENT_PUBLIC["sdk-php"]
    return {
        "packages": [
            {
                "name": identity.SDK_PACKAGE,
                "version": version,
                "source": {
                    "type": "git",
                    "url": "https://github.com/durable-workflow/sdk-php.git",
                    "reference": PUBLIC_SDK_REFERENCE,
                },
                "dist": {
                    "type": "zip",
                    "url": (
                        "https://api.github.com/repos/durable-workflow/"
                        f"sdk-php/zipball/{PUBLIC_SDK_REFERENCE}"
                    ),
                    "reference": PUBLIC_SDK_REFERENCE,
                    "shasum": "",
                },
            }
        ]
    }


def validate(
    candidate_manifest: dict | None = None,
    candidate_standalone: dict | None = None,
    candidate_lock: dict | None = None,
    *,
    release_version: str | None = None,
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
    def test_candidate_source_is_distinct_from_current_public_artifact(self) -> None:
        evidence = validate()

        self.assertEqual("verified", evidence["outcome"])
        self.assertEqual(CURRENT_PUBLIC, evidence["current_public_artifacts"])
        self.assertEqual(
            {
                "waterline": CANDIDATE_WATERLINE,
                "workflow": CURRENT_PUBLIC["workflow"],
                "sdk-php": CURRENT_PUBLIC["sdk-php"],
            },
            evidence["candidate_source"],
        )
        self.assertIsNone(evidence["release_tag"])

    def test_deliberately_stale_php_sdk_pin_is_rejected(self) -> None:
        stale = manifest()
        stale["require-dev"][identity.SDK_PACKAGE] = "2.0.0-rc.11"

        with self.assertRaisesRegex(
            identity.IdentityError, "do not match the current public dependency selection"
        ):
            validate(candidate_manifest=stale)

    def test_deliberately_stale_workflow_pin_is_rejected(self) -> None:
        stale = manifest()
        stale["require-dev"][identity.WORKFLOW_PACKAGE] = "2.0.0-rc.13"

        with self.assertRaisesRegex(
            identity.IdentityError, "do not match the current public dependency selection"
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

    def test_mutable_mismatched_or_local_service_lock_reference_is_rejected(
        self,
    ) -> None:
        stale_lock = lock()
        stale_lock["packages"][0]["source"]["reference"] = "2.0.0-rc.40"

        with self.assertRaisesRegex(identity.IdentityError, "immutable public"):
            validate(candidate_lock=stale_lock)

        mismatched_lock = lock()
        mismatched_lock["packages"][0]["dist"]["reference"] = "f" * 40

        with self.assertRaisesRegex(identity.IdentityError, "immutable public"):
            validate(candidate_lock=mismatched_lock)

        local_lock = lock()
        local_lock["packages"][0].pop("source")
        local_lock["packages"][0]["dist"] = {
            "type": "path",
            "url": "../../sdk-php",
            "reference": "worker-commit",
        }

        with self.assertRaisesRegex(identity.IdentityError, "immutable public"):
            validate(candidate_lock=local_lock)

    def test_release_tag_must_match_source_not_prior_published_artifact(self) -> None:
        evidence = validate(release_version=CANDIDATE_WATERLINE)

        self.assertEqual(CANDIDATE_WATERLINE, evidence["release_tag"])
        self.assertEqual(
            CURRENT_PUBLIC["waterline"],
            evidence["current_public_artifacts"]["waterline"],
        )

        with self.assertRaisesRegex(identity.IdentityError, "does not match source-declared"):
            validate(release_version="2.0.0-rc.14")

    def test_release_identity_rejects_versions_outside_the_supported_2_0_line(self) -> None:
        unsupported = manifest()
        unsupported["extra"]["durable-workflow"]["product-train"] = "2.1.0"

        with self.assertRaisesRegex(identity.IdentityError, "exact supported 2.0 release"):
            validate(candidate_manifest=unsupported)

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
        self.assertIn("--candidate-source", matching[0]["run"])
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

if __name__ == "__main__":
    unittest.main()
