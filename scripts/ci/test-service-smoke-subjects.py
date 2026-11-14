"""Synthetic OCI data only. No image, package, application or remote input."""

import io
import os
from pathlib import Path
import runpy
import sys
import tarfile
import tempfile
import unittest
from unittest.mock import patch

sys.path.insert(0, str(Path(__file__).resolve().parent))
import service_smoke_subjects as subjects

FIXTURE = runpy.run_path(str(Path(__file__).with_name("test-oci-release.py")), run_name="inert_oci_data")


class HeldArchiveTest(unittest.TestCase):
    def setUp(self):
        self.directory = tempfile.TemporaryDirectory(prefix="waterline-subject-source-")
        self.addCleanup(self.directory.cleanup)
        self.root = Path(self.directory.name)

    def hold(self, path):
        descriptor = os.open(path, os.O_RDONLY | os.O_NOFOLLOW | os.O_CLOEXEC)
        try:
            archive = subjects.HeldArchive(descriptor)
        finally:
            os.close(descriptor)
        self.addCleanup(archive.close)
        return archive

    def native(self, architecture="amd64"):
        return FIXTURE["native_archive"](self.root, architecture)

    def test_existing_oci_verifier_consumes_retained_members_without_extraction(self):
        path, _entries, manifest, config, attestation = self.native()
        before = set(self.root.iterdir())
        archive = self.hold(path)
        security = FIXTURE["security_evidence"](archive.digest, "amd64", config)
        result = archive.native_subject("amd64", FIXTURE["SOURCE"], security)
        self.assertEqual({"archive_digest": archive.digest, "architecture": "amd64", "source": FIXTURE["SOURCE"],
                          "manifest_digest": manifest, "config_digest": config, "attestation_digest": attestation}, result)
        self.assertEqual(before, set(self.root.iterdir()))
        archive.verify_unchanged()

    def test_wrong_source_platform_or_scan_subject_cannot_bind(self):
        path, _entries, _manifest, config, _attestation = self.native()
        archive = self.hold(path)
        security = FIXTURE["security_evidence"](archive.digest, "amd64", config)
        for architecture, source, report in (("arm64", FIXTURE["SOURCE"], security),
                                             ("amd64", "f" * 40, security),
                                             ("amd64", FIXTURE["SOURCE"], {**security, "subject": "sha256:" + "0" * 64})):
            with self.subTest(architecture=architecture, source=source), self.assertRaises(subjects.oci_release.EvidenceError):
                archive.native_subject(architecture, source, report)

    def test_changed_descriptor_bytes_refuse(self):
        path, _entries, _manifest, _config, _attestation = self.native()
        archive = self.hold(path)
        with path.open("r+b") as stream:
            stream.seek(512)
            stream.write(b"changed")
        with self.assertRaises(subjects.SubjectError):
            archive.verify_unchanged()

    def test_name_replacement_cannot_change_the_retained_input(self):
        path, _entries, _manifest, _config, _attestation = self.native()
        archive = self.hold(path)
        original = path.with_name("original.tar")
        path.rename(original)
        path.write_bytes(b"replacement")
        # Renaming an admitted file changes its retained metadata and refuses;
        # the implementation never opens the replacement's pathname.
        with self.assertRaises(subjects.SubjectError):
            archive.verify_unchanged()
        self.assertEqual(b"replacement", path.read_bytes())

    def test_links_extended_headers_and_duplicate_paths_refuse(self):
        for kind in (tarfile.SYMTYPE, tarfile.LNKTYPE, tarfile.XHDTYPE, tarfile.GNUTYPE_LONGNAME):
            path = self.root / "special.tar"
            with tarfile.open(path, "w") as output:
                member = tarfile.TarInfo("index.json")
                member.type, member.linkname = kind, "../unselected"
                output.addfile(member)
            with self.subTest(kind=kind), self.assertRaises(subjects.SubjectError):
                self.hold(path)
        path = self.root / "duplicate.tar"
        with tarfile.open(path, "w") as output:
            for _ in range(2):
                member = tarfile.TarInfo("index.json")
                member.size = 2
                output.addfile(member, io.BytesIO(b"{}"))
        with self.assertRaises(subjects.SubjectError):
            self.hold(path)

    def test_path_traversal_extra_files_and_changed_blob_refuse(self):
        variants = [{"../outside": b"bad"}, {"/absolute": b"bad"}, {"extra": b"bad"},
                    {"blobs/sha256/" + "0" * 64: b"changed"}]
        for index, entries in enumerate(variants):
            path = self.root / (str(index) + ".tar")
            FIXTURE["write_tar"](path, entries)
            with self.subTest(entries=list(entries)), self.assertRaises(subjects.SubjectError):
                self.hold(path)
        self.assertFalse((self.root.parent / "outside").exists())

    def test_truncated_tar_and_nonzero_bytes_after_terminator_refuse(self):
        path, _entries, _manifest, _config, _attestation = self.native()
        raw = path.read_bytes()
        for content in (raw[:600], raw + b"hidden data"):
            path.write_bytes(content)
            with self.subTest(length=len(content)), self.assertRaises(subjects.SubjectError):
                self.hold(path)

    def test_bounds_nonregular_input_and_deadline_refuse(self):
        path, _entries, _manifest, _config, _attestation = self.native()
        with patch.object(subjects, "MAX_ARCHIVE", 1), self.assertRaises(subjects.SubjectError):
            self.hold(path)
        descriptor = os.open(self.root, os.O_RDONLY | os.O_DIRECTORY)
        try:
            with self.assertRaises(subjects.SubjectError):
                subjects.HeldArchive(descriptor)
        finally:
            os.close(descriptor)
        archive = self.hold(path)
        archive.deadline -= 31
        with self.assertRaises(subjects.SubjectError):
            archive.check_read()
        # Holding an input during bounded application phases does not consume
        # the independent 30-second final byte-validation window.
        archive.verify_unchanged()


if __name__ == "__main__":
    unittest.main()
