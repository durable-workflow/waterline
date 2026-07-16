#!/usr/bin/env python3
"""Create or verify one immutable Waterline release-plan tag through a Git remote."""

from __future__ import annotations

import argparse
import datetime as dt
import json
import re
import subprocess
import sys
from pathlib import Path
from typing import Any

COMMIT_PATTERN = re.compile(r"^[0-9a-f]{40}$")
PLAN_TAG_PATTERN = re.compile(r"^release-plan/[a-z0-9][a-z0-9._-]{0,55}$")
TAG_PATTERN = re.compile(r"^[0-9]+\.[0-9]+\.[0-9]+(?:[-+][0-9A-Za-z][0-9A-Za-z.-]*)?$")
SCHEMA = "durable-workflow.release-tag-publication/v1"
MAX_REMOTE_DIAGNOSTIC = 2048
PERMISSION_BOUNDARY = (
    "repository write deploy key release-plan-publication; private key available only in the "
    "required-reviewer release-plan-publication GitHub environment; environment branch policy v2"
)

ANSI_ESCAPE_PATTERN = re.compile(r"\x1b(?:\[[0-?]*[ -/]*[@-~]|\][^\x07]*(?:\x07|\x1b\\))")
PRIVATE_KEY_BLOCK_PATTERN = re.compile(
    r"-----BEGIN [^\r\n-]*PRIVATE KEY-----.*?(?:-----END [^\r\n-]*PRIVATE KEY-----|\Z)",
    re.IGNORECASE | re.DOTALL,
)
CREDENTIAL_PATTERN = re.compile(
    r"\b(authorization|proxy-authorization|[A-Za-z0-9_-]*"
    r"(?:token|secret|password|passwd|private[_ -]?key|deploy[_ -]?key)[A-Za-z0-9_-]*)"
    r"(\s*[:=]\s*)(?:bearer\s+|basic\s+)?[^\s,;]+",
    re.IGNORECASE,
)
GITHUB_TOKEN_PATTERN = re.compile(
    r"\b(?:gh[pousr]_[A-Za-z0-9_]{20,}|github_pat_[A-Za-z0-9_]{20,})\b"
)
URL_CREDENTIAL_PATTERN = re.compile(r"\b((?:https?|ssh)://)[^/\s@]+@", re.IGNORECASE)


def sanitize_remote_diagnostic(value: str) -> str:
    """Retain useful remote output without persisting credentials or unbounded data."""

    diagnostic = ANSI_ESCAPE_PATTERN.sub("", value)
    diagnostic = PRIVATE_KEY_BLOCK_PATTERN.sub("[REDACTED PRIVATE KEY]", diagnostic)
    diagnostic = URL_CREDENTIAL_PATTERN.sub(r"\1[REDACTED]@", diagnostic)
    diagnostic = CREDENTIAL_PATTERN.sub(r"\1\2[REDACTED]", diagnostic)
    diagnostic = GITHUB_TOKEN_PATTERN.sub("[REDACTED]", diagnostic)
    diagnostic = re.sub(r"[\x00-\x08\x0b-\x1f\x7f]", "?", diagnostic).strip()
    if not diagnostic:
        return "No remote diagnostic was emitted by Git"
    if len(diagnostic) > MAX_REMOTE_DIAGNOSTIC:
        marker = "\n[diagnostic truncated]"
        diagnostic = diagnostic[: MAX_REMOTE_DIAGNOSTIC - len(marker)].rstrip() + marker
    return diagnostic


class PublicationError(RuntimeError):
    """The planned Git ref cannot be advanced safely."""

    def __init__(
        self,
        message: str,
        *,
        phase: str,
        operation: str | None = None,
        remote_diagnostic: str | None = None,
        evidence: dict[str, Any] | None = None,
        safe_recovery_action: str | None = None,
    ) -> None:
        super().__init__(message)
        self.phase = phase
        self.operation = operation
        self.evidence = evidence or {}
        self.safe_recovery_action = safe_recovery_action
        self.remote_diagnostic = (
            sanitize_remote_diagnostic(remote_diagnostic) if remote_diagnostic is not None else None
        )


def canonical_json(value: Any) -> bytes:
    return (json.dumps(value, indent=2, sort_keys=True, ensure_ascii=True) + "\n").encode()


