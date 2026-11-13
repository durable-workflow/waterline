"""Unwired ownership checks for the ordinary current-target service smoke.

The caller supplies its already bounded Docker transport. No command, resource,
image or execution subject is selected by importing this module.
"""

from __future__ import annotations

from dataclasses import dataclass
import json
import math
import re
from typing import Callable


FULL_ID = re.compile(r"[a-f\d]{64}", re.ASCII)
OWNER_KEY = "dev.durable-workflow.owner"
RUN_KEY = "dev.durable-workflow.run"
ROLE_KEY = "dev.durable-workflow.role"
ROLES = {"metadata", "trust", "memory-refusal", "fixture", "application"}
MIB = 1024 * 1024
TMP = "rw,nosuid,nodev,noexec,size=16m,mode=1777"
DATA = "rw,nosuid,nodev,noexec,size=64m,uid=82,gid=82,mode=0755"
CACHE = "rw,nosuid,nodev,noexec,size=16m,uid=82,gid=82,mode=0755"


def profile(role: str, network: str | None) -> dict:
    if role not in ROLES:
        raise OwnershipError("Unselected ordinary smoke role")
    if role in {"fixture", "application"}:
        exact_id(network)
    elif network is not None:
        raise OwnershipError("This role cannot join an application network")
    writable = {"/data": DATA, "/tmp": TMP}
    if role in {"application", "memory-refusal"}:
        writable |= {"/app/bootstrap/cache": CACHE, "/app/storage": DATA}
    return {
        "cpus": 2_000_000_000 if role == "application" else 1_000_000_000,
        "memory": (768 if role == "application" else 256) * MIB,
        "pids": 256 if role == "application" else 128,
        "user": "0:0" if role in {"application", "memory-refusal"} else "1000:1000",
        "network": network or "none", "tmpfs": writable,
        "cap_add": ["CHOWN", "DAC_OVERRIDE", "SETGID", "SETUID"]
        if role in {"application", "memory-refusal"} else [],
    }


def validate_profile(record: dict, role: str, network: str | None) -> None:
    expected = profile(role, network)
    config = record.get("Config")
    host = record.get("HostConfig")
    if not isinstance(config, dict) or not isinstance(host, dict):
        raise OwnershipError("Missing effective container profile")
    required = {
        "ReadonlyRootfs": True, "Init": True, "NanoCpus": expected["cpus"],
        "Memory": expected["memory"], "MemorySwap": expected["memory"],
        "PidsLimit": expected["pids"], "NetworkMode": expected["network"],
        "Privileged": False, "PidMode": "", "IpcMode": "private", "UTSMode": "",
        "CgroupnsMode": "private",
        "Binds": None, "CapDrop": ["ALL"], "CapAdd": expected["cap_add"] or None,
        "SecurityOpt": ["no-new-privileges"], "Tmpfs": expected["tmpfs"],
        "RestartPolicy": {"Name": "no", "MaximumRetryCount": 0},
        "LogConfig": {"Type": "json-file", "Config": {"max-size": "1m", "max-file": "1"}},
    }
    if config.get("User") != expected["user"] or any(host.get(k) != v for k, v in required.items()):
        raise OwnershipError("Effective container profile differs from selected bounds")
    if host.get("Mounts") or host.get("Devices") or host.get("DeviceRequests"):
        raise OwnershipError("Host mounts or devices are unselected")
    mounts = record.get("Mounts")
    if not isinstance(mounts, list) or any(
        mount.get("Type") != "tmpfs" or mount.get("Destination") not in expected["tmpfs"]
        or mount.get("RW") is not True or mount.get("Source") not in (None, "")
        for mount in mounts if isinstance(mount, dict)
    ) or any(not isinstance(mount, dict) for mount in mounts):
        raise OwnershipError("Effective mount escaped disposable tmpfs paths")
    if len({mount["Destination"] for mount in mounts}) != len(mounts):
        raise OwnershipError("Duplicate effective mount destination")
    ports = host.get("PortBindings") or {}
    if role == "application":
        if (set(ports) != {"8080/tcp"} or not isinstance(ports["8080/tcp"], list)
                or len(ports["8080/tcp"]) != 1):
            raise OwnershipError("Application must publish only its one loopback port")
        binding = ports["8080/tcp"][0]
        if (not isinstance(binding, dict) or set(binding) != {"HostIp", "HostPort"}
                or binding["HostIp"] != "127.0.0.1" or not isinstance(binding["HostPort"], str)
                or (binding["HostPort"] and (re.fullmatch(r"[1-9]\d{0,4}", binding["HostPort"], re.ASCII) is None
                                             or int(binding["HostPort"]) > 65535))):
            raise OwnershipError("Application port escaped numeric loopback")
    elif ports:
        raise OwnershipError("This role cannot publish a host port")


class OwnershipError(RuntimeError):
    """A resource no longer has the recorded identity and ownership."""


