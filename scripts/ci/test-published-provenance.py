#!/usr/bin/env python3
"""Counterfactuals for the published per-platform provenance guard."""

import copy
import hashlib
import importlib.util
import json
from pathlib import Path
import unittest

spec = importlib.util.spec_from_file_location("published_provenance", Path(__file__).with_name("published_provenance.py"))
guard = importlib.util.module_from_spec(spec)
spec.loader.exec_module(guard)
IMAGES = {"amd64": "sha256:" + "a" * 64, "arm64": "sha256:" + "b" * 64}
ATTESTATIONS = {"amd64": "sha256:" + "c" * 64, "arm64": "sha256:" + "d" * 64}


def index():
    images = [{"mediaType": guard.MANIFEST, "digest": IMAGES[arch], "size": 1, "platform": {"architecture": arch, "os": "linux"}} for arch in guard.PLATFORMS]
    attestations = [{"mediaType": guard.MANIFEST, "digest": ATTESTATIONS[arch], "size": 1,
                     "annotations": {guard.REFERENCE_TYPE: "attestation-manifest", guard.REFERENCE_DIGEST: IMAGES[arch]},
                     "platform": {"architecture": "unknown", "os": "unknown"}} for arch in guard.PLATFORMS]
    return {"schemaVersion": 2, "mediaType": guard.INDEX, "manifests": images + attestations}


def attestation(image=IMAGES["amd64"], predicates=tuple(sorted(guard.REQUIRED_PREDICATES))):
    return {"schemaVersion": 2, "mediaType": guard.MANIFEST, "artifactType": guard.ATTESTATION_ARTIFACT,
            "subject": {"mediaType": guard.MANIFEST, "digest": image, "size": 1},
            "layers": [{"mediaType": guard.IN_TOTO, "annotations": {guard.PREDICATE_TYPE: kind}} for kind in predicates]}


class PublishedProvenanceTest(unittest.TestCase):
    def test_each_platform_has_exactly_one_attestation(self):
        self.assertEqual(guard.check_index(index()), {arch: {"image": IMAGES[arch], "attestation": ATTESTATIONS[arch]} for arch in guard.PLATFORMS})

    def test_missing_extra_duplicate_or_misplaced_entries_refuse(self):
        def drop_attestation(value): value["manifests"].pop()
        def duplicate_attestation(value): value["manifests"][3]["annotations"][guard.REFERENCE_DIGEST] = IMAGES["amd64"]
        def extra_entry(value): value["manifests"].append(copy.deepcopy(value["manifests"][0]))
        def wrong_reference(value): value["manifests"][2]["annotations"][guard.REFERENCE_DIGEST] = "sha256:" + "e" * 64
        def runtime_platform(value): value["manifests"][2]["platform"] = {"architecture": "amd64", "os": "linux"}
        def same_platform(value): value["manifests"][1]["platform"] = {"architecture": "amd64", "os": "linux"}
        def images_only(value): value["manifests"] = value["manifests"][:2]
        def unknown_reference(value): value["manifests"][0].setdefault("annotations", {})[guard.REFERENCE_TYPE] = "signature"
        for mutate in (drop_attestation, duplicate_attestation, extra_entry, wrong_reference, runtime_platform, same_platform, images_only, unknown_reference):
            value = index()
            mutate(value)
            with self.subTest(case=mutate.__name__), self.assertRaises(guard.ProvenanceError):
                guard.check_index(value)

    def test_attestation_must_cover_this_image_with_provenance_and_sbom(self):
        guard.check_attestation(attestation(), IMAGES["amd64"])
        for value in [attestation(image=IMAGES["arm64"]), attestation(predicates=("https://slsa.dev/provenance/v1",)),
                      attestation(predicates=("https://slsa.dev/provenance/v1", "https://slsa.dev/provenance/v1")),
                      {**attestation(), "artifactType": "application/example"}]:
            with self.subTest(value=value), self.assertRaises(guard.ProvenanceError):
                guard.check_attestation(value, IMAGES["amd64"])

    def test_raw_manifest_must_match_its_registry_digest(self):
        raw = json.dumps(index()).encode()
        self.assertEqual(guard.manifest(raw, "sha256:" + hashlib.sha256(raw).hexdigest()), index())
        with self.assertRaises(guard.ProvenanceError):
            guard.manifest(raw, "sha256:" + "0" * 64)


if __name__ == "__main__":
    unittest.main()
