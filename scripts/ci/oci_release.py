#!/usr/bin/env python3
"""Verify inert native OCI evidence before the protected publisher can log in."""

from __future__ import annotations

import argparse
import base64
from datetime import datetime, timedelta, timezone
import hashlib
import json
import os
import re
import shutil
import sys
import tarfile
import tempfile
import zipfile
from pathlib import Path, PurePosixPath

REPOSITORY = "durable-workflow/waterline"
VERSION = "2.18.0"
DESTINATION = "docker.io/durableworkflow/waterline:2.18.0"
WORKFLOW = ".github/workflows/service-image.yml"
PUBLISHER = ".github/workflows/service-image-publish.yml"
INDEX = "application/vnd.oci.image.index.v1+json"
MANIFEST = "application/vnd.oci.image.manifest.v1+json"
CONFIG = "application/vnd.oci.image.config.v1+json"
EMPTY_CONFIG = "application/vnd.oci.empty.v1+json"
IN_TOTO = "application/vnd.in-toto+json"
ATTESTATION_ARTIFACT = "application/vnd.docker.attestation.manifest.v1+json"
REFERENCE_TYPE = "vnd.docker.reference.type"
REFERENCE_DIGEST = "vnd.docker.reference.digest"
PREDICATE_TYPE = "in-toto.io/predicate-type"
STATEMENT = "https://in-toto.io/Statement/v1"
SLSA_PROVENANCE = "https://slsa.dev/provenance/v1"
SPDX = "https://spdx.dev/Document"
MAX_ARCHIVE = 1024 * 1024 * 1024
MAX_JSON = 1024 * 1024
# BuildKit's SPDX document lists every package in the image; the cap bounds it.
MAX_ATTESTATION = 32 * 1024 * 1024
SHA256_PREFIX = "sha256:"
BLOBS_DIRECTORY = "blobs/sha256"
INDEX_FILE = "index.json"
PROVENANCE_FILE = "provenance.json"
SHA = re.compile(r"[0-9a-f]{40}", re.ASCII)
DIGEST = re.compile(r"sha256:[0-9a-f]{64}", re.ASCII)
SDK = {"sdk": "3.16.0", "sdk_source": "00a531e688ccbcaf4ce59a662cdbcd03dd49ee4c"}
SECURITY_POLICY = Path(__file__).resolve().parents[2] / "release/service-image-security.json"


class EvidenceError(ValueError):
    """Evidence does not bind the reviewed source and both native images."""


def require(condition: bool, message: str) -> None:
    if not condition:
        raise EvidenceError(message)


def unique_object(pairs: list[tuple]) -> dict:
    result = {}
    for key, value in pairs:
        require(key not in result, "Duplicate JSON field")
        result[key] = value
    return result


def invalid_constant(value: str) -> None:
    raise EvidenceError("Non-finite JSON value")


def document(raw: bytes, limit: int = MAX_JSON) -> dict:
    require(len(raw) <= limit, "Oversized JSON document")
    value = json.loads(raw, object_pairs_hook=unique_object, parse_constant=invalid_constant)
    require(isinstance(value, dict), "Expected JSON object")
    return value


def read_json(path: Path) -> dict:
    require(path.stat().st_size <= MAX_JSON, "Oversized JSON file")
    return document(path.read_bytes())


def encode(value: dict) -> bytes:
    return json.dumps(value, sort_keys=True, separators=(",", ":"), allow_nan=False).encode()


def digest_file(path: Path) -> str:
    with path.open("rb") as stream:
        return SHA256_PREFIX + hashlib.file_digest(stream, "sha256").hexdigest()


def positive(value: object) -> int:
    require(isinstance(value, str) and re.fullmatch(r"[1-9]\d{0,19}", value, re.ASCII) is not None, "Expected positive decimal identity")
    return int(value)


def commit(value: object) -> str:
    require(isinstance(value, str) and SHA.fullmatch(value) is not None, "Expected immutable commit identity")
    return value


def sha256(value: object) -> str:
    require(isinstance(value, str) and re.fullmatch(r"[0-9a-f]{64}", value, re.ASCII) is not None, "Expected SHA-256 identity")
    return value


