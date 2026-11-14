"""Unwired metadata and cache phases for the ordinary current-target smoke.

Image/source admission, role creation and actual HTTP assertions belong to the
complete caller. Supplying data to these functions grants no artifact authority.
"""

from __future__ import annotations

import base64
from dataclasses import dataclass
import hashlib
import json
import re
from typing import Callable

import waterline_release_identity as identity
from service_smoke_resources import Container, validate_profile


PROBE_SHA256 = "45135ff1488cbc8f450a95af1e8ff6697c8daf87c03c9859d74f6602aa0287c7"
LOCK_SHA256 = "c2181f466bcbb799e3ce183d58531574d32de0cf9eba2f78dd9c18b211c60466"
CACHE_PATH = "/app/bootstrap/cache/routes-v7.php"
SCHEMA = "waterline.current-standalone-runtime-observation/v1"
PHP_BINARY = "/usr/local/bin/php"
FRAMEWORK_CLASS = "Illuminate\\Foundation\\Application"
CLASSES = {
    "Waterline\\Http\\Controllers\\Remote\\RemoteWorkflowsController": "/app/waterline/app/Http/Controllers/Remote/RemoteWorkflowsController.php",
    "Waterline\\Support\\WorkflowStreamPresenter": "/app/waterline/app/Support/WorkflowStreamPresenter.php",
    FRAMEWORK_CLASS: "/app/vendor/laravel/framework/src/Illuminate/Foundation/Application.php",
}
INI_KEYS = {"memory_limit", "date.timezone", "auto_prepend_file", "auto_append_file", "include_path",
            "opcache.enable_cli", "opcache.file_cache", "allow_url_include"}
INITIAL_INCLUDE_PATH = ".:/usr/local/lib/php"
# These are the only include-path declarations in the exact sealed lock.
COMPOSER_INCLUDE_PATHS = ("/app/vendor/pear/net_url2", "/app/vendor/pear/pear_exception")
BASE_KEYS = {"schema", "mode", "php_version", "php_int_size", "php_binary", "effective_uid",
             "ini_loaded_file", "ini_scanned_files", "ini_before", "ini_after", "lock_sha256", "installed_sha256"}
PHP_ERROR_CLASSES = frozenset({"Error", "ErrorException", "Exception", "RuntimeException", "TypeError",
                               "ValueError", "ParseError", "ArgumentCountError", "ReflectionException", "PDOException"})
PHP_ERROR_SEVERITIES = frozenset({1, 2, 4, 8, 16, 32, 64, 128, 256, 512, 1024, 2048, 4096, 8192, 16384})


class ObservationError(RuntimeError):
    """Captured data does not bind the selected metadata or cache phase."""


def digest(raw: bytes) -> str:
    return hashlib.sha256(raw).hexdigest()


def closed_php_origin(value: object, error_class: str) -> dict | None:
    if not isinstance(value, dict) or set(value) != {"filename_sha256", "line", "severity"}:
        return None
    file_hash, line, severity = value["filename_sha256"], value["line"], value["severity"]
    if (file_hash is not None and not is_digest(file_hash)) or (
            line is not None and (type(line) is not int or not 0 <= line <= 1000000)):
        return None
    if severity is not None and (error_class != "ErrorException" or type(severity) is not int
                                 or severity not in PHP_ERROR_SEVERITIES):
        return None
    return {"filename_sha256": file_hash, "line": line, "severity": severity}


def closed_php_error(stderr: bytes, mode: str) -> tuple[str, dict | None]:
    if not 0 < len(stderr) <= 1024:
        return "unavailable", None
    try:
        value = identity.decode_json(stderr, "bounded PHP refusal")
    except identity.IdentityError:
        return "unavailable", None
    if set(value) != {"mode", "error_class", "origin"} or value["mode"] != mode:
        return "unavailable", None
    error_class = value["error_class"]
    if not isinstance(error_class, str) or error_class not in PHP_ERROR_CLASSES:
        return "unrecognized", None
    origin = closed_php_origin(value["origin"], error_class)
    return (error_class, origin) if origin is not None else ("unavailable", None)


