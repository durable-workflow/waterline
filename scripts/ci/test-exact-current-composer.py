#!/usr/bin/env python3
"""Behavior coverage for bounded Composer metadata convergence."""

from __future__ import annotations

import json
import os
import subprocess
import tempfile
import unittest
from pathlib import Path


ROOT = Path(__file__).parents[2]
SCRIPT = ROOT / "scripts" / "ci" / "check-exact-current-composer.sh"
WATERLINE = "2.0.0-rc.17"
WORKFLOW = "2.0.0-rc.18"
SDK = "2.0.0-rc.12"
DESCRIPTION = (
    "Operational UI for Durable Workflow across embedded and service-mode deployments."
)


class ExactCurrentComposerTest(unittest.TestCase):
    def run_probe(
        self, *, cached_attempts: int, metadata_cached_attempts: int, attempts: int
    ) -> tuple[subprocess.CompletedProcess[str], dict[str, object], int]:
        with tempfile.TemporaryDirectory() as temporary:
            directory = Path(temporary)
            binary_directory = directory / "bin"
            binary_directory.mkdir()
            composer = binary_directory / "composer"
            counter = directory / "composer-require-attempts"
            composer.write_text(
                """#!/bin/sh
set -eu
case " $* " in
    *" require "*)
        count=0
        if [ -f "$FAKE_COMPOSER_COUNTER" ]; then
            count="$(sed -n '1p' "$FAKE_COMPOSER_COUNTER")"
        fi
        count=$((count + 1))
        printf '%s\n' "$count" > "$FAKE_COMPOSER_COUNTER"
        if [ "$count" -le "$FAKE_COMPOSER_CACHED_ATTEMPTS" ]; then
            printf 'Packagist Composer metadata is still cached\n' >&2
            exit 2
        fi
        ;;
    *" show "*)
        count="$(sed -n '1p' "$FAKE_COMPOSER_COUNTER")"
        description="$FAKE_COMPOSER_DESCRIPTION"
        if [ "$count" -le "$FAKE_COMPOSER_METADATA_CACHED_ATTEMPTS" ]; then
            description='Stale package description.'
        fi
        printf '{"description":"%s"}\n' "$description"
        ;;
esac
exit 0
""",
                encoding="utf-8",
            )
            composer.chmod(0o755)
            manifest = directory / "composer.json"
            manifest.write_text(
                json.dumps(
                    {
                        "extra": {"durable-workflow": {"product-train": WATERLINE}},
                        "description": DESCRIPTION,
                        "require": {"durable-workflow/sdk": SDK},
                        "require-dev": {"durable-workflow/workflow": WORKFLOW},
                    }
                ),
                encoding="utf-8",
            )
            evidence = directory / "evidence.json"
            process = subprocess.run(
                ["sh", str(SCRIPT)],
                cwd=ROOT,
                env={
                    **os.environ,
                    "PATH": f"{binary_directory}:{os.environ['PATH']}",
                    "FAKE_COMPOSER_COUNTER": str(counter),
                    "FAKE_COMPOSER_CACHED_ATTEMPTS": str(cached_attempts),
                    "FAKE_COMPOSER_METADATA_CACHED_ATTEMPTS": str(
                        metadata_cached_attempts
                    ),
                    "FAKE_COMPOSER_DESCRIPTION": DESCRIPTION,
                    "EXACT_CURRENT_COMPOSER_MANIFEST": str(manifest),
                    "EXACT_CURRENT_COMPOSER_EVIDENCE": str(evidence),
                    "EXACT_CURRENT_COMPOSER_ATTEMPTS": str(attempts),
                    "EXACT_CURRENT_COMPOSER_RETRY_SLEEP": "0",
                },
                capture_output=True,
                text=True,
                check=False,
            )
            observed = json.loads(evidence.read_text(encoding="utf-8"))
            count = int(counter.read_text(encoding="utf-8").strip())
            return process, observed, count

    def test_cached_metadata_is_rechecked_until_the_exact_tuple_installs(self) -> None:
        process, evidence, attempts = self.run_probe(
            cached_attempts=1, metadata_cached_attempts=0, attempts=2
        )

        self.assertEqual(0, process.returncode, process.stderr)
        self.assertEqual(2, attempts)
        self.assertEqual("pass", evidence["outcome"])
        self.assertEqual(
            {"waterline": WATERLINE, "workflow": WORKFLOW, "sdk-php": SDK},
            evidence["packages"],
        )
        self.assertEqual(
            {
                "name": "durable-workflow/waterline",
                "description": DESCRIPTION,
            },
            evidence["package_metadata"],
        )
        self.assertIn("Waiting for Composer metadata convergence (1/2)", process.stderr)

    def test_stale_description_is_rechecked_until_registry_metadata_matches(
        self,
    ) -> None:
        process, evidence, attempts = self.run_probe(
            cached_attempts=0, metadata_cached_attempts=1, attempts=2
        )

        self.assertEqual(0, process.returncode, process.stderr)
        self.assertEqual(2, attempts)
        self.assertEqual(DESCRIPTION, evidence["package_metadata"]["description"])
        self.assertIn(
            f"Composer reported the published Waterline description: {DESCRIPTION}",
            process.stdout,
        )

    def test_non_installable_tuple_fails_after_the_bound_with_versions(self) -> None:
        process, evidence, attempts = self.run_probe(
            cached_attempts=2, metadata_cached_attempts=0, attempts=2
        )

        self.assertNotEqual(0, process.returncode)
        self.assertEqual(2, attempts)
        self.assertEqual("incomplete", evidence["outcome"])
        self.assertIn("after 2 attempt(s)", evidence["reason"])
        for version in (WATERLINE, WORKFLOW, SDK):
            self.assertIn(version, evidence["reason"])
        self.assertIn(
            "::error title=Composer metadata did not converge::", process.stderr
        )


if __name__ == "__main__":
    unittest.main()
