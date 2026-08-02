#!/usr/bin/env python3
"""Behavior coverage for the deployed Waterline release-completion audit."""

from __future__ import annotations

import copy
import json
import os
import subprocess
import tempfile
import unittest
from pathlib import Path


ROOT = Path(__file__).parents[2]
SCRIPT = ROOT / "scripts" / "ci" / "check-docs-release-audit.sh"
VERSIONS = {
    "cli": "2.0.0-rc.12",
    "sdk-php": "2.0.0-rc.7",
    "sdk-python": "2.0.0-rc.8",
    "sdk-rust": "2.0.0-rc.7",
    "server": "2.0.0-rc.13",
    "waterline": "2.0.0-rc.10",
    "workflow": "2.0.0-rc.12",
}
SCENARIOS = [
    "php_user_local_server_completion",
    "python_user_local_server_completion",
    "rust_user_local_server_completion",
    "operator_local_server_observation",
    "laravel_user_embedded_completion",
]


def audit() -> dict:
    return {
        "schema": "durable-workflow.docs.page-release-audit",
        "artifact_versions": VERSIONS,
        "artifact_compatibility_evidence": {
            "role": "qualified_aggregate_recommendation",
            "outcome": "pass",
            "qualified_artifact_versions": VERSIONS,
        },
        "quickstart_qualification": {
            "role": "five_scenario_exact_current",
            "outcome": "pass",
            "artifact_versions": VERSIONS,
            "required_scenarios": SCENARIOS,
            "evidence": {
                "outcome": "pass",
                "runner_blocked": False,
                "artifact_tuple": VERSIONS,
            },
        },
    }


class DocsReleaseAuditTest(unittest.TestCase):
    def run_audit(self, value: dict) -> tuple[subprocess.CompletedProcess[str], dict]:
        with tempfile.TemporaryDirectory() as temporary:
            directory = Path(temporary)
            audit_path = directory / "audit.json"
            composer_path = directory / "composer.json"
            evidence_path = directory / "evidence.json"
            audit_path.write_text(json.dumps(value), encoding="utf-8")
            composer_path.write_text(
                json.dumps(
                    {
                        "name": "durable-workflow/waterline",
                        "require": {"durable-workflow/sdk": VERSIONS["sdk-php"]},
                        "require-dev": {
                            "durable-workflow/workflow": VERSIONS["workflow"]
                        },
                    }
                ),
                encoding="utf-8",
            )
            process = subprocess.run(
                ["sh", str(SCRIPT)],
                cwd=ROOT,
                env={
                    **os.environ,
                    "DOCS_RELEASE_AUDIT_ARTIFACT": "waterline",
                    "DOCS_RELEASE_AUDIT_VERSION": VERSIONS["waterline"],
                    "DOCS_RELEASE_AUDIT_URL": audit_path.as_uri(),
                    "DOCS_RELEASE_AUDIT_ATTEMPTS": "1",
                    "DOCS_RELEASE_AUDIT_RETRY_SLEEP": "0",
                    "DOCS_RELEASE_AUDIT_COMPOSER_MANIFEST": str(composer_path),
                    "DOCS_RELEASE_AUDIT_EVIDENCE": str(evidence_path),
                },
                text=True,
                capture_output=True,
                check=False,
            )
            evidence = json.loads(evidence_path.read_text(encoding="utf-8"))
            return process, evidence

    def test_exact_tuple_and_five_scenarios_pass(self) -> None:
        process, evidence = self.run_audit(audit())

        self.assertEqual(0, process.returncode, process.stderr)
        self.assertEqual("pass", evidence["outcome"])

    def test_source_only_or_stale_quickstart_evidence_cannot_pass(self) -> None:
        stale = copy.deepcopy(audit())
        stale["quickstart_qualification"]["outcome"] = "incomplete"
        stale["quickstart_qualification"]["evidence"] = None

        process, evidence = self.run_audit(stale)

        self.assertNotEqual(0, process.returncode)
        self.assertEqual("stale", evidence["outcome"])


if __name__ == "__main__":
    unittest.main()
