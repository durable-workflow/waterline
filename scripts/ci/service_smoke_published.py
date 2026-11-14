"""Unwired current published-image subjects, using raw registry inputs only.

The existing published workflow owns anonymous acquisition and signed producer
provenance. This reader selects no registry, image, download or runtime itself.
"""

from __future__ import annotations

import hashlib
import re

import oci_release
import published_provenance
from waterline_release_identity import decode_json


class PublishedError(RuntimeError):
    """The current public index, child or config does not bind the subject."""


def digest(raw: bytes) -> str:
    return "sha256:" + hashlib.sha256(raw).hexdigest()


def document(raw: bytes, expected: str) -> dict:
    if (not isinstance(raw, bytes) or not 0 < len(raw) <= 1024 * 1024
            or not isinstance(expected, str) or re.fullmatch(r"sha256:[a-f\d]{64}", expected, re.ASCII) is None
            or digest(raw) != expected):
        raise PublishedError("Registry input differs from its independently selected digest")
    value = decode_json(raw, "captured published registry document")
    if not isinstance(value, dict):
        raise PublishedError("Expected a strict published registry object")
    return value


class PublishedInputs:
    def __init__(self, *, index_digest: str, architecture: str, source: str, index: bytes,
                 child: bytes, config: bytes, attestation: bytes):
        if architecture not in {"amd64", "arm64"} or re.fullmatch(r"[a-f\d]{40}", source, re.ASCII) is None:
            raise PublishedError("Unselected current published source/platform")
        index_value = document(index, index_digest)
        platforms = published_provenance.check_index(index_value)
        selected = platforms[architecture]
        child_value = document(child, selected["image"])
        if child_value.get("schemaVersion") != 2 or child_value.get("mediaType") != oci_release.MANIFEST:
            raise PublishedError("Selected child is not the platform image manifest")
        selected_config = child_value.get("config", {})
        if selected_config.get("mediaType") != oci_release.CONFIG:
            raise PublishedError("Published child has an unsupported config type")
        config_value = document(config, selected_config.get("digest"))
        labels = config_value.get("config", {}).get("Labels", {})
        if (config_value.get("architecture") != architecture or config_value.get("os") != "linux"
                or labels.get("org.opencontainers.image.revision") != source
                or labels.get("dev.durable-workflow.release.tag") != oci_release.VERSION):
            raise PublishedError("Published config source/version/platform differs")
        attestation_value = document(attestation, selected["attestation"])
        published_provenance.check_attestation(attestation_value, selected["image"])
        self.subject = {"index_digest": index_digest, "manifest_digest": selected["image"],
                        "config_digest": selected_config["digest"], "attestation_digest": selected["attestation"],
                        "architecture": architecture, "source": source}
        self.config = bytes(config)
        self.inputs = {"index": bytes(index), "child": bytes(child), "config": bytes(config), "attestation": bytes(attestation)}
        self.hashes = {name: digest(raw) for name, raw in self.inputs.items()}

    def config_bytes(self, expected: str) -> bytes:
        self.verify_unchanged()
        if expected != self.subject["config_digest"]:
            raise PublishedError("Unselected published config")
        return self.config

    def verify_unchanged(self):
        if self.hashes != {name: digest(raw) for name, raw in self.inputs.items()} or digest(self.config) != self.subject["config_digest"]:
            raise PublishedError("Captured published input bytes changed")
