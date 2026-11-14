"""Unwired finite transport for the ordinary current-target service smoke."""

from __future__ import annotations

import os
from pathlib import Path
import selectors
import signal
import stat
import subprocess
import time
import uuid

from service_smoke_resources import role_listing_arguments

DEADLINE_EXCEEDED = "Command deadline exceeded"


class TransportError(RuntimeError):
    """A command, byte bound or anonymous configuration invariant failed."""


def validate_command_bounds(command, data, timeout, stdout_limit, stderr_limit) -> None:
    if (not isinstance(command, list) or not 1 <= len(command) <= 256
            or any(not isinstance(arg, str) or "\0" in arg for arg in command)
            or sum(len(arg.encode()) for arg in command) > 128 * 1024
            or not isinstance(data, bytes) or len(data) > 1024 * 1024
            or type(timeout) not in (int, float) or type(stdout_limit) is not int or type(stderr_limit) is not int
            or not 0 < timeout <= 30 or not 0 < stdout_limit <= 1024 * 1024
            or not 0 < stderr_limit <= 65536):
        raise TransportError("Unselected command bounds")


def remaining_seconds(deadline: float) -> float:
    remaining = deadline - time.monotonic()
    if remaining <= 0:
        raise TransportError(DEADLINE_EXCEEDED)
    return remaining


def write_chunk(stream, pending: memoryview, selector) -> memoryview:
    try:
        written = os.write(stream.fileno(), pending[:65536])
    except BrokenPipeError as error:
        raise TransportError("Command closed before accepting its complete input") from error
    pending = pending[written:]
    if not pending:
        selector.unregister(stream)
        stream.close()
    return pending


def read_chunk(stream, selector, buffers: dict, limits: dict) -> None:
    chunk = os.read(stream.fileno(), 65536)
    if not chunk:
        selector.unregister(stream)
        return
    if len(buffers[stream]) + len(chunk) > limits[stream]:
        raise TransportError("Command output bound exceeded")
    buffers[stream].extend(chunk)


def drain_process(process, data: bytes, deadline: float, buffers: dict, limits: dict) -> None:
    pending = memoryview(data)
    with selectors.DefaultSelector() as selector:
        for stream in buffers:
            os.set_blocking(stream.fileno(), False)
            selector.register(stream, selectors.EVENT_READ)
        if pending:
            os.set_blocking(process.stdin.fileno(), False)
            selector.register(process.stdin, selectors.EVENT_WRITE)
        else:
            process.stdin.close()
        while selector.get_map():
            for key, _event in selector.select(min(remaining_seconds(deadline), 0.1)):
                if key.fileobj is process.stdin:
                    pending = write_chunk(key.fileobj, pending, selector)
                else:
                    read_chunk(key.fileobj, selector, buffers, limits)


def kill_group(process) -> None:
    try:
        os.killpg(process.pid, signal.SIGKILL)
    except ProcessLookupError:
        pass


def bounded_process(command: list[str], *, data: bytes = b"", timeout: float = 30,
                    stdout_limit: int = 1024 * 1024, stderr_limit: int = 65536,
                    pass_fds: tuple[int, ...] = ()) -> subprocess.CompletedProcess:
    validate_command_bounds(command, data, timeout, stdout_limit, stderr_limit)
    process = subprocess.Popen(
        command, stdin=subprocess.PIPE, stdout=subprocess.PIPE, stderr=subprocess.PIPE,
        start_new_session=True, pass_fds=pass_fds,
        env={"PATH": "/usr/bin:/bin", "LANG": "C.UTF-8", "LC_ALL": "C.UTF-8",
             "GIT_NO_LAZY_FETCH": "1", "GIT_NO_REPLACE_OBJECTS": "1",
             "GIT_CONFIG_NOSYSTEM": "1", "GIT_CONFIG_GLOBAL": "/dev/null"},
    )
    buffers = {process.stdout: bytearray(), process.stderr: bytearray()}
    limits = {process.stdout: stdout_limit, process.stderr: stderr_limit}
    end = time.monotonic() + timeout
    try:
        drain_process(process, data, end, buffers, limits)
        # Keep the leader unreaped until group termination, preventing PID reuse.
        while os.waitid(os.P_PID, process.pid, os.WEXITED | os.WNOHANG | os.WNOWAIT) is None:
            time.sleep(min(remaining_seconds(end), 0.005))
        kill_group(process)
        code = process.wait(timeout=5)
        return subprocess.CompletedProcess(command, code, bytes(buffers[process.stdout]),
                                           bytes(buffers[process.stderr]))
    except subprocess.TimeoutExpired as error:
        raise TransportError(DEADLINE_EXCEEDED) from error
    finally:
        if process.returncode is None:
            kill_group(process)
            process.wait(timeout=5)
        for stream in (process.stdin, process.stdout, process.stderr):
            stream.close()


