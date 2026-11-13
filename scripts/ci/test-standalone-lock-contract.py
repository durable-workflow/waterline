#!/usr/bin/env python3
"""Focused coverage for the standalone Composer lock release contract."""

from __future__ import annotations

import importlib.util
import sys
import unittest
from pathlib import Path


SCRIPT = Path(__file__).with_name("standalone_lock_contract.py")
PACKAGE = "durable-workflow/sdk"
VERSION = "2.0.0-rc.7"
REFERENCE = "1b4e0eb7a44fb190d5654868d816be4f2fd82d93"


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
    def test_requires_patched_framework_floor_even_with_patched_lock(self) -> None:
        for constraint in (None, "^12.0", "^12.71.0", "^12.72.0 || ^11.0", "^13.41.0"):
            with self.subTest(constraint=constraint), self.assertRaises(contract.ContractError):
                contract.validate_framework_host(
                    {"require": {"laravel/framework": constraint}},
                    {"packages": [{"name": "laravel/framework", "version": "v12.72.0"}]},
                )

    def test_rejects_affected_or_unstable_framework_lock(self) -> None:
        for version in (None, "v12.69.3", "v12.71.0", "v12.72.0-beta.1", "v13.41.0"):
            with self.subTest(version=version), self.assertRaises(contract.ContractError):
                contract.validate_framework_host(
                    {"require": {"laravel/framework": "^12.72.0"}},
                    {"packages": [{"name": "laravel/framework", "version": version}]},
                )

    def test_accepts_patched_framework_and_later_stable_patch(self) -> None:
        for constraint, version in (("^12.72.0", "v12.72.0"), ("^12.72.0", "12.73.0")):
            with self.subTest(version=version):
                self.assertEqual(version, contract.validate_framework_host(
                    {"require": {"laravel/framework": constraint}},
                    {"packages": [{"name": "laravel/framework", "version": version}]},
                ))

    def test_framework_lock_must_meet_declared_floor(self) -> None:
        with self.assertRaises(contract.ContractError):
            contract.validate_framework_host(
                {"require": {"laravel/framework": "^12.73.0"}},
                {"packages": [{"name": "laravel/framework", "version": "v12.72.0"}]},
            )

    def test_accepts_lock_matching_public_package_identity(self) -> None:
        self.assertEqual(
            VERSION,
            contract.validate_identity(manifest(), lock(), published()),
        )

    def test_rejects_worker_source_reference_for_public_version(self) -> None:
        stale = "d19e2a38c42dbb74cc3b15dcf25743da93adc0b1"

        with self.assertRaisesRegex(
            contract.ContractError,
            r"source\.reference.+public 2\.0\.0-rc\.7 package",
        ):
            contract.validate_identity(manifest(), lock(stale), published())

    def test_rejects_dist_identity_inconsistent_with_public_package(self) -> None:
        stale = "d19e2a38c42dbb74cc3b15dcf25743da93adc0b1"
        inconsistent = lock()
        inconsistent["packages"][0]["dist"] = dist(stale)

        with self.assertRaisesRegex(
            contract.ContractError,
            r"dist\.url.+public 2\.0\.0-rc\.7 package",
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