def unpack_oci(archive: Path, root: Path) -> None:
    require(0 < archive.stat().st_size <= MAX_ARCHIVE, "OCI archive size is outside bounds")
    root.mkdir()
    total = 0
    seen = set()
    with tarfile.open(archive, mode="r:") as source:
        for count, member in enumerate(source, start=1):
            require(count <= 2048, "Too many OCI archive entries")
            path = PurePosixPath(member.name)
            require(not path.is_absolute() and ".." not in path.parts, "Unsafe OCI archive path")
            name = member.name.removeprefix("./").rstrip("/")
            require(name not in seen, "Duplicate OCI archive path")
            seen.add(name)
            if member.isdir():
                require(name in {"blobs", BLOBS_DIRECTORY}, "Unexpected OCI directory")
                (root / name).mkdir(parents=True, exist_ok=True)
                continue
            require(member.isfile(), "OCI links and special files are forbidden")
            require(name in {INDEX_FILE, "oci-layout"} or re.fullmatch(r"blobs/sha256/[0-9a-f]{64}", name, re.ASCII) is not None, "Unexpected OCI file")
            total += member.size
            require(0 <= member.size <= MAX_ARCHIVE and total <= MAX_ARCHIVE, "Oversized OCI contents")
            target = root / name
            target.parent.mkdir(parents=True, exist_ok=True)
            stream = source.extractfile(member)
            require(stream is not None, "Missing OCI file content")
            with stream, target.open("xb") as output:
                shutil.copyfileobj(stream, output, 1024 * 1024)
            if name.startswith("blobs/"):
                require(digest_file(target) == SHA256_PREFIX + target.name, "OCI blob digest mismatch")


def blob(root: Path, descriptor: dict) -> Path:
    require(isinstance(descriptor, dict) and DIGEST.fullmatch(str(descriptor.get("digest", ""))) is not None, "Unsupported OCI descriptor digest")
    size = descriptor.get("size")
    require(type(size) is int and 0 <= size <= MAX_ARCHIVE and "urls" not in descriptor, "Invalid OCI descriptor size or external URL")
    path = root / BLOBS_DIRECTORY / descriptor["digest"].split(":")[1]
    require(path.is_file() and path.stat().st_size == size and digest_file(path) == descriptor["digest"], "Missing or changed OCI descriptor content")
    return path


def native_index(root: Path) -> list:
    """Return the entries of the single-platform index BuildKit exported."""
    require(read_json(root / "oci-layout") == {"imageLayoutVersion": "1.0.0"}, "Unsupported OCI layout")
    index = read_json(root / INDEX_FILE)
    for _depth in range(3):
        manifests = index.get("manifests")
        require(index.get("schemaVersion") == 2 and isinstance(manifests, list) and manifests, "Invalid native OCI index")
        if len(manifests) == 1 and manifests[0].get("mediaType") == INDEX:
            index = read_json(blob(root, manifests[0]))
            continue
        return manifests
    raise EvidenceError("OCI index nesting exceeds bounds")


def inspect_image(root: Path, descriptor: dict, architecture: str, source: str) -> tuple[dict, str]:
    require(descriptor.get("mediaType") == MANIFEST and set(descriptor) <= {"mediaType", "digest", "size", "platform", "annotations"}, "Unsupported native OCI media type")
    require(descriptor.get("platform") == {"architecture": architecture, "os": "linux"}, "OCI platform mismatch")
    manifest = read_json(blob(root, descriptor))
    require(manifest.get("schemaVersion") == 2 and manifest.get("mediaType") == MANIFEST, "Invalid OCI image manifest")
    config_descriptor = manifest.get("config", {})
    require(config_descriptor.get("mediaType") == CONFIG, "Unsupported OCI image config")
    config = read_json(blob(root, config_descriptor))
    require(config.get("os") == "linux" and config.get("architecture") == architecture, "OCI architecture mismatch")
    labels = config.get("config", {}).get("Labels", {})
    require(labels.get("org.opencontainers.image.revision") == source and labels.get("dev.durable-workflow.release.tag") == VERSION, "OCI source/version label mismatch")
    layers = manifest.get("layers")
    require(isinstance(layers, list) and 0 < len(layers) <= 128, "Invalid OCI layer count")
    for layer in layers:
        require(layer.get("mediaType") in {"application/vnd.oci.image.layer.v1.tar+gzip", "application/vnd.oci.image.layer.v1.tar+zstd", "application/vnd.oci.image.layer.v1.tar"}, "Unsupported OCI layer type")
        blob(root, layer)
    return {"mediaType": MANIFEST, "digest": descriptor["digest"], "size": descriptor["size"], "platform": {"os": "linux", "architecture": architecture}}, config_descriptor["digest"]


