"""Unwired current published-image subjects, using registry and engine observations.

The existing published workflow owns anonymous acquisition and signed producer
provenance. This reader selects no registry, image, download or runtime itself.
"""

from __future__ import annotations

import hashlib
import json
import re

import oci_release
import published_provenance
from service_smoke_resources import observation as docker_observation
from waterline_release_identity import decode_json

DIGEST_PATTERN = r"sha256:[a-f\d]{64}"


class PublishedError(RuntimeError):
    """The current public index, child or config does not bind the subject."""


def digest(raw: bytes) -> str:
    return "sha256:" + hashlib.sha256(raw).hexdigest()


def document(raw: bytes, expected: str) -> dict:
    if (not isinstance(raw, bytes) or not 0 < len(raw) <= 1024 * 1024
            or not isinstance(expected, str) or re.fullmatch(DIGEST_PATTERN, expected, re.ASCII) is None
            or digest(raw) != expected):
        raise PublishedError("Registry input differs from its independently selected digest")
    value = decode_json(raw, "captured published registry document")
    if not isinstance(value, dict):
        raise PublishedError("Expected a strict published registry object")
    return value


def encode(value: dict) -> bytes:
    return json.dumps(value, sort_keys=True, separators=(",", ":"), allow_nan=False).encode()


def configuration(value: dict, architecture: str, source: str):
    labels = value.get("config", {}).get("Labels", {})
    if (value.get("architecture") != architecture or value.get("os") != "linux"
            or labels.get("org.opencontainers.image.revision") != source
            or labels.get("dev.durable-workflow.release.tag") != oci_release.VERSION):
        raise PublishedError("Published config source/version/platform differs")


class PublishedInputs:
    def select(self, index_digest: str, architecture: str, source: str, index: bytes, child: bytes, attestation: bytes):
        if architecture not in {"amd64", "arm64"} or re.fullmatch(r"[a-f\d]{40}", source, re.ASCII) is None:
            raise PublishedError("Unselected current published source/platform")
        index_value = document(index, index_digest)
        platforms = published_provenance.check_index(index_value)
        selected = platforms[architecture]
        child_value = document(child, selected["image"])
        if child_value.get("schemaVersion") != 2 or child_value.get("mediaType") != oci_release.MANIFEST:
            raise PublishedError("Selected child is not the platform image manifest")
        selected_config = child_value.get("config", {})
        if (selected_config.get("mediaType") != oci_release.CONFIG
                or not isinstance(selected_config.get("digest"), str)
                or re.fullmatch(DIGEST_PATTERN, selected_config["digest"], re.ASCII) is None):
            raise PublishedError("Published child has an unsupported config type")
        attestation_value = document(attestation, selected["attestation"])
        published_provenance.check_attestation(attestation_value, selected["image"])
        self.subject = {"index_digest": index_digest, "manifest_digest": selected["image"],
                        "config_digest": selected_config["digest"], "attestation_digest": selected["attestation"],
                        "architecture": architecture, "source": source}
        self.inputs = {"index": bytes(index), "child": bytes(child), "attestation": bytes(attestation)}

    def seal(self):
        self.hashes = {name: digest(raw) for name, raw in self.inputs.items()}
        self.config_sha256 = digest(self.config)
        self.subject_sha256 = digest(encode(self.subject))

    def __init__(self, *, index_digest: str, architecture: str, source: str, index: bytes,
                 child: bytes, config: bytes, attestation: bytes):
        self.select(index_digest, architecture, source, index, child, attestation)
        configuration(document(config, self.subject["config_digest"]), architecture, source)
        self.config = bytes(config)
        self.inputs["config"] = bytes(config)
        self.seal()

    @classmethod
    def from_engine(cls, *, index_digest: str, architecture: str, source: str, index: bytes,
                    child: bytes, engine: bytes, attestation: bytes):
        result = cls.__new__(cls)
        result.select(index_digest, architecture, source, index, child, attestation)
        # Docker's rendered inspect JSON is an observation, not the original
        # OCI config encoding. Its Id must match the config selected by the
        # public child; its own byte digest is recorded separately.
        value = docker_observation(engine)
        if (not isinstance(value, list) or len(value) != 1 or not isinstance(value[0], dict)
                or value[0].get("Id") != result.subject["config_digest"]
                or not isinstance(value[0].get("Config"), dict)):
            raise PublishedError("Engine image differs from the selected public child config")
        image = value[0]
        root = image.get("RootFS", {})
        if (root.get("Type") != "layers" or not isinstance(root.get("Layers"), list)
                or not 0 < len(root["Layers"]) <= 128
                or any(not isinstance(layer, str) or re.fullmatch(DIGEST_PATTERN, layer, re.ASCII) is None for layer in root["Layers"])):
            raise PublishedError("Published engine layer identities are incomplete")
        normalized = {"architecture": image.get("Architecture"), "os": image.get("Os"),
                      "config": image["Config"], "rootfs": {"type": "layers", "diff_ids": root["Layers"]}}
        configuration(normalized, architecture, source)
        result.config = encode(normalized)
        result.inputs["engine_observation"] = bytes(engine)
        result.subject["config_observation_sha256"] = digest(engine)
        result.seal()
        return result

    def config_bytes(self, expected: str) -> bytes:
        self.verify_unchanged()
        if expected != self.subject["config_digest"]:
            raise PublishedError("Unselected published config")
        return self.config

    def verify_unchanged(self):
        if (self.hashes != {name: digest(raw) for name, raw in self.inputs.items()}
                or digest(self.config) != self.config_sha256 or digest(encode(self.subject)) != self.subject_sha256):
            raise PublishedError("Captured published input bytes changed")