def run_git(*arguments: str) -> subprocess.CompletedProcess[str]:
    return subprocess.run(
        ["git", *arguments],
        check=False,
        text=True,
        stdout=subprocess.PIPE,
        stderr=subprocess.PIPE,
    )


def resolve_remote_tag(remote: str, ref: str) -> str | None:
    operation = f"git ls-remote {sanitize_remote_diagnostic(remote)} {ref}"
    result = run_git("ls-remote", "--", remote, ref, f"{ref}^{{}}")
    if result.returncode:
        raise PublicationError(
            f"repository authority could not read {ref} (git exit {result.returncode})",
            phase="repository-authority",
            operation=operation,
            remote_diagnostic=result.stderr,
        )

    targets: dict[str, str] = {}
    for line in result.stdout.splitlines():
        fields = line.split("\t", 1)
        if len(fields) == 2 and fields[1] in {ref, f"{ref}^{{}}"}:
            targets[fields[1]] = fields[0]
    if not targets:
        return None
    target = targets.get(f"{ref}^{{}}", targets.get(ref))
    if target is None or not COMMIT_PATTERN.fullmatch(target):
        raise PublicationError(
            f"remote {ref} has an invalid Git object identity",
            phase="source-tag",
            operation=operation,
        )
    return target


def require_local_commit(commit: str) -> None:
    operation = f"git cat-file -t {commit}"
    result = run_git("cat-file", "-t", commit)
    if result.returncode or result.stdout.strip() != "commit":
        raise PublicationError(
            f"planned commit {commit} is not available as a commit in the clean checkout",
            phase="source-checkout",
            operation=operation,
        )


def source_identity_recovery(plan_tag: str, tag: str) -> str:
    return (
        f"Keep refs/tags/{tag} absent for {plan_tag}; admit a protected successor plan whose Waterline "
        "source commit declares the durable-workflow/waterline Composer package identity, then rerun "
        "Release plan recovery; do not tag or publish the conflicting source commit"
    )


def require_source_identity(commit: str, tag: str, plan_tag: str) -> None:
    require_local_commit(commit)
    operation = f"git show {commit}:composer.json"
    result = run_git("show", f"{commit}:composer.json")
    package_name: str | None = None
    legacy_replacement: str | None = None
    if result.returncode == 0:
        try:
            manifest = json.loads(result.stdout)
            if isinstance(manifest, dict):
                package_name = manifest.get("name") if isinstance(manifest.get("name"), str) else None
                replace = manifest.get("replace")
                if isinstance(replace, dict) and isinstance(replace.get("laravel-workflow/waterline"), str):
                    legacy_replacement = replace["laravel-workflow/waterline"]
        except json.JSONDecodeError:
            pass

    if package_name != "durable-workflow/waterline" or legacy_replacement != "self.version":
        identity = (
            f"package {package_name or '<missing>'} with legacy replacement "
            f"{legacy_replacement or '<missing>'}"
            if result.returncode == 0
            else "no readable composer.json"
        )
        raise PublicationError(
            f"planned commit {commit} declares {identity}, not the Waterline Composer source identity",
            phase="source-identity",
            operation=operation,
            remote_diagnostic=result.stderr if result.returncode else None,
            evidence={
                "classification": "terminal-source-identity-conflict",
                "manifest_path": "composer.json",
                "package": package_name,
                "legacy_replacement": legacy_replacement,
                "planned_version": tag,
            },
            safe_recovery_action=source_identity_recovery(plan_tag, tag),
        )


def safe_recovery(plan_tag: str) -> str:
    return (
        "Approve or restore the repository's release-plan-publication environment and write deploy key, "
        f"then rerun Release plan recovery for {plan_tag}; do not move the tag, push it from a workstation, "
        "or substitute a personal credential"
    )


def evidence_base(tag: str, commit: str, plan_tag: str, remote: str) -> dict[str, Any]:
    return {
        "schema": SCHEMA,
        "release_plan_tag": plan_tag,
        "attempted_ref": f"refs/tags/{tag}",
        "planned_commit": commit,
        "remote": sanitize_remote_diagnostic(remote),
        "effective_permission_boundary": PERMISSION_BOUNDARY,
        "observed_at": dt.datetime.now(dt.UTC).replace(microsecond=0).isoformat().replace("+00:00", "Z"),
    }