def inspect_attestation(root: Path, descriptor: dict, image: dict, source: str) -> dict:
    """Bind BuildKit's SLSA provenance and SPDX SBOM to exactly this native image."""
    references = {REFERENCE_TYPE: "attestation-manifest", REFERENCE_DIGEST: image["digest"]}
    require(descriptor.get("mediaType") == MANIFEST and descriptor.get("annotations") == references
            and descriptor.get("platform") == {"architecture": "unknown", "os": "unknown"}
            and set(descriptor) == {"mediaType", "digest", "size", "platform", "annotations"}, "Attestation manifest does not reference this native image")
    manifest = read_json(blob(root, descriptor))
    require(manifest.get("schemaVersion") == 2 and manifest.get("mediaType") == MANIFEST
            and manifest.get("artifactType") == ATTESTATION_ARTIFACT, "Invalid attestation manifest")
    subject = manifest.get("subject")
    require(isinstance(subject, dict) and subject.get("mediaType") == MANIFEST and subject.get("digest") == image["digest"]
            and subject.get("size") == image["size"], "Attestation subject is not this native image")
    config = manifest.get("config")
    require(isinstance(config, dict) and config.get("mediaType") == EMPTY_CONFIG, "Unsupported attestation config")
    blob(root, config)
    layers = manifest.get("layers")
    require(isinstance(layers, list) and len(layers) == 2, "Expected exactly provenance and SBOM attestations")
    predicates = {}
    for layer in layers:
        kind = (layer.get("annotations") or {}).get(PREDICATE_TYPE)
        require(layer.get("mediaType") == IN_TOTO and kind in {SLSA_PROVENANCE, SPDX} and kind not in predicates, "Unexpected attestation layer")
        require(type(layer.get("size")) is int and layer["size"] <= MAX_ATTESTATION, "Oversized attestation layer")
        statement = document(blob(root, layer).read_bytes(), MAX_ATTESTATION)
        require(statement.get("_type") == STATEMENT and statement.get("predicateType") == kind, "Invalid in-toto statement")
        subjects = statement.get("subject")
        require(isinstance(subjects, list) and all(isinstance(item, dict) and item.get("digest") == {"sha256": image["digest"].split(":")[1]}
                for item in subjects), "In-toto subject is not this native image")
        require(isinstance(statement.get("predicate"), dict), "Missing attestation predicate")
        predicates[kind] = statement["predicate"]
    request = predicates[SLSA_PROVENANCE]
    for key in ("buildDefinition", "externalParameters", "request", "args"):
        request = request.get(key) if isinstance(request, dict) else None
    arguments = request if isinstance(request, dict) else {}
    require(arguments.get("build-arg:SOURCE_COMMIT") == source and arguments.get("build-arg:WATERLINE_VERSION") == VERSION,
            "Build provenance does not bind the reviewed source and version")
    return {"mediaType": MANIFEST, "digest": descriptor["digest"], "size": descriptor["size"],
            "annotations": references, "platform": {"architecture": "unknown", "os": "unknown"}}


def inspect_oci(root: Path, architecture: str, source: str) -> tuple[dict, str, dict]:
    manifests = native_index(root)
    images = [item for item in manifests if (item.get("annotations") or {}).get(REFERENCE_TYPE) is None]
    attestations = [item for item in manifests if (item.get("annotations") or {}).get(REFERENCE_TYPE) is not None]
    require(len(manifests) == 2 and len(images) == 1 and len(attestations) == 1, "Expected one native image and exactly one attestation manifest")
    image, config_digest = inspect_image(root, images[0], architecture, source)
    return image, config_digest, inspect_attestation(root, attestations[0], image, source)


