#!/usr/bin/env python3
"""Regression coverage for scheduled Waterline release selection."""

from __future__ import annotations

import importlib.util
import json
import subprocess
import sys
import tempfile
import unittest
from pathlib import Path
from typing import Any


SCRIPT = Path(__file__).with_name("resolve-current-waterline-release.py")
SPEC = importlib.util.spec_from_file_location("current_waterline_release", SCRIPT)
if SPEC is None or SPEC.loader is None:
    raise RuntimeError(f"cannot load {SCRIPT}")
resolver = importlib.util.module_from_spec(SPEC)
sys.modules[SPEC.name] = resolver
SPEC.loader.exec_module(resolver)


class ReleaseClient:
    def __init__(self, releases: list[dict[str, Any]]) -> None:
        self.releases = releases
        self.urls: list[str] = []

    def json(self, url: str) -> list[dict[str, Any]]:
        self.urls.append(url)
        return self.releases


def release(version: str) -> dict[str, Any]:
    return {
        "tag_name": version,
        "draft": False,
        "prerelease": "-" in version,
        "published_at": "2026-08-24T00:00:00Z",
    }


class CurrentWaterlineReleaseTest(unittest.TestCase):
    def git(self, repository: Path, *arguments: str) -> str:
        return subprocess.run(
            ["git", "-C", str(repository), *arguments],
            text=True,
            capture_output=True,
            check=True,
        ).stdout.strip()

    def commit(self, repository: Path, message: str) -> str:
        self.git(repository, "commit", "--allow-empty", "-m", message)
        return self.git(repository, "rev-parse", "HEAD")

    def repository(self, directory: Path) -> tuple[Path, str, str]:
        repository = directory / "waterline"
        repository.mkdir()
        self.git(repository, "init", "--quiet", "--initial-branch", "v2")
        self.git(repository, "config", "user.email", "test@example.com")
        self.git(repository, "config", "user.name", "Test")

        old_commit = self.commit(repository, "older release")
        self.git(
            repository,
            "tag",
            "--annotate",
            "2.0.0-rc.21",
            "--message",
            "older release",
            old_commit,
        )
        tuple_path = repository / "release" / "current-product-tuple.json"
        tuple_path.parent.mkdir()
        tuple_path.write_text(
            json.dumps({"versions": {"waterline": "2.0.0-rc.21"}}),
            encoding="utf-8",
        )
        self.git(repository, "add", str(tuple_path))
        current_commit = self.commit(repository, "current release")
        self.git(
            repository,
            "tag",
            "--annotate",
            "2.0.0-rc.24",
            "--message",
            "current release",
            current_commit,
        )
        return repository, old_commit, current_commit

    def test_stale_repository_tuple_cannot_override_current_public_v2_release(self) -> None:
        with tempfile.TemporaryDirectory() as temporary:
            repository, _, current_commit = self.repository(Path(temporary))
            client = ReleaseClient(
                [release("2.0.0-rc.21"), release("2.0.0-rc.24")]
            )

            selected = resolver.resolve(client, repository, current_commit)

        self.assertEqual(("2.0.0-rc.24", current_commit), selected)
        self.assertEqual(1, len(client.urls))
        self.assertIn("per_page=100&page=1", client.urls[0])

    def test_newer_release_outside_v2_does_not_replace_v2_authority(self) -> None:
        with tempfile.TemporaryDirectory() as temporary:
            repository, old_commit, current_commit = self.repository(Path(temporary))
            self.git(repository, "checkout", "--quiet", "--detach", old_commit)
            unrelated_commit = self.commit(repository, "unrelated release")
            self.git(
                repository,
                "tag",
                "--annotate",
                "2.0.0-rc.25",
                "--message",
                "unrelated release",
                unrelated_commit,
            )
            client = ReleaseClient(
                [release("2.0.0-rc.24"), release("2.0.0-rc.25")]
            )

            selected = resolver.resolve(client, repository, current_commit)

        self.assertEqual(("2.0.0-rc.24", current_commit), selected)

    def test_draft_or_wrong_release_channel_cannot_be_selected(self) -> None:
        with tempfile.TemporaryDirectory() as temporary:
            repository, _, current_commit = self.repository(Path(temporary))
            draft = release("2.0.0-rc.24")
            draft["draft"] = True
            not_prerelease = release("2.0.0-rc.21")
            not_prerelease["prerelease"] = False

            with self.assertRaisesRegex(resolver.ResolutionError, "no public"):
                resolver.resolve(
                    ReleaseClient([draft, not_prerelease]),
                    repository,
                    current_commit,
                )


if __name__ == "__main__":
    unittest.main()
