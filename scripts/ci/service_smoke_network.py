"""Unwired owned bridge lifecycle for ordinary standalone acceptance."""

from __future__ import annotations

import re

from service_smoke_resources import ROLE_KEY, OwnershipError, exact_id, observation, ownership_labels


OPTIONS = {
    "com.docker.network.bridge.enable_ip_masquerade": "false",
    "com.docker.network.bridge.enable_icc": "true",
    "com.docker.network.bridge.host_binding_ipv4": "127.0.0.1",
}


def labels(run: str) -> dict:
    return {**ownership_labels(run, "fixture"), ROLE_KEY: "network"}


def listing(run: str) -> list[str]:
    arguments = ["network", "ls", "--no-trunc"]
    for key, value in labels(run).items():
        arguments += ["--filter", "label=" + key + "=" + value]
    return arguments + ["--format", "{{.ID}}"]


def candidates(docker, run: str) -> list[str]:
    result = docker(listing(run))
    if (result.returncode != 0 or result.stderr
            or re.fullmatch(rb"(?:[a-f0-9]{64}\n){0,2}", result.stdout) is None):
        raise OwnershipError("Network reconciliation was incomplete")
    selected = result.stdout.decode().splitlines()
    if len(selected) > 1:
        raise OwnershipError("Ambiguous labelled networks are preserved")
    return selected


def inspect(docker, identifier: str, run: str) -> dict:
    result = docker(["network", "inspect", exact_id(identifier)])
    records = observation(result.stdout)
    if result.returncode != 0 or result.stderr or len(records) != 1 or not isinstance(records[0], dict):
        raise OwnershipError("Captured network inspection failed")
    record = records[0]
    if record.get("Id") != identifier or record.get("Labels") != labels(run):
        raise OwnershipError("Network identity or ownership changed")
    return record


def validate(record: dict, members: set[str]) -> None:
    if (record.get("Driver") != "bridge" or record.get("Scope") != "local"
            or record.get("Internal") is not False or record.get("Attachable") is not False
            or record.get("Ingress") is not False or record.get("EnableIPv6") is not False
            or record.get("Options") != OPTIONS):
        raise OwnershipError("Effective bridge profile differs from selected local publication")
    actual = record.get("Containers")
    if not isinstance(actual, dict) or set(actual) != members:
        raise OwnershipError("Network membership differs from captured roles")
    for identifier in actual:
        exact_id(identifier)


class Network:
    """Create once; recover a lost create response for deletion only."""

    def __init__(self, docker, cleanup, run: str):
        labels(run)
        self.docker, self.cleanup_transport, self.run = docker, cleanup, run
        self.attempted, self.identifier = False, None

    def create(self) -> str:
        if self.attempted:
            raise OwnershipError("Network may be created only once")
        arguments = ["network", "create", "--driver", "bridge"]
        for key, value in labels(self.run).items():
            arguments += ["--label", key + "=" + value]
        for key, value in OPTIONS.items():
            arguments += ["--opt", key + "=" + value]
        self.attempted = True
        result = self.docker([*arguments, self.run + "-network"])
        if re.fullmatch(rb"[a-f0-9]{64}\n", result.stdout) is None:
            raise OwnershipError("Network creation did not return one full ID")
        self.identifier = result.stdout.decode().rstrip("\n")
        if result.returncode != 0 or result.stderr:
            raise OwnershipError("Network creation failed after ID capture")
        self.check(set())
        return self.identifier

    def check(self, members: set[str]) -> dict:
        record = inspect(self.docker, self.identifier, self.run)
        validate(record, members)
        return record

    def cleanup(self) -> dict:
        if not self.attempted:
            return {"absent": True, "recovered_id": None}
        matches = candidates(self.cleanup_transport, self.run)
        if self.identifier is not None and matches and matches != [self.identifier]:
            raise OwnershipError("Foreign network replacement is preserved")
        selected = self.identifier or (matches[0] if matches else None)
        if selected is not None and matches:
            self.remove(selected)
        if candidates(self.cleanup_transport, self.run):
            raise OwnershipError("Labelled network remains after cleanup")
        if selected is not None:
            result = self.cleanup_transport(["network", "inspect", selected])
            expected = b"error response from daemon: network " + selected.encode() + b" not found\n"
            if result.returncode != 1 or result.stdout.strip() != b"[]" or result.stderr.lower() != expected:
                raise OwnershipError("Captured network absence is unconfirmed")
        return {"absent": True, "recovered_id": selected if self.identifier is None else None}

    def remove(self, identifier: str):
        record = inspect(self.cleanup_transport, identifier, self.run)
        # Changed execution settings do not revoke deletion authority, but an
        # attached object must be handled by its own captured-role owner.
        if record.get("Containers") != {}:
            raise OwnershipError("Network still contains attached resources")
        result = self.cleanup_transport(["network", "rm", identifier])
        if result.returncode != 0 or result.stderr:
            raise OwnershipError("Captured network removal failed")