def validate_image_security(value: dict, subject: str, architecture: str, config_digest: str) -> None:
    policy = read_json(SECURITY_POLICY)
    require(isinstance(value, dict), "Missing image security evidence")
    require(value.get("schema") == "waterline.image-security/v1" and value.get("status") == "pass", "Image security did not pass")
    require(value.get("subject") == subject and value.get("architecture") == architecture
            and value.get("config_digest") == config_digest, "Image security evidence does not bind this exact image")
    require(value.get("scanner_image") == policy["scanner_image"], "Image security scanner identity changed")
    require(value.get("findings") == [] and type(value.get("high_critical_count")) is int
            and value["high_critical_count"] == 0, "Unreviewed high/critical image findings")
    require(value.get("inventory", {}).get("alpine", 0) > 0, "Image scan has no operating-system package inventory")
    database = value.get("database", {})
    require(database.get("Version") == 2, "Unsupported vulnerability database")
    updated = datetime.fromisoformat(database["UpdatedAt"].replace("Z", "+00:00"))
    require(updated.tzinfo is not None, "Vulnerability database timestamp has no timezone")
    age = datetime.now(timezone.utc) - updated
    require(-timedelta(minutes=5) <= age <= timedelta(hours=policy["database_max_age_hours"]), "Vulnerability database is stale or in the future")


def scan(args: argparse.Namespace) -> None:
    report, database, policy = read_json(args.report), read_json(args.database), read_json(SECURITY_POLICY)
    require(report.get("SchemaVersion") == 2 and report.get("ArtifactType") == "container_image", "Expected a complete container vulnerability report")
    metadata = report.get("Metadata", {})
    require(metadata.get("OS", {}).get("Family") == "alpine", "Expected the Alpine service runtime")
    config_digest = metadata.get("ImageID")
    require(DIGEST.fullmatch(str(config_digest)) is not None, "Missing scanned image config digest")
    require(metadata.get("ImageConfig", {}).get("architecture") == args.architecture, "Scanned architecture differs from the selected platform")
    subject = digest_file(args.archive) if args.archive else args.image
    require(isinstance(subject, str) and (DIGEST.fullmatch(subject) is not None
            or re.fullmatch(r"docker\.io/durableworkflow/waterline@sha256:[0-9a-f]{64}", subject) is not None), "Scan subject must be an exact archive or platform digest")
    inventory, counts, findings = {}, {}, []
    results = report.get("Results")
    require(isinstance(results, list) and results, "Vulnerability report has no inventory results")
    for result in results:
        kind = result.get("Type", "unknown")
        inventory[kind] = inventory.get(kind, 0) + len(result.get("Packages", []))
        for item in result.get("Vulnerabilities", []):
            severity = item["Severity"]
            counts[severity] = counts.get(severity, 0) + 1
            if severity in {"HIGH", "CRITICAL"}:
                findings.append({key: item.get(key, "") for key in
                                 ("VulnerabilityID", "PkgName", "InstalledVersion", "FixedVersion", "Severity")})
    summary = {"schema": "waterline.image-security/v1", "status": "fail" if findings else "pass",
               "subject": subject, "architecture": args.architecture, "config_digest": config_digest,
               "scanner_image": policy["scanner_image"], "database": database, "os": metadata["OS"],
               "inventory": inventory, "severity_counts": counts, "high_critical_count": len(findings),
               "findings": findings, "report_digest": digest_file(args.report)}
    args.output.write_bytes(encode(summary) + b"\n")
    validate_image_security(summary, subject, args.architecture, config_digest)
    print(f"Exact {args.architecture} image scan passed: {sum(inventory.values())} packages; zero high/critical findings.")


def scan_layout(args: argparse.Namespace) -> None:
    unpack_oci(args.archive, args.directory)
    inspect_oci(args.directory, args.architecture, commit(args.source))


