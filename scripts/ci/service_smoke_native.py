"""Unwired native graph/cache/API lifecycle inside the ordinary acceptance path.

No command-line entrypoint or workflow calls this module. The final literal
bootstrap and independently selected native input descriptors remain separate.
"""

from __future__ import annotations

import hashlib
import platform
import re
import time
from urllib.parse import urlunsplit

from service_smoke_fixture import BROWSER_FILE, FILES, Fixture, source_hashes, startup
from service_smoke_http import Loopback, native_api, native_budget
from service_smoke_network import Network
from service_smoke_observation import CLASSES, FRAMEWORK_CLASS, PHP_BINARY, cache_phases, verify_metadata
from service_smoke_resources import RoleSet, OwnershipError, observation, validate_profile
from waterline_release_identity import decode_json


APP_START = """mkdir -p /app/storage/framework/cache/data /app/storage/framework/sessions /app/storage/framework/views /app/storage/logs
exec timeout -s TERM -k 5 600 /app/entrypoint.sh"""
SHELL = "/bin/sh"
TRUST = """/usr/local/bin/php /usr/local/share/waterline/verify-ca-trust.php
/usr/local/bin/php /usr/local/share/waterline/verify-brotli.php
exec /usr/local/bin/php /usr/local/share/waterline/verify-curl.php"""
UID_PROBE = b'''<?php
declare(strict_types=1);
if (($argv[1] ?? '') !== 'service-uid') { throw new RuntimeException('Unselected UID observation.'); }
$servers = [];
foreach (glob('/proc/[0-9]*/cmdline') as $file) {
    $arguments = explode("\\0", (string) @file_get_contents($file));
    if (!in_array('-S', $arguments, true) || !in_array('0.0.0.0:8080', $arguments, true)) { continue; }
    if (($arguments[0] ?? '') !== 'php' && ($arguments[0] ?? '') !== '/usr/local/bin/php') {
        throw new RuntimeException('Unexpected HTTP interpreter.');
    }
    $status = (string) file_get_contents(dirname($file).'/status');
    if (!preg_match('/^Uid:\\s+(\\d+)\\s+(\\d+)\\s+(\\d+)\\s+(\\d+)$/m', $status, $match)
        || array_slice($match, 1) !== ['82', '82', '82', '82']) {
        throw new RuntimeException('HTTP service did not drop all UIDs to www-data.');
    }
    $servers[] = ['pid' => (int) basename(dirname($file)), 'uids' => [82, 82, 82, 82], 'arguments' => $arguments];
}
if (count($servers) !== 1) { throw new RuntimeException('Expected one actual PHP HTTP service.'); }
echo json_encode(['servers' => $servers], JSON_THROW_ON_ERROR)."\\n";
'''


def successful(result, label: str) -> bytes:
    if result.returncode != 0 or result.stderr:
        raise OwnershipError(label + " failed or returned unexpected diagnostics")
    return result.stdout


def image_configuration(docker, archive, subject: dict) -> dict:
    config_id = subject["config_digest"]
    config = decode_json(archive.config_bytes(config_id), "held image config")
    record = observation(successful(docker(["image", "inspect", config_id]), "Exact image inspection"))
    if (len(record) != 1 or not isinstance(record[0], dict) or record[0].get("Id") != config_id
            or record[0].get("Os") != "linux" or record[0].get("Architecture") != subject["architecture"]
            or not isinstance(record[0].get("Config"), dict)
            or record[0].get("RootFS") != {"Type": "layers", "Layers": config.get("rootfs", {}).get("diff_ids")}):
        raise OwnershipError("Imported engine image differs from the independently verified OCI config")
    # The immutable config ID binds the OCI bytes. Engine output can add inert
    # default fields absent from that encoding. The raw config remains authority
    # for the environment; effective program/environment/profile are checked
    # again on every captured container before execution.
    return config["config"]


def app_environment(fixture_name: str, browser_budget: float = 0.8) -> dict:
    if re.fullmatch(r"waterline-service-[a-f\d]{32}-(?:browser-)?fixture", fixture_name) is None:
        raise OwnershipError("Unselected synthetic fixture endpoint")
    if browser_budget not in {0.8, 3.5}:
        raise OwnershipError("Unselected existing browser budget")
    # This closed authority is the owned synthetic peer on the local bridge.
    # It carries no production data and cannot select an external endpoint.
    endpoint = urlunsplit(("http", fixture_name + ":18081", "", "", ""))
    return {"APP_KEY": "base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=", "DB_CONNECTION": "sqlite",
            "DB_DATABASE": "/data/waterline.sqlite", "WATERLINE_SERVER_ENDPOINT": endpoint,
            "WATERLINE_SERVER_TOKEN": "smoke-token", "WATERLINE_NAMESPACE": "smoke", "WATERLINE_ACCESS_MODE": "operator",
            "WATERLINE_ALLOW_UNAUTHENTICATED": "true", "WATERLINE_SERVER_REQUEST_TIMEOUT": "0.2",
            "WATERLINE_BROWSER_REQUEST_TIMEOUT": str(browser_budget)}


