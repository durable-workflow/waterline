#!/usr/bin/env python3
"""Regression coverage for coordinated PHP SDK and Waterline releases."""

from __future__ import annotations

import copy
import io
import importlib.util
import os
import shlex
import subprocess
import sys
import tarfile
import tempfile
import unittest
from pathlib import Path

import yaml

from release_recovery_consumer_conformance import legacy_beta_one_plan


SCRIPT = Path(__file__).with_name("php_waterline_release_train.py")
WORKFLOW = (
    Path(__file__).parents[2] / ".github" / "workflows" / "release-plan-recovery.yml"
)
SPEC = importlib.util.spec_from_file_location("php_waterline_release_train", SCRIPT)
if SPEC is None or SPEC.loader is None:
    raise RuntimeError(f"cannot load {SCRIPT}")
train = importlib.util.module_from_spec(SPEC)
sys.modules[SPEC.name] = train
SPEC.loader.exec_module(train)


def plan() -> dict:
    return {
        "components": {
            "workflow": {"version": "2.0.0-rc.12", "commit": "a" * 40},
            "waterline": {"version": "2.0.0-rc.9", "commit": "b" * 40},
            "sdk-php": {"version": "2.0.0-rc.6", "commit": "c" * 40},
        },
    }


def manifest(candidate: dict) -> tuple[dict, bytes]:
    versions = train.versions(candidate)
    value = {
        "name": "durable-workflow/waterline",
        "require": {train.SDK_PACKAGE: versions["sdk-php"]},
        "require-dev": {train.WORKFLOW_PACKAGE: versions["workflow"]},
        "extra": {"durable-workflow": {"product-train": versions["waterline"]}},
    }
    return value, b"exact planned Waterline composer source"


def workflow() -> dict:
    document = yaml.load(WORKFLOW.read_text(encoding="utf-8"), Loader=yaml.BaseLoader)
    if not isinstance(document, dict):
        raise RuntimeError(f"cannot load workflow {WORKFLOW}")
    return document


def workflow_step(job: str, command: str) -> dict:
    steps = workflow()["jobs"][job]["steps"]
    matches = [step for step in steps if command in step.get("run", "")]
    if len(matches) != 1:
        raise RuntimeError(f"expected one {job} step invoking {command}")
    return matches[0]


def run_step(step: dict, directory: Path) -> subprocess.CompletedProcess[str]:
    environment = os.environ.copy()
    environment.update(step.get("env", {}))
    return subprocess.run(
        ["bash", "-euo", "pipefail", "-c", step["run"]],
        cwd=directory,
        env=environment,
        check=False,
        capture_output=True,
        text=True,
    )