def native(args: argparse.Namespace) -> None:
    source, tree = commit(args.source), commit(args.tree)
    require(args.version == VERSION and args.architecture in {"amd64", "arm64"}, "Unsupported bounded release or architecture")
    sdk = read_json(args.sdk)
    require(sdk == SDK, "Native installed SDK identity differs from the approved cohort")
    with tempfile.TemporaryDirectory(prefix="waterline-oci-") as temporary:
        root = Path(temporary) / "layout"
        unpack_oci(args.archive, root)
        descriptor, config_digest, attestation = inspect_oci(root, args.architecture, source)
    require(args.image_id == config_digest, "Tested Docker image differs from exported OCI config")
    security = read_json(args.security)
    validate_image_security(security, digest_file(args.archive), args.architecture, config_digest)
    provenance = {"schema": "waterline.native-oci/v2", "version": VERSION, "source": source, "tree": tree,
                  "architecture": args.architecture, "manifest_digest": descriptor["digest"], "config_digest": config_digest,
                  "attestation_digest": attestation["digest"],
                  "archive_digest": digest_file(args.archive), "runner_commit": commit(os.environ["GITHUB_SHA"]),
                  "run_id": positive(os.environ["GITHUB_RUN_ID"]), "run_attempt": positive(os.environ["GITHUB_RUN_ATTEMPT"]),
                  "build_workflow_sha256": digest_file(args.workflow).split(":")[1], "image_security": security, **sdk}
    args.output.write_bytes(encode(provenance) + b"\n")


def validate_tag(root: Path, source: str) -> None:
    reference = read_json(root / "tag-ref.json")
    require(reference.get("ref") == "refs/tags/" + VERSION, "Unexpected release tag")
    target = reference.get("object", {})
    if target.get("type") == "tag":
        annotated = read_json(root / "tag-object.json")
        require(annotated.get("sha") == target.get("sha") and annotated.get("tag") == VERSION, "Annotated tag identity mismatch")
        target = annotated.get("object", {})
    require(target.get("type") == "commit" and target.get("sha") == source, "Release tag does not resolve to reviewed source")


def selected_artifacts(directory: Path, run_id: int, attempt: int) -> dict:
    artifacts = read_json(directory / "artifacts.json").get("artifacts", [])
    require(isinstance(artifacts, list) and len(artifacts) <= 20, "Unexpected evidence artifact inventory")
    chosen = {}
    for architecture in ("amd64", "arm64"):
        name = f"waterline-oci-{architecture}-{run_id}-{attempt}"
        matches = [item for item in artifacts if item.get("name") == name]
        require(len(matches) == 1, "Missing or duplicate native artifact")
        item = matches[0]
        require(type(item.get("id")) is int and item["id"] > 0 and item.get("expired") is False, "Invalid native artifact identity")
        require(type(item.get("size_in_bytes")) is int and 0 < item["size_in_bytes"] <= MAX_ARCHIVE + MAX_JSON, "Oversized native artifact")
        require(isinstance(item.get("digest"), str) and DIGEST.fullmatch(item["digest"]) is not None, "Missing immutable Actions artifact digest")
        chosen[architecture] = {key: item[key] for key in ("id", "digest", "size_in_bytes")}
    return chosen


