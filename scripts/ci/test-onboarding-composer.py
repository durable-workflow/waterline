#!/usr/bin/env python3
import copy
import unittest

import onboarding_composer as contract


def authority(sdk="2.4.0"):
    return {"schema": contract.SCHEMA, "versions": {"waterline": "2.13.0", "workflow": "2.5.12", "sdk-php": sdk}}


def locks(selected="2.13.1", sdk="2.4.0"):
    result = {}
    for kind in ("approved", "onboarding"):
        for graph, peer in contract.PEERS.items():
            versions = {contract.WATERLINE: "2.13.0" if kind == "approved" else selected,
                        peer: "2.5.12" if graph == "embedded" else (sdk if kind == "approved" else "2.4.0")}
            result[f"{kind}-{graph}"] = {"packages": [
                {"name": name, "version": version, "source": {"reference": "a" * 40}, "dist": {"type": "zip"}}
                for name, version in versions.items()
            ]}
    return result


class OnboardingComposerTest(unittest.TestCase):
    def test_exact_approved_and_ordinary_manifests_remain_distinct(self):
        manifests = contract.manifests(authority())
        self.assertEqual(manifests["approved-service"]["require"], {contract.WATERLINE: "2.13.0", contract.SDK: "2.4.0"})
        self.assertEqual(manifests["onboarding-service"]["require"], {contract.WATERLINE: "^2.0", contract.SDK: "^2.0"})
        self.assertTrue(all("repositories" not in manifest for manifest in manifests.values()))

    def test_registry_ahead_is_observation_and_never_qualified(self):
        evidence = contract.validate(authority(), "2.13.2", locks())
        self.assertEqual(evidence["ordinary_selection"], "2.13.1")
        self.assertEqual(evidence["approved_cohort"][contract.WATERLINE], "2.13.0")
        self.assertEqual(evidence["ordinary_selection_qualification"], "ahead-of-approved-not-qualified")
        self.assertFalse(evidence["full_release_acceptance"])

    def test_published_check_rejects_an_earlier_registry_selection(self):
        cohort, selection = authority(), locks()
        with self.assertRaisesRegex(ValueError, "selected 2.13.1, expected 2.13.2"):
            contract.validate(cohort, "2.13.2", selection, "2.13.2")

    def test_published_check_requires_exact_candidate_identity(self):
        result = contract.validate(authority(), "2.13.2", locks("2.13.2"), "2.13.2")
        self.assertEqual(result["strict_expected_waterline"], "2.13.2")
        cohort, selection = authority(), locks()
        with self.assertRaisesRegex(ValueError, "immutable candidate"):
            contract.validate(cohort, "2.13.2", selection, "2.13.1")

    def test_current_cohort_retains_strict_ordinary_selection(self):
        result = contract.validate(authority(), "2.13.0", locks("2.13.0"))
        self.assertEqual(result["strict_expected_waterline"], "2.13.0")
        cohort, selection = authority(), locks("2.13.1")
        with self.assertRaises(ValueError):
            contract.validate(cohort, "2.13.0", selection)

    def test_baseline_cannot_be_replaced_by_newer_registry_packages(self):
        cohort = authority()
        wrong = locks()
        wrong["approved-embedded"]["packages"][0]["version"] = "2.13.1"
        with self.assertRaisesRegex(ValueError, "Approved cohort"):
            contract.validate(cohort, "2.13.2", wrong)

    def test_mismatched_default_graphs_fail(self):
        cohort = authority()
        wrong = locks()
        wrong["onboarding-service"]["packages"][0]["version"] = "2.13.2"
        with self.assertRaisesRegex(ValueError, "different Waterline"):
            contract.validate(cohort, "2.13.2", wrong)

    def test_new_approved_sdk_does_not_change_legacy_onboarding_constraint(self):
        result = contract.validate(authority("3.4.1"), "2.13.2", locks(sdk="3.4.1"))
        self.assertEqual(result["graphs"]["approved-service"][contract.SDK], "3.4.1")
        self.assertEqual(result["graphs"]["onboarding-service"][contract.SDK], "2.4.0")

    def test_missing_mutable_or_unsupported_routes_fail(self):
        cohort = authority()
        for mutate in (
            lambda packages: packages.pop(),
            lambda packages: packages[0]["source"].update(reference="main"),
            lambda packages: packages[0]["dist"].update(type="path"),
            lambda packages: packages[0].update(version="2.13.2-dev"),
            lambda packages: packages[0].update(version="2.1١.0"),
            lambda packages: packages[1].update(version="3.4.1"),
        ):
            with self.subTest(mutation=mutate):
                wrong = copy.deepcopy(locks())
                mutate(wrong["onboarding-service"]["packages"])
                with self.assertRaises(ValueError):
                    contract.validate(cohort, "2.13.2", wrong)

    def test_candidate_and_authority_cannot_fall_back_or_downgrade(self):
        cohort, selection = authority(), locks()
        with self.assertRaisesRegex(ValueError, "predates"):
            contract.validate(cohort, "2.12.3", selection)
        with self.assertRaises(ValueError):
            contract.manifests({"schema": "unknown", "versions": {}})


if __name__ == "__main__":
    unittest.main()
