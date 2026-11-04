#!/usr/bin/env python3
"""Refuse a published Waterline index that lacks per-platform build provenance.

The registry reads happen in the workflow. This script only judges raw
manifests, so its rules stay testable without network access.
"""

from __future__ import annotations

import argparse
import hashlib
import json
import sys
from pathlib import Path

INDEX = "application/vnd.oci.image.index.v1+json"
MANIFEST = "application/vnd.oci.image.manifest.v1+json"
ATTESTATION_ARTIFACT = "application/vnd.docker.attestation.manifest.v1+json"
IN_TOTO = "application/vnd.in-toto+json"
REFERENCE_TYPE = "vnd.docker.reference.type"
REFERENCE_DIGEST = "vnd.docker.reference.digest"
PREDICATE_TYPE = "in-toto.io/predicate-type"
REQUIRED_PREDICATES = {"https://slsa.dev/provenance/v1", "https://spdx.dev/Document"}
PLATFORMS = ("amd64", "arm64")
MAX_MANIFEST = 1024 * 1024


class ProvenanceError(ValueError):
    """The published index does not carry the required provenance."""


def require(condition: bool, message: str) -> None:
    if not condition:
        raise ProvenanceError(message)


def manifest(raw: bytes, digest: str) -> dict:
    require(len(raw) <= MAX_MANIFEST, "Oversized registry manifest")
    require("sha256:" + hashlib.sha256(raw).hexdigest() == digest, "Registry manifest differs from its digest")
    value = json.loads(raw)
    require(isinstance(value, dict), "Registry manifest is not an object")
    return value


def check_index(index: dict) -> dict:
    """Return each platform's image digest and its single attestation digest."""
    require(index.get("schemaVersion") == 2 and index.get("mediaType") == INDEX, "Published tag is not an OCI image index")
    entries = index.get("manifests")
    require(isinstance(entries, list) and len(entries) == 2 * len(PLATFORMS), "Expected two images and two attestation manifests")
    images, attestations = {}, {}
    for entry in entries:
        require(entry.get("mediaType") == MANIFEST, "Unexpected index entry type")
        annotations = entry.get("annotations") or {}
        platform = entry.get("platform") or {}
        if annotations.get(REFERENCE_TYPE) == "attestation-manifest":
            require(platform == {"architecture": "unknown", "os": "unknown"}, "Attestation entry claims a runtime platform")
            subject = annotations.get(REFERENCE_DIGEST)
            require(subject not in attestations, "More than one attestation manifest for an image")
            attestations[subject] = entry["digest"]
        else:
            require(REFERENCE_TYPE not in annotations, "Unknown index reference type")
            architecture = platform.get("architecture")
            require(platform.get("os") == "linux" and architecture in PLATFORMS and architecture not in images, "Unexpected or duplicate image platform")
            images[architecture] = entry["digest"]
    require(set(images) == set(PLATFORMS), "Index is missing a native platform image")
    require(set(attestations) == set(images.values()), "Each platform image needs exactly one attestation manifest")
    return {architecture: {"image": digest, "attestation": attestations[digest]} for architecture, digest in images.items()}


def check_attestation(attestation: dict, image: str) -> None:
    require(attestation.get("mediaType") == MANIFEST and attestation.get("artifactType") == ATTESTATION_ARTIFACT, "Not a BuildKit attestation manifest")
    require((attestation.get("subject") or {}).get("digest") == image, "Attestation subject is not the platform image")
    layers = attestation.get("layers")
    require(isinstance(layers, list), "Attestation has no statements")
    predicates = [(layer.get("annotations") or {}).get(PREDICATE_TYPE) for layer in layers if layer.get("mediaType") == IN_TOTO]
    require(len(predicates) == len(layers) and sorted(predicates) == sorted(REQUIRED_PREDICATES), "Attestation must hold exactly SLSA provenance and an SPDX SBOM")


def main() -> None:
    parser = argparse.ArgumentParser(description=__doc__)
    sub = parser.add_subparsers(dest="command", required=True)
    index = sub.add_parser("index", help="Print platform and attestation digests from a raw index")
    index.add_argument("--raw", type=Path, required=True)
    index.add_argument("--digest", required=True)
    attestation = sub.add_parser("attestation", help="Check one raw attestation manifest")
    attestation.add_argument("--raw", type=Path, required=True)
    attestation.add_argument("--digest", required=True)
    attestation.add_argument("--image", required=True)
    args = parser.parse_args()
    if args.command == "index":
        print(json.dumps(check_index(manifest(args.raw.read_bytes(), args.digest)), sort_keys=True))
    else:
        check_attestation(manifest(args.raw.read_bytes(), args.digest), args.image)


if __name__ == "__main__":
    try:
        main()
    except (OSError, ValueError, KeyError, TypeError) as error:
        print(f"Published provenance refused: {error}", file=sys.stderr)
        raise SystemExit(1) from None