class PhpWaterlineReleaseTrainTest(unittest.TestCase):
    def test_sdk_only_advance_stays_incomplete(self) -> None:
        baseline = plan()
        candidate = copy.deepcopy(baseline)
        candidate["components"]["sdk-php"]["version"] = "2.0.0-rc.7"
        composer, raw = manifest(candidate)

        with self.assertRaisesRegex(train.TrainError, "sequential Waterline"):
            train.validate(candidate, baseline, composer, raw)

    def test_paired_sequential_successor_is_publishable(self) -> None:
        baseline = plan()
        candidate = copy.deepcopy(baseline)
        candidate["components"]["sdk-php"]["version"] = "2.0.0-rc.7"
        candidate["components"]["waterline"]["version"] = "2.0.0-rc.10"
        composer, raw = manifest(candidate)

        evidence = train.validate(candidate, baseline, composer, raw)

        self.assertEqual("verified", evidence["outcome"])
        self.assertEqual("paired-sdk-waterline-successor", evidence["transition"])

    def test_cross_prerelease_compatibility_constraint_is_rejected(self) -> None:
        candidate = plan()
        composer, raw = manifest(candidate)
        composer["require"][train.SDK_PACKAGE] = "2.0.0-rc.7"

        with self.assertRaisesRegex(train.TrainError, "Composer-satisfiable"):
            train.validate(candidate, candidate, composer, raw)

    def test_historical_exact_pair_remains_recoverable(self) -> None:
        baseline = plan()
        historical = copy.deepcopy(baseline)
        historical["components"]["sdk-php"]["version"] = "2.0.0-rc.5"
        historical["components"]["waterline"]["version"] = "2.0.0-rc.8"
        composer, raw = manifest(historical)

        evidence = train.validate(historical, baseline, composer, raw)

        self.assertEqual("historical-compatible-pair", evidence["transition"])

    def test_completed_immutable_beta_one_skips_prerelease_train_validator(
        self,
    ) -> None:
        historical = legacy_beta_one_plan()

        self.assertEqual("beta-1-e743e3760000", historical["plan"])
        self.assertEqual("0.1.16", historical["components"]["sdk-php"]["version"])
        with self.assertRaisesRegex(
            train.TrainError,
            "exact prerelease sdk-php",
        ):
            train.versions(historical)

        qualification = workflow_step(
            "discover", "scripts/ci/php_waterline_release_train.py"
        )
        self.assertEqual(
            "steps.recovery.outputs.action == 'publish'", qualification["if"]
        )

    def test_publication_requires_and_retains_extracted_train_qualification(
        self,
    ) -> None:
        document = workflow()
        discover_steps = document["jobs"]["discover"]["steps"]
        publish_steps = document["jobs"]["publish"]["steps"]

        qualification = next(
            step
            for step in discover_steps
            if "scripts/ci/php_waterline_release_train.py" in step.get("run", "")
        )
        qualification_command = shlex.split(qualification["run"])
        evidence_name = qualification_command[
            qualification_command.index("--evidence") + 1
        ]
        extraction = next(
            step for step in publish_steps if "tar -xf" in step.get("run", "")
        )
        extraction_command = shlex.split(extraction["run"])
        extraction_directory = extraction_command[
            extraction_command.index("-C") + 1
        ]
        requirement = workflow_step("publish", "$QUALIFICATION_EVIDENCE")
        required_evidence = requirement["env"]["QUALIFICATION_EVIDENCE"]
        publication_uploads = [
            step
            for step in publish_steps
            if step.get("uses", "").startswith("actions/upload-artifact@")
        ]

        self.assertEqual(1, len(publication_uploads))
        publication = publication_uploads[0]
        retained_paths = publication["with"]["path"].splitlines()
        self.assertEqual(
            str(Path(extraction_directory) / evidence_name), required_evidence
        )
        self.assertIn(required_evidence, retained_paths)
        self.assertEqual("error", publication["with"]["if-no-files-found"])

        with tempfile.TemporaryDirectory() as temporary:
            directory = Path(temporary)
            archive_directory = directory / "isolated-release-recovery"
            archive_directory.mkdir()
            handoff = archive_directory / "release-recovery-handoff.tar"
            with tarfile.open(handoff, "w") as archive:
                release_plan = tarfile.TarInfo("release-plan.json")
                release_plan.size = 3
                archive.addfile(release_plan, io.BytesIO(b"{}\n"))

            self.assertEqual(0, run_step(extraction, directory).returncode)
            missing = run_step(requirement, directory)
            self.assertNotEqual(0, missing.returncode)

            with tarfile.open(handoff, "w") as archive:
                qualification_evidence = tarfile.TarInfo(evidence_name)
                qualification_evidence.size = 3
                archive.addfile(qualification_evidence, io.BytesIO(b"{}\n"))
            (directory / extraction_directory / "release-plan.json").unlink()
            (directory / extraction_directory).rmdir()
            self.assertEqual(0, run_step(extraction, directory).returncode)
            self.assertEqual(0, run_step(requirement, directory).returncode)
            self.assertTrue((directory / required_evidence).is_file())


if __name__ == "__main__":
    unittest.main()