def validate_arguments(tag: str, commit: str, plan_tag: str, remote: str) -> None:
    if not TAG_PATTERN.fullmatch(tag):
        raise PublicationError("release tag must be exact SemVer", phase="input")
    if not COMMIT_PATTERN.fullmatch(commit):
        raise PublicationError("planned commit must be a full lowercase Git commit", phase="input")
    if not PLAN_TAG_PATTERN.fullmatch(plan_tag):
        raise PublicationError("release plan tag has an invalid identity", phase="input")
    if not remote or remote.startswith("-") or any(character in remote for character in "\r\n\0"):
        raise PublicationError("Git remote has an invalid identity", phase="input")


def publish_tag(remote: str, tag: str, commit: str, plan_tag: str) -> dict[str, Any]:
    ref = f"refs/tags/{tag}"
    require_source_identity(commit, tag, plan_tag)
    existing = resolve_remote_tag(remote, ref)
    if existing is not None:
        if existing != commit:
            raise PublicationError(
                f"existing {ref} resolves to {existing}, not planned commit {commit}",
                phase="source-tag",
                operation=f"git ls-remote {sanitize_remote_diagnostic(remote)} {ref}",
            )
        return {"action": "verified", "ref": ref, "commit": existing}

    operation = f"git push {sanitize_remote_diagnostic(remote)} {commit}:{ref}"
    pushed = run_git("push", "--porcelain", "--", remote, f"{commit}:{ref}")

    # A concurrent identical recovery may win the race. The remote ref, rather
    # than the push exit status, is the final authority for idempotency.
    observed = resolve_remote_tag(remote, ref)
    if observed != commit:
        if observed is not None:
            raise PublicationError(
                f"post-push {ref} resolves to {observed}, not planned commit {commit}",
                phase="source-tag",
                operation=operation,
                remote_diagnostic=pushed.stderr if pushed.returncode else None,
            )
        raise PublicationError(
            f"repository authority rejected creation of {ref} at planned commit {commit} "
            f"(git exit {pushed.returncode})",
            phase="repository-authority",
            operation=operation,
            remote_diagnostic=pushed.stderr,
        )
    return {
        "action": "created" if pushed.returncode == 0 else "verified-after-race",
        "ref": ref,
        "commit": observed,
    }


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--remote", default="origin")
    parser.add_argument("--tag", required=True)
    parser.add_argument("--commit", required=True)
    parser.add_argument("--plan-tag", required=True)
    parser.add_argument("--evidence", required=True, type=Path)
    args = parser.parse_args()

    state = evidence_base(args.tag, args.commit, args.plan_tag, args.remote)
    try:
        validate_arguments(args.tag, args.commit, args.plan_tag, args.remote)
        publication = publish_tag(args.remote, args.tag, args.commit, args.plan_tag)
        state.update(
            {
                "phase": "source-tag",
                "outcome": "verified",
                "action": publication["action"],
                "source_tag": {"ref": publication["ref"], "commit": publication["commit"]},
                "safe_recovery_action": "No action is required",
            }
        )
        args.evidence.write_bytes(canonical_json(state))
        print(
            f"{publication['action']} {publication['ref']} at planned commit {publication['commit']} "
            f"through {PERMISSION_BOUNDARY}"
        )
        return 0
    except PublicationError as error:
        recovery = error.safe_recovery_action or safe_recovery(args.plan_tag)
        state.update(
            {
                "phase": error.phase,
                "outcome": "failed",
                "reason": str(error),
                "git_operation": error.operation,
                "safe_recovery_action": recovery,
            }
        )
        state.update(error.evidence)
        if error.remote_diagnostic is not None:
            state["remote_diagnostic"] = error.remote_diagnostic
        args.evidence.write_bytes(canonical_json(state))
        diagnostic = f"; remote diagnostic: {error.remote_diagnostic}" if error.remote_diagnostic else ""
        print(
            f"release tag publication failed for refs/tags/{args.tag} at planned commit {args.commit}; "
            f"effective permission boundary: {PERMISSION_BOUNDARY}; reason: {error}{diagnostic}; "
            f"safe recovery: {recovery}",
            file=sys.stderr,
        )
        return 1


if __name__ == "__main__":
    raise SystemExit(main())