def probe_refusal(mode: str, result) -> ObservationError:
    # Retain only trusted mode, bounded process metadata and a closed class
    # from the PHP refusal shape. Origin is only a filename hash and bounded
    # numbers, never a path, message, extra file read or either raw stream.
    error_class, origin = closed_php_error(result.stderr, mode)
    details = {
        "schema": "waterline.ordinary-probe-refusal/v1", "mode": mode,
        "returncode": result.returncode if type(result.returncode) is int and -255 <= result.returncode <= 255 else None,
        "php_error_class": error_class, "php_error_origin": origin,
        "stdout": {"bytes": len(result.stdout), "sha256": digest(result.stdout)},
        "stderr": {"bytes": len(result.stderr), "sha256": digest(result.stderr)},
    }
    return ObservationError("Probe failed or produced unexpected diagnostics: " + json.dumps(details, sort_keys=True, separators=(",", ":")))


def is_digest(value: object) -> bool:
    return isinstance(value, str) and re.fullmatch(r"[a-f\d]{64}", value, re.ASCII) is not None


def check_program(program: bytes) -> None:
    if not isinstance(program, bytes) or len(program) != 10501 or digest(program) != PROBE_SHA256:
        raise ObservationError("Probe differs from the one reviewed callable representation")


def check_ini_transition(before: dict, after: dict, *, metadata: bool) -> None:
    expected = dict(before)
    if not metadata:
        expected["include_path"] = ":".join((*COMPOSER_INCLUDE_PATHS, INITIAL_INCLUDE_PATH))
    changed = sorted(key for key in INI_KEYS if expected[key] != after[key])
    if before["include_path"] != INITIAL_INCLUDE_PATH or changed:
        # Closed names only; never include an observed configuration value.
        raise ObservationError("PHP configuration mismatch: " + ",".join(changed or ["include_path"]))


def check_ini(report: dict, *, metadata: bool) -> None:
    before, after = report.get("ini_before"), report.get("ini_after")
    if (not isinstance(before, dict) or not isinstance(after, dict)
            or set(before) != INI_KEYS or set(after) != INI_KEYS):
        raise ObservationError("PHP configuration is incomplete")
    if any(not isinstance(value, str) and value is not False for value in [*before.values(), *after.values()]):
        raise ObservationError("Invalid PHP configuration value")
    check_ini_transition(before, after, metadata=metadata)
    for key in ("auto_prepend_file", "auto_append_file", "opcache.file_cache", "allow_url_include"):
        if before[key] not in (False, "", "0"):
            raise ObservationError("Unselected PHP bootstrap or executable cache input")
    for key in ("ini_loaded_file", "ini_scanned_files"):
        value = report.get(key)
        if (metadata and value is not None) or (value is not None and (not isinstance(value, str) or len(value) > 4096)):
            raise ObservationError("Unexpected PHP configuration source")


def check_cache(value: object, *, present: bool) -> dict:
    if (not isinstance(value, dict) or set(value) != {"path", "present", "bytes", "sha256"}
            or value["path"] != CACHE_PATH or value["present"] is not present or type(value["bytes"]) is not int):
        raise ObservationError("Cache observation has the wrong identity or phase")
    valid = 0 < value["bytes"] <= 2 * 1024 * 1024 and is_digest(value["sha256"]) if present else (
        value["bytes"] == 0 and value["sha256"] is None
    )
    if not valid:
        raise ObservationError("Missing or stale bounded cache observation")
    return value


def check_base(report: dict, mode: str, installed_sha256: str | None = None) -> None:
    metadata = mode == "metadata"
    if (report.get("schema") != SCHEMA or report.get("mode") != mode
            or report.get("php_version") != "8.3.36" or type(report.get("php_int_size")) is not int
            or report["php_int_size"] != 8 or report.get("php_binary") != PHP_BINARY
            or type(report.get("effective_uid")) is not int
            or report["effective_uid"] != (1000 if metadata else 82)
            or report.get("lock_sha256") != LOCK_SHA256 or not is_digest(report.get("installed_sha256"))):
        raise ObservationError("Runtime or metadata identity differs from the current target")
    if installed_sha256 is not None and report["installed_sha256"] != installed_sha256:
        raise ObservationError("Application package bytes differ from verified metadata")
    check_ini(report, metadata=metadata)


