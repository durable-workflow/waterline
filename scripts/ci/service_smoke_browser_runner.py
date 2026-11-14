"""Unwired held Node image and private output binding for published phases."""

from __future__ import annotations

import hashlib
import os
import resource
import stat
import time

from service_smoke_browser import BrowserError, browser_program, rpc_process
from service_smoke_io import bounded_process
from service_smoke_subjects import file_identity
from waterline_release_identity import decode_json


class NodeImage:
    def __init__(self, descriptor: int, expected_sha256: str):
        self.descriptor = os.dup(descriptor)
        self.identity = file_identity(os.fstat(self.descriptor))
        try:
            state = os.fstat(self.descriptor)
            if (not stat.S_ISREG(state.st_mode) or state.st_uid not in {0, os.getuid()} or state.st_mode & 0o022
                    or not 0 < state.st_size <= 128 * 1024 * 1024):
                raise BrowserError("Expected the independently captured bounded Node executable")
            digest = hashlib.sha256()
            offset, deadline = 0, time.monotonic() + 10
            while offset < state.st_size:
                if time.monotonic() >= deadline:
                    raise BrowserError("Node executable capture deadline exceeded")
                raw = os.pread(self.descriptor, min(1024 * 1024, state.st_size - offset), offset)
                if not raw:
                    raise BrowserError("Node executable capture was incomplete")
                digest.update(raw)
                offset += len(raw)
            self.sha256 = digest.hexdigest()
            if self.sha256 != expected_sha256:
                raise BrowserError("Node executable differs from its selected tooling subject")
            self.check()
        except BaseException:
            self.close()
            raise

    @property
    def path(self):
        self.check()
        return "/proc/self/fd/" + str(self.descriptor)

    def check(self):
        if file_identity(os.fstat(self.descriptor)) != self.identity:
            raise BrowserError("Held Node executable changed")

    def close(self):
        if self.descriptor >= 0:
            os.close(self.descriptor)
            self.descriptor = -1


def process_limits():
    available = sorted(os.sched_getaffinity(0))
    os.sched_setaffinity(0, set(available[:2]))
    resource.setrlimit(resource.RLIMIT_CORE, (0, 0))
    resource.setrlimit(resource.RLIMIT_CPU, (120, 120))
    resource.setrlimit(resource.RLIMIT_NOFILE, (1024, 1024))
    resource.setrlimit(resource.RLIMIT_NPROC, (512, 512))
    resource.setrlimit(resource.RLIMIT_FSIZE, (64 * 1024 * 1024, 64 * 1024 * 1024))


def tooling_inventory(node: NodeImage, dependencies) -> dict:
    result = bounded_process([node.path, "--version"], pass_fds=(node.descriptor,))
    if result.returncode != 0 or result.stderr or result.stdout != b"v24.22.1\n":
        raise BrowserError("Selected Node runtime differs from the existing published job")
    report = {"node_sha256": node.sha256, "node_version": "24.22.1", "packages": {}}
    for name in ("playwright", "playwright-core"):
        raw = dependencies.read("node_modules/" + name + "/package.json")
        metadata = decode_json(raw, "captured browser tooling package")
        if metadata.get("name") != name or metadata.get("version") != "1.64.0":
            raise BrowserError("Existing published browser tooling identity changed")
        report["packages"][name] = {"version": metadata["version"], "metadata_sha256": hashlib.sha256(raw).hexdigest()}
    dependencies.check()
    return report


def hosted_memory_bound() -> int:
    if os.environ.get("GITHUB_ACTIONS") != "true" or os.getuid() == 0:
        raise BrowserError("Browser phases require the existing unprivileged hosted job")
    with open("/proc/meminfo", "rb") as stream:
        metadata = stream.read(65537)
    if len(metadata) > 65536:
        raise BrowserError("Hosted memory observation exceeds its bound")
    selected = [line.split() for line in metadata.splitlines() if line.startswith(b"MemTotal:")]
    if len(selected) != 1 or len(selected[0]) != 3 or selected[0][2] != b"kB":
        raise BrowserError("Hosted job memory bound is unavailable")
    value = int(selected[0][1]) * 1024
    if not 0 < value <= 32 * 1024 * 1024 * 1024:
        raise BrowserError("Hosted machine exceeds the selected existing-job memory ceiling")
    return value


