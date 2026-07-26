#!/usr/bin/env python3
"""Focused coverage for the standalone Composer lock release contract."""

from __future__ import annotations

import importlib.util
import sys
import unittest
from pathlib import Path


SCRIPT = Path(__file__).with_name("standalone_lock_contract.py")
PACKAGE = "durable-workflow/sdk"
VERSION = "2.0.0-beta.17"
REFERENCE = "175ab6396df4fd3809dccceebcf957c03e14ee86"


def load_contract():
    specification = importlib.util.spec_from_file_location(
        "standalone_lock_contract",
        SCRIPT,
    )
    if specification is None or specification.loader is None:
        raise RuntimeError(f"unable to load {SCRIPT}")
    module = importlib.util.module_from_spec(specification)
    sys.modules[specification.name] = module
    specification.loader.exec_module(module)
    return module


contract = load_contract()


def source(reference: str) -> dict[str, str]:
    return {
        "type": "git",
        "url": "https://github.com/durable-workflow/sdk-php.git",
        "reference": reference,
    }


def dist(reference: str) -> dict[str, str]:
    return {
        "type": "zip",
        "url": (
            "https://api.github.com/repos/durable-workflow/"
            f"sdk-php/zipball/{reference}"
        ),
        "reference": reference,
    }


def manifest(version: str = VERSION) -> dict[str, object]:
    return {"require": {PACKAGE: version}}


def lock(reference: str = REFERENCE) -> dict[str, object]:
    return {
        "packages": [
            {
                "name": PACKAGE,
                "version": VERSION,
                "source": source(reference),
                "dist": dist(reference),
            }
        ]
    }


def published(reference: str = REFERENCE) -> dict[str, object]:
    return {
        "name": PACKAGE,
        "versions": [VERSION],
        "source": source(reference),
        "dist": dist(reference),
    }


class StandaloneLockContractTest(unittest.TestCase):
    def test_accepts_lock_matching_public_package_identity(self) -> None:
        self.assertEqual(
            VERSION,
            contract.validate_identity(manifest(), lock(), published()),
        )

    def test_rejects_worker_source_reference_for_public_version(self) -> None:
        stale = "d19e2a38c42dbb74cc3b15dcf25743da93adc0b1"

        with self.assertRaisesRegex(
            contract.ContractError,
            r"source\.reference.+public 2\.0\.0-beta\.17 package",
        ):
            contract.validate_identity(manifest(), lock(stale), published())

    def test_rejects_dist_identity_inconsistent_with_public_package(self) -> None:
        stale = "d19e2a38c42dbb74cc3b15dcf25743da93adc0b1"
        inconsistent = lock()
        inconsistent["packages"][0]["dist"] = dist(stale)

        with self.assertRaisesRegex(
            contract.ContractError,
            r"dist\.url.+public 2\.0\.0-beta\.17 package",
        ):
            contract.validate_identity(manifest(), inconsistent, published())

    def test_rejects_non_exact_locked_version(self) -> None:
        mismatched = manifest("^2.0")

        with self.assertRaisesRegex(
            contract.ContractError,
            "expected exact pin",
        ):
            contract.validate_identity(mismatched, lock(), published())


if __name__ == "__main__":
    unittest.main()
