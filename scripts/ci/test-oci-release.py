#!/usr/bin/env python3
"""Counterfactuals for the inert OCI and protected-source publication boundary."""

import argparse
import base64
import copy
import hashlib
import importlib.util
import io
import json
import os
from pathlib import Path
import tarfile
import tempfile
import unittest
from unittest.mock import patch
import zipfile

spec = importlib.util.spec_from_file_location("oci_release", Path(__file__).with_name("oci_release.py"))
oci = importlib.util.module_from_spec(spec)
spec.loader.exec_module(oci)
SOURCE = "a" * 40
TREE = "b" * 40
RUNNER = "c" * 40
PUBLISHER = "d" * 40


def blob(value):
    raw = value if isinstance(value, bytes) else oci.encode(value)
    return "sha256:" + hashlib.sha256(raw).hexdigest(), raw


def native_archive(directory, architecture, source=SOURCE, mutate=None):
    """Write BuildKit's single-platform export: a nested index with one image and its attestations."""
    layer_digest, layer = blob(b"opaque-compressed-layer")
    config_digest, config = blob({"os": "linux", "architecture": architecture, "config": {"Labels": {
        "org.opencontainers.image.revision": source, "dev.durable-workflow.release.tag": oci.VERSION}}})
    manifest_digest, manifest = blob({"schemaVersion": 2, "mediaType": oci.MANIFEST,
        "config": {"mediaType": oci.CONFIG, "digest": config_digest, "size": len(config)},
        "layers": [{"mediaType": "application/vnd.oci.image.layer.v1.tar+gzip", "digest": layer_digest, "size": len(layer)}]})
    parts = {
        "provenance": {"_type": oci.STATEMENT, "predicateType": oci.SLSA_PROVENANCE, "subject": [], "predicate": {
            "buildDefinition": {"externalParameters": {"request": {"args": {
                "build-arg:SOURCE_COMMIT": source, "build-arg:WATERLINE_VERSION": oci.VERSION}}}}}},
        "sbom": {"_type": oci.STATEMENT, "predicateType": oci.SPDX,
                 "subject": [{"name": "image", "digest": {"sha256": manifest_digest.split(":")[1]}}], "predicate": {"spdxVersion": "SPDX-2.3"}},
    }
    image_descriptor = {"mediaType": oci.MANIFEST, "digest": manifest_digest, "size": len(manifest),
                        "platform": {"architecture": architecture, "os": "linux"}}
    parts["image_descriptor"] = image_descriptor
    if mutate:
        mutate(parts)
    provenance_digest, provenance = blob(parts["provenance"])
    sbom_digest, sbom = blob(parts["sbom"])
    empty_digest, empty = blob(b"{}")
    attestation = {"schemaVersion": 2, "mediaType": oci.MANIFEST, "artifactType": oci.ATTESTATION_ARTIFACT,
        "config": {"mediaType": oci.EMPTY_CONFIG, "digest": empty_digest, "size": len(empty)},
        "layers": [
            {"mediaType": oci.IN_TOTO, "digest": sbom_digest, "size": len(sbom), "annotations": {oci.PREDICATE_TYPE: oci.SPDX}},
            {"mediaType": oci.IN_TOTO, "digest": provenance_digest, "size": len(provenance), "annotations": {oci.PREDICATE_TYPE: oci.SLSA_PROVENANCE}},
        ],
        "subject": {"mediaType": oci.MANIFEST, "digest": manifest_digest, "size": len(manifest)}}
    parts["attestation"] = attestation
    if mutate:
        mutate(parts)
    attestation_digest, attestation_raw = blob(parts["attestation"])
    attestation_descriptor = {"mediaType": oci.MANIFEST, "digest": attestation_digest, "size": len(attestation_raw),
        "annotations": {oci.REFERENCE_DIGEST: manifest_digest, oci.REFERENCE_TYPE: "attestation-manifest"},
        "platform": {"architecture": "unknown", "os": "unknown"}}
    parts["attestation_descriptor"] = attestation_descriptor
    parts["manifests"] = [parts["image_descriptor"], attestation_descriptor]
    if mutate:
        mutate(parts)
    nested_digest, nested = blob({"schemaVersion": 2, "mediaType": oci.INDEX, "manifests": parts["manifests"]})
    entries = {"oci-layout": oci.encode({"imageLayoutVersion": "1.0.0"}),
        "index.json": oci.encode({"schemaVersion": 2, "mediaType": oci.INDEX, "manifests": [{"mediaType": oci.INDEX, "digest": nested_digest, "size": len(nested)}]})}
    for digest, raw in [(layer_digest, layer), (config_digest, config), (manifest_digest, manifest), (provenance_digest, provenance),
                        (sbom_digest, sbom), (empty_digest, empty), (attestation_digest, attestation_raw), (nested_digest, nested)]:
        entries["blobs/sha256/" + digest.split(":")[1]] = raw
    path = directory / f"{architecture}.oci.tar"
    write_tar(path, entries)
    return path, entries, manifest_digest, config_digest, attestation_digest