def phase_output(root, phase: str) -> tuple[str, int, int]:
    if phase not in {"before", "cached", "cleared"}:
        raise BrowserError("Unselected browser output phase")
    root.check()
    name = "browser-" + phase
    os.mkdir(name, 0o700, dir_fd=root.descriptor)
    descriptor = os.open(name, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW | os.O_CLOEXEC, dir_fd=root.descriptor)
    try:
        os.mkdir("tmp", 0o700, dir_fd=descriptor)
        temporary = os.open("tmp", os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW | os.O_CLOEXEC, dir_fd=descriptor)
        return name, descriptor, temporary
    except BaseException:
        os.close(descriptor)
        raise


class BrowserRunner:
    def __init__(self, *, node: NodeImage, dependencies, outputs, original: bytes, kind: str, source: str):
        self.node, self.dependencies, self.outputs = node, dependencies, outputs
        self.original, self.kind, self.source = original, kind, source
        self.tooling = tooling_inventory(node, dependencies)
        self.tooling["host_memory_bytes"] = hosted_memory_bound()
        self.tooling["process_bounds"] = {"cpu_affinity_count": 2, "cpu_seconds": 120, "wall_seconds": 180,
                                           "uid_process_limit": 512, "node_heap_mib": 2048, "file_bytes": 64 * 1024 * 1024}

    def __call__(self, phase, fixture, port, role_check):
        name, output_fd, temporary_fd = phase_output(self.outputs, phase)
        identity = os.fstat(output_fd)

        def guard():
            self.node.check()
            self.dependencies.check()
            self.outputs.check()
            named = os.stat(name, dir_fd=self.outputs.descriptor, follow_symlinks=False)
            if (named.st_dev, named.st_ino, named.st_mode) != (identity.st_dev, identity.st_ino, identity.st_mode):
                raise BrowserError("Browser phase output namespace changed")
            role_check()

        try:
            program = browser_program(self.original, kind=self.kind, source=self.source, phase=phase,
                                      base="http://127.0.0.1:" + str(port), output="/proc/self/fd/" + str(output_fd),
                                      dependency_root="/proc/self/fd/" + str(self.dependencies.descriptor))
            result = rpc_process([self.node.path, "--max-old-space-size=2048", "--input-type=module", "--eval", program],
                                 fixture, guard, pass_fds=(self.node.descriptor, self.dependencies.descriptor, output_fd),
                                 temporary="/proc/" + str(os.getpid()) + "/fd/" + str(temporary_fd), limits=process_limits)
            report = result["report"]
            if report.get("source") != self.source or report.get("phase") != phase:
                raise BrowserError("Browser report belongs to another artifact source or cache phase")
            expected = "browser.json" if self.kind == "primary" else "browser-auto.json"
            descriptor = os.open(expected, os.O_RDONLY | os.O_NOFOLLOW | os.O_CLOEXEC, dir_fd=output_fd)
            try:
                state = os.fstat(descriptor)
                if not stat.S_ISREG(state.st_mode) or state.st_nlink != 1 or state.st_size > 65536:
                    raise BrowserError("Invalid bounded retained browser report")
                retained = decode_json(os.read(descriptor, 65537), "retained actual browser report")
                if file_identity(os.fstat(descriptor)) != file_identity(state):
                    raise BrowserError("Retained browser report changed during capture")
                if retained != {key: value for key, value in report.items() if key != "phase"}:
                    raise BrowserError("Actual stdout and retained browser report differ")
            finally:
                os.close(descriptor)
            guard()
            result["tooling"] = self.tooling
            return result
        finally:
            os.close(temporary_fd)
            os.close(output_fd)
