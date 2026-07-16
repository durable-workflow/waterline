#!/usr/bin/env python3
"""Executable coverage for immutable Waterline release tag publication."""

from __future__ import annotations

import json
import os
import subprocess
import sys
import tempfile
import unittest
from pathlib import Path

SCRIPT = Path(__file__).with_name("publish-planned-tag.py")
PLAN_TAG = "release-plan/alpha-continuity-test"
RELEASE_TAG = "2.0.0-alpha.136"


def git(*arguments: str, cwd: Path | None = None, check: bool = True) -> subprocess.CompletedProcess[str]:
    return subprocess.run(
        ["git", *arguments],
        cwd=cwd,
        check=check,
        text=True,
        stdout=subprocess.PIPE,
        stderr=subprocess.PIPE,
    )


class PlannedTagPublicationTest(unittest.TestCase):
    def setUp(self) -> None:
        self.temporary = tempfile.TemporaryDirectory(prefix="waterline-planned-tag-test-")
        self.root = Path(self.temporary.name)
        self.source = self.root / "source"
        self.remote = self.root / "remote.git"
        git("init", "--quiet", "--initial-branch=v2", str(self.source))
        git("init", "--quiet", "--bare", str(self.remote))
        git("config", "user.name", "Release Test", cwd=self.source)
        git("config", "user.email", "release-test@example.invalid", cwd=self.source)
        self.first = self.commit("first")
        self.second = self.commit("second")

    def tearDown(self) -> None:
        self.temporary.cleanup()

    def commit(
        self,
        value: str,
        *,
        package: str = "durable-workflow/waterline",
        legacy_replacement: str = "self.version",
    ) -> str:
        (self.source / "value.txt").write_text(f"{value}\n", encoding="utf-8")
        (self.source / "composer.json").write_text(
            json.dumps(
                {
                    "name": package,
                    "replace": {"laravel-workflow/waterline": legacy_replacement},
                },
                indent=2,
            )
            + "\n",
            encoding="utf-8",
        )
        git("add", "composer.json", "value.txt", cwd=self.source)
        git("commit", "--quiet", "-m", value, cwd=self.source)
        return git("rev-parse", "HEAD", cwd=self.source).stdout.strip()

    def publish(
        self,
        commit: str,
        evidence_name: str = "evidence.json",
        *,
        tag: str = RELEASE_TAG,
        plan_tag: str = PLAN_TAG,
    ) -> subprocess.CompletedProcess[str]:
        return subprocess.run(
            [
                sys.executable,
                str(SCRIPT),
                "--remote",
                str(self.remote),
                "--tag",
                tag,
                "--commit",
                commit,
                "--plan-tag",
                plan_tag,
                "--evidence",
                str(self.root / evidence_name),
            ],
            cwd=self.source,
            check=False,
            text=True,
            stdout=subprocess.PIPE,
            stderr=subprocess.PIPE,
        )

    def evidence(self, name: str = "evidence.json") -> dict[str, object]:
        return json.loads((self.root / name).read_text(encoding="utf-8"))

    def remote_tag(self) -> str:
        return git("--git-dir", str(self.remote), "rev-parse", f"refs/tags/{RELEASE_TAG}").stdout.strip()

    def test_creates_exact_tag_and_identical_rerun_is_idempotent(self) -> None:
        created = self.publish(self.first)
        self.assertEqual(created.returncode, 0, created.stderr)
        self.assertEqual(self.remote_tag(), self.first)
        self.assertEqual(self.evidence()["action"], "created")
        self.assertEqual(self.evidence()["release_plan_tag"], PLAN_TAG)

        verified = self.publish(self.first, "rerun.json")
        self.assertEqual(verified.returncode, 0, verified.stderr)
        self.assertEqual(self.remote_tag(), self.first)
        self.assertEqual(self.evidence("rerun.json")["action"], "verified")

    def test_rejects_moved_tag_with_actionable_non_secret_evidence(self) -> None:
        git(
            "push",
            "--force",
            str(self.remote),
            f"{self.second}:refs/tags/{RELEASE_TAG}",
            cwd=self.source,
        )

        rejected = self.publish(self.first)
        self.assertEqual(rejected.returncode, 1)
        self.assertEqual(self.remote_tag(), self.second)
        evidence = self.evidence()
        self.assertEqual(evidence["attempted_ref"], f"refs/tags/{RELEASE_TAG}")
        self.assertEqual(evidence["planned_commit"], self.first)
        self.assertEqual(evidence["outcome"], "failed")
        self.assertIn("release-plan-publication", str(evidence["effective_permission_boundary"]))
        self.assertIn("rerun Release plan recovery", str(evidence["safe_recovery_action"]))

    def test_rejects_source_identity_conflict_without_creating_tag(self) -> None:
        conflicting_commit = self.commit(
            "conflicting source identity",
            package="example/not-waterline",
            legacy_replacement="2.0.0-alpha.1",
        )

        rejected = self.publish(conflicting_commit)

        self.assertEqual(rejected.returncode, 1)
        evidence = self.evidence()
        self.assertEqual(evidence["phase"], "source-identity")
        self.assertEqual(evidence["classification"], "terminal-source-identity-conflict")
        self.assertEqual(evidence["package"], "example/not-waterline")
        self.assertEqual(evidence["planned_version"], RELEASE_TAG)
        self.assertIn("protected successor plan", str(evidence["safe_recovery_action"]))
        absent = git("ls-remote", str(self.remote), f"refs/tags/{RELEASE_TAG}")
        self.assertEqual(absent.stdout, "")

    def test_rejects_invalid_release_and_plan_identities(self) -> None:
        variants = {
            "mutable release ref": {"tag": "latest", "plan_tag": PLAN_TAG},
            "non-plan authority": {"tag": RELEASE_TAG, "plan_tag": "v2"},
        }
        for label, values in variants.items():
            with self.subTest(label=label):
                rejected = self.publish(
                    self.first,
                    f"{label.replace(' ', '-')}.json",
                    tag=values["tag"],
                    plan_tag=values["plan_tag"],
                )
                self.assertEqual(rejected.returncode, 1)
                self.assertEqual(
                    self.evidence(f"{label.replace(' ', '-')}.json")["phase"],
                    "input",
                )

    def test_records_repository_authority_push_rejection_without_credentials(self) -> None:
        leaked_token = "ghp_" + "abcdefghijklmnopqrstuvwxyz" + "1234567890"
        hook = self.remote / "hooks" / "pre-receive"
        hook.write_text(
            "#!/usr/bin/env bash\n"
            "printf '%s\\n' 'release policy refused the exact planned tag' >&2\n"
            f"printf '%s\\n' 'Authorization: Bearer {leaked_token}' >&2\n"
            "printf '%02048d' 0 >&2\n"
            "exit 1\n",
            encoding="utf-8",
        )
        os.chmod(hook, 0o755)

        rejected = self.publish(self.first)

        self.assertEqual(rejected.returncode, 1)
        evidence = self.evidence()
        self.assertEqual(evidence["phase"], "repository-authority")
        self.assertIn("git push", str(evidence["git_operation"]))
        self.assertIn("write deploy key", str(evidence["effective_permission_boundary"]))
        diagnostic = str(evidence["remote_diagnostic"])
        self.assertIn("release policy refused", diagnostic)
        self.assertIn("[REDACTED]", diagnostic)
        self.assertIn("[diagnostic truncated]", diagnostic)
        self.assertNotIn(leaked_token, json.dumps(evidence))
        self.assertNotIn(leaked_token, rejected.stderr)


if __name__ == "__main__":
    unittest.main()
