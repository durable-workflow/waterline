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
ROLES = {"metadata", "trust", "memory-refusal", "fixture", "browser-fixture", "application"}
NETWORK_ROLES = {"fixture", "browser-fixture", "application"}
MIB = 1024 * 1024
TEMP_PATH = "/run/waterline"
APPLICATION_PORT = "8080/tcp"
FIXTURE_PORT = "18082/tcp"
PHP_BINARY = "/usr/local/bin/php"
STDIN = "/dev/stdin"
DATA = "rw,nosuid,nodev,noexec,size=64m,uid=82,gid=82,mode=0755"
ROOT_DATA = "rw,nosuid,nodev,noexec,size=64m,uid=0,gid=0,mode=0755"
CACHE = "rw,nosuid,nodev,noexec,size=16m,uid=82,gid=82,mode=0755"
ROLE_ENVIRONMENT_KEYS = {"APP_KEY", "DB_CONNECTION", "DB_DATABASE", "PORT", "WATERLINE_SERVER_ENDPOINT",
                         "WATERLINE_SERVER_TOKEN", "WATERLINE_NAMESPACE", "WATERLINE_ACCESS_MODE",
                         "WATERLINE_ALLOW_UNAUTHENTICATED", "WATERLINE_SERVER_REQUEST_TIMEOUT",
                         "WATERLINE_BROWSER_REQUEST_TIMEOUT"}


def profile(role: str, network: str | None) -> dict:
    if role not in ROLES:
        raise OwnershipError("Unselected ordinary smoke role")
    if role in NETWORK_ROLES:
        exact_id(network)
    elif network is not None:
        raise OwnershipError("This role cannot join an application network")
    uid = 82 if role in {"application", "memory-refusal"} else 1000
    temporary = f"rw,nosuid,nodev,noexec,size=16m,uid={uid},gid={uid},mode=0700"
    writable = {"/data": ROOT_DATA, TEMP_PATH: temporary}
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
    if (config.get("User") != expected["user"] or config.get("Healthcheck") != {"Test": ["NONE"]}
            or any(host.get(k) != v for k, v in required.items())):
        raise OwnershipError("Effective container profile differs from selected bounds")
    if host.get("Mounts") or host.get("Devices") or host.get("DeviceRequests"):
        raise OwnershipError("Host mounts or devices are unselected")
    environment = config.get("Env")
    if not isinstance(environment, list) or [v for v in environment if isinstance(v, str) and v.startswith("TMPDIR=")] != ["TMPDIR=" + TEMP_PATH]:
        raise OwnershipError("Missing selected private temporary directory")
    validate_mounts(record.get("Mounts"), expected["tmpfs"])
    validate_ports(host.get("PortBindings") or {}, role)


def validate_mounts(mounts: object, selected: dict) -> None:
    if not isinstance(mounts, list) or any(
        mount.get("Type") != "tmpfs" or mount.get("Destination") not in selected
        or mount.get("RW") is not True or mount.get("Source") not in (None, "")
        for mount in mounts if isinstance(mount, dict)
    ) or any(not isinstance(mount, dict) for mount in mounts):
        raise OwnershipError("Effective mount escaped disposable tmpfs paths")
    if len({mount["Destination"] for mount in mounts}) != len(mounts):
        raise OwnershipError("Duplicate effective mount destination")


def validate_ports(ports: dict, role: str) -> None:
    if role in {"application", "browser-fixture"}:
        port = APPLICATION_PORT if role == "application" else FIXTURE_PORT
        if (set(ports) != {port} or not isinstance(ports[port], list)
                or len(ports[port]) != 1):
            raise OwnershipError("Application must publish only its one loopback port")
        binding = ports[port][0]
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


def valid_probe_arguments(role: str, arguments: list[str]) -> bool:
    return role == "application" and ((len(arguments) == 4
            and arguments[:2] == [PHP_BINARY, STDIN]
            and arguments[2] in {"inventory", "clear-before", "cache-build", "clear-after"}
            and re.fullmatch(r"[a-f\d]{64}", arguments[3], re.ASCII) is not None) or (
                arguments == [PHP_BINARY, "-n", STDIN, "service-uid"]))


