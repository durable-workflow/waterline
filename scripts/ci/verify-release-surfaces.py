#!/usr/bin/env python3
"""Verify every source-bound Waterline distribution for release completion."""

from __future__ import annotations

import argparse
import importlib.util
import json
import os
import sys
import time
from pathlib import Path
from typing import Any


RECOVERY_PATH = Path(__file__).with_name("component-release-recovery.py")
SPEC = importlib.util.spec_from_file_location(
    "waterline_component_release_recovery", RECOVERY_PATH
)
if SPEC is None or SPEC.loader is None:
    raise RuntimeError(f"cannot load {RECOVERY_PATH}")
recovery = importlib.util.module_from_spec(SPEC)
sys.modules[SPEC.name] = recovery
SPEC.loader.exec_module(recovery)

SERVICE_IMAGE = recovery.Component(
    "durable-workflow/waterline",
    "v2",
    "oci",
    "docker.io/durableworkflow/waterline",
    (),
    None,
    None,
)


def verify(client: Any, version: str) -> dict[str, Any]:
    identity = {
        "version": version,
        "commit": recovery.resolve_tag(client, "durable-workflow/waterline", version),
    }
    if identity["commit"] is None:
        raise recovery.NotFound(
            f"Waterline source tag {version} is absent", "source-tag"
        )
    recovery.require_source_tag(client, "waterline", identity)
    return {
        "schema": "durable-workflow.waterline-release-surfaces/v1",
        "outcome": "verified",
        "version": version,
        "source_commit": identity["commit"],
        "github_release": recovery.verify_github_release(client, "waterline", version),
        "packagist": recovery.verify_composer(
            client,
            recovery.COMPONENTS["waterline"],
            version,
            identity["commit"],
        ),
        "service_image": recovery.verify_oci(
            client,
            SERVICE_IMAGE,
            version,
            identity["commit"],
        ),
    }


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--version", required=True)
    parser.add_argument("--attempts", type=int, default=1)
    parser.add_argument("--sleep", type=int, default=0)
    parser.add_argument("--evidence", required=True, type=Path)
    args = parser.parse_args()
    if args.attempts < 1 or args.sleep < 0:
        parser.error("attempts must be positive and sleep must be non-negative")

    client = recovery.PublicClient(
        os.environ.get("GITHUB_TOKEN") or os.environ.get("GH_TOKEN")
    )
    last_error: Exception | None = None
    for attempt in range(1, args.attempts + 1):
        try:
            evidence = verify(client, args.version)
            args.evidence.write_text(
                json.dumps(evidence, indent=2, sort_keys=True, ensure_ascii=True)
                + "\n",
                encoding="utf-8",
            )
            print(
                f"Verified Waterline {args.version} GitHub release, Packagist package, "
                "and source-bound service image"
            )
            return 0
        except (recovery.NotFound, recovery.RecoveryError) as error:
            last_error = error
            if attempt < args.attempts:
                print(
                    f"Waterline release surfaces are incomplete ({attempt}/{args.attempts}): {error}",
                    file=sys.stderr,
                )
                time.sleep(args.sleep)

    assert last_error is not None
    args.evidence.write_text(
        json.dumps(
            {
                "schema": "durable-workflow.waterline-release-surfaces/v1",
                "outcome": "incomplete",
                "version": args.version,
                "reason": str(last_error),
            },
            indent=2,
            sort_keys=True,
            ensure_ascii=True,
        )
        + "\n",
        encoding="utf-8",
    )
    raise last_error


if __name__ == "__main__":
    raise SystemExit(main())