def raw_metadata(report: dict, key: str) -> bytes:
    value = report.get(key + "_base64")
    if not isinstance(value, str) or len(value) > 1400000:
        raise ObservationError("Missing bounded raw metadata")
    try:
        raw = base64.b64decode(value, validate=True)
    except ValueError as error:
        raise ObservationError("Invalid raw metadata encoding") from error
    if not 0 < len(raw) <= 1024 * 1024 or digest(raw) != report[key + "_sha256"]:
        raise ObservationError("Raw metadata differs from its captured digest")
    return raw


@dataclass(frozen=True)
class Metadata:
    run: str
    image: str
    role_id: str
    lock: bytes
    installed: bytes
    installed_sha256: str
    report: dict


def run_probe(docker: Callable, role: Container, program: bytes, mode: str,
              installed_sha256: str | None):
    if mode == "metadata":
        if role.role != "metadata" or installed_sha256 is not None:
            raise ObservationError("Metadata needs its separate pre-application role")
        launch = role.inspect(docker)["Config"]
        if launch.get("Entrypoint") != [PHP_BINARY] or launch.get("Cmd") != ["-n", "/dev/stdin", "metadata"]:
            raise ObservationError("Metadata launch is not the selected no-INI interpreter")
        return role.command(docker, "start", [], options=("--attach", "--interactive"), data=program)
    if mode not in {"inventory", "clear-before", "cache-build", "clear-after"} or not is_digest(installed_sha256):
        raise ObservationError("Unselected or unsealed application observation")
    return role.command(docker, "exec", [PHP_BINARY, "/dev/stdin", mode, installed_sha256],
                        options=("--interactive", "--user", "82:82"), data=program)


def check_role_state(docker: Callable, role: Container, mode: str) -> None:
    record = role.inspect(docker)
    validate_profile(record, role.role, role.network)
    state = record.get("State")
    if not isinstance(state, dict) or state.get("OOMKilled") is not False:
        raise ObservationError("Probe runtime state is unavailable or OOM")
    if mode == "metadata" and (state.get("Status") != "exited" or state.get("ExitCode") != 0):
        raise ObservationError("Pre-application metadata role did not exit successfully")
    if mode != "metadata" and state.get("Status") != "running":
        raise ObservationError("Application stopped during cache observation")


def captured_report(docker: Callable, role: Container, program: bytes, mode: str,
                    *, installed_sha256: str | None = None) -> dict:
    check_program(program)
    result = run_probe(docker, role, program, mode, installed_sha256)
    if result.returncode != 0 or result.stderr:
        raise probe_refusal(mode, result)
    check_role_state(docker, role, mode)
    report = identity.decode_json(result.stdout, "captured current-target probe")
    check_base(report, mode, installed_sha256)
    return report


def verify_metadata(docker: Callable, role: Container, program: bytes, source_lock: bytes) -> Metadata:
    if not isinstance(source_lock, bytes) or digest(source_lock) != LOCK_SHA256:
        raise ObservationError("Lock is not the accepted current-target source bytes")
    report = captured_report(docker, role, program, "metadata")
    if set(report) != BASE_KEYS | {"lock_base64", "installed_base64", "cache"}:
        raise ObservationError("Unexpected metadata report shape")
    check_cache(report["cache"], present=False)
    lock, installed = raw_metadata(report, "lock"), raw_metadata(report, "installed")
    if lock != source_lock:
        raise ObservationError("Image lock differs from the captured reviewed source")
    graph = identity.validate_installed_graph(identity.decode_json(lock, "source lock"),
                                               identity.decode_json(installed, "installed packages"))
    if graph["package_count"] != 79:
        raise ObservationError("Current-target production inventory count changed")
    return Metadata(role.run, role.image, role.identifier, lock, installed, digest(installed), report)


