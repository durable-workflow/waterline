"""Synthetic registry objects only. No download, image or runtime is used."""

import json
from pathlib import Path
import runpy
import sys
import unittest

sys.path.insert(0, str(Path(__file__).resolve().parent))
import service_smoke_published as published

FIXTURE = runpy.run_path(str(Path(__file__).with_name("test-published-provenance.py")), run_name="inert_published_registry")


def encode(value):
    return json.dumps(value, separators=(",", ":")).encode()


def inputs():
    source = "a" * 40
    config = encode({"architecture": "amd64", "os": "linux", "config": {"Labels": {
        "org.opencontainers.image.revision": source, "dev.durable-workflow.release.tag": "2.18.1"}}})
    child = encode({"schemaVersion": 2, "mediaType": published.oci_release.MANIFEST,
                    "config": {"mediaType": published.oci_release.CONFIG, "digest": published.digest(config)}})
    attestation = encode(FIXTURE["attestation"](image=published.digest(child)))
    index = FIXTURE["index"]()
    index["manifests"][0]["digest"] = published.digest(child)
    index["manifests"][2]["digest"] = published.digest(attestation)
    index["manifests"][2]["annotations"][published.published_provenance.REFERENCE_DIGEST] = published.digest(child)
    raw_index = encode(index)
    return {"index_digest": published.digest(raw_index), "architecture": "amd64", "source": source,
            "index": raw_index, "child": child, "config": config, "attestation": attestation}


class PublishedInputTest(unittest.TestCase):
    def test_index_child_config_and_attestation_are_one_platform_subject(self):
        raw = inputs()
        held = published.PublishedInputs(**raw)
        self.assertEqual(raw["config"], held.config_bytes(held.subject["config_digest"]))
        self.assertEqual(published.digest(raw["child"]), held.subject["manifest_digest"])
        held.verify_unchanged()

    def test_replaced_input_source_or_platform_cannot_bind(self):
        for key, value in (("child", b"{}"), ("config", b"{}"), ("attestation", b"{}"),
                           ("source", "f" * 40), ("architecture", "arm64")):
            raw = inputs()
            raw[key] = value
            with self.subTest(key=key), self.assertRaises((published.PublishedError, ValueError)):
                published.PublishedInputs(**raw)

    def test_missing_index_platform_attestation_or_mutated_capture_refuses(self):
        raw = inputs()
        index = json.loads(raw["index"])
        index["manifests"].pop()
        raw["index"] = encode(index)
        raw["index_digest"] = published.digest(raw["index"])
        with self.assertRaises(ValueError):
            published.PublishedInputs(**raw)
        held = published.PublishedInputs(**inputs())
        held.inputs["child"] = b"unselected"
        with self.assertRaises(published.PublishedError):
            held.verify_unchanged()

    def test_strict_duplicate_nonfinite_and_oversized_registry_objects_refuse(self):
        for raw in (b'{"key":1,"key":2}', b'{"value":NaN}', b'{}' + b' ' * (1024 * 1024)):
            selected = published.digest(raw)
            with self.subTest(raw=raw[:30]), self.assertRaises(RuntimeError):
                published.document(raw, selected)

    def test_engine_observation_binds_config_id_without_claiming_original_encoding(self):
        raw = inputs()
        original = json.loads(raw.pop("config"))
        selected_config = json.loads(raw["child"])["config"]["digest"]
        observation = [{"Id": selected_config, "Architecture": "amd64", "Os": "linux", "Config": original["config"],
                        "RootFS": {"Type": "layers", "Layers": ["sha256:" + "1" * 64]}}]
        engine = encode(observation)
        held = published.PublishedInputs.from_engine(**raw, engine=engine)
        self.assertEqual(selected_config, held.subject["config_digest"])
        self.assertEqual(published.digest(engine), held.subject["config_observation_sha256"])
        self.assertNotEqual(selected_config, published.digest(held.config_bytes(selected_config)))
        observation[0]["Id"] = "sha256:" + "2" * 64
        replaced = encode(observation)
        with self.assertRaises(published.PublishedError):
            published.PublishedInputs.from_engine(**raw, engine=replaced)

    def test_mutating_selected_subject_cannot_change_image_authority(self):
        held = published.PublishedInputs(**inputs())
        held.subject["config_digest"] = "sha256:" + "0" * 64
        with self.assertRaises(published.PublishedError):
            held.verify_unchanged()


if __name__ == "__main__":
    unittest.main()
