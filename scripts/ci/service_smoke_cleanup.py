"""Unwired deletion-only recovery independent of refused source/data roots.

The initial trusted invocation must retain this context in its existing job
outputs before creation. A later cleanup invocation receives those exact values
and frozen code, rather than rereading authority from a refused workspace.
"""

import re

from service_smoke_network import Network
from service_smoke_resources import Container, OwnershipError, ROLES, RoleSet, exact_id, ownership_labels


def context(run: str, image: str, architecture: str) -> dict:
    ownership_labels(run, "metadata")
    if not isinstance(image, str) or re.fullmatch(r"sha256:[a-f\d]{64}", image, re.ASCII) is None:
        raise OwnershipError("Cleanup needs its independently captured immutable image")
    if architecture not in {"amd64", "arm64"}:
        raise OwnershipError("Cleanup platform is unselected")
    return {"run": run, "image": image, "architecture": architecture}


def recover(docker, selected: dict, *, identifiers: dict | None = None, network_id: str | None = None) -> dict:
    if not isinstance(selected, dict) or set(selected) != {"run", "image", "architecture"}:
        raise OwnershipError("Incomplete independently captured cleanup context")
    selected = context(**selected)
    identifiers = {} if identifiers is None else identifiers
    if not isinstance(identifiers, dict) or set(identifiers) - ROLES:
        raise OwnershipError("Unselected cleanup role inventory")
    for value in identifiers.values():
        exact_id(value)
    if network_id is not None:
        exact_id(network_id)
    roles = RoleSet(docker, docker, run=selected["run"], image=selected["image"],
                    platform="linux/" + selected["architecture"], base_environment=())
    # Every closed role is independently reconciled. A create whose ID or
    # checkpoint output was lost cannot hide an owned object from this pass.
    roles.attempted = {role: Container(identifiers[role], selected["run"], role, selected["image"])
                       if role in identifiers else None for role in sorted(ROLES)}
    result = {"containers": roles.cleanup()}
    network = Network(docker, docker, selected["run"])
    network.attempted, network.identifier = True, network_id
    try:
        result["network"] = network.cleanup()
    except Exception as error:
        result["network"] = {"absent": False, "error_class": type(error).__name__}
    result["status"] = "pass" if result["containers"]["absent"] and result["network"]["absent"] else "nonpass"
    return result