def valid_fixture_arguments(role: str, arguments: list[str]) -> bool:
    if role not in {"fixture", "browser-fixture"} or len(arguments) != 5 or arguments[:3] != [PHP_BINARY, "-n", STDIN]:
        return False
    return (arguments[3] == "phase" and arguments[4] in {"before", "cached", "cleared"}) or (
        arguments[3] == "mode" and arguments[4] in {"healthy", "backend-stall", "write-stall"}
    ) or arguments[3:] == ["audit", ""] or (
        role == "browser-fixture" and arguments[3] == "browser-mode" and 0 < len(arguments[4]) <= 8192
    )


def validate_exec(role: str, arguments: list[str], options: tuple[str, ...]) -> None:
    valid = (valid_probe_arguments(role, arguments) and options == ("--interactive", "--user", "82:82")) or (
        valid_fixture_arguments(role, arguments) and options == ("--interactive", "--user", "1000:1000")
    )
    if not valid:
        raise OwnershipError("Unselected probe command")


def validate_command(role: str, verb: str, arguments: list[str], options: tuple[str, ...]) -> None:
    selected = {
        "start": ((), ("--attach", "--interactive")),
        "exec": (("--interactive", "--user", "82:82"), ("--interactive", "--user", "1000:1000")),
        "logs": (("--tail", "200"),), "port": ((),), "stop": (("--time", "5"),),
    }
    if verb not in selected or options not in selected[verb]:
        raise OwnershipError("Unselected container operation")
    if verb == "start" and (role == "metadata") != (options == ("--attach", "--interactive")):
        raise OwnershipError("Unselected role input attachment")
    if not isinstance(arguments, list) or any(not isinstance(argument, str) for argument in arguments):
        raise OwnershipError("Invalid container operation arguments")
    if verb in {"start", "stop", "logs"} and arguments:
        raise OwnershipError("Additional container selectors are forbidden")
    if verb == "port" and arguments != [APPLICATION_PORT]:
        raise OwnershipError("Unselected application port")
    if verb == "exec":
        validate_exec(role, arguments, options)


def validate_input(role: str, verb: str, options: tuple[str, ...], data: bytes) -> None:
    accepts_program = verb == "exec" or (
        role == "metadata" and verb == "start" and options == ("--attach", "--interactive")
    )
    if not isinstance(data, bytes) or (accepts_program and not 0 < len(data) <= 1024 * 1024) or (not accepts_program and data):
        raise OwnershipError("Unselected command input")


@dataclass(frozen=True)
class CreationSpec:
    entrypoint: tuple[str, ...]
    command: tuple[str, ...]
    environment: tuple[str, ...]

    def check(self, config: dict) -> None:
        actual_environment = config.get("Env")
        if (config.get("Entrypoint") != list(self.entrypoint) or (config.get("Cmd") or []) != list(self.command)
                or not isinstance(actual_environment, list) or not all(isinstance(value, str) for value in actual_environment)
                or tuple(sorted(actual_environment)) != self.environment):
            raise OwnershipError("Created role program or environment differs from captured arguments")


@dataclass(frozen=True)
class Container:
    identifier: str
    run: str
    role: str
    image: str
    network: str | None = None
    creation: CreationSpec | None = None

    def __post_init__(self):
        exact_id(self.identifier)
        ownership_labels(self.run, self.role)
        if self.network is not None:
            exact_id(self.network)
        if not isinstance(self.image, str) or re.fullmatch(r"sha256:[a-f\d]{64}", self.image, re.ASCII) is None:
            raise OwnershipError("Expected the captured immutable engine image ID")

    @property
    def labels(self) -> dict[str, str]:
        return ownership_labels(self.run, self.role)

    def inspect(self, docker: Callable) -> dict:
        return self.validate_inspection(docker(["inspect", self.identifier]))

    def validate_inspection(self, result) -> dict:
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
                options: tuple[str, ...] = (), data: bytes = b"") -> object:
        validate_command(self.role, verb, arguments, options)
        validate_input(self.role, verb, options, data)
        if self.creation is None:
            raise OwnershipError("Cleanup-only resource has no captured creation specification")
        record = self.inspect(docker)
        self.creation.check(record["Config"])
        validate_profile(record, self.role, self.network)
        return docker([verb, *options, self.identifier, *arguments], data=data)

    def remove(self, docker: Callable) -> None:
        self.inspect(docker)
        result = docker(["rm", "--force", "--volumes", self.identifier])
        if result.returncode != 0:
            raise OwnershipError("Captured container removal failed")
        absent = docker(["inspect", self.identifier])
        if not exact_missing(absent, self.identifier):
            raise OwnershipError("Captured container absence is unconfirmed")


