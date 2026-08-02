#!/usr/bin/env python3
"""Focused coverage for complete Waterline release-surface verification."""

from __future__ import annotations

import importlib.util
import sys
import unittest
from pathlib import Path
from unittest import mock


SCRIPT = Path(__file__).with_name("verify-release-surfaces.py")
SPEC = importlib.util.spec_from_file_location("verify_release_surfaces", SCRIPT)
if SPEC is None or SPEC.loader is None:
    raise RuntimeError(f"cannot load {SCRIPT}")
surfaces = importlib.util.module_from_spec(SPEC)
sys.modules[SPEC.name] = surfaces
SPEC.loader.exec_module(surfaces)


class VerifyReleaseSurfacesTest(unittest.TestCase):
    def test_completion_requires_source_release_package_and_image(self) -> None:
        commit = "a" * 40
        client = mock.Mock()
        with (
            mock.patch.object(surfaces.recovery, "resolve_tag", return_value=commit),
            mock.patch.object(
                surfaces.recovery, "require_source_tag", return_value=commit
            ) as source,
            mock.patch.object(
                surfaces.recovery,
                "verify_github_release",
                return_value={
                    "url": "https://github.com/durable-workflow/waterline/releases/tag/2.0.0-rc.10"
                },
            ) as github_release,
            mock.patch.object(
                surfaces.recovery,
                "verify_composer",
                return_value={"source_reference": commit, "dist_reference": commit},
            ) as packagist,
            mock.patch.object(
                surfaces.recovery,
                "verify_oci",
                return_value={"manifest_digest": "sha256:" + "b" * 64},
            ) as image,
        ):
            evidence = surfaces.verify(client, "2.0.0-rc.10")

        self.assertEqual("verified", evidence["outcome"])
        self.assertEqual(commit, evidence["source_commit"])
        source.assert_called_once()
        github_release.assert_called_once()
        packagist.assert_called_once()
        image.assert_called_once_with(
            client,
            surfaces.SERVICE_IMAGE,
            "2.0.0-rc.10",
            commit,
        )

    def test_service_image_constructs_namespaced_docker_hub_repository(self) -> None:
        self.assertEqual(
            "docker.io/durableworkflow/waterline",
            surfaces.SERVICE_IMAGE.package,
        )
        self.assertEqual(
            "durableworkflow/waterline",
            surfaces.SERVICE_IMAGE.package.split("/", 1)[1],
        )

    def test_missing_source_tag_cannot_complete(self) -> None:
        with mock.patch.object(surfaces.recovery, "resolve_tag", return_value=None):
            with self.assertRaisesRegex(surfaces.recovery.NotFound, "source tag"):
                surfaces.verify(mock.Mock(), "2.0.0-rc.10")


if __name__ == "__main__":
    unittest.main()
