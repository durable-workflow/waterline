#!/usr/bin/env python3
"""Separate approved package acceptance from ordinary registry resolution."""

from __future__ import annotations

import argparse
import json
import re
from pathlib import Path

WATERLINE = "durable-workflow/waterline"
WORKFLOW = "durable-workflow/workflow"
SDK = "durable-workflow/sdk"
PEERS = {"embedded": WORKFLOW, "service": SDK}
SCHEMA = "durable-workflow.waterline-current-product-tuple/v1"
# Package versions are ASCII; Python's Unicode-aware \d would broaden identity.
VERSION = re.compile(r"^(?P<major>[23])\.(?P<minor>0|[1-9][0-9]*)\.(?P<patch>0|[1-9][0-9]*)$")


def version(value: object, *, sdk: bool = False) -> tuple[int, int, int]:
    match = VERSION.fullmatch(value) if isinstance(value, str) else None
    if match is None or (not sdk and match["major"] != "2"):
        raise ValueError("Expected an exact supported stable package version")
    return tuple(int(match[name]) for name in ("major", "minor", "patch"))


def approved_packages(authority: dict) -> dict[str, str]:
    if authority.get("schema") != SCHEMA:
        raise ValueError("Unsupported current product tuple schema")
    values = authority.get("versions", {})
    packages = {WATERLINE: values.get("waterline"), WORKFLOW: values.get("workflow"), SDK: values.get("sdk-php")}
    for package, value in packages.items():
        version(value, sdk=package == SDK)
    return packages


def manifests(authority: dict) -> dict[str, dict]:
    approved = approved_packages(authority)
    result = {}
    for kind in ("approved", "onboarding"):
        for graph, peer in PEERS.items():
            name = f"{kind}-{graph}"
            result[name] = {
                "name": f"durable-workflow/waterline-{name}-probe", "version": "1.0.0",
                "require": {package: approved[package] if kind == "approved" else "^2.0" for package in (WATERLINE, peer)},
                "minimum-stability": "stable", "prefer-stable": True,
                "config": {"allow-plugins": {}, "preferred-install": "dist"},
            }
    return result


def locked_roots(lock: dict, graph: str, *, approved: bool) -> dict[str, str]:
    expected = {WATERLINE, PEERS[graph]}
    entries = [package for package in lock.get("packages", []) if package.get("name") in expected]
    if len(entries) != 2 or {entry["name"] for entry in entries} != expected:
        raise ValueError(f"{graph} did not resolve both roots exactly once")
    roots = {}
    for entry in entries:
        package = entry["name"]
        value = entry.get("version")
        version(value, sdk=approved and package == SDK)
        reference = entry.get("source", {}).get("reference")
        if not isinstance(reference, str) or re.fullmatch(r"[0-9a-f]{40}", reference) is None or entry.get("dist", {}).get("type") != "zip":
            raise ValueError("Onboarding must install immutable normal package routes")
        roots[package] = value
    return roots


def validate(authority: dict, candidate: str, locks: dict[str, dict], expected: str | None = None) -> dict:
    approved = approved_packages(authority)
    candidate_order = version(candidate)
    approved_order = version(approved[WATERLINE])
    if candidate_order < approved_order:
        raise ValueError("Candidate source predates the approved Waterline release")
    resolved = {}
    for kind in ("approved", "onboarding"):
        for graph, peer in PEERS.items():
            name = f"{kind}-{graph}"
            roots = locked_roots(locks[name], graph, approved=kind == "approved")
            if kind == "approved" and roots != {WATERLINE: approved[WATERLINE], peer: approved[peer]}:
                raise ValueError("Approved cohort installation differs from its exact authority")
            resolved[name] = roots
    defaults = {resolved[f"onboarding-{graph}"][WATERLINE] for graph in PEERS}
    if len(defaults) != 1:
        raise ValueError("Ordinary onboarding graphs selected different Waterline releases")
    selected = defaults.pop()
    if not approved_order <= version(selected) <= candidate_order:
        raise ValueError("Ordinary onboarding is outside approved-to-candidate release bounds")
    if expected is not None and version(expected) != candidate_order:
        raise ValueError("Published onboarding expectation must match the immutable candidate")
    strict = expected or (approved[WATERLINE] if candidate_order == approved_order else None)
    if strict is not None and selected != strict:
        raise ValueError(f"Ordinary onboarding selected {selected}, expected {strict}")
    return {
        "schema": "durable-workflow.waterline.onboarding-composer-qualification/v2",
        "authority": "checked-in-product-tuple", "approved_cohort": approved,
        "candidate_source": candidate, "graphs": resolved,
        "ordinary_constraint": "^2.0", "strict_expected_waterline": strict,
        "ordinary_selection": selected,
        "ordinary_selection_qualification": "matches-approved" if selected == approved[WATERLINE] else "ahead-of-approved-not-qualified",
        "outcome": "pass", "full_release_acceptance": False,
    }


def main() -> None:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--authority", type=Path, required=True)
    parser.add_argument("--candidate-composer", type=Path, required=True)
    parser.add_argument("--directory", type=Path, required=True)
    parser.add_argument("--write-manifests", action="store_true")
    parser.add_argument("--expected-waterline")
    args = parser.parse_args()
    authority = json.loads(args.authority.read_text())
    selected_manifests = manifests(authority)
    if args.write_manifests:
        for name, manifest in selected_manifests.items():
            folder = args.directory / name
            folder.mkdir()
            (folder / "composer.json").write_text(json.dumps(manifest, indent=2) + "\n")
        return
    source = json.loads(args.candidate_composer.read_text())
    candidate = source.get("extra", {}).get("durable-workflow", {}).get("product-train")
    locks = {name: json.loads((args.directory / name / "composer.lock").read_text()) for name in selected_manifests}
    print(json.dumps(validate(authority, candidate, locks, args.expected_waterline), sort_keys=True))


if __name__ == "__main__":
    main()