def labels_arguments(run: str, role: str) -> list[str]:
    return [argument for key, value in ownership_labels(run, role).items()
            for argument in ("--label", key + "=" + value)]


def role_listing_arguments(run: str, role: str) -> list[str]:
    arguments = ["ps", "--all", "--no-trunc"]
    for key, value in ownership_labels(run, role).items():
        arguments += ["--filter", "label=" + key + "=" + value]
    return arguments + ["--format", "{{.ID}}"]


def role_candidates(docker: Callable, run: str, role: str) -> list[str]:
    result = docker(role_listing_arguments(run, role))
    if (result.returncode != 0 or result.stderr or len(result.stdout) > 130
            or re.fullmatch(rb"(?:[a-f0-9]{64}\n){0,2}", result.stdout) is None):
        raise OwnershipError("Role reconciliation was incomplete")
    identifiers = result.stdout.decode("ascii").splitlines()
    if len(identifiers) > 1:
        raise OwnershipError("Ambiguous role candidates are preserved")
    return identifiers


def exact_missing(result, identifier: str) -> bool:
    return (result.returncode == 1 and result.stdout in (b"[]\n", b"[]")
            and result.stderr.lower() == b"error: no such object: " + identifier.encode() + b"\n")


def clean_role(docker: Callable, run: str, role: str, image: str, network: str | None,
               captured: str | None) -> dict:
    matches = role_candidates(docker, run, role)
    if captured is not None and matches and matches != [captured]:
        raise OwnershipError("Foreign same-role replacement is preserved")
    selected = captured or (matches[0] if matches else None)
    if selected is None:
        return {"absent": True, "recovered_id": None}
    exact_id(selected)
    inspection = docker(["inspect", selected])
    if not exact_missing(inspection, selected):
        # Reconciliation grants deletion only. The recovered object is never
        # returned to the caller's launch path or used for evidence collection.
        record = Container(selected, run, role, image, network)
        record.validate_inspection(inspection)
        record.remove(docker)
    if role_candidates(docker, run, role):
        raise OwnershipError("A labelled role remains after captured-ID cleanup")
    return {"absent": True, "recovered_id": selected if captured is None else None}


def audit_role_absence(docker: Callable, run: str, role: str, identifier: str | None) -> None:
    if role_candidates(docker, run, role):
        raise OwnershipError("A labelled role appeared during final cleanup audit")
    if identifier is not None and not exact_missing(docker(["inspect", exact_id(identifier)]), identifier):
        raise OwnershipError("Final captured-ID absence is unconfirmed")


