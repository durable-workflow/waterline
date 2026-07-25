#!/usr/bin/env python3
"""Behavior tests for deterministic database qualification sharding and timing."""

from __future__ import annotations

import argparse
import importlib.util
import sys
import tempfile
import unittest
from pathlib import Path


ROOT = Path(__file__).parents[2]


def load_module(name: str, path: Path):
    specification = importlib.util.spec_from_file_location(name, path)
    if specification is None or specification.loader is None:
        raise RuntimeError(f"unable to load {path}")
    module = importlib.util.module_from_spec(specification)
    sys.modules[name] = module
    specification.loader.exec_module(module)
    return module


shards = load_module(
    "qualification_shards",
    ROOT / "scripts" / "ci" / "qualification_shards.py",
)
timing = load_module(
    "qualification_timing",
    ROOT / "scripts" / "ci" / "qualification_timing.py",
)


class QualificationShardTest(unittest.TestCase):
    def write_inventory(self, directory: str) -> Path:
        path = Path(directory) / "tests.xml"
        path.write_text(
            """<?xml version="1.0"?>
<testSuite xmlns="https://xml.phpunit.de/testSuite">
 <tests>
  <testClass name="Waterline\\Tests\\Feature\\AlphaTest" file="AlphaTest.php">
   <testMethod id="AlphaTest::testOne" name="testOne"/>
   <testMethod id="AlphaTest::testData#first" name="testData"/>
   <testMethod id="AlphaTest::testData#second" name="testData"/>
  </testClass>
  <testClass name="Waterline\\Tests\\Feature\\BetaTest" file="BetaTest.php">
   <testMethod id="BetaTest::testOne" name="testOne"/>
   <testMethod id="BetaTest::testTwo" name="testTwo"/>
  </testClass>
  <testClass name="Waterline\\Tests\\Unit\\GammaTest" file="GammaTest.php">
   <testMethod id="GammaTest::testOne" name="testOne"/>
  </testClass>
 </tests>
</testSuite>
""",
            encoding="utf-8",
        )
        return path

    def test_phpunit_inventory_counts_data_sets(self) -> None:
        with tempfile.TemporaryDirectory() as directory:
            inventory = shards.read_inventory(self.write_inventory(directory))

        self.assertEqual(
            [
                ("Waterline\\Tests\\Feature\\AlphaTest", 3),
                ("Waterline\\Tests\\Feature\\BetaTest", 2),
                ("Waterline\\Tests\\Unit\\GammaTest", 1),
            ],
            [(item.name, item.test_cases) for item in inventory],
        )

    def test_every_class_is_assigned_once_with_balanced_case_weight(self) -> None:
        inventory = tuple(
            shards.TestClass(f"Waterline\\Tests\\Feature\\Class{index}Test", weight)
            for index, weight in enumerate((9, 8, 7, 6, 5, 4, 3, 2, 1))
        )

        first = shards.plan_shards(inventory, 3)
        second = shards.plan_shards(tuple(reversed(inventory)), 3)
        assigned = [item.name for shard in first for item in shard.classes]

        self.assertEqual(first, second)
        self.assertCountEqual([item.name for item in inventory], assigned)
        self.assertEqual(len(assigned), len(set(assigned)))
        self.assertLessEqual(
            max(shard.estimated_weight for shard in first)
            - min(shard.estimated_weight for shard in first),
            2,
        )

    def test_real_composer_host_receives_an_expensive_weight_floor(self) -> None:
        name = next(iter(shards.CLASS_WEIGHT_FLOORS))
        test_class = shards.TestClass(name, 1)

        self.assertEqual(120, test_class.estimated_weight)

    def test_sharded_filter_selects_only_complete_test_classes(self) -> None:
        shard = shards.Shard(
            0,
            (
                shards.TestClass("Waterline\\Tests\\Feature\\AlphaTest", 3),
                shards.TestClass("Waterline\\Tests\\Unit\\GammaTest", 1),
            ),
        )

        self.assertEqual(
            (
                "~^(?:Waterline\\\\Tests\\\\Feature\\\\AlphaTest|"
                "Waterline\\\\Tests\\\\Unit\\\\GammaTest)::~"
            ),
            shards.test_filter(shard, 4),
        )
        self.assertEqual("", shards.test_filter(shard, 1))

    def test_invalid_shard_count_fails_closed(self) -> None:
        inventory = (shards.TestClass("Waterline\\Tests\\Unit\\OnlyTest", 1),)

        for shard_count in (0, 2):
            with self.subTest(shard_count=shard_count):
                with self.assertRaises(ValueError):
                    shards.plan_shards(inventory, shard_count)


class QualificationTimingTest(unittest.TestCase):
    def write_junit(self, directory: str, tests: int = 3) -> Path:
        path = Path(directory) / "junit.xml"
        path.write_text(
            f"""<?xml version="1.0"?>
<testsuites>
 <testsuite name="Unit" tests="{tests}" assertions="7"
            errors="0" failures="0" skipped="1" time="1.25"/>
</testsuites>
""",
            encoding="utf-8",
        )
        return path

    def arguments(self, **overrides):
        values = {
            "database": "mysql",
            "shard_label": "1/4",
            "expected_tests": 3,
            "selected_classes": 2,
            "started_at": 100,
            "test_started_at": 110,
            "completed_at": 130,
            "target_seconds": 480,
            "status": 0,
            "junit": "",
            "github_step_summary": "",
        }
        values.update(overrides)
        return argparse.Namespace(**values)

    def test_junit_summary_exposes_counts_and_both_wall_clocks(self) -> None:
        with tempfile.TemporaryDirectory() as directory:
            stats = timing.read_junit(self.write_junit(directory))

        lines = timing.summary_lines(self.arguments(), stats)

        self.assertEqual(3, stats.tests)
        self.assertIn("- Test command wall time: 20s", lines)
        self.assertIn("- Measured runner-steps time: 30s", lines)
        self.assertIn("- 480s qualification target: `within target`", lines)
        self.assertIn(
            "- JUnit: 3 tests, 7 assertions, 0 failures, 0 errors, 1 skipped",
            lines,
        )

    def test_successful_command_with_incomplete_junit_fails_closed(self) -> None:
        with tempfile.TemporaryDirectory() as directory:
            stats = timing.read_junit(self.write_junit(directory, tests=2))

        self.assertEqual(
            "qualification coverage mismatch: expected 3 tests, observed 2",
            timing.coverage_failure(0, 3, stats),
        )
        self.assertIsNone(timing.coverage_failure(1, 3, stats))


if __name__ == "__main__":
    unittest.main()
