"""Unwired held-byte inputs for ordinary native artifact acceptance.

The ordinary OCI verifier judges the manifests. This reader supplies bounded
regular members through one retained archive descriptor without extraction.
"""

from __future__ import annotations

import hashlib
import io
import os
from pathlib import Path, PurePosixPath
import re
import stat
import tarfile
import time
from types import SimpleNamespace

import oci_release
from service_smoke_io import bounded_process, trusted_parent


BLOCK_SIZE = tarfile.BLOCKSIZE
MAX_ARCHIVE = 1024 * 1024 * 1024
MAX_DOCUMENT = 32 * 1024 * 1024


class SubjectError(RuntimeError):
    """An artifact does not have the selected immutable input identity."""


def directory_identity(value: os.stat_result) -> tuple:
    return value.st_dev, value.st_ino, value.st_mode, value.st_uid, value.st_gid


class GitInputs:
    """Capture local immutable Git objects, never source files from later paths."""

    def __init__(self, repository: Path, commit: str):
        self.commit = oci_release.commit(commit)
        self.repository = repository
        self.descriptor = trusted_parent(repository)
        self.identity = directory_identity(os.fstat(self.descriptor))
        try:
            raw = self.command(["rev-parse", "--verify", self.commit + "^{tree}"]).stdout
            if re.fullmatch(rb"[a-f0-9]{40}\n", raw) is None:
                raise SubjectError("Source commit did not resolve to one immutable tree")
            self.tree = raw.decode().rstrip("\n")
        except BaseException:
            self.close()
            raise

    def check(self):
        current = trusted_parent(self.repository)
        try:
            if directory_identity(os.fstat(current)) != self.identity or directory_identity(os.fstat(self.descriptor)) != self.identity:
                raise SubjectError("Source repository namespace changed")
        finally:
            os.close(current)

    def command(self, arguments: list[str], limit: int = 1024 * 1024):
        self.check()
        result = bounded_process(
            ["/usr/bin/git", "--no-replace-objects", "-C", "/proc/self/fd/" + str(self.descriptor), *arguments],
            pass_fds=(self.descriptor,), stdout_limit=limit,
        )
        self.check()
        if result.returncode != 0 or result.stderr:
            raise SubjectError("Local immutable source read failed")
        return result

    def read(self, path: str) -> bytes:
        if not isinstance(path, str):
            raise SubjectError("Unselected immutable source path")
        name = PurePosixPath(path)
        if (name.is_absolute() or ".." in name.parts
                or str(name) != path or not name.parts):
            raise SubjectError("Unselected immutable source path")
        specification = self.commit + ":" + path
        size = self.command(["cat-file", "-s", specification], limit=64).stdout
        if re.fullmatch(rb"(?:0|[1-9][0-9]{0,6})\n", size) is None or int(size) > 1024 * 1024:
            raise SubjectError("Immutable source blob exceeds its bound")
        raw = self.command(["cat-file", "blob", specification]).stdout
        if len(raw) != int(size):
            raise SubjectError("Immutable source blob length changed")
        return raw

    def close(self):
        if self.descriptor >= 0:
            os.close(self.descriptor)
            self.descriptor = -1


def file_identity(value: os.stat_result) -> tuple:
    return tuple(getattr(value, key) for key in (
        "st_dev", "st_ino", "st_mode", "st_uid", "st_gid", "st_nlink", "st_size", "st_mtime_ns", "st_ctime_ns",
    ))


class MemberReader(io.RawIOBase):
    def __init__(self, archive, offset: int, size: int):
        super().__init__()
        self.archive, self.offset, self.size, self.position = archive, offset, size, 0

    def readable(self):
        return True

    def readinto(self, buffer):
        self.archive.check_read()
        count = min(len(buffer), self.size - self.position, 1024 * 1024)
        if count == 0:
            return 0
        raw = os.pread(self.archive.descriptor, count, self.offset + self.position)
        if len(raw) != count:
            raise SubjectError("Truncated retained archive member")
        buffer[:count] = raw
        self.position += count
        return count


class ArchivePath:
    """Small read-only path interface used by the existing inert OCI verifier."""

    def __init__(self, archive, name: str = ""):
        self.archive, self.member_name = archive, name

    def __truediv__(self, component: str):
        path = PurePosixPath(self.member_name) / component
        if path.is_absolute() or ".." in path.parts:
            raise SubjectError("Archive member escaped its selected root")
        return ArchivePath(self.archive, str(path))

    @property
    def name(self):
        return PurePosixPath(self.member_name).name

    def is_file(self):
        return self.member_name in self.archive.members

    def stat(self):
        self.archive.check()
        return SimpleNamespace(st_size=self.archive.members[self.member_name][1])

    def open(self, mode="rb"):
        if mode != "rb":
            raise SubjectError("Archive members are read only")
        self.archive.check()
        offset, size = self.archive.members[self.member_name]
        return io.BufferedReader(MemberReader(self.archive, offset, size), buffer_size=65536)

    def read_bytes(self):
        if self.stat().st_size > MAX_DOCUMENT:
            raise SubjectError("Archive document exceeds its bound")
        with self.open() as stream:
            return stream.read()