def create_profile_arguments(role: str, run: str, network: str | None, platform: str) -> list[str]:
    selected = profile(role, network)
    if platform not in {"linux/amd64", "linux/arm64"}:
        raise OwnershipError("Unselected native platform")
    arguments = ["create", "--name", run + "-" + role, *labels_arguments(run, role),
                 "--platform", platform, "--pull", "never", "--init", "--read-only", "--no-healthcheck",
                 "--cpus", str(selected["cpus"] // 1_000_000_000), "--memory", str(selected["memory"]),
                 "--memory-swap", str(selected["memory"]), "--pids-limit", str(selected["pids"]),
                 "--user", selected["user"], "--network", selected["network"], "--cap-drop", "ALL",
                 "--security-opt", "no-new-privileges", "--ipc", "private", "--cgroupns", "private",
                 "--restart", "no", "--log-driver", "json-file", "--log-opt", "max-size=1m", "--log-opt", "max-file=1"]
    for capability in selected["cap_add"]:
        arguments += ["--cap-add", capability]
    for path, options in selected["tmpfs"].items():
        arguments += ["--tmpfs", path + ":" + options]
    if role == "metadata":
        arguments += ["--interactive"]
    if role in {"application", "browser-fixture"}:
        arguments += ["--publish", "127.0.0.1::" + ("8080" if role == "application" else "18082")]
    return arguments


def environment_map(values: tuple[str, ...]) -> dict[str, str]:
    if not isinstance(values, tuple):
        raise OwnershipError("Image environment must be captured as immutable values")
    result = {}
    for item in values:
        if not isinstance(item, str) or "=" not in item or "\0" in item:
            raise OwnershipError("Invalid captured image environment")
        key, value = item.split("=", 1)
        if not key or key in result:
            raise OwnershipError("Duplicate captured image environment variable")
        result[key] = value
    return result


def selected_environment(base: tuple[str, ...], environment: dict[str, str]) -> dict[str, str]:
    if not isinstance(environment, dict) or any(
        key not in ROLE_ENVIRONMENT_KEYS or not isinstance(value, str) or "\0" in value or len(value) > 1024
        for key, value in environment.items()
    ):
        raise OwnershipError("Unselected role environment override")
    result = environment_map(base)
    if any(key.startswith("LD_") or key in {"PHPRC", "PHP_INI_SCAN_DIR", "APP_ROUTES_CACHE", "ENV", "BASH_ENV"} for key in result):
        raise OwnershipError("Unselected image loader environment")
    return {**result, **environment, "TMPDIR": TEMP_PATH}


class RoleSet:
    """At most five roles in one ordinary smoke invocation, with no name cleanup."""

    def __init__(self, docker: Callable, cleanup: Callable, *, run: str, image: str,
                 platform: str, base_environment: tuple[str, ...], network: str | None = None, checkpoint=None):
        ownership_labels(run, "metadata")
        if not isinstance(image, str) or re.fullmatch(r"sha256:[a-f\d]{64}", image, re.ASCII) is None:
            raise OwnershipError("Expected a captured immutable image ID")
        self.docker, self.cleanup_transport = docker, cleanup
        self.run, self.image, self.platform, self.network = run, image, platform, network
        self.base_environment = base_environment
        selected_environment(base_environment, {})
        self.attempted: dict[str, Container | None] = {}
        self.checkpoint = checkpoint

    def create(self, role: str, *, entrypoint: str, command: list[str], environment: dict[str, str]) -> Container:
        if role in self.attempted:
            raise OwnershipError("A role may be created only once per run")
        if role in {"fixture", "browser-fixture"} and ({"fixture", "browser-fixture"} & self.attempted.keys()):
            raise OwnershipError("One invocation cannot create both fixture profiles")
        network = self.network if role in NETWORK_ROLES else None
        arguments = create_profile_arguments(role, self.run, network, self.platform)
        if (entrypoint not in {PHP_BINARY, "/bin/sh", "/app/entrypoint.sh"}
                or not isinstance(command, list) or any(not isinstance(value, str) or "\0" in value for value in command)):
            raise OwnershipError("Invalid fixed role program")
        expected_environment = selected_environment(self.base_environment, environment)
        for key, value in {**environment, "TMPDIR": TEMP_PATH}.items():
            arguments += ["--env", key + "=" + value]
        self.attempted[role] = None
        if self.checkpoint is not None:
            self.checkpoint("container", role, None)
        result = self.docker([*arguments, "--entrypoint", entrypoint, self.image, *command])
        if re.fullmatch(rb"[a-f0-9]{64}\n", result.stdout) is None:
            raise OwnershipError("Creation did not return one full captured ID")
        creation = CreationSpec((entrypoint,), tuple(command), tuple(sorted(key + "=" + value for key, value in expected_environment.items())))
        record = Container(result.stdout.decode().rstrip("\n"), self.run, role, self.image, network, creation)
        self.attempted[role] = record
        if self.checkpoint is not None:
            self.checkpoint("container", role, record.identifier)
        if result.returncode != 0 or result.stderr:
            raise OwnershipError("Creation failed after returning its captured ID")
        inspected = record.inspect(self.docker)
        validate_profile(inspected, role, network)
        creation.check(inspected["Config"])
        return record

    def cleanup(self) -> dict:
        results, errors = {}, []
        for role, captured in reversed(list(self.attempted.items())):
            try:
                results[role] = clean_role(
                    self.cleanup_transport, self.run, role, self.image,
                    self.network if role in NETWORK_ROLES else None,
                    captured.identifier if captured else None,
                )
            except Exception as error:
                # One refused object must not skip another role's cleanup.
                errors.append({"role": role, "error_class": type(error).__name__})
                results[role] = {"absent": False, "recovered_id": None}
        for role, captured in self.attempted.items():
            try:
                identifier = captured.identifier if captured else results[role]["recovered_id"]
                audit_role_absence(self.cleanup_transport, self.run, role, identifier)
            except Exception as error:
                errors.append({"role": role, "error_class": type(error).__name__})
                results[role]["absent"] = False
        return {"roles": results, "errors": errors, "absent": not errors}
