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
AGGREGATE_VERSIONS = {
    **VERSIONS,
    "sdk-php": "2.0.0-rc.6",
    "waterline": "2.0.0-rc.9",
}
COMPOSER_VERSIONS = {
    "waterline": VERSIONS["waterline"],
    "sdk-php": VERSIONS["sdk-php"],
    "workflow": VERSIONS["workflow"],
}


def audit() -> dict:
    return {
        "schema": "durable-workflow.docs.page-release-audit",
        "artifact_versions": VERSIONS,
        "artifact_compatibility_evidence": {
            "role": "qualified_aggregate_recommendation",
            "outcome": "pass",
            "qualified_artifact_versions": AGGREGATE_VERSIONS,
        },
        "component_release_qualifications": {
            "role": "retained_component_release_qualifications",
            "schema": "durable-workflow.docs.public-component-release-qualifications",
            "schema_version": 1,
            "outcome": "pass",
            "qualifications": [
                {
                    "id": f"waterline-{VERSIONS['waterline']}-composer",
                    "component": {
                        "artifact": "waterline",
                        "version": VERSIONS["waterline"],
                    },
                    "qualification": {
                        "schema": "durable-workflow.exact-current-composer-qualification/v1",
                        "outcome": "pass",
                        "packages": {
                            "waterline": VERSIONS["waterline"],
                            "sdk-php": VERSIONS["sdk-php"],
                            "workflow": VERSIONS["workflow"],
                        },
                    },
                    "source": {
                        "repository_url": "https://github.com/durable-workflow/waterline",
                        "release_tag": VERSIONS["waterline"],
                        "release_commit": "a" * 40,
                        "workflow_run": {
                            "name": "Release Docs Audit",
                            "run_id": 123456,
                            "run_attempt": 2,
                            "run_url": "https://github.com/durable-workflow/waterline/actions/runs/123456",
                        },
                    },
                }
            ],
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

    def test_retained_exact_composer_tuple_passes_with_older_aggregate(self) -> None:
        process, evidence = self.run_audit(audit())

        self.assertEqual(0, process.returncode, process.stderr)
        self.assertEqual("pass", evidence["outcome"])
        self.assertEqual(
            COMPOSER_VERSIONS,
            evidence["component_release_qualification"]["qualification"]["packages"],
        )

    def test_missing_component_release_qualification_cannot_pass(self) -> None:
        stale = copy.deepcopy(audit())
        stale["component_release_qualifications"]["qualifications"] = []

        process, evidence = self.run_audit(stale)

        self.assertNotEqual(0, process.returncode)
        self.assertEqual("stale", evidence["outcome"])


if __name__ == "__main__":
    unittest.main()
