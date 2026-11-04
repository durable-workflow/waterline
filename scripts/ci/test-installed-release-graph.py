#!/usr/bin/env python3
"""Offline countercontrols for comparing the complete production package graph."""

import copy
import importlib.util
import json
from pathlib import Path
import unittest


ROOT = Path(__file__).resolve().parents[2]
spec = importlib.util.spec_from_file_location(
    "waterline_release_identity", Path(__file__).with_name("waterline_release_identity.py")
)
identity = importlib.util.module_from_spec(spec)
spec.loader.exec_module(identity)


class InstalledReleaseGraphTest(unittest.TestCase):
    def setUp(self):
        self.lock = json.loads((ROOT / "standalone/composer.lock").read_text())
        # Synthetic metadata tests the comparator, not an installed artifact.
        self.installed = {"dev": False, "dev-package-names": [],
                          "packages": copy.deepcopy(self.lock["packages"])}

    def test_complete_graph_matches_every_production_record(self):
        self.installed["packages"].reverse()
        result = identity.validate_installed_graph(self.lock, self.installed)
        self.assertEqual(len(self.lock["packages"]), result["package_count"])
        self.assertEqual(set(p["name"] for p in self.lock["packages"]), set(result["packages"]))
        self.assertNotIn(identity.WORKFLOW_PACKAGE, result["packages"])

    def test_stale_symfony_version_or_source_is_refused_even_with_the_same_sdk(self):
        for field, value in (("version", "v7.4.21"), ("reference", "0" * 40)):
            installed = copy.deepcopy(self.installed)
            package = next(p for p in installed["packages"] if p["name"] == "symfony/http-foundation")
            if field == "reference":
                package["source"][field] = value
            else:
                package[field] = value
            with self.subTest(field=field), self.assertRaises(identity.IdentityError):
                identity.validate_installed_graph(self.lock, installed)

    def test_missing_extra_duplicate_and_malformed_records_are_refused(self):
        inventories = [self.installed["packages"][:-1], [], None,
                       [*self.installed["packages"], self.installed["packages"][0]],
                       [*self.installed["packages"], {"name": "extra/package", "version": "1.0.0",
                                                    "source": {"reference": "a" * 40}}]]
        malformed = copy.deepcopy(self.installed["packages"])
        malformed[0]["source"]["reference"] = "main"
        inventories.append(malformed)
        for packages in inventories:
            with self.subTest(packages=type(packages).__name__), self.assertRaises(identity.IdentityError):
                identity.validate_installed_graph(self.lock, {**self.installed, "packages": packages})

    def test_development_flags_and_core_packages_are_refused(self):
        changes = [{"dev": True}, {"dev": None}, {"dev-package-names": ["phpunit/phpunit"]},
                   {"dev-package-names": None}]
        for change in changes:
            with self.subTest(change=change), self.assertRaises(identity.IdentityError):
                identity.validate_installed_graph(self.lock, {**self.installed, **change})
        for change in ({"dev_requirement": True}, {"name": identity.WORKFLOW_PACKAGE}):
            installed = copy.deepcopy(self.installed)
            installed["packages"][0].update(change)
            with self.subTest(change=change), self.assertRaises(identity.IdentityError):
                identity.validate_installed_graph(self.lock, installed)


if __name__ == "__main__":
    unittest.main()
