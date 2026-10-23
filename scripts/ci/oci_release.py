#!/usr/bin/env python3
"""Verify inert native OCI evidence before the protected publisher can log in."""

from __future__ import annotations

import argparse
import base64
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
VERSION = "2.13.2"
DESTINATION = "docker.io/durableworkflow/waterline:2.13.2"
WORKFLOW = ".github/workflows/service-image.yml"
PUBLISHER = ".github/workflows/service-image-publish.yml"
INDEX = "application/vnd.oci.image.index.v1+json"
MANIFEST = "application/vnd.oci.image.manifest.v1+json"
CONFIG = "application/vnd.oci.image.config.v1+json"
MAX_ARCHIVE = 1024 * 1024 * 1024
MAX_JSON = 1024 * 1024
SHA = re.compile(r"[0-9a-f]{40}", re.ASCII)
DIGEST = re.compile(r"sha256:[0-9a-f]{64}", re.ASCII)
SDK = {"sdk": "3.4.1", "sdk_source": "cb1d11e57767e20e1fdb8081024f67057ff66714"}


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


def document(raw: bytes) -> dict:
    require(len(raw) <= MAX_JSON, "Oversized JSON document")
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
        return "sha256:" + hashlib.file_digest(stream, "sha256").hexdigest()


def positive(value: object) -> int:
    require(isinstance(value, str) and re.fullmatch(r"[1-9][0-9]{0,19}", value, re.ASCII) is not None, "Expected positive decimal identity")
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
                require(name in {"blobs", "blobs/sha256"}, "Unexpected OCI directory")
                (root / name).mkdir(parents=True, exist_ok=True)
                continue
            require(member.isfile(), "OCI links and special files are forbidden")
            require(name in {"index.json", "oci-layout"} or re.fullmatch(r"blobs/sha256/[0-9a-f]{64}", name, re.ASCII) is not None, "Unexpected OCI file")
            total += member.size
            require(0 <= member.size <= MAX_ARCHIVE and total <= MAX_ARCHIVE, "Oversized OCI contents")
            target = root / name
            target.parent.mkdir(parents=True, exist_ok=True)
            stream = source.extractfile(member)
            require(stream is not None, "Missing OCI file content")
            with stream, target.open("xb") as output:
                shutil.copyfileobj(stream, output, 1024 * 1024)
            if name.startswith("blobs/"):
                require(digest_file(target) == "sha256:" + target.name, "OCI blob digest mismatch")


def blob(root: Path, descriptor: dict) -> Path:
    require(isinstance(descriptor, dict) and DIGEST.fullmatch(str(descriptor.get("digest", ""))) is not None, "Unsupported OCI descriptor digest")
    size = descriptor.get("size")
    require(type(size) is int and 0 <= size <= MAX_ARCHIVE and "urls" not in descriptor, "Invalid OCI descriptor size or external URL")
    path = root / "blobs/sha256" / descriptor["digest"].split(":")[1]
    require(path.is_file() and path.stat().st_size == size and digest_file(path) == descriptor["digest"], "Missing or changed OCI descriptor content")
    return path


def inspect_oci(root: Path, architecture: str, source: str) -> tuple[dict, str]:
    require(read_json(root / "oci-layout") == {"imageLayoutVersion": "1.0.0"}, "Unsupported OCI layout")
    index = read_json(root / "index.json")
    descriptor = None
    for _depth in range(3):
        require(index.get("schemaVersion") == 2 and isinstance(index.get("manifests"), list) and len(index["manifests"]) == 1, "Expected one native OCI image")
        descriptor = index["manifests"][0]
        payload = blob(root, descriptor)
        if descriptor.get("mediaType") == MANIFEST:
            break
        require(descriptor.get("mediaType") == INDEX, "Unsupported native OCI media type")
        index = read_json(payload)
    else:
        raise EvidenceError("OCI index nesting exceeds bounds")
    require(descriptor is not None, "Missing native manifest")
    manifest = read_json(payload)
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


def native(args: argparse.Namespace) -> None:
    source, tree = commit(args.source), commit(args.tree)
    require(args.version == VERSION and args.architecture in {"amd64", "arm64"}, "Unsupported bounded release or architecture")
    sdk = read_json(args.sdk)
    require(sdk == SDK, "Native installed SDK identity differs from the approved cohort")
    with tempfile.TemporaryDirectory(prefix="waterline-oci-") as temporary:
        root = Path(temporary) / "layout"
        unpack_oci(args.archive, root)
        descriptor, config_digest = inspect_oci(root, args.architecture, source)
    require(args.image_id == config_digest, "Tested Docker image differs from exported OCI config")
    provenance = {"schema": "waterline.native-oci/v1", "version": VERSION, "source": source, "tree": tree,
                  "architecture": args.architecture, "manifest_digest": descriptor["digest"], "config_digest": config_digest,
                  "archive_digest": digest_file(args.archive), "runner_commit": commit(os.environ["GITHUB_SHA"]),
                  "run_id": positive(os.environ["GITHUB_RUN_ID"]), "run_attempt": positive(os.environ["GITHUB_RUN_ATTEMPT"]),
                  "build_workflow_sha256": digest_file(args.workflow).split(":")[1], **sdk}
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