class HeldArchive:
    def __init__(self, descriptor: int):
        # Duplicate the already no-follow captured input; never reopen its name.
        self.descriptor = os.dup(descriptor)
        self.deadline = time.monotonic() + 30
        self.members: dict[str, tuple[int, int]] = {}
        try:
            metadata = os.fstat(self.descriptor)
            if (not stat.S_ISREG(metadata.st_mode) or metadata.st_nlink != 1
                    or metadata.st_uid not in {0, os.getuid()} or metadata.st_mode & 0o022
                    or not 0 < metadata.st_size <= MAX_ARCHIVE):
                raise SubjectError("OCI input must be a bounded owned regular descriptor")
            self.identity = file_identity(metadata)
            self.size = metadata.st_size
            self.digest = self.hash_range(0, self.size)
            self.scan_members()
            self.check()
        except BaseException:
            self.close()
            raise

    def check(self):
        if file_identity(os.fstat(self.descriptor)) != self.identity:
            raise SubjectError("Retained OCI input changed")

    def check_read(self):
        self.check()
        if time.monotonic() >= self.deadline:
            raise SubjectError("OCI input validation deadline exceeded")

    def hash_range(self, offset: int, size: int) -> str:
        digest = hashlib.sha256()
        end = offset + size
        while offset < end:
            self.check_read()
            raw = os.pread(self.descriptor, min(1024 * 1024, end - offset), offset)
            if not raw:
                raise SubjectError("Truncated OCI input")
            digest.update(raw)
            offset += len(raw)
        return "sha256:" + digest.hexdigest()

    def check_padding(self, offset: int):
        # Reject concatenated/hidden archives after the two standard zero blocks.
        if self.size - offset < 2 * BLOCK_SIZE:
            raise SubjectError("Missing OCI tar terminator")
        while offset < self.size:
            self.check_read()
            raw = os.pread(self.descriptor, min(65536, self.size - offset), offset)
            if not raw or any(raw):
                raise SubjectError("Unexpected data after OCI tar terminator")
            offset += len(raw)

    def read_header(self, offset: int):
        raw = os.pread(self.descriptor, BLOCK_SIZE, offset)
        if len(raw) != BLOCK_SIZE:
            raise SubjectError("Truncated OCI tar header")
        if raw == b"\0" * BLOCK_SIZE:
            return None
        try:
            member = tarfile.TarInfo.frombuf(raw, encoding="utf-8", errors="strict")
        except (tarfile.HeaderError, UnicodeError, ValueError) as error:
            raise SubjectError("Invalid OCI tar header") from error
        if member.type not in {tarfile.REGTYPE, tarfile.AREGTYPE, tarfile.DIRTYPE}:
            raise SubjectError("OCI links, extended headers and special files are forbidden")
        return member

    def scan_members(self):
        offset, count, total = 0, 0, 0
        seen = set()
        while offset < self.size:
            self.check_read()
            member = self.read_header(offset)
            if member is None:
                self.check_padding(offset)
                return
            count += 1
            if count > 2048:
                raise SubjectError("Too many OCI tar members")
            name = member.name.removeprefix("./").rstrip("/")
            path = PurePosixPath(name)
            if path.is_absolute() or ".." in path.parts or name in seen:
                raise SubjectError("Unsafe or duplicate OCI member")
            seen.add(name)
            data_offset = offset + BLOCK_SIZE
            if member.isdir():
                if name not in {"blobs", "blobs/sha256"} or member.size != 0:
                    raise SubjectError("Unselected OCI directory")
            else:
                total += member.size
                if not 0 <= member.size <= MAX_ARCHIVE or total > MAX_ARCHIVE or data_offset + member.size > self.size:
                    raise SubjectError("Oversized or truncated OCI member")
                self.capture_member(name, data_offset, member.size)
            offset = data_offset + ((member.size + BLOCK_SIZE - 1) // BLOCK_SIZE) * BLOCK_SIZE
        raise SubjectError("Missing OCI tar terminator")

    def capture_member(self, name: str, offset: int, size: int):
        if name not in {"oci-layout", "index.json"} and re.fullmatch(r"blobs/sha256/[a-f\d]{64}", name, re.ASCII) is None:
            raise SubjectError("Unselected OCI tar file")
        if name.startswith("blobs/") and self.hash_range(offset, size) != "sha256:" + name.rsplit("/", 1)[1]:
            raise SubjectError("OCI blob bytes differ from their content address")
        self.members[name] = (offset, size)

    def native_subject(self, architecture: str, source: str, security: dict) -> dict:
        if architecture not in {"amd64", "arm64"}:
            raise SubjectError("Unselected native artifact platform")
        source = oci_release.commit(source)
        self.deadline = time.monotonic() + 30
        descriptor, config, attestation = oci_release.inspect_oci(ArchivePath(self), architecture, source)
        oci_release.validate_image_security(security, self.digest, architecture, config)
        self.check()
        return {"archive_digest": self.digest, "architecture": architecture, "source": source,
                "manifest_digest": descriptor["digest"], "config_digest": config,
                "attestation_digest": attestation["digest"]}

    def verify_unchanged(self):
        self.deadline = time.monotonic() + 30
        self.check()
        if self.hash_range(0, self.size) != self.digest:
            raise SubjectError("Retained OCI bytes changed before result emission")

    def close(self):
        if self.descriptor >= 0:
            os.close(self.descriptor)
            self.descriptor = -1