def trusted_parent(path: Path) -> int:
    if not path.is_absolute() or ".." in path.parts:
        raise TransportError("Private configuration needs an absolute trusted parent")
    descriptor = os.open("/", os.O_RDONLY | os.O_DIRECTORY | os.O_CLOEXEC)
    try:
        for component in path.parts[1:]:
            child = os.open(component, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW | os.O_CLOEXEC,
                            dir_fd=descriptor)
            state = os.fstat(child)
            if state.st_uid not in {0, os.getuid()} or state.st_mode & 0o022:
                os.close(child)
                raise TransportError("Private configuration parent is writable by another principal")
            os.close(descriptor)
            descriptor = child
        return descriptor
    except BaseException:
        os.close(descriptor)
        raise


class AnonymousConfig:
    """Hold an empty, unlinked directory that cannot acquire configuration files.

    Docker receives the retained descriptor through /proc/self/fd. No later
    operation reopens the original directory name, and no file can be created
    inside a directory after its final link is removed.
    """

    def __init__(self, parent: Path):
        self.descriptor = -1
        parent_fd = trusted_parent(parent)
        name = "waterline-service-config-" + uuid.uuid4().hex
        try:
            os.mkdir(name, mode=0o700, dir_fd=parent_fd)
            self.descriptor = os.open(name, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW | os.O_CLOEXEC,
                                      dir_fd=parent_fd)
            os.rmdir(name, dir_fd=parent_fd)
            state = os.fstat(self.descriptor)
            self.identity = (state.st_dev, state.st_ino)
            self.check()
        except BaseException:
            self.close()
            raise
        finally:
            os.close(parent_fd)

    def check(self) -> None:
        state = os.fstat(self.descriptor)
        if (not stat.S_ISDIR(state.st_mode) or stat.S_IMODE(state.st_mode) != 0o700
                or state.st_uid != os.getuid() or state.st_nlink != 0
                or (state.st_dev, state.st_ino) != self.identity or os.listdir(self.descriptor)):
            raise TransportError("Anonymous Docker configuration changed")

    @property
    def path(self) -> str:
        self.check()
        return "/proc/self/fd/" + str(self.descriptor)

    def close(self) -> None:
        if self.descriptor >= 0:
            os.close(self.descriptor)
            self.descriptor = -1


class Docker:
    """Finite local Docker transport; this class creates no container itself."""

    def __init__(self, parent: Path):
        self.config = AnonymousConfig(parent)
        self.started = time.monotonic()
        self.cleanup_started = None

    def __call__(self, arguments: list[str], *, data: bytes = b"") -> subprocess.CompletedProcess:
        remaining = 600 - (time.monotonic() - self.started)
        if remaining <= 0:
            raise TransportError("Ordinary smoke total deadline exceeded")
        return self._command(arguments, data=data, timeout=min(30, remaining))

    def _command(self, arguments: list[str], *, data: bytes = b"", timeout: float) -> subprocess.CompletedProcess:
        self.config.check()
        result = bounded_process(
            ["/usr/bin/docker", "--host", "unix:///var/run/docker.sock", "--config", self.config.path,
             *arguments], data=data, timeout=timeout, pass_fds=(self.config.descriptor,),
        )
        self.config.check()
        return result

    def cleanup(self, arguments: list[str]) -> subprocess.CompletedProcess:
        # This transport is independent of any refused source/workspace path.
        # The resource owner still verifies exact IDs and labels before removal.
        if self.cleanup_started is None:
            self.cleanup_started = time.monotonic()
        remaining = 120 - (time.monotonic() - self.cleanup_started)
        if remaining <= 0:
            raise TransportError("Ordinary smoke cleanup deadline exceeded")
        listing = self.is_role_listing(arguments) or self.is_network_listing(arguments)
        selected = listing or (len(arguments) == 2 and arguments[0] == "inspect") or (
            len(arguments) == 4 and arguments[:3] == ["rm", "--force", "--volumes"]
        ) or (len(arguments) == 3 and arguments[:2] in (["network", "inspect"], ["network", "rm"]))
        if not selected or (not listing and (len(arguments[-1]) != 64 or any(c not in "0123456789abcdef" for c in arguments[-1]))):
            raise TransportError("Cleanup accepts only full-ID inspection and removal")
        return self._command(arguments, timeout=min(30, remaining))

    @staticmethod
    def is_role_listing(arguments: list[str]) -> bool:
        if len(arguments) != 11 or not arguments[6].startswith("label=dev.durable-workflow.run="):
            return False
        if not arguments[8].startswith("label=dev.durable-workflow.role="):
            return False
        run, role = arguments[6].split("=", 2)[2], arguments[8].split("=", 2)[2]
        try:
            return arguments == role_listing_arguments(run, role)
        except (ValueError, RuntimeError):
            return False

    @staticmethod
    def is_network_listing(arguments: list[str]) -> bool:
        # Import only for this independent deletion path, avoiding an import
        # cycle with the ordinary transport and container ownership primitive.
        from service_smoke_network import listing
        if len(arguments) != 11 or not arguments[6].startswith("label=dev.durable-workflow.run="):
            return False
        try:
            return arguments == listing(arguments[6].split("=", 2)[2])
        except (ValueError, RuntimeError):
            return False

    def close(self) -> None:
        self.config.close()
