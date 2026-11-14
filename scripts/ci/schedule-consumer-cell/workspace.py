"""Held fixture workspace; adapted from SDK Python321/377a3eeeece605da675e4b628c308dd2e6be527c.

No source or execution qualification is inherited from that proposal.
"""

from __future__ import annotations

import hashlib
import fcntl
import os
import stat
from pathlib import Path

CREDENTIAL_SEALS = fcntl.F_SEAL_WRITE | fcntl.F_SEAL_GROW | fcntl.F_SEAL_SHRINK | fcntl.F_SEAL_SEAL

NATIVE_PREFLIGHT = """set -eu
test -d "$1" && test ! -L "$1" || exit 78
test -f "$1/$2" && test ! -L "$1/$2" || exit 78
test "$(stat -c '%d:%i:%u:%g:%a' -- "$1")" = "$3" || exit 78
test "$(stat -c '%d:%i:%u:%g:%a:%h' -- "$1/$2")" = "$4" || exit 78
shift 4
exec /usr/local/bin/durable-workflow-server "$@"
"""


def shape(info):
    return info.st_dev, info.st_ino, info.st_mode, info.st_uid, info.st_gid, info.st_nlink


class Workspace:
    def __init__(self, path):
        self.path = Path(path).absolute()
        self.handles = []
        self.directories = []
        self.files = {}
        self.credentials = {}
        if ".." in self.path.parts:
            raise ValueError("workspace path is not canonical")
        try:
            parent = os.open("/", os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW)
            self.handles.append(parent)
            for index, part in enumerate(self.path.parts[1:]):
                if index == len(self.path.parts) - 2:
                    os.mkdir(part, mode=0o700, dir_fd=parent)
                descriptor = os.open(part, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW, dir_fd=parent)
                self.handles.append(descriptor)
                info = os.fstat(descriptor)
                sticky_root = info.st_uid == 0 and bool(info.st_mode & stat.S_ISVTX)
                if info.st_uid not in {0, os.getuid()} or (info.st_mode & 0o022 and not sticky_root):
                    raise ValueError("workspace parent is not owner-controlled")
                self.directories.append((parent, part, descriptor, shape(info)[:5]))
                parent = descriptor
            self.root = parent
            self.check()
        except BaseException:
            self.close()
            raise

    def check(self):
        for parent, name, descriptor, expected in self.directories:
            if (
                shape(os.fstat(descriptor))[:5] != expected
                or shape(os.stat(name, dir_fd=parent, follow_symlinks=False))[:5] != expected
            ):
                raise ValueError("held workspace directory was replaced")
        for item in self.files.values():
            self.check_file(item)
        for item in self.credentials.values():
            self.check_credential(item)

    def directory(self, name):
        self.check()
        self.name(name)
        os.mkdir(name, mode=0o700, dir_fd=self.root)
        descriptor = os.open(name, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW, dir_fd=self.root)
        self.handles.append(descriptor)
        self.directories.append((self.root, name, descriptor, shape(os.fstat(descriptor))[:5]))
        self.check()
        return descriptor

    @staticmethod
    def name(name):
        if not name or name in {".", ".."} or "/" in name or "\x00" in name:
            raise ValueError("workspace entry must be one literal basename")

    @staticmethod
    def proc(descriptor, name=None):
        return "/proc/" + str(os.getpid()) + "/fd/" + str(descriptor) + ("/" + name if name else "")

    def mount_path(self, directory):
        self.check()
        entry = next(value for value in self.directories if value[2] == directory)
        if entry[0] != self.root:
            raise ValueError("bind source is not one held workspace child")
        return str(self.path / entry[1])

    def native_arguments(self, directory, database, *arguments):
        self.check()
        folder = os.fstat(directory)
        file = os.fstat(database["fd"])
        directory_key = (
            f"{folder.st_dev}:{folder.st_ino}:{folder.st_uid}:{folder.st_gid}:{stat.S_IMODE(folder.st_mode):o}"
        )
        file_key = (
            f"{file.st_dev}:{file.st_ino}:{file.st_uid}:{file.st_gid}:{stat.S_IMODE(file.st_mode):o}:{file.st_nlink}"
        )
        return [
            "-c",
            NATIVE_PREFLIGHT,
            "checked-native",
            "/cohort",
            database["name"],
            directory_key,
            file_key,
            *arguments,
        ]

    def check_file(self, item):
        current = os.stat(item["name"], dir_fd=item["parent"], follow_symlinks=False)
        held = os.fstat(item["fd"])
        if shape(current) != item["shape"] or shape(held) != item["shape"]:
            raise ValueError("held workspace file was replaced")
        if held.st_size > item["maximum"]:
            raise ValueError("workspace file exceeded its bound")
        if item["sha256"] is not None:
            data = os.pread(item["fd"], item["maximum"] + 1, 0)
            if hashlib.sha256(data).hexdigest() != item["sha256"]:
                raise ValueError("held workspace bytes changed")

    def capture(self, parent, name, *, immutable=False, maximum=64 * 1024 * 1024):
        self.check()
        self.name(name)
        descriptor = os.open(name, os.O_RDWR | os.O_NOFOLLOW, dir_fd=parent)
        return self.remember(parent, name, descriptor, immutable=immutable, maximum=maximum)

    def remember(self, parent, name, descriptor, *, immutable, maximum, initial_sha256=None):
        self.handles.append(descriptor)
        info = os.fstat(descriptor)
        if (
            not stat.S_ISREG(info.st_mode)
            or info.st_uid != os.getuid()
            or info.st_nlink != 1
            or info.st_mode & 0o077
            or info.st_size > maximum
        ):
            raise ValueError("workspace file is not private, regular and single-link")
        item = {
            "parent": parent,
            "name": name,
            "fd": descriptor,
            "shape": shape(info),
            "maximum": maximum,
            "sha256": hashlib.sha256(os.pread(descriptor, maximum + 1, 0)).hexdigest() if immutable else None,
        }
        if (
            initial_sha256 is not None
            and hashlib.sha256(os.pread(descriptor, maximum + 1, 0)).hexdigest() != initial_sha256
        ):
            raise ValueError("created workspace bytes differ")
        self.files[(parent, name)] = item
        self.check()
        return item

    def write(self, name, data, *, parent=None, immutable=True):
        self.check()
        self.name(name)
        parent = self.root if parent is None else parent
        data = data.encode() if isinstance(data, str) else data
        descriptor = os.open(name, os.O_RDWR | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW, 0o600, dir_fd=parent)
        try:
            with os.fdopen(descriptor, "wb", closefd=False) as stream:
                stream.write(data)
                stream.flush()
                os.fsync(descriptor)
        except BaseException:
            os.close(descriptor)
            raise
        item = self.remember(
            parent,
            name,
            descriptor,
            immutable=immutable,
            maximum=max(64 * 1024 * 1024, len(data)),
            initial_sha256=hashlib.sha256(data).hexdigest(),
        )
        self.check()
        return item

    def credential(self, data):
        self.check()
        data = data.encode() if isinstance(data, str) else data
        if not isinstance(data, bytes) or not 0 < len(data) <= 8192:
            raise ValueError("fixture credential exceeds its bound")
        descriptor = os.memfd_create("waterline-schedule-fixture", os.MFD_CLOEXEC | os.MFD_ALLOW_SEALING)
        try:
            os.fchmod(descriptor, 0o600)
            remaining = memoryview(data)
            while remaining:
                written = os.write(descriptor, remaining)
                if written <= 0:
                    raise OSError("credential write did not advance")
                remaining = remaining[written:]
            fcntl.fcntl(descriptor, fcntl.F_ADD_SEALS, CREDENTIAL_SEALS)
            item = {"fd": descriptor, "shape": shape(os.fstat(descriptor)),
                    "bytes": len(data), "sha256": hashlib.sha256(data).hexdigest()}
            self.check_credential(item)
            self.check()
        except BaseException:
            os.close(descriptor)
            raise
        self.handles.append(descriptor)
        self.credentials[descriptor] = item
        return item

    @staticmethod
    def check_credential(item):
        held = os.fstat(item["fd"])
        if (shape(held) != item["shape"] or not stat.S_ISREG(held.st_mode)
            or held.st_uid != os.getuid() or stat.S_IMODE(held.st_mode) != 0o600
            or held.st_nlink != 0 or held.st_size != item["bytes"]
            or fcntl.fcntl(item["fd"], fcntl.F_GET_SEALS) != CREDENTIAL_SEALS
            or hashlib.sha256(os.pread(item["fd"], 8193, 0)).hexdigest() != item["sha256"]):
            raise ValueError("held anonymous credential differs")

    def remove(self, item):
        # Releasing an anonymous held descriptor cannot unlink a replacement.
        # Named evidence and data files are retained; they carry no delete API.
        descriptor = item.get("fd")
        if self.credentials.get(descriptor) is not item:
            raise ValueError("only held anonymous credentials can be released")
        self.check_credential(item)
        os.close(descriptor)
        self.handles.remove(descriptor)
        del self.credentials[descriptor]

    def close(self):
        for descriptor in reversed(self.handles):
            os.close(descriptor)
        self.handles.clear()

    def __enter__(self):
        return self

    def __exit__(self, *_error):
        self.close()