def plan(args: argparse.Namespace) -> None:
    source, tree, runner = commit(args.source), commit(args.tree), commit(args.runner)
    run_id, attempt = positive(args.run), positive(args.attempt)
    require(os.environ.get("GITHUB_REPOSITORY") == REPOSITORY and os.environ.get("GITHUB_REF") == "refs/heads/main", "Publisher requires the fixed protected main repository")
    require(os.environ.get("GITHUB_SHA") == commit(args.publisher), "Publisher source changed after independent review")
    require(digest_file(Path(PUBLISHER)) == SHA256_PREFIX + sha256(args.publisher_hash), "Publisher workflow changed after review")
    validate_tag(args.directory, source)
    selected = read_json(args.directory / "commit.json")
    require(selected.get("sha") == source and selected.get("commit", {}).get("tree", {}).get("sha") == tree, "Reviewed source/tree mismatch")
    comparison = read_json(args.directory / "compare.json")
    require(comparison.get("status") in {"ahead", "identical"} and comparison.get("merge_base_commit", {}).get("sha") == source, "Release source is not in reviewed protected main history")
    pulls = read_json(args.directory / "pulls.json").get("pulls", [])
    merged = [pull for pull in pulls if pull.get("merge_commit_sha") == source and pull.get("merged_at")
              and pull.get("state") == "closed" and pull.get("base", {}).get("ref") == "main"
              and pull.get("base", {}).get("repo", {}).get("full_name") == REPOSITORY]
    require(len(merged) == 1 and SHA.fullmatch(str(merged[0].get("head", {}).get("sha", ""))) is not None, "Release source must be an exact merged main pull request")
    run = read_json(args.directory / "run.json")
    workflow = read_json(args.directory / "workflow.json")
    require(run.get("id") == run_id and run.get("run_attempt") == attempt and run.get("status") == "completed" and run.get("conclusion") == "success", "Unprivileged evidence run is not terminal success")
    require(run.get("repository", {}).get("full_name") == REPOSITORY and run.get("head_repository", {}).get("full_name") == REPOSITORY, "Wrong evidence repository")
    require(run.get("workflow_id") == workflow.get("id") and workflow.get("path") == WORKFLOW and run.get("path") == WORKFLOW, "Wrong evidence workflow")
    require(run.get("head_sha") == runner, "Unprivileged runner commit mismatch")
    valid_push = run.get("event") == "push" and run.get("head_branch") == VERSION and runner == source
    valid_dispatch = run.get("event") == "workflow_dispatch" and run.get("head_branch") == "main"
    require(valid_push or valid_dispatch, "Evidence must be the exact tag build or reviewed main recovery build")
    content = read_json(args.directory / "build-workflow.json")
    require(content.get("encoding") == "base64" and content.get("size", MAX_JSON + 1) <= MAX_JSON, "Invalid reviewed build workflow response")
    raw = base64.b64decode(content.get("content", ""), validate=False)
    require(hashlib.sha256(raw).hexdigest() == sha256(args.build_hash), "Build workflow changed after review")
    chosen = selected_artifacts(args.directory, run_id, attempt)
    args.output.write_bytes(encode({"source": source, "tree": tree, "source_pull": merged[0]["number"], "qualified_head": merged[0]["head"]["sha"], "runner_commit": runner, "run_id": run_id,
                                  "run_attempt": attempt, "build_workflow_sha256": args.build_hash, "artifacts": chosen}) + b"\n")


def unpack_artifact(path: Path, target: Path, metadata: dict) -> None:
    require(path.stat().st_size == metadata["size_in_bytes"] and digest_file(path) == metadata["digest"], "Actions artifact hash or size mismatch")
    target.mkdir()
    with zipfile.ZipFile(path) as archive:
        members = archive.infolist()
        require(len(members) == 2 and {item.filename for item in members} == {"image.oci.tar", PROVENANCE_FILE}, "Unexpected artifact files")
        for member in members:
            limit = MAX_JSON if member.filename == PROVENANCE_FILE else MAX_ARCHIVE
            require(0 < member.file_size <= limit and not member.is_dir() and member.flag_bits & 1 == 0, "Invalid artifact member")
            with archive.open(member) as source, (target / member.filename).open("xb") as output:
                shutil.copyfileobj(source, output, 1024 * 1024)