def exact_id(value: str) -> str:
    if not isinstance(value, str) or FULL_ID.fullmatch(value) is None:
        raise OwnershipError("Expected a captured full Docker ID")
    return value


def unique_object(pairs: list[tuple]) -> dict:
    result = {}
    for key, value in pairs:
        if key in result:
            raise OwnershipError("Duplicate Docker observation key")
        result[key] = value
    return result


def invalid_constant(_value: str) -> None:
    raise OwnershipError("Non-finite Docker observation")


def finite_float(value: str) -> float:
    result = float(value)
    if not math.isfinite(result):
        raise OwnershipError("Non-finite Docker observation")
    return result


def ownership_labels(run: str, role: str) -> dict[str, str]:
    if not isinstance(run, str) or re.fullmatch(r"waterline-service-[a-f\d]{32}", run, re.ASCII) is None:
        raise OwnershipError("Invalid per-run ownership nonce")
    if role not in ROLES:
        raise OwnershipError("Unselected ordinary smoke role")
    return {OWNER_KEY: "waterline", RUN_KEY: run, ROLE_KEY: role}


def observation(raw: bytes) -> list:
    if not isinstance(raw, bytes) or len(raw) > 1024 * 1024:
        raise OwnershipError("Docker observation exceeds its bound")
    try:
        value = json.loads(raw.decode("utf-8"), object_pairs_hook=unique_object,
                           parse_constant=invalid_constant, parse_float=finite_float)
    except (ValueError, RecursionError) as error:
        raise OwnershipError("Invalid Docker observation") from error
    if not isinstance(value, list):
        raise OwnershipError("Docker observation must be a list")
    return value


@dataclass(frozen=True)
class Container:
    identifier: str
    run: str
    role: str
    image: str
    network: str | None = None

    def __post_init__(self):
        exact_id(self.identifier)
        ownership_labels(self.run, self.role)
        profile(self.role, self.network)
        if not isinstance(self.image, str) or re.fullmatch(r"sha256:[a-f\d]{64}", self.image, re.ASCII) is None:
            raise OwnershipError("Expected the captured immutable engine image ID")

    @property
    def labels(self) -> dict[str, str]:
        return ownership_labels(self.run, self.role)

    def inspect(self, docker: Callable) -> dict:
        result = docker(["inspect", self.identifier])
        if result.returncode != 0:
            raise OwnershipError("Cannot inspect the captured container")
        value = observation(result.stdout)
        if len(value) != 1 or not isinstance(value[0], dict):
            raise OwnershipError("Ambiguous container observation")
        record = value[0]
        config = record.get("Config")
        labels = config.get("Labels") if isinstance(config, dict) else None
        if (record.get("Id") != self.identifier or record.get("Image") != self.image
                or not isinstance(labels, dict)
                or any(labels.get(key) != value for key, value in self.labels.items())):
            raise OwnershipError("Container identity, image or ownership changed")
        return record

    def command(self, docker: Callable, verb: str, arguments: list[str], *,
                options: tuple[str, ...] = ()) -> object:
        selected = {
            "start": ((), ("--attach", "--interactive")),
            "exec": (("--interactive", "--user", "82:82"),),
            "logs": (("--tail", "200"),),
            "port": ((),),
            "stop": (("--time", "5"),),
        }
        if verb not in selected or options not in selected[verb]:
            raise OwnershipError("Unselected container operation")
        if not isinstance(arguments, list) or any(not isinstance(argument, str) for argument in arguments):
            raise OwnershipError("Invalid container operation arguments")
        if verb in {"start", "stop", "logs"} and arguments:
            raise OwnershipError("Additional container selectors are forbidden")
        if verb == "port" and arguments != ["8080/tcp"]:
            raise OwnershipError("Unselected application port")
        if verb == "exec" and not (
            len(arguments) == 4 and arguments[:2] == ["/usr/local/bin/php", "/dev/stdin"]
            and arguments[2] in {"inventory", "clear-before", "cache-build", "clear-after"}
            and re.fullmatch(r"[a-f\d]{64}", arguments[3], re.ASCII) is not None
        ):
            raise OwnershipError("Unselected probe command")
        validate_profile(self.inspect(docker), self.role, self.network)
        return docker([verb, *options, self.identifier, *arguments])

    def remove(self, docker: Callable) -> None:
        self.inspect(docker)
        result = docker(["rm", "--force", "--volumes", self.identifier])
        if result.returncode != 0:
            raise OwnershipError("Captured container removal failed")
        absent = docker(["inspect", self.identifier])
        missing = (b"no such object: " + self.identifier.encode())
        if (absent.returncode != 1 or observation(absent.stdout) != []
                or missing not in absent.stderr.lower()):
            raise OwnershipError("Captured container absence is unconfirmed")


def labels_arguments(run: str, role: str) -> list[str]:
    return [argument for key, value in ownership_labels(run, role).items()
            for argument in ("--label", key + "=" + value)]