def installed_sdk(report: dict) -> dict:
    installed = identity.decode_json(raw_metadata(report, "installed"), "actual installed metadata")
    graph = identity.production_graph(installed.get("packages"))
    sdk = graph.get(identity.SDK_PACKAGE)
    if sdk is None:
        raise ObservationError("Verified installed graph has no SDK identity")
    return {"sdk": sdk["version"], "sdk_source": sdk["source_reference"]}


def verify_classes(value: object, source_classes: dict[str, str]) -> dict:
    if not isinstance(value, dict) or set(value) != set(CLASSES):
        raise ObservationError("Canonical runtime classes are missing or extra")
    for name, path in CLASSES.items():
        item = value[name]
        if (not isinstance(item, dict) or set(item) != {"path", "sha256"}
                or item["path"] != path or not is_digest(item["sha256"])):
            raise ObservationError("Runtime class path or digest is invalid")
        if name in source_classes and item["sha256"] != source_classes[name]:
            raise ObservationError("Waterline class differs from reviewed source bytes")
    if set(source_classes) != set(CLASSES) - {FRAMEWORK_CLASS}:
        raise ObservationError("Independent Waterline class authorities are incomplete")
    return value


def check_class_authorities(source_classes: dict[str, str]) -> None:
    if (not isinstance(source_classes, dict)
            or set(source_classes) != set(CLASSES) - {FRAMEWORK_CLASS}
            or not all(is_digest(value) for value in source_classes.values())):
        raise ObservationError("Independent source class authorities are incomplete")


def verify_phase(report: dict, mode: str, present: bool, source_classes: dict[str, str]) -> None:
    expected = BASE_KEYS | {"classes", "cache"} | ({"routes"} if mode == "inventory" else {"command", "exit_status"})
    if set(report) != expected:
        raise ObservationError("Unexpected cache-phase report shape")
    verify_classes(report["classes"], source_classes)
    check_cache(report["cache"], present=present)
    if mode != "inventory" and (
        type(report.get("exit_status")) is not int or report["exit_status"] != 0 or report.get("command") != (
            "route:cache" if mode == "cache-build" else "route:clear"
        )
    ):
        raise ObservationError("Cache command did not complete its exact selected effect")


def check_next_inventory(inventories: list, routes: object) -> list:
    current = identity.validate_route_inventory(routes)
    if len(inventories) == 1:
        identity.validate_cached_routes(inventories[0], current)
    elif len(inventories) == 2:
        identity.validate_route_cache_routes(*inventories, current)
    return current


def cache_phases(docker: Callable, role: Container, program: bytes, metadata: Metadata,
                 source_classes: dict[str, str], exercise: Callable) -> dict:
    if role.role != "application" or role.run != metadata.run or role.image != metadata.image:
        raise ObservationError("Application differs from the graph-verified image/run")
    check_class_authorities(source_classes)
    reports = []
    inventories = []
    observations = []
    stable_classes = None
    built_cache = None
    stages = [("clear-before", False), ("inventory", False), ("cache-build", True),
              ("inventory", True), ("clear-after", False), ("inventory", False)]
    for index, (mode, present) in enumerate(stages, 1):
        try:
            report = captured_report(docker, role, program, mode, installed_sha256=metadata.installed_sha256)
        except ObservationError as error:
            raise ObservationError(f"Cache probe {index}/{len(stages)} ({mode}): {error}") from error
        verify_phase(report, mode, present, source_classes)
        classes = {key: report[key] for key in ("classes", "ini_before", "ini_loaded_file", "ini_scanned_files")}
        if stable_classes is not None and classes != stable_classes:
            raise ObservationError("Loaded code changed across cache phases")
        stable_classes = classes
        cache = report["cache"]
        if mode == "cache-build":
            built_cache = cache
        elif present and cache != built_cache:
            raise ObservationError("Compiled cache changed before cached inventory")
        if mode == "inventory":
            inventories.append(check_next_inventory(inventories, report["routes"]))
            phase = ("before", "cached", "cleared")[len(inventories) - 1]
            observations.append(exercise(phase, role))
        reports.append(report)
    comparison = identity.validate_route_cache_routes(*inventories)
    return {"cache_phases": reports, "route_comparison": comparison, "http_phases": observations}