def plan(args: argparse.Namespace) -> None:
    source, tree, runner = commit(args.source), commit(args.tree), commit(args.runner)
    run_id, attempt = positive(args.run), positive(args.attempt)
    require(os.environ.get("GITHUB_REPOSITORY") == REPOSITORY and os.environ.get("GITHUB_REF") == "refs/heads/main", "Publisher requires the fixed protected main repository")
    require(os.environ.get("GITHUB_SHA") == commit(args.publisher), "Publisher source changed after independent review")
    require(digest_file(Path(PUBLISHER)) == "sha256:" + sha256(args.publisher_hash), "Publisher workflow changed after review")
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
    artifacts = read_json(args.directory / "artifacts.json").get("artifacts", [])
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
    args.output.write_bytes(encode({"source": source, "tree": tree, "source_pull": merged[0]["number"], "qualified_head": merged[0]["head"]["sha"], "runner_commit": runner, "run_id": run_id,
                                  "run_attempt": attempt, "build_workflow_sha256": args.build_hash, "artifacts": chosen}) + b"\n")


def unpack_artifact(path: Path, target: Path, metadata: dict) -> None:
    require(path.stat().st_size == metadata["size_in_bytes"] and digest_file(path) == metadata["digest"], "Actions artifact hash or size mismatch")
    target.mkdir()
    with zipfile.ZipFile(path) as archive:
        members = archive.infolist()
        require(len(members) == 2 and {item.filename for item in members} == {"image.oci.tar", "provenance.json"}, "Unexpected artifact files")
        for member in members:
            limit = MAX_JSON if member.filename == "provenance.json" else MAX_ARCHIVE
            require(0 < member.file_size <= limit and not member.is_dir() and member.flag_bits & 1 == 0, "Invalid artifact member")
            with archive.open(member) as source, (target / member.filename).open("xb") as output:
                shutil.copyfileobj(source, output, 1024 * 1024)


def prepare(args: argparse.Namespace) -> None:
    selected = read_json(args.directory / "plan.json")
    layouts, descriptors = [], []
    for architecture in ("amd64", "arm64"):
        target = args.directory / architecture
        unpack_artifact(args.directory / f"{architecture}.zip", target, selected["artifacts"][architecture])
        provenance = read_json(target / "provenance.json")
        expected = {"schema": "waterline.native-oci/v1", "version": VERSION, "source": selected["source"], "tree": selected["tree"],
                    "architecture": architecture, **{key: selected[key] for key in ("runner_commit", "run_id", "run_attempt", "build_workflow_sha256")}, **SDK}
        require(set(provenance) == set(expected) | {"manifest_digest", "config_digest", "archive_digest"}, "Unexpected provenance fields")
        require(all(provenance.get(key) == value for key, value in expected.items()), "Native provenance differs from reviewed run/cohort")
        archive = target / "image.oci.tar"
        require(digest_file(archive) == provenance["archive_digest"], "Native OCI archive hash mismatch")
        layout = target / "layout"
        unpack_oci(archive, layout)
        descriptor, config_digest = inspect_oci(layout, architecture, selected["source"])
        require(descriptor["digest"] == provenance["manifest_digest"] and config_digest == provenance["config_digest"], "Native manifest/config provenance mismatch")
        layouts.append(layout)
        descriptors.append(descriptor)
    merged = args.directory / "verified-oci"
    (merged / "blobs/sha256").mkdir(parents=True)
    for layout in layouts:
        for source in (layout / "blobs/sha256").iterdir():
            target = merged / "blobs/sha256" / source.name
            if not target.exists():
                shutil.copyfile(source, target)
    index = encode({"schemaVersion": 2, "mediaType": INDEX, "manifests": descriptors})
    index_digest = "sha256:" + hashlib.sha256(index).hexdigest()
    (merged / "blobs/sha256" / index_digest.split(":")[1]).write_bytes(index)
    (merged / "oci-layout").write_bytes(encode({"imageLayoutVersion": "1.0.0"}))
    (merged / "index.json").write_bytes(encode({"schemaVersion": 2, "manifests": [{"mediaType": INDEX, "digest": index_digest,
        "size": len(index), "annotations": {"org.opencontainers.image.ref.name": VERSION}}]}))
    (args.directory / "verified.json").write_bytes(encode({"image_digest": index_digest, "source": selected["source"],
        "tree": selected["tree"], "version": VERSION, "destination": DESTINATION, "manifests": descriptors}) + b"\n")


def main() -> None:
    parser = argparse.ArgumentParser(description=__doc__)
    sub = parser.add_subparsers(dest="command", required=True)
    local = sub.add_parser("native")
    for name in ("archive", "sdk", "workflow", "output"):
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
    args = parser.parse_args()
    {"native": native, "plan": plan, "prepare": prepare}[args.command](args)


if __name__ == "__main__":
    try:
        main()
    except (EvidenceError, OSError, ValueError, KeyError, TypeError, tarfile.TarError, zipfile.BadZipFile) as error:
        print(f"OCI publication refused: {error}", file=sys.stderr)
        raise SystemExit(1) from None