def prepare(args: argparse.Namespace) -> None:
    selected = read_json(args.directory / "plan.json")
    layouts, descriptors, attestations = [], [], []
    for architecture in ("amd64", "arm64"):
        target = args.directory / architecture
        unpack_artifact(args.directory / f"{architecture}.zip", target, selected["artifacts"][architecture])
        provenance = read_json(target / PROVENANCE_FILE)
        expected = {"schema": "waterline.native-oci/v2", "version": VERSION, "source": selected["source"], "tree": selected["tree"],
                    "architecture": architecture, **{key: selected[key] for key in ("runner_commit", "run_id", "run_attempt", "build_workflow_sha256")}, **SDK}
        require(set(provenance) == set(expected) | {"manifest_digest", "config_digest", "attestation_digest", "archive_digest", "image_security"}, "Unexpected provenance fields")
        require(all(provenance.get(key) == value for key, value in expected.items()), "Native provenance differs from reviewed run/cohort")
        archive = target / "image.oci.tar"
        require(digest_file(archive) == provenance["archive_digest"], "Native OCI archive hash mismatch")
        layout = target / "layout"
        unpack_oci(archive, layout)
        descriptor, config_digest, attestation = inspect_oci(layout, architecture, selected["source"])
        require(descriptor["digest"] == provenance["manifest_digest"] and config_digest == provenance["config_digest"]
                and attestation["digest"] == provenance["attestation_digest"], "Native manifest/config/attestation provenance mismatch")
        validate_image_security(provenance["image_security"], provenance["archive_digest"], architecture, config_digest)
        layouts.append(layout)
        descriptors.append(descriptor)
        attestations.append(attestation)
    merged = args.directory / "verified-oci"
    (merged / BLOBS_DIRECTORY).mkdir(parents=True)
    for layout in layouts:
        for source in (layout / BLOBS_DIRECTORY).iterdir():
            target = merged / BLOBS_DIRECTORY / source.name
            if not target.exists():
                shutil.copyfile(source, target)
    # Both images first, then each image's attestation manifest, as BuildKit
    # pushes a multi-platform index. The index digest covers all four.
    index = encode({"schemaVersion": 2, "mediaType": INDEX, "manifests": descriptors + attestations})
    index_digest = SHA256_PREFIX + hashlib.sha256(index).hexdigest()
    (merged / BLOBS_DIRECTORY / index_digest.split(":")[1]).write_bytes(index)
    (merged / "oci-layout").write_bytes(encode({"imageLayoutVersion": "1.0.0"}))
    (merged / INDEX_FILE).write_bytes(encode({"schemaVersion": 2, "manifests": [{"mediaType": INDEX, "digest": index_digest,
        "size": len(index), "annotations": {"org.opencontainers.image.ref.name": VERSION}}]}))
    (args.directory / "verified.json").write_bytes(encode({"image_digest": index_digest, "source": selected["source"],
        "tree": selected["tree"], "version": VERSION, "destination": DESTINATION, "manifests": descriptors,
        "attestations": attestations}) + b"\n")


def main() -> None:
    parser = argparse.ArgumentParser(description=__doc__)
    sub = parser.add_subparsers(dest="command", required=True)
    local = sub.add_parser("native")
    for name in ("archive", "sdk", "workflow", "security", "output"):
        local.add_argument("--" + name, type=Path, required=True)
    for name in ("source", "tree", "version", "architecture", "image-id"):
        local.add_argument("--" + name, required=True)
    admission = sub.add_parser("plan")
    admission.add_argument("--directory", type=Path, required=True)
    admission.add_argument("--output", type=Path, required=True)
    for name in ("source", "tree", "runner", "run", "attempt", "publisher", "publisher-hash", "build-hash"):
        admission.add_argument("--" + name, required=True)
    promotion = sub.add_parser("prepare")
    promotion.add_argument("--directory", type=Path, required=True)
    security = sub.add_parser("scan")
    for name in ("report", "database", "output"):
        security.add_argument("--" + name, type=Path, required=True)
    subject = security.add_mutually_exclusive_group(required=True)
    subject.add_argument("--archive", type=Path)
    subject.add_argument("--image")
    security.add_argument("--architecture", choices=("amd64", "arm64"), required=True)
    layout = sub.add_parser("scan-layout")
    layout.add_argument("--archive", type=Path, required=True)
    layout.add_argument("--directory", type=Path, required=True)
    layout.add_argument("--architecture", choices=("amd64", "arm64"), required=True)
    layout.add_argument("--source", required=True)
    args = parser.parse_args()
    {"native": native, "plan": plan, "prepare": prepare, "scan": scan, "scan-layout": scan_layout}[args.command](args)


if __name__ == "__main__":
    try:
        main()
    except (OSError, ValueError, KeyError, TypeError, tarfile.TarError, zipfile.BadZipFile) as error:
        print(f"OCI publication refused: {error}", file=sys.stderr)
        raise SystemExit(1) from None
