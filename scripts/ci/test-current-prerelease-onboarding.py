#!/usr/bin/env python3
"""Focused coverage for qualified prerelease onboarding resolution."""

from __future__ import annotations

import copy
import hashlib
import importlib.util
import json
import sys
import unittest
from pathlib import Path
from typing import Any


SCRIPT = Path(__file__).parents[1] / "resolve-current-prerelease.py"
SPEC = importlib.util.spec_from_file_location("current_prerelease_onboarding", SCRIPT)
if SPEC is None or SPEC.loader is None:
    raise RuntimeError(f"cannot load {SCRIPT}")
resolver = importlib.util.module_from_spec(SPEC)
sys.modules[SPEC.name] = resolver
SPEC.loader.exec_module(resolver)

VERSION = "2.0.0-rc.91"
RELEASE_COMMIT = "a" * 40
WORKFLOW_COMMIT = "b" * 40
RUN_ID = 123456789
RUN_ATTEMPT = 2
IMAGE_DIGEST = "sha256:" + "c" * 64
PACKAGES = {
    "sdk-php": "2.0.0-rc.93",
    "waterline": VERSION,
    "workflow": "2.0.0-rc.92",
}
ASSET_NAME = f"waterline-exact-composer-qualification-{RUN_ID}-{RUN_ATTEMPT}.json"
ASSET_URL = (
    f"https://github.com/{resolver.REPOSITORY}/releases/download/{VERSION}/{ASSET_NAME}"
)


def asset() -> dict[str, Any]:
    return {
        "schema": resolver.ASSET_SCHEMA,
        "schema_version": 2,
        "repository": resolver.REPOSITORY,
        "workflow_run": {
            "name": resolver.WORKFLOW_NAME,
            "path": resolver.WORKFLOW_PATH,
            "event": "repository_dispatch",
            "run_id": RUN_ID,
            "run_attempt": RUN_ATTEMPT,
            "run_url": (
                f"https://github.com/{resolver.REPOSITORY}/actions/runs/{RUN_ID}"
            ),
            "head_sha": WORKFLOW_COMMIT,
        },
        "release": {"tag": VERSION, "source_commit": RELEASE_COMMIT},
        "qualification": {
            "schema": resolver.QUALIFICATION_SCHEMA,
            "outcome": "pass",
            "packages": PACKAGES,
            "package_metadata": {"name": "durable-workflow/waterline"},
            "composer_graphs": {"embedded": {}, "service": {}},
        },
        "release_surfaces": {
            "schema": resolver.SURFACES_SCHEMA,
            "outcome": "verified",
            "version": VERSION,
            "source_commit": RELEASE_COMMIT,
            "github_release": {"tag": VERSION},
            "packagist": {"version": VERSION},
            "service_image": {
                "kind": "oci",
                "image": f"docker.io/{resolver.SERVICE_IMAGE}:{VERSION}",
                "digest": IMAGE_DIGEST,
                "platforms": ["linux/amd64", "linux/arm64"],
            },
        },
    }


def authority(asset_source: bytes) -> dict[str, Any]:
    return {
        "schema": resolver.AUTHORITY_SCHEMA,
        "schema_version": 1,
        "outcome": "pass",
        "current_qualification_id": f"waterline-{VERSION}-composer",
        "current_release": {"artifact": "waterline", "version": VERSION},
        "qualifications": [
            {
                "id": f"waterline-{VERSION}-composer",
                "component": {"artifact": "waterline", "version": VERSION},
                "qualification": {
                    "schema": resolver.QUALIFICATION_SCHEMA,
                    "outcome": "pass",
                    "packages": PACKAGES,
                },
                "source": {
                    "repository_url": resolver.REPOSITORY_URL,
                    "release_tag": VERSION,
                    "release_commit": RELEASE_COMMIT,
                    "workflow_run": {
                        "name": resolver.WORKFLOW_NAME,
                        "path": resolver.WORKFLOW_PATH,
                        "event": "repository_dispatch",
                        "head_sha": WORKFLOW_COMMIT,
                        "run_id": RUN_ID,
                        "run_attempt": RUN_ATTEMPT,
                        "run_url": (
                            f"https://github.com/{resolver.REPOSITORY}/actions/"
                            f"runs/{RUN_ID}"
                        ),
                        "run_conclusion": "success",
                        "qualification_outcome": "pass",
                    },
                    "artifact": {
                        "name": ASSET_NAME,
                        "artifact_id": 987654321,
                        "url": ASSET_URL,
                        "digest": (
                            "sha256:" + hashlib.sha256(asset_source).hexdigest()
                        ),
                    },
                },
                "evidence_role": "current",
            }
        ],
    }


def encoded(value: dict[str, Any]) -> bytes:
    return json.dumps(value, sort_keys=True).encode()


class CurrentPrereleaseOnboardingTest(unittest.TestCase):
    def fixtures(
        self,
    ) -> tuple[dict[str, Any], bytes, dict[str, bytes]]:
        asset_value = asset()
        asset_source = encoded(asset_value)
        authority_value = authority(asset_source)
        sources = {
            resolver.AUTHORITY_URL: encoded(authority_value),
            ASSET_URL: asset_source,
        }
        return authority_value, asset_source, sources

    def test_resolves_image_digest_and_independent_composer_graphs(self) -> None:
        _, _, sources = self.fixtures()

        selected = resolver.resolve(sources.__getitem__)

        self.assertEqual(VERSION, selected.version)
        self.assertEqual(PACKAGES, selected.packages)
        self.assertEqual(IMAGE_DIGEST, selected.image_digest)
        self.assertEqual(
            f"{resolver.SERVICE_IMAGE}@{IMAGE_DIGEST}",
            selected.public_value()["service_image"]["pull"],
        )

    def test_rejects_changed_qualification_asset_bytes(self) -> None:
        _, _, sources = self.fixtures()
        sources[ASSET_URL] += b"\n"

        with self.assertRaisesRegex(resolver.ResolutionError, "digest"):
            resolver.resolve(sources.__getitem__)

    def test_rejects_unqualified_or_unversioned_service_images(self) -> None:
        authority_value, _, _ = self.fixtures()
        cases = (
            ("latest", "docker.io/durableworkflow/waterline:latest"),
            ("stable fallback", "docker.io/durableworkflow/waterline:1.0.17"),
            ("wrong RC", "docker.io/durableworkflow/waterline:2.0.0-rc.90"),
        )

        for name, image in cases:
            with self.subTest(name=name):
                changed_asset = asset()
                changed_asset["release_surfaces"]["service_image"]["image"] = image
                asset_source = encoded(changed_asset)
                changed_authority = copy.deepcopy(authority_value)
                changed_authority["qualifications"][0]["source"]["artifact"][
                    "digest"
                ] = "sha256:" + hashlib.sha256(asset_source).hexdigest()
                sources = {
                    resolver.AUTHORITY_URL: encoded(changed_authority),
                    ASSET_URL: asset_source,
                }

                with self.assertRaisesRegex(
                    resolver.ResolutionError, "required service image"
                ):
                    resolver.resolve(sources.__getitem__)

    def test_rejects_a_stable_package_identity(self) -> None:
        authority_value, _, _ = self.fixtures()
        changed = copy.deepcopy(authority_value)
        changed["qualifications"][0]["qualification"]["packages"]["workflow"] = "1.0.80"
        sources = {
            resolver.AUTHORITY_URL: encoded(changed),
            ASSET_URL: b"not reached",
        }

        with self.assertRaisesRegex(resolver.ResolutionError, "2.0 RC"):
            resolver.resolve(sources.__getitem__)


if __name__ == "__main__":
    unittest.main()