def write_tar(path, entries):
    with tarfile.open(path, "w") as archive:
        for name, raw in entries.items():
            info = tarfile.TarInfo(name)
            info.size = len(raw)
            archive.addfile(info, io.BytesIO(raw))


def security_evidence(subject, architecture, config):
    return {"schema": "waterline.image-security/v1", "status": "pass", "subject": subject,
            "architecture": architecture, "config_digest": config,
            "scanner_image": oci.read_json(oci.SECURITY_POLICY)["scanner_image"],
            "findings": [], "high_critical_count": 0, "inventory": {"alpine": 45},
            "database": {"Version": 2, "UpdatedAt": oci.datetime.now(oci.timezone.utc).isoformat()}}


class OciReleaseTest(unittest.TestCase):
    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory()
        self.addCleanup(self.temporary.cleanup)
        self.root = Path(self.temporary.name)

    def test_actual_archive_hashes_and_native_config_bind_source(self):
        archive, _entries, manifest_digest, config_digest, attestation_digest = native_archive(self.root, "arm64")
        layout = self.root / "layout"
        oci.unpack_oci(archive, layout)
        descriptor, actual_config, attestation = oci.inspect_oci(layout, "arm64", SOURCE)
        self.assertEqual(descriptor["digest"], manifest_digest)
        self.assertEqual(actual_config, config_digest)
        self.assertEqual(attestation["digest"], attestation_digest)
        self.assertEqual(attestation["annotations"][oci.REFERENCE_DIGEST], manifest_digest)
        for architecture, source in [("amd64", SOURCE), ("arm64", "f" * 40)]:
            with self.assertRaises(oci.EvidenceError):
                oci.inspect_oci(layout, architecture, source)

    def test_tar_paths_links_duplicates_and_changed_blobs_are_refused(self):
        _archive, entries, _manifest, _config, _attestation = native_archive(self.root, "amd64")
        attacks = [{"../outside": b"bad"}, {"/absolute": b"bad"}, {"blobs/sha256/" + "0" * 64: b"changed"}]
        for index, attack in enumerate(attacks):
            path = self.root / f"attack-{index}.tar"
            write_tar(path, attack)
            with self.assertRaises(oci.EvidenceError):
                oci.unpack_oci(path, self.root / f"bad-{index}")
        for link in (tarfile.SYMTYPE, tarfile.LNKTYPE):
            path = self.root / f"link-{link!r}.tar"
            with tarfile.open(path, "w") as archive:
                info = tarfile.TarInfo("index.json")
                info.type, info.linkname = link, "../outside"
                archive.addfile(info)
            with self.assertRaises(oci.EvidenceError):
                oci.unpack_oci(path, self.root / f"link-{link!r}")
        duplicate = self.root / "duplicate.tar"
        with tarfile.open(duplicate, "w") as archive:
            for _ in range(2):
                info = tarfile.TarInfo("index.json")
                info.size = 2
                archive.addfile(info, io.BytesIO(b"{}"))
        with self.assertRaises(oci.EvidenceError):
            oci.unpack_oci(duplicate, self.root / "duplicate")
        self.assertFalse((self.root.parent / "outside").exists())

    def test_descriptor_size_external_url_missing_blob_and_oversize_refuse(self):
        archive, _entries, _manifest, _config, _attestation = native_archive(self.root, "amd64")
        layout = self.root / "layout"
        oci.unpack_oci(archive, layout)
        descriptor = oci.read_json(layout / "index.json")["manifests"][0]
        for mutation in [{"size": True}, {"size": descriptor["size"] + 1}, {"urls": ["https://invalid.test/blob"]}, {"digest": "sha256:" + "0" * 64}]:
            with self.assertRaises(oci.EvidenceError):
                oci.blob(layout, {**descriptor, **mutation})
        with patch.object(oci, "MAX_ARCHIVE", 1), self.assertRaises(oci.EvidenceError):
            oci.unpack_oci(archive, self.root / "too-large")

    def test_duplicate_or_nonfinite_json_is_not_data_authority(self):
        for raw in [b'{"a":1,"a":2}', b'{"number":NaN}', b'[]']:
            with self.assertRaises(oci.EvidenceError):
                oci.document(raw)

    def plan_fixture(self):
        workflow_bytes = b"reviewed unprivileged build workflow\n"
        run_id, attempt = 12345, 1
        data = {
            "tag-ref.json": {"ref": "refs/tags/2.14.0", "object": {"type": "tag", "sha": "e" * 40}},
            "tag-object.json": {"sha": "e" * 40, "tag": "2.14.0", "object": {"type": "commit", "sha": SOURCE}},
            "commit.json": {"sha": SOURCE, "commit": {"tree": {"sha": TREE}}},
            "compare.json": {"status": "ahead", "merge_base_commit": {"sha": SOURCE}},
            "pulls.json": {"pulls": [{"number": 246, "merge_commit_sha": SOURCE, "merged_at": "2026-10-23T00:00:00Z", "state": "closed",
                "base": {"ref": "main", "repo": {"full_name": oci.REPOSITORY}}, "head": {"sha": "e" * 40}}]},
            "workflow.json": {"id": 10, "path": oci.WORKFLOW},
            "run.json": {"id": run_id, "run_attempt": attempt, "status": "completed", "conclusion": "success",
                "repository": {"full_name": oci.REPOSITORY}, "head_repository": {"full_name": oci.REPOSITORY},
                "workflow_id": 10, "path": oci.WORKFLOW, "head_sha": RUNNER, "event": "workflow_dispatch", "head_branch": "main"},
            "build-workflow.json": {"encoding": "base64", "size": len(workflow_bytes), "content": base64.b64encode(workflow_bytes).decode()},
            "artifacts.json": {"artifacts": [{"id": index + 20, "name": f"waterline-oci-{arch}-{run_id}-{attempt}",
                "size_in_bytes": 100, "expired": False, "digest": "sha256:" + "f" * 64} for index, arch in enumerate(("amd64", "arm64"))]},
        }
        publisher = self.root / "publisher.yml"
        publisher.write_bytes(b"reviewed protected publisher\n")
        args = argparse.Namespace(directory=self.root, output=self.root / "plan.json", source=SOURCE, tree=TREE,
            runner=RUNNER, run=str(run_id), attempt=str(attempt), publisher=PUBLISHER,
            publisher_hash=oci.digest_file(publisher).split(":")[1], build_hash=hashlib.sha256(workflow_bytes).hexdigest())
        return data, publisher, args

    def write_documents(self, data):
        for name, value in data.items():
            (self.root / name).write_bytes(oci.encode(value))

    def admit(self, data, publisher, args, **environment):
        self.write_documents(data)
        values = {"GITHUB_REPOSITORY": oci.REPOSITORY, "GITHUB_REF": "refs/heads/main", "GITHUB_SHA": PUBLISHER, **environment}
        with patch.dict(os.environ, values), patch.object(oci, "PUBLISHER", str(publisher)):
            oci.plan(args)

    def test_reviewed_main_recovery_and_exact_tag_runs_are_admitted(self):
        data, publisher, args = self.plan_fixture()
        self.admit(data, publisher, args)
        self.assertEqual(set(oci.read_json(args.output)["artifacts"]), {"amd64", "arm64"})
        data["run.json"].update(event="push", head_branch=oci.VERSION, head_sha=SOURCE)
        args.runner = SOURCE
        self.admit(data, publisher, args)

    def test_wrong_ref_issuer_repository_run_attempt_head_and_hash_refuse(self):
        original, publisher, args = self.plan_fixture()
        for key, value in [("GITHUB_REF", "refs/pull/1/merge"), ("GITHUB_REF", "refs/heads/candidate"), ("GITHUB_REPOSITORY", "other/waterline"), ("GITHUB_SHA", "f" * 40)]:
            with self.subTest(context=key), self.assertRaises(oci.EvidenceError):
                self.admit(original, publisher, args, **{key: value})
        mutations = [
            ("run.json", "conclusion", "failure"), ("run.json", "status", "in_progress"),
            ("run.json", "run_attempt", 2), ("run.json", "head_sha", "f" * 40),
            ("run.json", "workflow_id", 99), ("run.json", "head_branch", "candidate"),
            ("run.json", "event", "pull_request"), ("run.json", "path", "other.yml"),
            ("build-workflow.json", "content", base64.b64encode(b"changed").decode()),
        ]
        for name, key, value in mutations:
            data = copy.deepcopy(original)
            data[name][key] = value
            with self.subTest(field=key), self.assertRaises(oci.EvidenceError):
                self.admit(data, publisher, args)
        for invalid in ["../42", "1/other", "1e3", "０", "1０", "0", "-1"]:
            bad = copy.copy(args)
            bad.run = invalid
            with self.subTest(run=invalid), self.assertRaises(oci.EvidenceError):
                self.admit(original, publisher, bad)

    def test_moved_tag_tree_missing_expired_duplicate_and_unhashed_artifact_refuse(self):
        original, publisher, args = self.plan_fixture()
        changes = [
            lambda d: d["tag-object.json"]["object"].update(sha="f" * 40),
            lambda d: d["commit.json"]["commit"]["tree"].update(sha="f" * 40),
            lambda d: d["compare.json"].update(status="diverged"),
            lambda d: d["compare.json"]["merge_base_commit"].update(sha="f" * 40),
            lambda d: d["pulls.json"]["pulls"][0].update(merged_at=None),
            lambda d: d["artifacts.json"]["artifacts"].pop(),
            lambda d: d["artifacts.json"]["artifacts"].append(d["artifacts.json"]["artifacts"][0]),
            lambda d: d["artifacts.json"]["artifacts"][0].update(expired=True),
            lambda d: d["artifacts.json"]["artifacts"][0].update(digest=None),
            lambda d: d["artifacts.json"]["artifacts"][0].update(size_in_bytes=oci.MAX_ARCHIVE * 2),
        ]
        for change in changes:
            data = copy.deepcopy(original)
            change(data)
            with self.assertRaises(oci.EvidenceError):
                self.admit(data, publisher, args)

    def artifact_fixture(self):
        selected = {"source": SOURCE, "tree": TREE, "runner_commit": RUNNER, "run_id": 12345,
                    "run_attempt": 1, "build_workflow_sha256": "f" * 64, "artifacts": {}}
        for architecture in ("amd64", "arm64"):
            archive, _entries, manifest, config, attestation = native_archive(self.root, architecture)
            provenance = {"schema": "waterline.native-oci/v2", "version": oci.VERSION, "source": SOURCE, "tree": TREE,
                "architecture": architecture, "runner_commit": RUNNER, "run_id": 12345, "run_attempt": 1,
                "build_workflow_sha256": "f" * 64, "manifest_digest": manifest, "config_digest": config,
                "attestation_digest": attestation,
                "archive_digest": oci.digest_file(archive),
                "image_security": security_evidence(oci.digest_file(archive), architecture, config), **oci.SDK}
            target = self.root / f"{architecture}.zip"
            with zipfile.ZipFile(target, "w") as zipped:
                zipped.write(archive, "image.oci.tar")
                zipped.writestr("provenance.json", oci.encode(provenance))
            selected["artifacts"][architecture] = {"id": 20, "digest": oci.digest_file(target), "size_in_bytes": target.stat().st_size}
        (self.root / "plan.json").write_bytes(oci.encode(selected))
        return selected

    def test_two_verified_native_manifests_form_one_digest_preserving_index(self):
        self.artifact_fixture()
        oci.prepare(argparse.Namespace(directory=self.root))
        evidence = oci.read_json(self.root / "verified.json")
        self.assertEqual(evidence["destination"], "docker.io/durableworkflow/waterline:2.14.0")
        self.assertEqual([item["platform"]["architecture"] for item in evidence["manifests"]], ["amd64", "arm64"])
        self.assertEqual([item["annotations"][oci.REFERENCE_DIGEST] for item in evidence["attestations"]],
                         [item["digest"] for item in evidence["manifests"]])
        root = self.root / "verified-oci"
        descriptor = oci.read_json(root / "index.json")["manifests"][0]
        self.assertEqual(oci.digest_file(oci.blob(root, descriptor)), evidence["image_digest"])
        published = oci.read_json(oci.blob(root, descriptor))["manifests"]
        self.assertEqual(published, evidence["manifests"] + evidence["attestations"])
        for item in published:
            oci.blob(root, item)

    def test_changed_zip_or_candidate_commands_cannot_reach_verified_output(self):
        selected = self.artifact_fixture()
        archive = self.root / "amd64.zip"
        archive.write_bytes(archive.read_bytes() + b"changed")
        arguments = argparse.Namespace(directory=self.root)
        with self.assertRaises(oci.EvidenceError):
            oci.prepare(arguments)
        self.assertFalse((self.root / "verified.json").exists())
        attack = self.root / "commands.zip"
        with zipfile.ZipFile(attack, "w") as zipped:
            zipped.writestr("../command.sh", b"exit 0")
            zipped.writestr("provenance.json", b"{}")
        metadata = {"digest": oci.digest_file(attack), "size_in_bytes": attack.stat().st_size}
        with self.assertRaises(oci.EvidenceError):
            oci.unpack_artifact(attack, self.root / "commands", metadata)

    def test_security_requires_exact_subject_platform_scanner_and_fresh_database(self):
        original = security_evidence("sha256:" + "a" * 64, "amd64", "sha256:" + "b" * 64)
        oci.validate_image_security(original, original["subject"], "amd64", original["config_digest"])
        for change in [{"subject": "sha256:" + "c" * 64}, {"architecture": "arm64"},
                       {"config_digest": "sha256:" + "c" * 64}, {"scanner_image": "unreviewed:latest"},
                       {"status": "fail"}, {"findings": [{"Severity": "HIGH"}]},
                       {"high_critical_count": 1}, {"inventory": {}},
                       {"database": {"Version": 2, "UpdatedAt": "2026-01-01T00:00:00Z"}}]:
            with self.subTest(change=change), self.assertRaises(oci.EvidenceError):
                oci.validate_image_security({**original, **change}, original["subject"], "amd64", original["config_digest"])

    def test_scan_retains_compact_inventory_and_refuses_a_fixed_high(self):
        archive, _entries, _manifest, config, _attestation = native_archive(self.root, "amd64")
        report = {"SchemaVersion": 2, "ArtifactType": "container_image", "Metadata": {
            "OS": {"Family": "alpine", "Name": "3.24.3"}, "ImageID": config,
            "ImageConfig": {"architecture": "amd64"}}, "Results": [{"Type": "alpine", "Packages": [{"Name": "curl"}]}]}
        report_path, database, output = self.root / "scan.json", self.root / "db.json", self.root / "summary.json"
        database.write_bytes(oci.encode(security_evidence("unused", "amd64", config)["database"]))
        args = argparse.Namespace(report=report_path, database=database, archive=archive,
            image=None, architecture="amd64", output=output)
        report_path.write_bytes(oci.encode(report))
        oci.scan(args)
        self.assertEqual(oci.read_json(output)["inventory"], {"alpine": 1})
        outside = self.root.parent / (self.root.name + "-report-target")
        report["Metadata"]["OS"]["Name"] = "../" + outside.name
        report_path.write_bytes(oci.encode(report))
        oci.scan(args)
        self.assertEqual(oci.read_json(output)["os"]["Name"], "../" + outside.name)
        self.assertFalse(outside.exists())
        report["Results"][0]["Vulnerabilities"] = [{"VulnerabilityID": "CVE-test", "PkgName": "curl",
            "InstalledVersion": "8.22.0", "FixedVersion": "8.23.0", "Severity": "HIGH"}]
        report_path.write_bytes(oci.encode(report))
        with self.assertRaises(oci.EvidenceError):
            oci.scan(args)
        self.assertEqual(oci.read_json(output)["status"], "fail")
        self.assertEqual(oci.read_json(output)["high_critical_count"], 1)

    def test_missing_scan_cannot_reach_verified_publication_output(self):
        selected = self.artifact_fixture()
        path = self.root / "amd64.zip"
        with zipfile.ZipFile(path) as archive:
            raw = archive.read("image.oci.tar")
            provenance = oci.document(archive.read("provenance.json"))
        del provenance["image_security"]
        with zipfile.ZipFile(path, "w") as archive:
            archive.writestr("image.oci.tar", raw)
            archive.writestr("provenance.json", oci.encode(provenance))
        selected["artifacts"]["amd64"].update(digest=oci.digest_file(path), size_in_bytes=path.stat().st_size)
        (self.root / "plan.json").write_bytes(oci.encode(selected))
        arguments = argparse.Namespace(directory=self.root)
        with self.assertRaises(oci.EvidenceError):
            oci.prepare(arguments)
        self.assertFalse((self.root / "verified.json").exists())

    def assert_layout_refused(self, mutate, message=None):
        directory = Path(tempfile.mkdtemp(dir=self.root))
        archive, *_ = native_archive(directory, "amd64", mutate=mutate)
        layout = directory / "layout"
        oci.unpack_oci(archive, layout)
        with self.assertRaises(oci.EvidenceError) as raised:
            oci.inspect_oci(layout, "amd64", SOURCE)
        if message:
            self.assertIn(message, str(raised.exception))

    def test_attestations_must_be_exactly_one_bound_provenance_and_sbom_per_image(self):
        other = "sha256:" + "0" * 64
        image = lambda parts: parts.get("image_descriptor")
        cases = [
            ("missing attestation", lambda p: "manifests" in p and p.update(manifests=[p["image_descriptor"]]), "exactly one attestation"),
            ("second attestation", lambda p: "manifests" in p and p.update(manifests=p["manifests"] + [p["attestation_descriptor"]]), "exactly one attestation"),
            ("extra image entry", lambda p: "manifests" in p and p.update(manifests=p["manifests"] + [p["image_descriptor"]]), "exactly one attestation"),
            ("reference to another image", lambda p: "manifests" in p and p["attestation_descriptor"]["annotations"].update({oci.REFERENCE_DIGEST: other}), "does not reference"),
            ("attestation platform", lambda p: "manifests" in p and p["attestation_descriptor"].update(platform={"architecture": "amd64", "os": "linux"}), "does not reference"),
            ("image platform", lambda p: "manifests" in p and image(p).update(platform={"architecture": "arm64", "os": "linux"}), "platform mismatch"),
            ("manifest subject", lambda p: "attestation" in p and "manifests" not in p and p["attestation"]["subject"].update(digest=other), "subject is not"),
            ("artifact type", lambda p: "attestation" in p and "manifests" not in p and p["attestation"].update(artifactType="application/example"), "Invalid attestation"),
            ("missing sbom", lambda p: "attestation" in p and "manifests" not in p and p["attestation"].update(layers=p["attestation"]["layers"][1:]), "provenance and SBOM"),
            ("duplicate provenance", lambda p: "attestation" in p and "manifests" not in p and p["attestation"].update(layers=[p["attestation"]["layers"][1]] * 2), "Unexpected attestation layer"),
            ("unknown predicate", lambda p: "attestation" in p and "manifests" not in p and p["attestation"]["layers"][0]["annotations"].update({oci.PREDICATE_TYPE: "https://example.test/x"}), "Unexpected attestation layer"),
            ("statement subject", lambda p: "attestation" not in p and p["sbom"]["subject"][0].update(digest={"sha256": "0" * 64}), "subject is not"),
            ("statement type", lambda p: "attestation" not in p and p["provenance"].update(predicateType=oci.SPDX), "Invalid in-toto"),
            ("source argument", lambda p: "attestation" not in p and p["provenance"]["predicate"]["buildDefinition"]["externalParameters"]["request"]["args"].update({"build-arg:SOURCE_COMMIT": "f" * 40}), "does not bind"),
            ("version argument", lambda p: "attestation" not in p and p["provenance"]["predicate"]["buildDefinition"]["externalParameters"]["request"]["args"].pop("build-arg:WATERLINE_VERSION"), "does not bind"),
            ("non-object build definition", lambda p: "attestation" not in p and p["provenance"]["predicate"].update(buildDefinition=["not", "an", "object"]), "does not bind"),
            ("non-object request", lambda p: "attestation" not in p and p["provenance"]["predicate"]["buildDefinition"]["externalParameters"].update(request="text"), "does not bind"),
        ]
        for name, mutate, message in cases:
            with self.subTest(case=name):
                self.assert_layout_refused(mutate, message)

    def test_attestation_layers_above_the_cap_are_refused(self):
        archive, *_ = native_archive(self.root, "amd64")
        layout = self.root / "layout"
        oci.unpack_oci(archive, layout)
        oci.inspect_oci(layout, "amd64", SOURCE)
        with patch.object(oci, "MAX_ATTESTATION", 64), self.assertRaisesRegex(oci.EvidenceError, "Oversized attestation"):
            oci.inspect_oci(layout, "amd64", SOURCE)

    def test_changed_attestation_cannot_reach_verified_publication_output(self):
        selected = self.artifact_fixture()
        path = self.root / "arm64.zip"
        with zipfile.ZipFile(path) as archive:
            raw = archive.read("image.oci.tar")
            provenance = oci.document(archive.read("provenance.json"))
        provenance["attestation_digest"] = "sha256:" + "0" * 64
        with zipfile.ZipFile(path, "w") as archive:
            archive.writestr("image.oci.tar", raw)
            archive.writestr("provenance.json", oci.encode(provenance))
        selected["artifacts"]["arm64"].update(digest=oci.digest_file(path), size_in_bytes=path.stat().st_size)
        (self.root / "plan.json").write_bytes(oci.encode(selected))
        with self.assertRaisesRegex(oci.EvidenceError, "attestation provenance mismatch"):
            oci.prepare(argparse.Namespace(directory=self.root))
        self.assertFalse((self.root / "verified.json").exists())


if __name__ == "__main__":
    unittest.main()
