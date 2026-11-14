"""Canonical first-stage source for the reviewed ordinary workflow run block.

This file is not an executable entrypoint. Activation must put these literal
bytes in the existing trusted workflow step, with one explicit launch call.
Executing a later mutable copy of this file supplies no launch authority.
"""

import hashlib
import os
from pathlib import Path
import stat
import signal


BOOTSTRAP_SHA256 = "2941540bdb4d09392e23f0b27e0fe292a5b2760ceca8bebacb76f07c8ad6e399"


def identity(value):
    return tuple(getattr(value, key) for key in ("st_dev", "st_ino", "st_mode", "st_uid", "st_gid", "st_nlink", "st_size", "st_mtime_ns", "st_ctime_ns"))


def root_descriptor(path: Path) -> int:
    if not path.is_absolute() or ".." in path.parts:
        raise RuntimeError("First-stage root must be an absolute no-follow directory")
    descriptor = os.open("/", os.O_RDONLY | os.O_DIRECTORY | os.O_CLOEXEC)
    try:
        for component in path.parts[1:]:
            child = os.open(component, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW | os.O_CLOEXEC, dir_fd=descriptor)
            os.close(descriptor)
            descriptor = child
            state = os.fstat(descriptor)
            if not stat.S_ISDIR(state.st_mode) or state.st_uid not in {0, os.getuid()} or state.st_mode & 0o022:
                raise RuntimeError("First-stage root has an unselected owner or write boundary")
        return descriptor
    except BaseException:
        os.close(descriptor)
        raise


def bootstrap_bytes(root: int) -> bytes:
    parent = os.dup(root)
    try:
        for component in ("scripts", "ci"):
            child = os.open(component, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW | os.O_CLOEXEC, dir_fd=parent)
            os.close(parent)
            parent = child
            state = os.fstat(parent)
            if state.st_uid not in {0, os.getuid()} or state.st_mode & 0o022:
                raise RuntimeError("First-stage source ancestor is writable by another principal")
        descriptor = os.open("service_smoke_bootstrap.py", os.O_RDONLY | os.O_NOFOLLOW | os.O_CLOEXEC, dir_fd=parent)
        try:
            before = os.fstat(descriptor)
            if (not stat.S_ISREG(before.st_mode) or before.st_nlink != 1 or before.st_uid not in {0, os.getuid()}
                    or before.st_mode & 0o022 or not 0 < before.st_size <= 1024 * 1024):
                raise RuntimeError("First-stage source must be one bounded owned regular file")
            raw = os.read(descriptor, 1024 * 1024 + 1)
            if (len(raw) != before.st_size or identity(os.fstat(descriptor)) != identity(before)
                    or hashlib.sha256(raw).hexdigest() != BOOTSTRAP_SHA256):
                raise RuntimeError("First-stage source substituted before compilation")
            return raw
        finally:
            os.close(descriptor)
    finally:
        os.close(parent)


def launch(root: Path, method: str, arguments: dict | None = None):
    selected = {"native": set(), "published": {"producer_gate_succeeded"}, "cleanup": {"contexts"}}
    arguments = {} if arguments is None else arguments
    if method not in selected or not isinstance(arguments, dict) or set(arguments) != selected[method]:
        raise RuntimeError("Unselected ordinary first-stage call")
    descriptor = root_descriptor(root)
    previous = {}
    try:
        raw = bootstrap_bytes(descriptor)
        namespace = {"__name__": "waterline_verified_invocation", "__file__": "<verified ordinary invocation>",
                     "__verified_source__": raw, "__verified_root__": str(root), "__verified_root_fd__": descriptor}

        def scope_check():
            if namespace.get("__ordinary_cancelled__") is True:
                raise InterruptedError("Ordinary work was cancelled; only owned cleanup remains available")

        def interrupted(number, _frame):
            namespace["__ordinary_cancelled__"] = True
            signal.signal(number, signal.SIG_IGN)
            raise InterruptedError("Ordinary invocation interrupted")

        namespace["__scope_check__"] = scope_check
        for number in (signal.SIGINT, signal.SIGTERM):
            previous[number] = signal.getsignal(number)
            signal.signal(number, interrupted)
        exec(compile(raw, "<verified ordinary invocation>", "exec"), namespace)
        return namespace[method](**arguments)
    finally:
        for number, handler in previous.items():
            signal.signal(number, handler)
        os.close(descriptor)
