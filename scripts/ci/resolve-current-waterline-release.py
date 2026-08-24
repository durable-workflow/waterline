#!/usr/bin/env python3
"""Resolve the newest public Waterline release reachable from the v2 target."""

from __future__ import annotations

import argparse
import importlib.util
import os
import re
import subprocess
import sys
import urllib.parse
from pathlib import Path
from typing import Any


RECOVERY_PATH = Path(__file__).with_name("component-release-recovery.py")
SPEC = importlib.util.spec_from_file_location(
    "waterline_component_release_recovery", RECOVERY_PATH
)
if SPEC is None or SPEC.loader is None:
    raise RuntimeError(f"cannot load {RECOVERY_PATH}")
recovery = importlib.util.module_from_spec(SPEC)
sys.modules[SPEC.name] = recovery
SPEC.loader.exec_module(recovery)

REPOSITORY = "durable-workflow/waterline"
RELEASE_PATTERN = re.compile(
    r"^2\.0\.0(?:-(?:alpha|beta|rc)\.(?:0|[1-9][0-9]*))?$"
)
PAGE_SIZE = 100
MAX_PAGES = 20


class ResolutionError(RuntimeError):
    """The current public v2 release cannot be selected safely."""


def git(repository: Path, *arguments: str, check: bool = True) -> str:
    process = subprocess.run(
        ["git", "-C", str(repository), *arguments],
        text=True,
        capture_output=True,
        check=False,
    )
    if check and process.returncode != 0:
        detail = process.stderr.strip() or process.stdout.strip() or "git command failed"
        raise ResolutionError(detail)
    return process.stdout.strip()


def public_release_versions(client: Any) -> dict[str, Any]:
    releases: dict[str, Any] = {}
    encoded_repository = urllib.parse.quote(REPOSITORY, safe="/")
    for page in range(1, MAX_PAGES + 1):
        values = client.json(
            f"https://api.github.com/repos/{encoded_repository}/releases"
            f"?per_page={PAGE_SIZE}&page={page}"
        )
        if not isinstance(values, list):
            raise ResolutionError("GitHub Releases did not return a list")
        for value in values:
            if not isinstance(value, dict):
                continue
            tag = value.get("tag_name")
            prerelease = value.get("prerelease")
            published_at = value.get("published_at")
            parsed = recovery.parse_semver(tag) if isinstance(tag, str) else None
            if (
                not isinstance(tag, str)
                or RELEASE_PATTERN.fullmatch(tag) is None
                or parsed is None
                or value.get("draft") is not False
                or not isinstance(prerelease, bool)
                or prerelease != bool(parsed.prerelease)
                or not isinstance(published_at, str)
                or not published_at
            ):
                continue
            releases[tag] = parsed
        if len(values) < PAGE_SIZE:
            break
    else:
        raise ResolutionError("GitHub Releases pagination exceeded its safety bound")
    return releases


def resolve(client: Any, repository: Path, target_commit: str) -> tuple[str, str]:
    if recovery.COMMIT_PATTERN.fullmatch(target_commit) is None:
        raise ResolutionError("scheduled v2 target is not an exact source commit")
    resolved_target = git(repository, "rev-parse", "--verify", f"{target_commit}^{{commit}}")
    if resolved_target != target_commit:
        raise ResolutionError("scheduled v2 target does not resolve to its declared commit")

    releases = public_release_versions(client)
    reachable_tags = set(
        git(repository, "tag", "--merged", target_commit, "--list").splitlines()
    )
    candidates = [
        (version, parsed)
        for version, parsed in releases.items()
        if version in reachable_tags
    ]
    if not candidates:
        raise ResolutionError(
            "no public Waterline 2.0 release is reachable from the scheduled v2 target"
        )

    version, _ = max(candidates, key=lambda candidate: candidate[1].precedence)
    release_commit = git(
        repository, "rev-parse", "--verify", f"refs/tags/{version}^{{commit}}"
    )
    if recovery.COMMIT_PATTERN.fullmatch(release_commit) is None:
        raise ResolutionError("selected Waterline release tag has no exact source commit")
    if subprocess.run(
        [
            "git",
            "-C",
            str(repository),
            "merge-base",
            "--is-ancestor",
            release_commit,
            target_commit,
        ],
        capture_output=True,
        check=False,
    ).returncode != 0:
        raise ResolutionError("selected Waterline release is not on the v2 target")
    return version, release_commit


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--target-commit", required=True)
    args = parser.parse_args()

    client = recovery.PublicClient(
        os.environ.get("GITHUB_TOKEN") or os.environ.get("GH_TOKEN")
    )
    try:
        version, commit = resolve(client, Path.cwd(), args.target_commit)
    except (ResolutionError, recovery.RecoveryError) as error:
        print(f"::error::{error}", file=sys.stderr)
        return 1
    print(version, commit)
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
