#!/usr/bin/env python3
"""Fail closed unless a PHP SDK advance carries a compatible Waterline successor."""

from __future__ import annotations

import argparse
import hashlib
import json
import re
from pathlib import Path
from typing import Any


VERSION = re.compile(
    r"^(?P<core>[0-9]+\.[0-9]+\.[0-9]+)-(?P<channel>alpha|beta|rc)\.(?P<sequence>[1-9][0-9]*)$"
)
SDK_PACKAGE = "durable-workflow/sdk"
WORKFLOW_PACKAGE = "durable-workflow/workflow"


class TrainError(RuntimeError):
    """The planned PHP and Waterline tuple is not publishable."""


def load_json(path: Path, label: str) -> tuple[dict[str, Any], bytes]:
    try:
        raw = path.read_bytes()
        value = json.loads(raw)
    except (OSError, json.JSONDecodeError) as error:
        raise TrainError(f"cannot load {label} {path}: {error}") from error
    if len(raw) > 1024 * 1024 or not isinstance(value, dict):
        raise TrainError(f"{label} must be a JSON object no larger than 1 MiB")
    return value, raw


def versions(plan: dict[str, Any]) -> dict[str, str]:
    components = plan.get("components")
    if not isinstance(components, dict):
        raise TrainError("release plan lacks exact components")
    result: dict[str, str] = {}
    for name in ("workflow", "waterline", "sdk-php"):
        identity = components.get(name)
        version = identity.get("version") if isinstance(identity, dict) else None
        if not isinstance(version, str) or VERSION.fullmatch(version) is None:
            raise TrainError(f"release plan lacks exact prerelease {name} version")
        result[name] = version
    return result


def exact_requirement(manifest: dict[str, Any], table: str, package: str) -> str:
    requirements = manifest.get(table)
    requirement = requirements.get(package) if isinstance(requirements, dict) else None
    if not isinstance(requirement, str) or VERSION.fullmatch(requirement) is None:
        raise TrainError(
            f"Waterline composer.json {table}.{package} must be an exact prerelease"
        )
    return requirement


def sequential(previous: str, successor: str) -> bool:
    before = VERSION.fullmatch(previous)
    after = VERSION.fullmatch(successor)
    return bool(
        before
        and after
        and before.group("core") == after.group("core")
        and before.group("channel") == after.group("channel")
        and int(after.group("sequence")) == int(before.group("sequence")) + 1
    )


def advances(previous: str, successor: str) -> bool:
    before = VERSION.fullmatch(previous)
    after = VERSION.fullmatch(successor)
    return bool(
        before
        and after
        and before.group("core") == after.group("core")
        and before.group("channel") == after.group("channel")
        and int(after.group("sequence")) > int(before.group("sequence"))
    )


def validate(
    plan: dict[str, Any],
    baseline: dict[str, Any],
    manifest: dict[str, Any],
    manifest_raw: bytes,
) -> dict[str, Any]:
    planned = versions(plan)
    current = versions(baseline)
    if manifest.get("name") != "durable-workflow/waterline":
        raise TrainError(
            "planned Waterline composer.json has the wrong package identity"
        )
    sdk = exact_requirement(manifest, "require", SDK_PACKAGE)
    workflow = exact_requirement(manifest, "require-dev", WORKFLOW_PACKAGE)
    waterline = (
        manifest.get("extra", {}).get("durable-workflow", {}).get("product-train")
    )
    if sdk != planned["sdk-php"] or workflow != planned["workflow"]:
        raise TrainError(
            "planned Waterline source is not Composer-satisfiable with the exact PHP SDK and Workflow tuple"
        )
    if waterline != planned["waterline"]:
        raise TrainError(
            "planned Waterline source does not declare its exact release version"
        )

    sdk_changed = planned["sdk-php"] != current["sdk-php"]
    sdk_advanced = advances(current["sdk-php"], planned["sdk-php"])
    if sdk_advanced and not sequential(current["waterline"], planned["waterline"]):
        raise TrainError(
            "a PHP SDK prerelease advance requires the next sequential Waterline prerelease"
        )
    return {
        "schema": "durable-workflow.php-waterline-plan-qualification/v1",
        "outcome": "verified",
        "transition": (
            "paired-sdk-waterline-successor"
            if sdk_advanced
            else "historical-compatible-pair"
            if sdk_changed
            else "compatible-current-pair"
        ),
        "versions": planned,
        "waterline_composer_sha256": hashlib.sha256(manifest_raw).hexdigest(),
    }


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--plan", required=True, type=Path)
    parser.add_argument("--baseline", required=True, type=Path)
    parser.add_argument("--waterline-composer", required=True, type=Path)
    parser.add_argument("--evidence", required=True, type=Path)
    args = parser.parse_args()

    plan, _ = load_json(args.plan, "release plan")
    baseline, _ = load_json(args.baseline, "baseline release plan")
    manifest, manifest_raw = load_json(
        args.waterline_composer, "Waterline composer manifest"
    )
    evidence = validate(plan, baseline, manifest, manifest_raw)
    args.evidence.write_text(
        json.dumps(evidence, indent=2, sort_keys=True, ensure_ascii=True) + "\n",
        encoding="utf-8",
    )
    print(
        "Verified planned Composer tuple "
        f"Waterline {evidence['versions']['waterline']}, "
        f"Workflow {evidence['versions']['workflow']}, "
        f"PHP SDK {evidence['versions']['sdk-php']}"
    )
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
