"""Unwired capture-before-compile loader for the ordinary acceptance caller.

The complete reviewed invocation must supply a literal digest map. This module
does not choose that map, a source commit, an artifact or an execution subject.
"""

from __future__ import annotations

import hashlib
import os
from pathlib import Path, PurePosixPath
import re
import stat
import sys
from types import ModuleType, SimpleNamespace


MODULES = (
    "waterline_release_identity", "service_smoke_resources", "service_smoke_network", "service_smoke_io",
    "oci_release", "service_smoke_subjects", "service_smoke_observation", "service_smoke_fixture",
    "service_smoke_http", "service_smoke_browser_source", "service_smoke_browser", "service_smoke_native", "service_smoke_entry",
)
MAX_SOURCE = 1024 * 1024


class LoadingError(RuntimeError):
    """The reviewed source bytes or their held directory authority changed."""


def directory_identity(value) -> tuple:
    return value.st_dev, value.st_ino, value.st_mode, value.st_uid, value.st_gid


def checked_directory(descriptor: int):
    state = os.fstat(descriptor)
    if not stat.S_ISDIR(state.st_mode) or state.st_uid not in {0, os.getuid()} or state.st_mode & 0o022:
        raise LoadingError("Source parent is writable by another principal")


def open_directory(path: Path) -> int:
    if not path.is_absolute() or ".." in path.parts:
        raise LoadingError("Expected an absolute no-follow source root")
    descriptor = os.open("/", os.O_RDONLY | os.O_DIRECTORY | os.O_CLOEXEC)
    try:
        for component in path.parts[1:]:
            child = os.open(component, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW | os.O_CLOEXEC, dir_fd=descriptor)
            os.close(descriptor)
            descriptor = child
            checked_directory(descriptor)
        return descriptor
    except BaseException:
        os.close(descriptor)
        raise


def regular_identity(value) -> tuple:
    return tuple(getattr(value, key) for key in (
        "st_dev", "st_ino", "st_mode", "st_uid", "st_gid", "st_nlink", "st_size", "st_mtime_ns", "st_ctime_ns",
    ))


class SourceRoot:
    def __init__(self, path: Path):
        self.path = path
        self.descriptor = open_directory(path)
        self.identity = directory_identity(os.fstat(self.descriptor))

    def check(self):
        current = open_directory(self.path)
        try:
            if directory_identity(os.fstat(current)) != self.identity or directory_identity(os.fstat(self.descriptor)) != self.identity:
                raise LoadingError("Captured source root namespace changed")
        finally:
            os.close(current)

    def open(self, path: str, limit: int = MAX_SOURCE) -> int:
        selected = PurePosixPath(path)
        if selected.is_absolute() or ".." in selected.parts or str(selected) != path or not selected.parts:
            raise LoadingError("Unselected relative source path")
        self.check()
        parent = os.dup(self.descriptor)
        try:
            for component in selected.parts[:-1]:
                child = os.open(component, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW | os.O_CLOEXEC, dir_fd=parent)
                os.close(parent)
                parent = child
                checked_directory(parent)
            descriptor = os.open(selected.name, os.O_RDONLY | os.O_NOFOLLOW | os.O_CLOEXEC, dir_fd=parent)
        finally:
            os.close(parent)
        try:
            value = os.fstat(descriptor)
            if (not stat.S_ISREG(value.st_mode) or value.st_nlink != 1 or value.st_uid not in {0, os.getuid()}
                    or value.st_mode & 0o022 or not 0 < value.st_size <= limit):
                raise LoadingError("Source input is not a bounded owned regular file")
            self.check()
            return descriptor
        except BaseException:
            os.close(descriptor)
            raise

    def read(self, path: str, limit: int = MAX_SOURCE) -> bytes:
        descriptor = self.open(path, limit)
        try:
            before = regular_identity(os.fstat(descriptor))
            value = os.read(descriptor, limit + 1)
            if len(value) != before[6] or regular_identity(os.fstat(descriptor)) != before:
                raise LoadingError("Source bytes changed during capture")
            self.check()
            return value
        finally:
            os.close(descriptor)

    def close(self):
        if self.descriptor >= 0:
            os.close(self.descriptor)
            self.descriptor = -1


class FrozenDocument:
    """The existing OCI verifier reads policy from these captured Git bytes."""

    def __init__(self, raw: bytes):
        if not isinstance(raw, bytes) or not 0 < len(raw) <= MAX_SOURCE:
            raise LoadingError("Missing bounded captured security policy")
        self.raw = raw

    def stat(self):
        return SimpleNamespace(st_size=len(self.raw))

    def read_bytes(self):
        return self.raw


def capture_sources(root: SourceRoot, reviewed: dict[str, str]) -> dict[str, bytes]:
    if (not isinstance(reviewed, dict) or set(reviewed) != set(MODULES)
            or any(not isinstance(value, str) or re.fullmatch(r"[a-f\d]{64}", value, re.ASCII) is None for value in reviewed.values())):
        raise LoadingError("Literal reviewed module subjects are incomplete")
    captured = {}
    for name in MODULES:
        raw = root.read("scripts/ci/" + name + ".py")
        if hashlib.sha256(raw).hexdigest() != reviewed[name]:
            raise LoadingError("Source substituted before capture: " + name)
        captured[name] = raw
    root.check()
    return captured


def load_sources(captured: dict[str, bytes], reviewed: dict[str, str]) -> dict[str, ModuleType]:
    if set(captured) != set(MODULES) or set(reviewed) != set(MODULES):
        raise LoadingError("Incomplete frozen module set")
    if any(not isinstance(captured[name], bytes) or not 0 < len(captured[name]) <= MAX_SOURCE
           or hashlib.sha256(captured[name]).hexdigest() != reviewed[name] for name in MODULES):
        raise LoadingError("Captured module changed before compilation")
    if any(name in sys.modules for name in MODULES):
        raise LoadingError("Acceptance modules were imported before frozen loading")
    # Check every subject before compiling any one of them. Imports among these
    # modules then resolve only to the already captured module namespace.
    compiled = {name: compile(captured[name], "<frozen ordinary acceptance: " + name + ">", "exec") for name in MODULES}
    loaded = {}
    try:
        for name in MODULES:
            module = ModuleType(name)
            module.__file__ = "/__waterline_frozen__/scripts/ci/" + name + ".py"
            module.__package__ = ""
            sys.modules[name] = module
            loaded[name] = module
            exec(compiled[name], module.__dict__)
    except BaseException:
        for name in loaded:
            sys.modules.pop(name, None)
        raise
    return loaded