def running(roles: RoleSet, role) -> dict:
    record = role.inspect(roles.docker)
    role.creation.check(record["Config"])
    validate_profile(record, role.role, role.network)
    state = record.get("State", {})
    if state.get("Status") != "running" or state.get("OOMKilled") is not False:
        raise OwnershipError("Selected role is no longer running without OOM")
    return record


def loopback_port(record: dict, selected: str = "8080/tcp") -> int:
    ports = record.get("NetworkSettings", {}).get("Ports", {})
    binding = ports.get(selected)
    if (not isinstance(binding, list) or len(binding) != 1 or set(binding[0]) != {"HostIp", "HostPort"}
            or binding[0]["HostIp"] != "127.0.0.1" or re.fullmatch(r"[1-9]\d{0,4}", binding[0]["HostPort"], re.ASCII) is None
            or int(binding[0]["HostPort"]) > 65535):
        raise OwnershipError("Actual application publication differs from numeric loopback")
    return int(binding[0]["HostPort"])


def await_health(http: Loopback, check, started: float) -> float:
    while time.monotonic() - started < 30:
        check()
        try:
            if http.request("GET", "/up").status == 200:
                elapsed = time.monotonic() - started
                if elapsed <= 30:
                    return elapsed
                break
        except (OSError, RuntimeError):
            check()
        time.sleep(0.2)
    raise OwnershipError("Application exceeded its unchanged 30-second cold-start budget")


def verify_uid(roles: RoleSet, role) -> dict:
    raw = successful(role.command(roles.docker, "exec", [PHP_BINARY, "-n", "/dev/stdin", "service-uid"],
                                  options=("--interactive", "--user", "82:82"), data=UID_PROBE), "Actual service UID")
    report = decode_json(raw, "actual HTTP process UID")
    servers = report.get("servers")
    if (not isinstance(servers, list) or len(servers) != 1 or servers[0].get("uids") != [82, 82, 82, 82]
            or type(servers[0].get("pid")) is not int or servers[0]["pid"] <= 1):
        raise OwnershipError("Actual HTTP process UID is unavailable")
    return report


def memory_refusal(roles: RoleSet) -> dict:
    environment = app_environment(roles.run + "-fixture") | {"DB_DATABASE": ":memory:"}
    role = roles.create("memory-refusal", entrypoint=SHELL,
                        command=["-eu", "-c", "exec timeout -s TERM -k 5 25 /app/entrypoint.sh"], environment=environment)
    successful(role.command(roles.docker, "start", []), "Memory-refusal start")
    until = time.monotonic() + 10
    while time.monotonic() < until:
        record = role.inspect(roles.docker)
        state = record.get("State", {})
        if state.get("Status") == "exited":
            log = role.command(roles.docker, "logs", [], options=("--tail", "200"))
            messages = log.stdout + log.stderr
            if (state.get("OOMKilled") is not False or type(state.get("ExitCode")) is not int
                    or state["ExitCode"] in (0, 124, 137) or log.returncode != 0
                    or any(part not in messages for part in (b"waterline-service: startup failed:", b"DB_DATABASE=:memory:", b"file-backed SQLite"))):
                raise OwnershipError("Memory refusal lacks its deterministic persistence diagnostic")
            return {"role_id": role.identifier, "state": state, "log": messages.decode("utf-8", errors="strict")}
        time.sleep(0.1)
    raise OwnershipError("Memory refusal did not terminate within its fixed bound")


def trust_controls(roles: RoleSet) -> dict:
    role = roles.create("trust", entrypoint=SHELL, command=["-eu", "-c", TRUST], environment={})
    successful(role.command(roles.docker, "start", []), "Trust controls start")
    until = time.monotonic() + 10
    while time.monotonic() < until:
        record = role.inspect(roles.docker)
        state = record.get("State", {})
        if state.get("Status") == "exited":
            if state.get("OOMKilled") is not False or type(state.get("ExitCode")) is not int or state["ExitCode"] != 0:
                raise OwnershipError("Image CA/decoder/curl guard failed")
            log = role.command(roles.docker, "logs", [], options=("--tail", "200"))
            if log.returncode != 0:
                raise OwnershipError("Trust guard diagnostics are unavailable")
            return {"role_id": role.identifier, "state": state,
                    "stdout": log.stdout.decode("utf-8", errors="strict"), "stderr": log.stderr.decode("utf-8", errors="strict")}
        time.sleep(0.1)
    raise OwnershipError("Trust guards did not finish within their fixed bound")


def fixture_protocol(audit: dict, phase: str) -> None:
    if audit.get("phase") != phase or not isinstance(audit.get("protocol"), list):
        raise OwnershipError("Fixture audit belongs to another cache phase")
    raw = "\n".join(audit["protocol"])
    for request in ("GET /api/workflows/smoke-order/runs/smoke-run/streams",
                    "POST /api/workflows/smoke-order/runs/smoke-run/query/current",
                    "POST /api/workflows/smoke-order/runs/smoke-run/signal/approve"):
        if request not in raw:
            raise OwnershipError("Missing fresh actual upstream request: " + request)


