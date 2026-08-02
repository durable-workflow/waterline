#!/usr/bin/env python3
"""Regression coverage for coordinated PHP SDK and Waterline releases."""

from __future__ import annotations

import copy
import importlib.util
import sys
import unittest
from pathlib import Path

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


def workflow_step(name: str) -> str:
    marker = f"      - name: {name}\n"
    source = WORKFLOW.read_text(encoding="utf-8")
    section = source.split(marker, 1)[1]
    return section.split("      - name:", 1)[0]


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
            "Require exact Composer pins for the sequential train"
        )
        self.assertIn(
            "        if: steps.recovery.outputs.action == 'publish'\n",
            qualification,
        )

    def test_publication_evidence_retains_the_validated_train_qualification(
        self,
    ) -> None:
        publication = workflow_step("Retain publication evidence")

        self.assertIn(
            "            recovery-input/php-waterline-plan-qualification.json\n",
            publication,
        )
        self.assertNotIn(
            "            php-waterline-plan-qualification.json\n",
            publication,
        )


if __name__ == "__main__":
    unittest.main()
