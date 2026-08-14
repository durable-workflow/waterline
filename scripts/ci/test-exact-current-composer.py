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
WATERLINE = "2.0.0-rc.21"
WORKFLOW = "2.0.0-rc.18"
SDK = "2.0.0-rc.12"
DESCRIPTION = (
    "Operational UI for Durable Workflow across embedded and service-mode deployments."
)


class ExactCurrentComposerTest(unittest.TestCase):
    def run_probe(
        self,
        *,
        embedded_cached_attempts: int,
        service_cached_attempts: int,
        metadata_cached_attempts: int,
        attempts: int,
    ) -> tuple[
        subprocess.CompletedProcess[str], dict[str, object], dict[str, int], str
    ]:
        with tempfile.TemporaryDirectory() as temporary:
            directory = Path(temporary)
            binary_directory = directory / "bin"
            binary_directory.mkdir()
            composer = binary_directory / "composer"
            counter = directory / "composer-require-attempts"
            command_log = directory / "composer-commands"
            composer.write_text(
                """#!/bin/sh
set -eu
printf '%s\n' "$*" >> "$FAKE_COMPOSER_COMMAND_LOG"
graph=''
case " $* " in
    *"/embedded "*) graph='embedded' ;;
    *"/service "*) graph='service' ;;
esac
case " $* " in
    *" require "*)
        if [ -z "$graph" ]; then
            printf 'Composer graph could not be identified\n' >&2
            exit 3
        fi
        counter="${FAKE_COMPOSER_COUNTER}.${graph}"
        count=0
        if [ -f "$counter" ]; then
            count="$(sed -n '1p' "$counter")"
        fi
        count=$((count + 1))
        printf '%s\n' "$count" > "$counter"
        cached_attempts="$FAKE_COMPOSER_EMBEDDED_CACHED_ATTEMPTS"
        if [ "$graph" = 'service' ]; then
            cached_attempts="$FAKE_COMPOSER_SERVICE_CACHED_ATTEMPTS"
        fi
        if [ "$count" -le "$cached_attempts" ]; then
            printf 'Packagist Composer metadata is still cached\n' >&2
            exit 2
        fi
        ;;
    *" show "*)
        count="$(sed -n '1p' "${FAKE_COMPOSER_COUNTER}.embedded")"
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
                        "require": {},
                        "require-dev": {"durable-workflow/workflow": WORKFLOW},
                    }
                ),
                encoding="utf-8",
            )
            service_manifest = directory / "service-composer.json"
            service_manifest.write_text(
                json.dumps({"require": {"durable-workflow/sdk": SDK}}),
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
                    "FAKE_COMPOSER_COMMAND_LOG": str(command_log),
                    "FAKE_COMPOSER_EMBEDDED_CACHED_ATTEMPTS": str(
                        embedded_cached_attempts
                    ),
                    "FAKE_COMPOSER_SERVICE_CACHED_ATTEMPTS": str(
                        service_cached_attempts
                    ),
                    "FAKE_COMPOSER_METADATA_CACHED_ATTEMPTS": str(
                        metadata_cached_attempts
                    ),
                    "FAKE_COMPOSER_DESCRIPTION": DESCRIPTION,
                    "EXACT_CURRENT_COMPOSER_MANIFEST": str(manifest),
                    "EXACT_CURRENT_COMPOSER_SERVICE_MANIFEST": str(service_manifest),
                    "EXACT_CURRENT_COMPOSER_EVIDENCE": str(evidence),
                    "EXACT_CURRENT_COMPOSER_ATTEMPTS": str(attempts),
                    "EXACT_CURRENT_COMPOSER_RETRY_SLEEP": "0",
                },
                capture_output=True,
                text=True,
                check=False,
            )
            observed = json.loads(evidence.read_text(encoding="utf-8"))
            counts = {
                graph: int(
                    counter.with_name(f"{counter.name}.{graph}")
                    .read_text(encoding="utf-8")
                    .strip()
                )
                for graph in ("embedded", "service")
                if counter.with_name(f"{counter.name}.{graph}").exists()
            }
            return process, observed, counts, command_log.read_text(encoding="utf-8")

    def test_cached_embedded_graph_is_rechecked_until_both_graphs_install(self) -> None:
        process, evidence, counts, commands = self.run_probe(
            embedded_cached_attempts=1,
            service_cached_attempts=0,
            metadata_cached_attempts=0,
            attempts=2,
        )

        self.assertEqual(0, process.returncode, process.stderr)
        self.assertEqual({"embedded": 2, "service": 1}, counts)
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
        self.assertEqual(
            {
                "embedded": {
                    "minimum_stability": "stable",
                    "root_require": {
                        "durable-workflow/waterline": WATERLINE,
                        "durable-workflow/workflow": WORKFLOW,
                    },
                },
                "service": {
                    "minimum_stability": "stable",
                    "root_require": {
                        "durable-workflow/waterline": WATERLINE,
                        "durable-workflow/sdk": SDK,
                    },
                },
            },
            evidence["composer_graphs"],
        )
        self.assertNotIn("minimum-stability", commands)
        self.assertIn("Waiting for Composer metadata convergence (1/2)", process.stderr)

    def test_cached_service_graph_is_rechecked_independently(self) -> None:
        process, evidence, counts, commands = self.run_probe(
            embedded_cached_attempts=0,
            service_cached_attempts=1,
            metadata_cached_attempts=0,
            attempts=2,
        )

        self.assertEqual(0, process.returncode, process.stderr)
        self.assertEqual({"embedded": 2, "service": 2}, counts)
        require_commands = [
            command
            for command in commands.splitlines()
            if " require " in f" {command} "
        ]
        self.assertTrue(
            any(
                f"durable-workflow/waterline:{WATERLINE}" in command
                and f"durable-workflow/workflow:{WORKFLOW}" in command
                and "durable-workflow/sdk" not in command
                for command in require_commands
            )
        )
        self.assertTrue(
            any(
                f"durable-workflow/waterline:{WATERLINE}" in command
                and f"durable-workflow/sdk:{SDK}" in command
                and "durable-workflow/workflow" not in command
                for command in require_commands
            )
        )
        self.assertEqual("pass", evidence["outcome"])

    def test_stale_description_is_rechecked_until_registry_metadata_matches(
        self,
    ) -> None:
        process, evidence, counts, _ = self.run_probe(
            embedded_cached_attempts=0,
            service_cached_attempts=0,
            metadata_cached_attempts=1,
            attempts=2,
        )

        self.assertEqual(0, process.returncode, process.stderr)
        self.assertEqual({"embedded": 2, "service": 2}, counts)
        self.assertEqual(DESCRIPTION, evidence["package_metadata"]["description"])
        self.assertIn(
            f"Composer reported the published Waterline description: {DESCRIPTION}",
            process.stdout,
        )

    def test_non_installable_tuple_fails_after_the_bound_with_versions(self) -> None:
        process, evidence, counts, _ = self.run_probe(
            embedded_cached_attempts=0,
            service_cached_attempts=2,
            metadata_cached_attempts=0,
            attempts=2,
        )

        self.assertNotEqual(0, process.returncode)
        self.assertEqual({"embedded": 2, "service": 2}, counts)
        self.assertEqual("incomplete", evidence["outcome"])
        self.assertIn("after 2 attempt(s)", evidence["reason"])
        self.assertIn("service graph", evidence["reason"])
        for version in (WATERLINE, SDK):
            self.assertIn(version, evidence["reason"])
        self.assertIn(
            "::error title=Composer metadata did not converge::", process.stderr
        )


if __name__ == "__main__":
    unittest.main()