class NativePhases:
    def __init__(self, roles, network, fixture_role, application, fixture, browser):
        self.roles, self.network, self.fixture_role, self.application = roles, network, fixture_role, application
        self.fixture, self.browser = fixture, browser
        self.port = loopback_port(running(roles, application))
        self.fixture_port = loopback_port(running(roles, fixture_role), "18082/tcp") if browser is not None else None
        self.http = Loopback(self.port, self.check)

    def check(self):
        fixture_record = running(self.roles, self.fixture_role)
        record = running(self.roles, self.application)
        self.network.check({self.fixture_role.identifier, self.application.identifier})
        if loopback_port(record) != self.port:
            raise OwnershipError("Application publication changed")
        if self.browser is not None and loopback_port(fixture_record, "18082/tcp") != self.fixture_port:
            raise OwnershipError("Browser fixture publication changed")

    def exercise(self, phase, _role):
        self.check()
        self.fixture.phase(phase)
        observed = {"api": native_api(self.http.request, phase), "budget": native_budget(self.http.request, self.fixture)}
        fixture_protocol(observed["budget"]["fixture_audit"], phase)
        if self.browser is not None:
            self.fixture.phase(phase)
            observed["browser"] = self.browser(phase, self.fixture, self.fixture_port, self.check)
        self.check()
        return observed


def exercise_native(docker, cleanup, *, run: str, archive, subject: dict, source, program: bytes,
                    checkpoint=None, browser=None, browser_budget: float = 0.8) -> dict:
    """Complete inner lifecycle. Only the later reviewed outer caller can bind inputs."""
    if platform.machine() != {"amd64": "x86_64", "arm64": "aarch64"}.get(subject.get("architecture")):
        raise OwnershipError("Acceptance must run on the artifact's actual native architecture")
    config = image_configuration(docker, archive, subject)
    if subject.get("source") != source.commit:
        raise OwnershipError("Artifact source differs from the selected immutable Git input")
    inputs = {name: source.read(path) for name, path in FILES.items()}
    if browser is not None:
        inputs["host.php"] = source.read(BROWSER_FILE)
    lock = source.read("standalone/composer.lock")
    source_classes = {name: hashlib.sha256(source.read(path.removeprefix("/app/waterline/"))).hexdigest()
                      for name, path in CLASSES.items() if name != FRAMEWORK_CLASS}
    roles = RoleSet(docker, cleanup, run=run, image=subject["config_digest"], platform="linux/" + subject["architecture"],
                    base_environment=tuple(config.get("Env", [])), checkpoint=checkpoint)
    network = Network(docker, cleanup, run, checkpoint=checkpoint)
    result, failure = {}, None
    try:
        metadata_role = roles.create("metadata", entrypoint=PHP_BINARY, command=["-n", "/dev/stdin", "metadata"], environment={})
        metadata = verify_metadata(docker, metadata_role, program, lock)
        result["metadata"] = metadata.report
        result["trust_controls"] = trust_controls(roles)
        result["memory_refusal"] = memory_refusal(roles)
        roles.network = network.create()
        fixture_kind = "fixture" if browser is None else "browser-fixture"
        fixture_command = startup(inputs, run + "-application" if browser is not None else None)
        fixture_role = roles.create(fixture_kind, entrypoint=SHELL, command=fixture_command, environment={})
        successful(fixture_role.command(docker, "start", []), "Protocol fixture start")
        fixture = Fixture(docker, fixture_role)
        startup_started = time.monotonic()
        application = roles.create("application", entrypoint=SHELL, command=["-eu", "-c", APP_START],
                                   environment=app_environment(run + "-" + fixture_kind, browser_budget))
        successful(application.command(docker, "start", []), "Application start")

        phases = NativePhases(roles, network, fixture_role, application, fixture, browser)
        result["startup_seconds"] = await_health(phases.http, phases.check, startup_started)
        result["service_uid"] = verify_uid(roles, application)
        result["observations"] = cache_phases(docker, application, program, metadata, source_classes, phases.exercise)
        result["fixture_sources"] = source_hashes(inputs)
        archive.verify_unchanged()
        source.check()
    except Exception as error:
        failure = {"class": type(error).__name__, "message": str(error)}
    finally:
        try:
            result["roles"] = roles.evidence()
        except Exception as error:
            result["role_evidence_failure"] = {"class": type(error).__name__, "message": str(error)}
            if failure is None:
                failure = result["role_evidence_failure"]
        result["cleanup"] = roles.cleanup()
        try:
            result["network_cleanup"] = network.cleanup()
        except Exception as error:
            result["network_cleanup"] = {"absent": False, "error_class": type(error).__name__}
    result["failure"] = failure
    result["status"] = "pass" if failure is None and result["cleanup"]["absent"] and result["network_cleanup"]["absent"] else "nonpass"
    return result
