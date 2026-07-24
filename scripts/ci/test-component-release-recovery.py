#!/usr/bin/env python3
"""Focused regression coverage for release recovery workflow verification."""

from __future__ import annotations

import datetime as dt
import hashlib
import importlib.util
import io
import json
import sys
import tempfile
import unittest
import urllib.error
from pathlib import Path
from unittest import mock

from cli_release_verifier_contract import (  # noqa: F401
    CliRecoveryWorkflowSourceTest,
    CliReleaseAuthorityTest,
)
from recovery_workflow_authority import (
    SCHEMA as AUTHORITY_SCHEMA,
)
from recovery_workflow_authority import (
    SOURCE_IDENTITY,
    authority_ref_url,
    authority_url,
    qualification_runs_url,
)

SCRIPT = Path(__file__).with_name("component-release-recovery.py")
SPEC = importlib.util.spec_from_file_location("component_release_recovery", SCRIPT)
assert SPEC is not None and SPEC.loader is not None
recovery = importlib.util.module_from_spec(SPEC)
sys.modules[SPEC.name] = recovery
SPEC.loader.exec_module(recovery)

REPOSITORY_ROOT = Path(__file__).resolve().parents[2]
WATERLINE_WORKFLOW_PATH = REPOSITORY_ROOT / ".github" / "workflows" / "release-plan-recovery.yml"
WATERLINE_WORKFLOW_BYTES = WATERLINE_WORKFLOW_PATH.read_bytes()
WATERLINE_WORKFLOW = WATERLINE_WORKFLOW_BYTES.decode("utf-8")
SCREENSHOTS_WORKFLOW_PATH = (
    REPOSITORY_ROOT / ".github" / "workflows" / "screenshots.yml"
)
SCREENSHOTS_WORKFLOW = SCREENSHOTS_WORKFLOW_PATH.read_text(encoding="utf-8")
RUST_WORKFLOW_PATH = Path(__file__).with_name("fixtures") / "sdk-rust-release-plan-recovery.yml"
RUST_WORKFLOW_BYTES = RUST_WORKFLOW_PATH.read_bytes()
RUST_WORKFLOW = RUST_WORKFLOW_BYTES.decode("utf-8")

SERVER_WORKFLOW = """name: Release plan recovery

on:
  schedule:
  workflow_dispatch:

jobs:
  recover:
    steps:
      - run: |
          python recovery.py resolve --preparation-output release-preparation.json
          gh api --method POST repos/example/git/refs \\
            -f ref="refs/tags/$RELEASE_TAG" -f sha="$RELEASE_COMMIT"
          select-publication-run
          gh run list --json databaseId,displayTitle,headBranch,headSha,status,conclusion
          python recovery.py --release-tag "$RELEASE_TAG" --release-commit "$RELEASE_COMMIT"
          gh workflow run release.yml --ref "$RELEASE_TAG" -f tag="$RELEASE_TAG"
"""


def github_http_error(status: int, body: bytes = b"error", **headers: str) -> urllib.error.HTTPError:
    return urllib.error.HTTPError(
        "https://api.github.com/repos/durable-workflow/.github/releases",
        status,
        "request failed",
        headers,
        io.BytesIO(body),
    )


def load_recovery_for_retry_tests():
    loaded = globals().get("recovery")
    if loaded is not None:
        return loaded
    loader = globals().get("load_recovery_module")
    if not callable(loader):
        raise RuntimeError("release recovery module loader is unavailable")
    return loader()


AUTHORITY_COMMIT = "a" * 40


def continuity_resolution_qualification() -> dict[str, object]:
    return {
        "repository": "durable-workflow/.github",
        "workflow": ".github/workflows/beta-candidate.yml",
        "event": "push",
        "head_branch": "main",
        "head_sha": "9" * 40,
        "run_id": 987,
        "run_attempt": 2,
        "status": "completed",
        "conclusion": "success",
    }


def continuity_resolution_qualification_run() -> dict[str, object]:
    qualification = continuity_resolution_qualification()
    return {
        "id": qualification["run_id"],
        "run_attempt": qualification["run_attempt"],
        "repository": {"full_name": "durable-workflow/.github"},
        "head_repository": {"full_name": "durable-workflow/.github"},
        "path": ".github/workflows/beta-candidate.yml@main",
        "event": qualification["event"],
        "head_branch": qualification["head_branch"],
        "head_sha": qualification["head_sha"],
        "status": qualification["status"],
        "conclusion": qualification["conclusion"],
    }



def lifecycle_plan(module, channel: str = "alpha") -> dict[str, object]:
    prerelease = "alpha" if channel == "alpha" else "beta"
    return {
        "schema": module.SCHEMA,
        "plan": "component-recovery",
        "channel": channel,
        "foundation": {"tag": module.FOUNDATION_TAG, "commit": module.FOUNDATION_COMMIT},
        "components": {
            name: {
                "version": (
                    f"2.0.0-{prerelease}.{index + 1}"
                    if name in {"workflow", "waterline"}
                    else f"1.0.{index}"
                ),
                "commit": f"{index + 1:040x}",
            }
            for index, name in enumerate(module.COMPONENTS)
        },
        "beta_authorization": (
            {"tag": "beta-authorization/component-recovery", "commit": "f" * 40}
            if channel == "beta"
            else None
        ),
    }


def supersession_record(module, failed, successor, failed_commit: str) -> dict[str, object]:
    identity = failed["components"]["workflow"]
    observed_commit = "e" * 40
    environment_url = (
        "https://github.com/durable-workflow/.github/deployments/activity_log?"
        "environments_filter=release-plan-supersession"
    )
    protection = {
        "custom_branch_policies": [{"id": 22, "name": "main"}],
        "deployment_branch_policy": {
            "custom_branch_policies": True,
            "protected_branches": False,
        },
        "environment_id": 11,
        "environment_url": environment_url,
        "required_reviewer_rule_ids": [33],
    }
    return {
        "schema": "durable-workflow.release-plan-failure/v1",
        "outcome": "terminal-failure",
        "failed_plan": {
            "tag": f"release-plan/{failed['plan']}",
            "commit": failed_commit,
            "sha256": module.manifest_digest(failed),
        },
        "conflicts": [
            {
                "component": "workflow",
                "version": identity["version"],
                "planned_commit": identity["commit"],
                "observed_commit": observed_commit,
                "reason": "published-version-source-conflict",
                "github_release": {
                    "id": 44,
                    "url": "https://github.com/durable-workflow/workflow/releases/44",
                },
                "distribution": {
                    "kind": "composer",
                    "source_reference": observed_commit,
                    "dist_reference": observed_commit,
                },
            }
        ],
        "successor_plan": {
            "tag": f"release-plan/{successor['plan']}",
            "sha256": module.manifest_digest(successor),
        },
        "authorization": {
            "actor": "release-operator",
            "environment": "release-plan-supersession",
            "environment_approval": {
                "comment": "approved",
                "environments": [
                    {
                        "html_url": environment_url,
                        "id": 11,
                        "name": "release-plan-supersession",
                        "node_id": "environment-node",
                        "url": (
                            "https://api.github.com/repos/durable-workflow/.github/"
                            "environments/release-plan-supersession"
                        ),
                    }
                ],
                "run_attempt": 1,
                "run_id": 456,
                "state": "approved",
                "user": {
                    "html_url": "https://github.com/release-reviewer",
                    "id": 55,
                    "login": "release-reviewer",
                    "node_id": "reviewer-node",
                    "url": "https://api.github.com/users/release-reviewer",
                },
            },
            "environment_protection": protection,
            "repository": "durable-workflow/.github",
            "run_attempt": 1,
            "run_id": 456,
            "run_url": "https://github.com/durable-workflow/.github/actions/runs/456",
            "workflow_commit": "f" * 40,
            "workflow_ref": (
                "durable-workflow/.github/.github/workflows/"
                "release-plan-supersession.yml@refs/heads/main"
            ),
        },
    }


def captured_github_authority(module, record: dict[str, object]) -> list[object]:
    authorization = record["authorization"]
    protection = authorization["environment_protection"]
    approval = authorization["environment_approval"]
    return [
        {
            "id": protection["environment_id"],
            "html_url": protection["environment_url"],
            "protection_rules": [
                {
                    "id": protection["required_reviewer_rule_ids"][0],
                    "type": "required_reviewers",
                    "reviewers": [
                        {
                            "type": "User",
                            "reviewer": {
                                **approval["user"],
                                "avatar_url": "https://avatars.githubusercontent.com/u/55?v=4",
                                "site_admin": False,
                                "type": "User",
                            },
                        }
                    ],
                }
            ],
            "deployment_branch_policy": protection["deployment_branch_policy"],
        },
        {
            "total_count": 1,
            "branch_policies": [
                {**protection["custom_branch_policies"][0], "type": "branch"}
            ],
        },
        {
            "actor": {"login": authorization["actor"]},
            "conclusion": "success",
            "event": "workflow_dispatch",
            "head_branch": "main",
            "head_sha": authorization["workflow_commit"],
            "html_url": authorization["run_url"],
            "id": authorization["run_id"],
            "path": f"{module.SUPERSESSION_WORKFLOW}@main",
            "repository": {"full_name": module.CONTROL_REPOSITORY},
            "run_attempt": authorization["run_attempt"],
            "status": "completed",
        },
        [
            {
                "comment": approval["comment"],
                "environments": [
                    {
                        **approval["environments"][0],
                        "can_admins_bypass": True,
                        "created_at": "2026-07-23T00:00:00Z",
                        "updated_at": "2026-07-23T00:00:00Z",
                    }
                ],
                "state": approval["state"],
                "user": {
                    **approval["user"],
                    "avatar_url": "https://avatars.githubusercontent.com/u/55?v=4",
                    "site_admin": False,
                    "type": "User",
                },
            }
        ],
    ]


class ExplicitTerminalLifecycleRegistry:
    component_name = "waterline"

    def __init__(
        self,
        module,
        shape: str,
        *,
        visible_from_round: int,
    ) -> None:
        self.module = module
        self.shape = shape
        self.visible_from_round = visible_from_round
        self.classification_round = 0
        self.failed = lifecycle_plan(module)
        self.failed["plan"] = "failed-plan"
        self.successor = json.loads(json.dumps(self.failed))
        self.successor["plan"] = "successor-plan"
        self.successor["components"]["workflow"]["version"] = "2.0.0-alpha.2"
        self.failed_tag = f"{module.PLAN_TAG_PREFIX}{self.failed['plan']}"
        self.successor_tag = f"{module.PLAN_TAG_PREFIX}{self.successor['plan']}"
        self.failed_commit = "a" * 40
        self.successor_commit = "b" * 40
        self.failure_commit = "c" * 40
        self.interruption_commit = "d" * 40
        self.acceptance_commit = "e" * 40
        self.tags = [self.failed_tag, self.successor_tag]
        self.commits = {
            self.failed_tag: self.failed_commit,
            self.successor_tag: self.successor_commit,
        }
        self.recorded_at = {
            self.failed_commit: dt.datetime(2026, 7, 20, tzinfo=dt.UTC),
            self.successor_commit: dt.datetime(2026, 7, 21, tzinfo=dt.UTC),
        }
        self.preparation = {
            "components": {
                self.component_name: {
                    "release_notes": {
                        "release_date": "2026-07-23",
                        "sha256": "c" * 64,
                        "source": {},
                    }
                }
            }
        }
        self.failure_tag = f"{module.FAILURE_TAG_PREFIX}{self.failed['plan']}"
        self.failure = supersession_record(
            module,
            self.failed,
            self.successor,
            self.failed_commit,
        )
        self.interruption_tag = (
            f"{module.CONTINUITY_TAG_PREFIX}{self.failed['plan']}/interrupted"
        )
        failed_digest = module.manifest_digest(self.failed)
        self.interruption_evidence = {
            "schema": module.CONTINUITY_EVIDENCE_SCHEMA,
            "phase": "interrupted",
            "outcome": "intentionally-interrupted",
            "release_plan": {
                "tag": self.failed_tag,
                "sha256": failed_digest,
            },
            "plan_record": {
                "tag": self.failed_tag,
                "commit": self.failed_commit,
                "sha256": failed_digest,
            },
        }
        self.acceptance_tag = (
            f"{module.CONTINUITY_TAG_PREFIX}{self.successor['plan']}/accepted"
        )
        successor_digest = module.manifest_digest(self.successor)
        self.acceptance_evidence = {
            "schema": module.CONTINUITY_EVIDENCE_SCHEMA,
            "phase": "accepted",
            "outcome": "accepted",
            "release_plan": {
                "tag": self.successor_tag,
                "sha256": successor_digest,
            },
            "candidate_identity": {
                "components": self.successor["components"],
                "plan_sha256": successor_digest,
            },
            "superseded_interruption": {
                "tag": self.interruption_tag,
                "commit": self.interruption_commit,
                "evidence_sha256": module.manifest_digest(self.interruption_evidence),
                "plan_sha256": failed_digest,
                "reason": module.CONTINUITY_SUPERSESSION_REASON,
            },
        }
        self.authority_responses = captured_github_authority(module, self.failure)
        self.client = mock.Mock()
        self.client.json.side_effect = self.public_json
        self.artifact_verifier = mock.Mock(
            side_effect=module.NotFound("component artifact is absent")
        )
        self.dependency_verifier = mock.Mock(return_value={"status": "verified"})

    def terminal_visible(self) -> bool:
        return self.classification_round >= self.visible_from_round

    def public_json(self, url: str, **_kwargs):
        if "/releases/tags/" in url:
            return {"tag_name": self.failed_tag, "draft": False, "assets": []}
        if self.authority_responses:
            return self.authority_responses.pop(0)
        raise AssertionError(f"unexpected public JSON request: {url}")

    def list_release_plan_tags(self, _client) -> list[str]:
        self.classification_round += 1
        return self.tags

    def resolve_tag(self, _client, repository: str, tag: str) -> str | None:
        if repository == self.module.CONTROL_REPOSITORY:
            if tag in self.commits:
                return self.commits[tag]
            if (
                self.shape == "terminal-failure"
                and tag == self.failure_tag
                and self.terminal_visible()
            ):
                return self.failure_commit
            if self.shape == "accepted-continuity":
                if tag == self.interruption_tag:
                    return self.interruption_commit
                if tag == self.acceptance_tag and self.terminal_visible():
                    return self.acceptance_commit
            return None
        if repository == self.module.COMPONENTS[self.component_name].repository:
            return None
        raise AssertionError(f"unexpected tag repository: {repository}@{tag}")

    def read_plan_authority(
        self,
        _client,
        tag: str,
        commit: str,
    ) -> tuple[dict[str, object], dict[str, object]]:
        if self.commits.get(tag) != commit:
            raise AssertionError(f"unexpected plan authority: {tag}@{commit}")
        plan = self.failed if tag == self.failed_tag else self.successor
        return plan, self.preparation

    def read_record(
        self,
        _client,
        tag: str,
        _commit: str,
        filename: str,
    ) -> dict[str, object]:
        records = {
            (self.failure_tag, "release-plan-failure.json"): self.failure,
            (self.failure_tag, "successor-release-plan.json"): self.successor,
            (self.interruption_tag, "continuity-evidence.json"): (
                self.interruption_evidence
            ),
            (self.interruption_tag, "release-plan.json"): self.failed,
            (self.acceptance_tag, "continuity-evidence.json"): (
                self.acceptance_evidence
            ),
            (self.acceptance_tag, "release-plan.json"): self.successor,
        }
        try:
            return records[(tag, filename)]
        except KeyError as error:
            raise AssertionError(
                f"unexpected immutable record: {tag}/{filename}"
            ) from error

    def immutable_plan_recorded_at(self, _client, commit: str) -> dt.datetime:
        return self.recorded_at[commit]


def qualification_run(
    status: str = "completed",
    conclusion: str | None = "success",
    *,
    head_sha: str = AUTHORITY_COMMIT,
    head_branch: str = "main",
    path: str = ".github/workflows/beta-candidate.yml",
) -> dict[str, object]:
    return {
        "id": 81,
        "run_attempt": 2,
        "name": "Beta candidate",
        "workflow_id": 37,
        "path": path,
        "event": "push",
        "head_branch": head_branch,
        "head_sha": head_sha,
        "status": status,
        "conclusion": conclusion,
        "url": "https://api.github.com/repos/durable-workflow/.github/actions/runs/81",
        "html_url": "https://github.com/durable-workflow/.github/actions/runs/81",
    }


class QualifiedAuthorityConsumerTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls) -> None:
        cls.recovery = load_recovery_for_retry_tests()

    def authority(self) -> dict[str, object]:
        return {
            "schema": AUTHORITY_SCHEMA,
            "source": SOURCE_IDENTITY,
            "workflows": {
                name: {
                    "repository": component.repository,
                    "ref": f"refs/heads/{component.default_branch}",
                    "path": ".github/workflows/release-plan-recovery.yml",
                    "state": "active",
                    "sha256": "b" * 64,
                }
                for name, component in self.recovery.COMPONENTS.items()
            },
        }

    def client(self, runs: list[dict[str, object]]):
        authority_raw = json.dumps(self.authority()).encode("utf-8")

        class Client:
            def __init__(self) -> None:
                self.requests: list[tuple[str, str]] = []

            def json(self, url: str) -> dict[str, object]:
                self.requests.append(("json", url))
                if url == authority_ref_url():
                    return {"sha": AUTHORITY_COMMIT}
                if url == qualification_runs_url(AUTHORITY_COMMIT):
                    return {"total_count": len(runs), "workflow_runs": runs}
                raise AssertionError(f"peer source was read before authority qualification: {url}")

            def bytes(self, url: str, *, accept: str | None = None) -> bytes:
                self.requests.append(("bytes", url))
                if url != authority_url(AUTHORITY_COMMIT):
                    raise AssertionError(f"peer source was read before authority qualification: {url}")
                return authority_raw

        return Client(), authority_raw

    def test_green_qualification_binds_manifest_bytes_and_revision(self) -> None:
        client, authority_raw = self.client([qualification_run()])
        workflows, source = self.recovery.load_recovery_workflow_authority(client)

        self.assertEqual(set(self.recovery.COMPONENTS), set(workflows))
        self.assertEqual(AUTHORITY_COMMIT, source["commit"])
        self.assertEqual(hashlib.sha256(authority_raw).hexdigest(), source["sha256"])
        self.assertEqual(AUTHORITY_COMMIT, source["qualification"]["head_sha"])
        self.assertEqual(".github/workflows/beta-candidate.yml", source["qualification"]["path"])
        self.assertEqual("main", source["qualification"]["head_branch"])
        self.assertEqual(
            [
                ("json", authority_ref_url()),
                ("json", qualification_runs_url(AUTHORITY_COMMIT)),
                ("bytes", authority_url(AUTHORITY_COMMIT)),
            ],
            client.requests,
        )

    def test_non_green_fails_before_authority_or_peer_source_reads(self) -> None:
        cases = (
            ("pending", [qualification_run("in_progress", None)], "pending"),
            ("failed", [qualification_run("completed", "failure")], "failed"),
            ("cancelled", [qualification_run("completed", "cancelled")], "cancelled"),
            ("absent", [], "absent"),
            ("revision-mismatch", [qualification_run(head_sha="c" * 40)], "another commit"),
            (
                "wrong-workflow",
                [qualification_run(path=".github/workflows/source-qualification.yml")],
                "absent",
            ),
            ("wrong-ref", [qualification_run(head_branch="v2")], "absent"),
            (
                "wrong-path-ref",
                [qualification_run(path=".github/workflows/beta-candidate.yml@v2")],
                "absent",
            ),
        )
        for label, runs, message in cases:
            with self.subTest(state=label):
                client, _authority_raw = self.client(runs)
                with self.assertRaisesRegex(self.recovery.RecoveryError, message):
                    self.recovery.load_recovery_workflow_authority(client)
                self.assertEqual(
                    [
                        ("json", authority_ref_url()),
                        ("json", qualification_runs_url(AUTHORITY_COMMIT)),
                    ],
                    client.requests,
                )


class ContinuityGateTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls) -> None:
        cls.recovery = load_recovery_for_retry_tests()

    def test_scheduled_recovery_pauses_until_remote_resume(self) -> None:
        plan = {"plan": "workspace-unavailable-test"}
        with (
            mock.patch.object(
                self.recovery,
                "resolve_tag",
                side_effect=["a" * 40, None],
            ),
            mock.patch.object(self.recovery, "read_record", return_value=plan),
            mock.patch.object(self.recovery, "validate_plan"),
        ):
            paused = self.recovery.scheduled_continuity_pause(mock.Mock(), plan)

        self.assertEqual(
            "beta-continuity/workspace-unavailable-test/resumed",
            paused["resumed_tag"],
        )
        with (
            mock.patch.object(
                self.recovery,
                "resolve_tag",
                side_effect=["a" * 40, "b" * 40],
            ),
            mock.patch.object(self.recovery, "read_record", return_value=plan),
            mock.patch.object(self.recovery, "validate_plan"),
        ):
            self.assertIsNone(self.recovery.scheduled_continuity_pause(mock.Mock(), plan))


class PublicClientRetryTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls) -> None:
        cls.recovery = load_recovery_for_retry_tests()

    def test_authenticated_requests_preserve_endpoint_api_versions(self) -> None:
        cases = (
            ({"X-GitHub-Api-Version": self.recovery.SUPERSESSION_API_VERSION}, self.recovery.SUPERSESSION_API_VERSION),
            ({}, "2022-11-28"),
        )
        for headers, expected_version in cases:
            with self.subTest(expected_version=expected_version):
                client = self.recovery.PublicClient(token="test-token")
                response = mock.Mock()
                with mock.patch.object(
                    self.recovery.urllib.request, "urlopen", return_value=response
                ) as open_url:
                    self.assertIs(
                        response,
                        client.request(
                            "https://api.github.com/repos/durable-workflow/.github/actions/runs/456",
                            headers=headers,
                        ),
                    )
                request = open_url.call_args.args[0]
                request_headers = {key.lower(): value for key, value in request.header_items()}
                self.assertEqual("Bearer test-token", request_headers["authorization"])
                self.assertEqual(expected_version, request_headers["x-github-api-version"])

    def test_retries_service_failures_connection_resets_and_timeouts(self) -> None:
        failures = (
            ("service", github_http_error(503, **{"Retry-After": "4"}), 4),
            ("connection-reset", urllib.error.URLError(ConnectionResetError("reset")), 1),
            ("timeout", urllib.error.URLError(TimeoutError("timed out")), 1),
        )

        for label, failure, expected_delay in failures:
            with self.subTest(label=label):
                sleeps: list[float] = []
                client = self.recovery.PublicClient(
                    max_attempts=2,
                    retry_base_seconds=1,
                    sleep=sleeps.append,
                )
                with mock.patch.object(
                    self.recovery.urllib.request,
                    "urlopen",
                    side_effect=[failure, io.BytesIO(b"[]")],
                ) as open_url:
                    self.assertEqual(
                        [],
                        client.json(
                            "https://api.github.com/repos/durable-workflow/.github/releases?per_page=100"
                        ),
                    )

                self.assertEqual([expected_delay], sleeps)
                self.assertEqual(2, open_url.call_count)

    def test_authentication_is_terminal_even_with_rate_limit_guidance(self) -> None:
        sleeps: list[float] = []
        client = self.recovery.PublicClient(max_attempts=3, sleep=sleeps.append)
        error = github_http_error(
            401,
            b"Bad credentials: API rate limit exceeded",
            **{"Retry-After": "20", "X-RateLimit-Remaining": "0"},
        )

        with (
            mock.patch.object(self.recovery.urllib.request, "urlopen", side_effect=error) as open_url,
            self.assertRaisesRegex(self.recovery.RecoveryError, r"public request failed \(401\)"),
        ):
            client.json("https://api.github.com/repos/durable-workflow/.github/releases?per_page=100")

        self.assertEqual([], sleeps)
        self.assertEqual(1, open_url.call_count)

    def test_authorization_requires_explicit_rate_limit_guidance(self) -> None:
        client = self.recovery.PublicClient(
            max_attempts=2,
            sleep=lambda _delay: self.fail("ordinary authorization failure was retried"),
        )
        with (
            mock.patch.object(
                self.recovery.urllib.request,
                "urlopen",
                side_effect=github_http_error(403, b"Resource not accessible"),
            ) as open_url,
            self.assertRaisesRegex(self.recovery.RecoveryError, r"public request failed \(403\)"),
        ):
            client.json("https://api.github.com/repos/durable-workflow/.github/releases?per_page=100")
        self.assertEqual(1, open_url.call_count)

        sleeps: list[float] = []
        client = self.recovery.PublicClient(max_attempts=2, retry_base_seconds=1, sleep=sleeps.append)
        with mock.patch.object(
            self.recovery.urllib.request,
            "urlopen",
            side_effect=[
                github_http_error(
                    403,
                    b"API rate limit exceeded",
                    **{"X-RateLimit-Remaining": "0"},
                ),
                io.BytesIO(b"[]"),
            ],
        ) as open_url:
            self.assertEqual(
                [],
                client.json("https://api.github.com/repos/durable-workflow/.github/releases?per_page=100"),
            )
        self.assertEqual([1], sleeps)
        self.assertEqual(2, open_url.call_count)

    def test_retry_exhaustion_has_a_distinct_infrastructure_classification(self) -> None:
        client = self.recovery.PublicClient(max_attempts=2, retry_base_seconds=1, sleep=lambda _delay: None)
        with (
            mock.patch.object(
                self.recovery.urllib.request,
                "urlopen",
                side_effect=[github_http_error(503), github_http_error(502)],
            ) as open_url,
            self.assertRaisesRegex(
                self.recovery.PublicInfrastructureError,
                r"classification=github-read-transient, endpoint_class=releases-api, "
                r"attempts=2, reason=retry-exhausted, status=502",
            ),
        ):
            client.json("https://api.github.com/repos/durable-workflow/.github/releases?per_page=100")
        self.assertEqual(2, open_url.call_count)


class ImmutablePlanDiscoveryTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls) -> None:
        cls.recovery = load_recovery_for_retry_tests()

    def test_updated_older_release_cannot_override_newer_immutable_plan(self) -> None:
        older = lifecycle_plan(self.recovery)
        older["plan"] = "older-alpha"
        newer = lifecycle_plan(self.recovery, "beta")
        newer["plan"] = "newer-beta"
        tags = ["release-plan/older-alpha", "release-plan/newer-beta"]
        commits = {tags[0]: "a" * 40, tags[1]: "b" * 40}
        recorded = {
            "a" * 40: dt.datetime(2026, 7, 20, tzinfo=dt.UTC),
            "b" * 40: dt.datetime(2026, 7, 22, tzinfo=dt.UTC),
        }

        with (
            mock.patch.object(
                self.recovery,
                "list_release_plan_tags",
                # The older Release may now appear first, but Release order is not authority.
                return_value=tags,
            ),
            mock.patch.object(
                self.recovery,
                "resolve_tag",
                side_effect=lambda _client, _repository, tag: commits[tag],
            ),
            mock.patch.object(
                self.recovery,
                "read_plan_authority",
                side_effect=[(older, None), (newer, None), (older, None), (newer, None)],
            ),
            mock.patch.object(
                self.recovery,
                "direct_plan_lifecycle",
                side_effect=[
                    ("actionable", None),
                    ("completed", None),
                    ("actionable", None),
                    ("completed", None),
                ],
            ),
            mock.patch.object(
                self.recovery,
                "immutable_plan_recorded_at",
                side_effect=lambda _client, commit: recorded[commit],
            ),
            mock.patch.object(
                self.recovery,
                "accepted_continuity_supersession",
                return_value=None,
            ),
            self.assertRaisesRegex(
                self.recovery.RecoveryError,
                "no public release plan is available",
            ),
        ):
            self.recovery.select_implicit_plan_authority(mock.Mock())

    def test_equal_versions_with_different_source_commits_are_conflicting(self) -> None:
        first = lifecycle_plan(self.recovery, "beta")
        first["plan"] = "first-beta-authority"
        second = json.loads(json.dumps(first))
        second["plan"] = "conflicting-beta-authority"
        second["components"]["workflow"]["commit"] = "f" * 40
        authorities = [
            {"tag": f"release-plan/{first['plan']}", "plan": first},
            {"tag": f"release-plan/{second['plan']}", "plan": second},
        ]

        with self.assertRaisesRegex(
            self.recovery.RecoveryError,
            "conflicting current product trains",
        ):
            self.recovery.current_product_train_authorities(authorities)

    def test_strict_semver_validation_precedes_authority_selection(self) -> None:
        for malformed in ("01.0.0", "1.0.0-alpha.01", "1.0.0-alpha..1", "1.0.0\n"):
            candidate = lifecycle_plan(self.recovery, "beta")
            candidate["components"]["server"]["version"] = malformed
            authority = {
                "tag": f"release-plan/{candidate['plan']}",
                "plan": candidate,
            }

            with self.subTest(version=malformed), self.assertRaisesRegex(
                self.recovery.RecoveryError,
                "components.server.version is not exact SemVer",
            ):
                self.recovery.current_product_train_authorities([authority])

        for valid in ("1.0.0-alpha.1", "1.0.0-alpha.1+build.01", "1.0.0+build.01"):
            candidate = lifecycle_plan(self.recovery, "beta")
            candidate["components"]["server"]["version"] = valid

            with self.subTest(version=valid):
                self.recovery.validate_plan(candidate)

    def test_unbounded_numeric_semver_identifiers_are_selected(self) -> None:
        long_numeric = "9" * 4301
        cases = (
            ("core", "1.0.0", f"{long_numeric}.0.0"),
            ("prerelease", "1.0.0-alpha.1", f"1.0.0-alpha.{long_numeric}"),
        )

        for kind, lower_version, higher_version in cases:
            lower = lifecycle_plan(self.recovery, "beta")
            lower["plan"] = f"unbounded-{kind}-lower"
            lower["components"]["server"]["version"] = lower_version
            higher = json.loads(json.dumps(lower))
            higher["plan"] = f"unbounded-{kind}-higher"
            higher["components"]["server"]["version"] = higher_version
            authorities = [
                {"tag": f"release-plan/{lower['plan']}", "plan": lower},
                {"tag": f"release-plan/{higher['plan']}", "plan": higher},
            ]

            with self.subTest(kind=kind):
                self.assertEqual(
                    [f"release-plan/{higher['plan']}"],
                    [
                        authority["tag"]
                        for authority in self.recovery.current_product_train_authorities(
                            authorities
                        )
                    ],
                )

    def test_unbounded_core_identifier_can_allocate_immediate_successor(self) -> None:
        failed = lifecycle_plan(self.recovery, "beta")
        failed["plan"] = "unbounded-core-predecessor"
        failed["components"]["server"]["version"] = f"1.0.{'9' * 4301}"
        successor = json.loads(json.dumps(failed))
        successor["plan"] = "unbounded-core-successor"
        successor["components"]["server"]["version"] = f"1.0.1{'0' * 4301}"

        self.recovery.validate_successor_transition(
            failed,
            successor,
            [
                {
                    "component": "server",
                    "reason": self.recovery.SUPERSESSION_REASON,
                }
            ],
        )

    def test_unbounded_prerelease_identifier_can_allocate_immediate_successor(
        self,
    ) -> None:
        failed = lifecycle_plan(self.recovery, "beta")
        failed["plan"] = "unbounded-prerelease-predecessor"
        failed["components"]["server"]["version"] = f"1.0.0-alpha.{'9' * 4301}"
        successor = json.loads(json.dumps(failed))
        successor["plan"] = "unbounded-prerelease-successor"
        successor["components"]["server"]["version"] = f"1.0.0-alpha.1{'0' * 4301}"

        self.recovery.validate_successor_transition(
            failed,
            successor,
            [
                {
                    "component": "server",
                    "reason": self.recovery.SUPERSESSION_REASON,
                }
            ],
        )

    def test_validated_source_manifest_supersession_selects_successor(self) -> None:
        predecessor = lifecycle_plan(self.recovery, "beta")
        predecessor["plan"] = "source-manifest-predecessor"
        successor = json.loads(json.dumps(predecessor))
        successor["plan"] = "source-manifest-successor"
        successor["components"]["workflow"]["commit"] = "f" * 40
        successor_tag = f"release-plan/{successor['plan']}"
        successor_authority = {
            "tag": successor_tag,
            "plan": successor,
            "lifecycle": "actionable",
            "successor": None,
        }
        authorities = [
            {
                "tag": f"release-plan/{predecessor['plan']}",
                "plan": predecessor,
                "lifecycle": "superseded",
                "successor": {
                    "tag": successor_tag,
                    "sha256": self.recovery.manifest_digest(successor),
                    "plan": successor,
                },
            },
            successor_authority,
        ]

        self.assertEqual(
            [successor_authority],
            self.recovery.current_product_train_authorities(authorities),
        )

    def test_scheduled_recovery_without_actionable_plan_is_a_truthful_no_op(self) -> None:
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            evidence = root / "release-recovery-evidence.json"
            github_output = root / "github-output"
            arguments = [
                "component-release-recovery.py",
                "resolve",
                "--component",
                "workflow",
                "--plan-output",
                str(root / "release-plan.json"),
                "--preparation-output",
                str(root / "release-preparation.json"),
                "--evidence",
                str(evidence),
                "--github-output",
                str(github_output),
                "--allow-empty",
            ]

            with (
                mock.patch.object(sys, "argv", arguments),
                mock.patch.object(
                    self.recovery,
                    "discover_plan",
                    side_effect=self.recovery.RecoveryError(
                        "no public release plan is available",
                        "plan-discovery",
                    ),
                ),
                mock.patch.object(self.recovery, "resolve_component") as recover_component,
            ):
                self.assertEqual(0, self.recovery.main())

            recover_component.assert_not_called()
            state = json.loads(evidence.read_text())
            self.assertEqual("plan-discovery", state["phase"])
            self.assertEqual("idle", state["outcome"])
            self.assertEqual("action=none\n", github_output.read_text())

    def test_explicit_completed_plan_is_not_recovered(self) -> None:
        candidate = lifecycle_plan(self.recovery, "beta")
        tag = f"release-plan/{candidate['plan']}"
        commit = "a" * 40
        authority = {
            "tag": tag,
            "commit": commit,
            "recorded_at": dt.datetime(2026, 7, 24, tzinfo=dt.UTC),
            "plan": candidate,
            "preparation": None,
            "lifecycle": "completed",
            "successor": None,
        }
        with (
            mock.patch.object(self.recovery, "classify_plan_authorities", return_value=[authority]),
            self.assertRaisesRegex(self.recovery.RecoveryError, "is completed and cannot be recovered"),
        ):
            self.recovery.select_explicit_plan_authority(mock.Mock(), tag, commit, candidate, None)

    def test_final_implicit_boundary_rejects_continuity_pause_activated_after_initial_read(
        self,
    ) -> None:
        candidate = lifecycle_plan(self.recovery)
        candidate_preparation = {
            "components": {
                "workflow": {
                    "release_notes": {
                        "release_date": "2026-07-23",
                        "sha256": "c" * 64,
                        "source": {},
                    }
                }
            }
        }
        component = self.recovery.COMPONENTS["workflow"]
        selected = {"tag": "release-plan/current", "lifecycle": "actionable"}
        authority = {"authority_snapshot": [selected]}
        continuity = mock.Mock(
            side_effect=[
                None,
                {
                    "accepted_tag": f"beta-continuity/{candidate['plan']}/accepted",
                    "accepted_commit": "b" * 40,
                    "resumed_tag": f"beta-continuity/{candidate['plan']}/resumed",
                },
            ]
        )
        publication_preflight = mock.Mock(
            side_effect=self.recovery.NotFound("not published")
        )

        with (
            mock.patch.object(self.recovery, "verify_plan_authority", return_value=({}, {})),
            mock.patch.object(self.recovery, "validate_release_preparation"),
            mock.patch.object(self.recovery, "resolve_tag", return_value=None),
            mock.patch.object(
                self.recovery,
                "classify_implicit_plan_authority",
                return_value=(selected, [selected]),
            ),
            mock.patch.object(
                self.recovery,
                "scheduled_continuity_pause",
                continuity,
            ),
            mock.patch.dict(
                self.recovery.VERIFIERS,
                {component.distribution: publication_preflight},
            ),
        ):
            self.assertIsNone(continuity(mock.Mock(), candidate))
            with self.assertRaisesRegex(
                self.recovery.RecoveryError,
                "continuity pause authority changed during component preflight",
            ):
                self.recovery.resolve_component(
                    mock.Mock(),
                    "workflow",
                    selected["tag"],
                    "a" * 40,
                    candidate,
                    candidate_preparation,
                    authority,
                )

        self.assertEqual(2, continuity.call_count)
        self.assertEqual(1, publication_preflight.call_count)

    def test_final_boundary_rejects_late_supersession_before_publish(self) -> None:
        candidate = lifecycle_plan(self.recovery)
        candidate_preparation = {
            "components": {
                "workflow": {
                    "release_notes": {
                        "release_date": "2026-07-23",
                        "sha256": "c" * 64,
                        "source": {},
                    }
                }
            }
        }
        component = self.recovery.COMPONENTS["workflow"]
        original_snapshot = [
            {"tag": "release-plan/older", "lifecycle": "actionable"}
        ]
        current_snapshot = [
            {"tag": "release-plan/older", "lifecycle": "superseded"},
            {"tag": "release-plan/successor", "lifecycle": "actionable"},
        ]
        publication_preflight = mock.Mock(
            side_effect=self.recovery.NotFound("not published")
        )

        with (
            mock.patch.object(self.recovery, "verify_plan_authority", return_value=({}, {})),
            mock.patch.object(self.recovery, "validate_release_preparation"),
            mock.patch.object(self.recovery, "resolve_tag", return_value=None),
            mock.patch.object(
                self.recovery,
                "classify_implicit_plan_authority",
                return_value=(current_snapshot[-1], current_snapshot),
            ) as classify,
            mock.patch.dict(
                self.recovery.VERIFIERS,
                {component.distribution: publication_preflight},
            ),
            self.assertRaisesRegex(
                self.recovery.RecoveryError,
                "refusing a stale recovery action",
            ),
        ):
            self.recovery.resolve_component(
                mock.Mock(),
                "workflow",
                "release-plan/older",
                "a" * 40,
                candidate,
                candidate_preparation,
                {"authority_snapshot": original_snapshot},
            )

        publication_preflight.assert_called_once()
        classify.assert_called_once()

    def test_final_boundary_rejects_nonselected_lifecycle_change_before_publish(self) -> None:
        candidate = lifecycle_plan(self.recovery)
        candidate_preparation = {
            "components": {
                "workflow": {
                    "release_notes": {
                        "release_date": "2026-07-23",
                        "sha256": "c" * 64,
                        "source": {},
                    }
                }
            }
        }
        component = self.recovery.COMPONENTS["workflow"]
        selected = {"tag": "release-plan/latest", "lifecycle": "actionable"}
        original_snapshot = [
            {"tag": "release-plan/older", "lifecycle": "completed"},
            selected,
        ]
        current_snapshot = [
            {"tag": "release-plan/older", "lifecycle": "superseded"},
            selected,
        ]
        publication_preflight = mock.Mock(
            side_effect=self.recovery.NotFound("not published")
        )

        with (
            mock.patch.object(self.recovery, "verify_plan_authority", return_value=({}, {})),
            mock.patch.object(self.recovery, "validate_release_preparation"),
            mock.patch.object(self.recovery, "resolve_tag", return_value=None),
            mock.patch.object(
                self.recovery,
                "classify_implicit_plan_authority",
                return_value=(selected, current_snapshot),
            ) as classify,
            mock.patch.dict(
                self.recovery.VERIFIERS,
                {component.distribution: publication_preflight},
            ),
            self.assertRaisesRegex(
                self.recovery.RecoveryError,
                "refusing a stale recovery action",
            ),
        ):
            self.recovery.resolve_component(
                mock.Mock(),
                "workflow",
                selected["tag"],
                "a" * 40,
                candidate,
                candidate_preparation,
                {"authority_snapshot": original_snapshot},
            )

        publication_preflight.assert_called_once()
        classify.assert_called_once()

    def test_explicit_actionable_plan_returns_publish_with_lifecycle_revalidation(
        self,
    ) -> None:
        candidate = lifecycle_plan(self.recovery)
        candidate_preparation = {
            "components": {
                "workflow": {
                    "release_notes": {
                        "release_date": "2026-07-23",
                        "sha256": "c" * 64,
                        "source": {},
                    }
                }
            }
        }
        component = self.recovery.COMPONENTS["workflow"]
        publication_preflight = mock.Mock(
            side_effect=self.recovery.NotFound("not published")
        )
        explicit_authority = {
            "selection": "explicit",
            "tag": "release-plan/manual",
            "commit": "a" * 40,
            "recorded_at": dt.datetime(2026, 7, 23, tzinfo=dt.UTC),
            "plan": candidate,
            "preparation": candidate_preparation,
            "lifecycle": "actionable",
            "successor": None,
        }
        current_authority = {
            key: value
            for key, value in explicit_authority.items()
            if key != "selection"
        }

        with (
            mock.patch.object(self.recovery, "verify_plan_authority", return_value=({}, {})),
            mock.patch.object(self.recovery, "validate_release_preparation"),
            mock.patch.object(self.recovery, "resolve_tag", return_value=None),
            mock.patch.object(
                self.recovery,
                "classify_implicit_plan_authority",
            ) as classify,
            mock.patch.object(
                self.recovery,
                "classify_plan_authorities",
                return_value=[current_authority],
            ) as classify_explicit,
            mock.patch.dict(
                self.recovery.VERIFIERS,
                {component.distribution: publication_preflight},
            ),
        ):
            for lifecycle in ("actionable", "interrupted"):
                with self.subTest(explicit_lifecycle=lifecycle):
                    explicit_authority["lifecycle"] = lifecycle
                    current_authority["lifecycle"] = lifecycle
                    state, outputs = self.recovery.resolve_component(
                        mock.Mock(),
                        "workflow",
                        "release-plan/manual",
                        "a" * 40,
                        candidate,
                        candidate_preparation,
                        explicit_authority,
                    )
                    self.assertEqual("publish", outputs["action"])
                    self.assertEqual("publication", state["phase"])

        self.assertEqual(2, publication_preflight.call_count)
        classify.assert_not_called()
        self.assertEqual(2, classify_explicit.call_count)

    def test_concurrent_terminal_supersession_retries_before_returning_action(self) -> None:
        older = lifecycle_plan(self.recovery)
        older["plan"] = "older-plan"
        successor = lifecycle_plan(self.recovery)
        successor["plan"] = "successor-plan"
        older_tag = "release-plan/older-plan"
        successor_tag = "release-plan/successor-plan"
        commits = {older_tag: "a" * 40, successor_tag: "b" * 40}
        plans = {older_tag: older, successor_tag: successor}
        recorded = {
            commits[older_tag]: dt.datetime(2026, 7, 20, tzinfo=dt.UTC),
            commits[successor_tag]: dt.datetime(2026, 7, 21, tzinfo=dt.UTC),
        }
        terminal_failure: dict[str, object] = {}
        registry_reads = 0

        def list_tags(_client: mock.Mock) -> list[str]:
            nonlocal registry_reads
            registry_reads += 1
            if registry_reads == 2:
                terminal_failure.update(
                    {"outcome": "terminal-failure", "successor": successor_tag}
                )
            return (
                [older_tag, successor_tag]
                if terminal_failure
                else [older_tag]
            )

        def lifecycle(
            _client: mock.Mock,
            tag: str,
            _commit: str,
            _plan: dict[str, object],
            _preparation: None,
        ) -> tuple[str, object | None]:
            if tag == older_tag and terminal_failure:
                return "superseded", {
                    "tag": successor_tag,
                    "sha256": self.recovery.manifest_digest(successor),
                    "plan": successor,
                }
            return "actionable", None

        with (
            mock.patch.object(
                self.recovery,
                "list_release_plan_tags",
                side_effect=list_tags,
            ),
            mock.patch.object(
                self.recovery,
                "resolve_tag",
                side_effect=lambda _client, _repository, tag: commits[tag],
            ),
            mock.patch.object(
                self.recovery,
                "read_plan_authority",
                side_effect=lambda _client, tag, _commit: (plans[tag], None),
            ),
            mock.patch.object(
                self.recovery,
                "direct_plan_lifecycle",
                side_effect=lifecycle,
            ),
            mock.patch.object(
                self.recovery,
                "immutable_plan_recorded_at",
                side_effect=lambda _client, commit: recorded[commit],
            ),
            mock.patch.object(
                self.recovery,
                "accepted_continuity_supersession",
                return_value=None,
            ),
        ):
            selected = self.recovery.select_implicit_plan_authority(mock.Mock())

        self.assertEqual(successor_tag, selected["tag"])
        self.assertEqual("actionable", selected["lifecycle"])
        self.assertEqual(4, registry_reads)

    def test_multiple_continuity_successors_for_one_interruption_fail_closed(self) -> None:
        interrupted = {"plan": "interrupted-beta"}
        first_successor = {"plan": "first-successor-beta"}
        second_successor = {"plan": "second-successor-beta"}
        plans = [interrupted, first_successor, second_successor]
        tags = [f"release-plan/{plan['plan']}" for plan in plans]
        commits = {
            tags[0]: "a" * 40,
            tags[1]: "b" * 40,
            tags[2]: "c" * 40,
        }
        interruption_tag = f"beta-continuity/{interrupted['plan']}/interrupted"
        interruption_commit = "d" * 40
        interruption_evidence = {"outcome": "intentionally-interrupted"}
        superseded_interruption = {
            "commit": interruption_commit,
            "evidence_sha256": self.recovery.manifest_digest(interruption_evidence),
            "plan_sha256": self.recovery.manifest_digest(interrupted),
            "reason": self.recovery.CONTINUITY_SUPERSESSION_REASON,
            "tag": interruption_tag,
        }
        recorded = {
            commits[tags[0]]: dt.datetime(2026, 7, 20, tzinfo=dt.UTC),
            commits[tags[1]]: dt.datetime(2026, 7, 21, tzinfo=dt.UTC),
            commits[tags[2]]: dt.datetime(2026, 7, 22, tzinfo=dt.UTC),
        }

        def resolve_tag(_client, _repository, tag):
            if tag == interruption_tag:
                return interruption_commit
            return commits[tag]

        def continuity_claim(_client, authority):
            if authority["tag"] == tags[0]:
                return None
            return superseded_interruption

        with (
            mock.patch.object(
                self.recovery,
                "list_release_plan_tags",
                return_value=tags,
            ),
            mock.patch.object(
                self.recovery,
                "resolve_tag",
                side_effect=resolve_tag,
            ),
            mock.patch.object(
                self.recovery,
                "read_plan_authority",
                side_effect=[(plan, None) for plan in plans],
            ),
            mock.patch.object(
                self.recovery,
                "direct_plan_lifecycle",
                side_effect=[
                    ("interrupted", interruption_tag),
                    ("completed", None),
                    ("completed", None),
                ],
            ),
            mock.patch.object(
                self.recovery,
                "immutable_plan_recorded_at",
                side_effect=lambda _client, commit: recorded[commit],
            ),
            mock.patch.object(
                self.recovery,
                "accepted_continuity_supersession",
                side_effect=continuity_claim,
            ),
            mock.patch.object(
                self.recovery,
                "list_continuity_resolution_tags",
                return_value=[],
            ),
            mock.patch.object(
                self.recovery,
                "read_record",
                return_value=interruption_evidence,
            ),
            self.assertRaisesRegex(
                self.recovery.RecoveryError,
                "multiple continuity successors",
            ),
        ):
            self.recovery.select_implicit_plan_authority(mock.Mock())

    def test_continuity_successor_fork_accepts_exact_digest_bound_resolution(self) -> None:
        interrupted_plan = {"plan": "interrupted"}
        interrupted = {
            "tag": "release-plan/interrupted",
            "commit": "a" * 40,
            "plan": interrupted_plan,
        }
        interruption = {
            "tag": "beta-continuity/interrupted/interrupted",
            "commit": "b" * 40,
            "evidence_sha256": "c" * 64,
        }
        successors = []
        for index, name in enumerate(("first-successor", "second-successor"), start=1):
            successors.append(
                {
                    "tag": f"release-plan/{name}",
                    "supersession": {
                        **interruption,
                        "continuity_claim": {
                            "plan": {
                                "tag": f"release-plan/{name}",
                                "commit": str(index) * 40,
                                "sha256": str(index + 2) * 64,
                            },
                            "acceptance": {
                                "tag": f"beta-continuity/{name}/accepted",
                                "commit": str(index + 4) * 40,
                                "sha256": str(index + 6) * 64,
                            },
                        },
                    },
                }
            )
        claims = [successor["supersession"]["continuity_claim"] for successor in successors]
        resolution = {
            "schema": self.recovery.CONTINUITY_RESOLUTION_SCHEMA,
            "qualification": continuity_resolution_qualification(),
            "interruption": {
                "plan": {
                    "tag": interrupted["tag"],
                    "commit": interrupted["commit"],
                    "sha256": self.recovery.manifest_digest(interrupted_plan),
                },
                "evidence": {
                    "tag": interruption["tag"],
                    "commit": interruption["commit"],
                    "sha256": interruption["evidence_sha256"],
                },
            },
            "successor_claims": claims,
            "selected_successor": claims[1]["plan"],
        }
        resolution_tag = (
            f"{self.recovery.CONTINUITY_RESOLUTION_TAG_PREFIX}interrupted/"
            f"{self.recovery.manifest_digest(resolution)}"
        )
        client = mock.Mock()
        client.json.return_value = continuity_resolution_qualification_run()
        with (
            mock.patch.object(
                self.recovery,
                "list_continuity_resolution_tags",
                return_value=[resolution_tag],
            ),
            mock.patch.object(self.recovery, "resolve_tag", return_value="f" * 40),
            mock.patch.object(self.recovery, "read_record", return_value=resolution),
        ):
            selected = self.recovery.resolve_continuity_successor_fork(
                client,
                interrupted,
                successors,
            )
        self.assertEqual("release-plan/second-successor", selected)
        valid_run = continuity_resolution_qualification_run()
        failures = (
            (None, "qualification is absent"),
            ({**valid_run, "status": "in_progress", "conclusion": None}, "qualification is pending"),
            ({**valid_run, "conclusion": "failure"}, "qualification failed"),
            ({**valid_run, "conclusion": "cancelled"}, "qualification was cancelled"),
            ({**valid_run, "head_sha": "8" * 40}, "another source revision"),
            ({**valid_run, "path": ".github/workflows/untrusted.yml@main"}, "untrusted workflow"),
        )
        with (
            mock.patch.object(self.recovery, "list_continuity_resolution_tags", return_value=[resolution_tag]),
            mock.patch.object(self.recovery, "resolve_tag", return_value="f" * 40),
            mock.patch.object(self.recovery, "read_record", return_value=resolution),
        ):
            for run, message in failures:
                with self.subTest(qualification=message):
                    client.json.return_value = run
                    with self.assertRaisesRegex(self.recovery.RecoveryError, message):
                        self.recovery.resolve_continuity_successor_fork(client, interrupted, successors)

    def test_terminal_failure_successor_requires_exact_authorized_plan_identity(self) -> None:
        failed = lifecycle_plan(self.recovery)
        failed["plan"] = "failed-plan"
        authorized_successor = json.loads(json.dumps(failed))
        authorized_successor["plan"] = "successor-plan"
        authorized_successor["components"]["workflow"]["version"] = "2.0.0-alpha.2"
        recorded_successor = json.loads(json.dumps(authorized_successor))
        recorded_successor["components"]["workflow"]["commit"] = "e" * 40
        failed_tag = f"release-plan/{failed['plan']}"
        successor_tag = f"release-plan/{authorized_successor['plan']}"
        failed_commit = "a" * 40
        successor_commit = "b" * 40
        failure_commit = "c" * 40
        failure = supersession_record(
            self.recovery,
            failed,
            authorized_successor,
            failed_commit,
        )

        with (
            mock.patch.object(
                self.recovery,
                "resolve_tag",
                side_effect=[None, failure_commit],
            ),
            mock.patch.object(
                self.recovery,
                "read_record",
                side_effect=[failure, authorized_successor],
            ),
            mock.patch.object(self.recovery, "revalidate_supersession_authority"),
        ):
            lifecycle, successor_identity = self.recovery.direct_plan_lifecycle(
                mock.Mock(),
                failed_tag,
                failed_commit,
                failed,
                None,
            )

        self.assertEqual("superseded", lifecycle)
        self.assertEqual(
            {
                "tag": successor_tag,
                "sha256": self.recovery.manifest_digest(authorized_successor),
                "plan": authorized_successor,
            },
            successor_identity,
        )

        commits = {failed_tag: failed_commit, successor_tag: successor_commit}
        recorded = {
            failed_commit: dt.datetime(2026, 7, 20, tzinfo=dt.UTC),
            successor_commit: dt.datetime(2026, 7, 21, tzinfo=dt.UTC),
        }
        with (
            mock.patch.object(
                self.recovery,
                "list_release_plan_tags",
                return_value=[failed_tag, successor_tag],
            ),
            mock.patch.object(
                self.recovery,
                "resolve_tag",
                side_effect=lambda _client, _repository, tag: commits[tag],
            ),
            mock.patch.object(
                self.recovery,
                "read_plan_authority",
                side_effect=[(failed, None), (recorded_successor, None)],
            ),
            mock.patch.object(
                self.recovery,
                "direct_plan_lifecycle",
                side_effect=[
                    (lifecycle, successor_identity),
                    ("completed", None),
                ],
            ),
            mock.patch.object(
                self.recovery,
                "immutable_plan_recorded_at",
                side_effect=lambda _client, commit: recorded[commit],
            ),
            mock.patch.object(
                self.recovery,
                "accepted_continuity_supersession",
                return_value=None,
            ),
            self.assertRaisesRegex(
                self.recovery.RecoveryError,
                "conflicting successor identity",
            ),
        ):
            self.recovery.select_implicit_plan_authority(mock.Mock())

    def test_terminal_failure_normalizes_captured_github_approval_shape(self) -> None:
        failed = lifecycle_plan(self.recovery)
        successor = json.loads(json.dumps(failed))
        successor["plan"] = "successor-plan"
        successor["components"]["workflow"]["version"] = "2.0.0-alpha.2"
        record = supersession_record(self.recovery, failed, successor, "a" * 40)
        client = mock.Mock()
        client.json.side_effect = captured_github_authority(self.recovery, record)

        self.recovery.revalidate_supersession_authority(record, client)

        self.assertEqual(4, client.json.call_count)
        mutations = (
            ("run", "id", 999),
            ("run", "run_attempt", 2),
            ("environment", "id", 999),
            ("history", "state", "rejected"),
            ("reviewer", "id", 999),
        )
        for target, field, value in mutations:
            with self.subTest(target=target, field=field):
                responses = captured_github_authority(self.recovery, record)
                if target == "run":
                    responses[2][field] = value
                elif target == "environment":
                    responses[0][field] = value
                elif target == "history":
                    responses[3][0][field] = value
                else:
                    responses[3][0]["user"][field] = value
                client = mock.Mock()
                client.json.side_effect = responses
                with self.assertRaises(self.recovery.RecoveryError):
                    self.recovery.revalidate_supersession_authority(record, client)

    def test_terminal_failure_rejects_approval_history_for_a_rerun_attempt(self) -> None:
        failed = lifecycle_plan(self.recovery)
        successor = json.loads(json.dumps(failed))
        successor["plan"] = "successor-plan"
        successor["components"]["workflow"]["version"] = "2.0.0-alpha.2"
        record = supersession_record(self.recovery, failed, successor, "a" * 40)
        record["authorization"]["run_attempt"] = 2
        record["authorization"]["environment_approval"]["run_attempt"] = 2
        client = mock.Mock()
        client.json.side_effect = captured_github_authority(self.recovery, record)

        with self.assertRaisesRegex(
            self.recovery.RecoveryError,
            "approval history cannot bind.*rerun attempt",
        ):
            self.recovery.revalidate_supersession_authority(record, client)
        self.assertEqual(3, client.json.call_count)

    def test_terminal_failure_rejects_approver_outside_current_policy(self) -> None:
        failed = lifecycle_plan(self.recovery)
        successor = json.loads(json.dumps(failed))
        successor["plan"] = "successor-plan"
        successor["components"]["workflow"]["version"] = "2.0.0-alpha.2"
        record = supersession_record(self.recovery, failed, successor, "a" * 40)
        responses = captured_github_authority(self.recovery, record)
        responses[0]["protection_rules"][0]["reviewers"][0]["reviewer"].update(
            {
                "html_url": "https://github.com/different-reviewer",
                "id": 77,
                "login": "different-reviewer",
                "node_id": "different-reviewer-node",
                "url": "https://api.github.com/users/different-reviewer",
            }
        )
        client = mock.Mock()
        client.json.side_effect = responses

        with self.assertRaisesRegex(
            self.recovery.RecoveryError,
            "approving user is not authorized by the current reviewer policy",
        ):
            self.recovery.revalidate_supersession_authority(record, client)
        self.assertEqual(4, client.json.call_count)

    def test_terminal_failure_rejects_malformed_authorization_json_types(self) -> None:
        failed = lifecycle_plan(self.recovery)
        failed["plan"] = "failed-plan"
        successor = json.loads(json.dumps(failed))
        successor["plan"] = "successor-plan"
        successor["components"]["workflow"]["version"] = "2.0.0-alpha.2"
        failed_commit = "a" * 40
        valid_failure = supersession_record(
            self.recovery,
            failed,
            successor,
            failed_commit,
        )
        valid_failure["authorization"]["run_id"] = 1
        valid_failure["authorization"]["run_url"] = (
            "https://github.com/durable-workflow/.github/actions/runs/1"
        )
        valid_failure["authorization"]["environment_approval"]["run_id"] = 1
        self.recovery.validate_supersession_record(
            valid_failure,
            failed,
            failed_commit,
            successor,
        )
        mutations = (
            (("authorization", "actor"), True),
            (("authorization", "workflow_commit"), int("1" * 40)),
            (("authorization", "environment_approval", "run_id"), True),
            (("authorization", "environment_approval", "run_attempt"), True),
            (
                (
                    "authorization",
                    "environment_protection",
                    "deployment_branch_policy",
                    "custom_branch_policies",
                ),
                1,
            ),
            (
                (
                    "authorization",
                    "environment_protection",
                    "deployment_branch_policy",
                    "protected_branches",
                ),
                0,
            ),
        )

        for path, value in mutations:
            with self.subTest(field=".".join(path)):
                malformed = json.loads(json.dumps(valid_failure))
                target = malformed
                for key in path[:-1]:
                    target = target[key]
                target[path[-1]] = value

                with self.assertRaises(self.recovery.RecoveryError):
                    self.recovery.validate_supersession_record(
                        malformed,
                        failed,
                        failed_commit,
                        successor,
                    )

    def test_terminal_failure_rejects_incomplete_lifecycle_authority(self) -> None:
        failed = lifecycle_plan(self.recovery)
        failed["plan"] = "failed-plan"
        successor = json.loads(json.dumps(failed))
        successor["plan"] = "successor-plan"
        successor["components"]["workflow"]["version"] = "2.0.0-alpha.2"
        failed_tag = f"release-plan/{failed['plan']}"
        failed_commit = "a" * 40
        incomplete = {
            "schema": "durable-workflow.release-plan-failure/v1",
            "outcome": "terminal-failure",
            "failed_plan": {
                "tag": failed_tag,
                "commit": failed_commit,
                "sha256": self.recovery.manifest_digest(failed),
            },
            "successor_plan": {
                "tag": f"release-plan/{successor['plan']}",
                "sha256": self.recovery.manifest_digest(successor),
            },
        }

        with (
            mock.patch.object(
                self.recovery,
                "resolve_tag",
                side_effect=[None, "c" * 40],
            ),
            mock.patch.object(
                self.recovery,
                "read_record",
                side_effect=[incomplete, successor],
            ),
            self.assertRaisesRegex(
                self.recovery.RecoveryError,
                "record keys must be exactly",
            ),
        ):
            self.recovery.direct_plan_lifecycle(
                mock.Mock(),
                failed_tag,
                failed_commit,
                failed,
                None,
            )

    def assert_explicit_terminal_record_cannot_publish(self, shape: str) -> None:
        for visible_from_round, expected_artifact_checks in ((1, 0), (2, 1)):
            with self.subTest(
                shape=shape,
                visible_from_round=visible_from_round,
            ):
                registry = ExplicitTerminalLifecycleRegistry(
                    self.recovery,
                    shape,
                    visible_from_round=visible_from_round,
                )
                component = self.recovery.COMPONENTS[registry.component_name]
                handoff = mock.Mock()
                with tempfile.TemporaryDirectory() as directory:
                    root = Path(directory)
                    plan_output = root / "release-plan.json"
                    preparation_output = root / "release-preparation.json"
                    evidence_output = root / "recovery-evidence.json"
                    github_output = root / "github-output"
                    argv = [
                        str(SCRIPT),
                        "resolve",
                        "--component",
                        registry.component_name,
                        "--plan-tag",
                        registry.failed_tag,
                        "--plan-output",
                        str(plan_output),
                        "--preparation-output",
                        str(preparation_output),
                        "--evidence",
                        str(evidence_output),
                        "--github-output",
                        str(github_output),
                    ]
                    with (
                        mock.patch.object(self.recovery.sys, "argv", argv),
                        mock.patch.object(
                            self.recovery,
                            "PublicClient",
                            return_value=registry.client,
                        ),
                        mock.patch.object(
                            self.recovery,
                            "list_release_plan_tags",
                            side_effect=registry.list_release_plan_tags,
                        ),
                        mock.patch.object(
                            self.recovery,
                            "resolve_tag",
                            side_effect=registry.resolve_tag,
                        ),
                        mock.patch.object(
                            self.recovery,
                            "read_plan_authority",
                            side_effect=registry.read_plan_authority,
                        ),
                        mock.patch.object(
                            self.recovery,
                            "read_record",
                            side_effect=registry.read_record,
                        ),
                        mock.patch.object(
                            self.recovery,
                            "immutable_plan_recorded_at",
                            side_effect=registry.immutable_plan_recorded_at,
                        ),
                        mock.patch.object(self.recovery, "validate_release_mirrors"),
                        mock.patch.object(
                            self.recovery,
                            "verify_plan_authority",
                            return_value=({}, {}),
                        ),
                        mock.patch.object(
                            self.recovery,
                            "validate_release_preparation",
                        ),
                        mock.patch.object(
                            self.recovery,
                            "verify_component",
                            registry.dependency_verifier,
                        ),
                        mock.patch.dict(
                            self.recovery.VERIFIERS,
                            {component.distribution: registry.artifact_verifier},
                        ),
                        mock.patch.object(
                            self.recovery,
                            "write_output",
                            handoff,
                        ),
                        mock.patch.object(
                            self.recovery.sys,
                            "stderr",
                            io.StringIO(),
                        ),
                    ):
                        exit_code = self.recovery.main()

                    evidence = json.loads(evidence_output.read_bytes())
                    self.assertEqual(1, exit_code)
                    self.assertEqual(registry.component_name, evidence["component"])
                    self.assertEqual("plan-discovery", evidence["phase"])
                    self.assertEqual("failed", evidence["outcome"])
                    self.assertIn(
                        "terminally superseded",
                        evidence["reason"],
                    )
                    self.assertEqual(
                        expected_artifact_checks,
                        registry.artifact_verifier.call_count,
                    )
                    if expected_artifact_checks:
                        identity = registry.failed["components"][
                            registry.component_name
                        ]
                        registry.artifact_verifier.assert_called_once_with(
                            registry.client,
                            component,
                            identity["version"],
                            identity["commit"],
                        )
                    self.assertEqual(
                        0 if visible_from_round == 1 else 2,
                        registry.dependency_verifier.call_count,
                    )
                    self.assertFalse(github_output.exists())
                    handoff.assert_not_called()

    def test_terminal_failure_record_blocks_explicit_absent_artifact_handoff(
        self,
    ) -> None:
        self.assert_explicit_terminal_record_cannot_publish("terminal-failure")

    def test_accepted_continuity_supersession_blocks_explicit_absent_artifact_handoff(
        self,
    ) -> None:
        self.assert_explicit_terminal_record_cannot_publish("accepted-continuity")


class ReleasePreparationRecoveryTest(unittest.TestCase):
    def candidate(self) -> dict[str, object]:
        return {
            "plan": "missing-preparation",
            "channel": "alpha",
            "components": {"workflow": {"version": "2.0.0-alpha.1", "commit": "a" * 40}},
        }

    def test_source_product_train_is_bound_to_the_planned_identity(self) -> None:
        identity = {"version": "2.0.0-beta.3", "commit": "a" * 40}
        client = mock.Mock()
        client.bytes.return_value = json.dumps(
            {
                "name": "durable-workflow/waterline",
                "extra": {"durable-workflow": {"product-train": identity["version"]}},
            }
        ).encode()

        evidence = recovery.source_product_train_evidence(client, "waterline", identity)

        self.assertEqual(identity["version"], evidence["product_train"])
        self.assertEqual(identity["commit"], evidence["source_commit"])
        client.bytes.assert_called_once_with(
            "https://api.github.com/repos/durable-workflow/waterline/contents/composer.json?ref="
            + identity["commit"],
            accept="application/vnd.github.raw+json",
        )

        client.bytes.return_value = client.bytes.return_value.replace(b"beta.3", b"beta.2")
        with self.assertRaisesRegex(recovery.RecoveryError, "not planned version 2.0.0-beta.3"):
            recovery.source_product_train_evidence(client, "waterline", identity)

    def test_discovery_rejects_missing_preparation_for_an_incomplete_release(self) -> None:
        candidate = self.candidate()
        tag = "release-plan/missing-preparation"
        record_commit = "b" * 40
        client = mock.Mock()
        client.json.return_value = {
            "tag_name": tag,
            "draft": False,
            "assets": [
                {
                    "name": "release-plan.json",
                    "browser_download_url": "https://example.invalid/release-plan.json",
                }
            ],
        }
        client.bytes.return_value = recovery.canonical_json(candidate)
        with (
            mock.patch.object(recovery, "validate_plan"),
            mock.patch.object(recovery, "resolve_tag", return_value=record_commit),
            mock.patch.object(
                recovery,
                "select_explicit_plan_authority",
                return_value={"selection": "explicit"},
            ),
            mock.patch.object(
                recovery,
                "read_record",
                side_effect=[candidate, recovery.NotFound("missing preparation")],
            ),
            mock.patch.object(
                recovery,
                "verify_component",
                side_effect=recovery.NotFound("release is incomplete"),
            ),
            self.assertRaisesRegex(recovery.RecoveryError, "only completed legacy releases"),
        ):
            recovery.discover_plan(client, tag, "workflow")

    def test_missing_preparation_cannot_resolve_to_publish(self) -> None:
        candidate = self.candidate()
        with (
            mock.patch.object(recovery, "verify_plan_authority", return_value=({}, {})),
            mock.patch.object(recovery, "resolve_tag", return_value=None),
            self.assertRaisesRegex(
                recovery.RecoveryError,
                "release preparation required before publishing workflow",
            ),
        ):
            recovery.resolve_component(
                mock.Mock(),
                "workflow",
                "release-plan/missing-preparation",
                "b" * 40,
                candidate,
                None,
            )

    def test_explicit_completed_release_still_resolves_to_skip(self) -> None:
        candidate = self.candidate()
        identity = candidate["components"]["workflow"]
        public_evidence = {"version": identity["version"], "commit": identity["commit"]}
        authority = {
            "selection": "explicit",
            "tag": "release-plan/missing-preparation",
            "commit": "b" * 40,
            "plan": candidate,
            "preparation": None,
            "lifecycle": "completed",
            "successor": None,
        }
        with (
            mock.patch.object(recovery, "verify_plan_authority", return_value=({}, {})),
            mock.patch.object(recovery, "resolve_tag", return_value=identity["commit"]),
            mock.patch.object(recovery, "verify_component", return_value=public_evidence),
            mock.patch.object(
                recovery,
                "classify_plan_authorities",
                return_value=[
                    {key: value for key, value in authority.items() if key != "selection"}
                ],
            ),
        ):
            state, outputs = recovery.resolve_component(
                mock.Mock(),
                "workflow",
                "release-plan/missing-preparation",
                "b" * 40,
                candidate,
                None,
                authority,
            )

        self.assertEqual("skip", outputs["action"])
        self.assertEqual("complete", state["phase"])
        self.assertNotIn("release_preparation", state)


class RecoveryWorkflowVerificationTest(unittest.TestCase):
    def waterline_mutation(self, old: str, new: str, *, count: int = 1) -> str:
        self.assertIn(old, WATERLINE_WORKFLOW)
        changed = WATERLINE_WORKFLOW.replace(old, new, count)
        self.assertNotEqual(changed, WATERLINE_WORKFLOW)
        return changed

    def mutation(self, old: str, new: str, *, count: int = 1) -> str:
        self.assertIn(old, RUST_WORKFLOW)
        changed = RUST_WORKFLOW.replace(old, new, count)
        self.assertNotEqual(changed, RUST_WORKFLOW)
        return changed

    def assert_rust_rejected(self, source: str) -> None:
        self.assertNotEqual(source, RUST_WORKFLOW)
        with self.assertRaises(recovery.RecoveryError) as caught:
            recovery.verify_recovery_workflow_source(
                "sdk-rust", source, hashlib.sha256(RUST_WORKFLOW_BYTES).hexdigest()
            )
        self.assertEqual(caught.exception.phase, "default-branch-preflight")

    def assert_waterline_rejected(self, source: str) -> None:
        self.assertNotEqual(source, WATERLINE_WORKFLOW)
        with self.assertRaises(recovery.RecoveryError) as caught:
            recovery.verify_recovery_workflow_source(
                "waterline", source, hashlib.sha256(WATERLINE_WORKFLOW_BYTES).hexdigest()
            )
        self.assertEqual(caught.exception.phase, "default-branch-preflight")

    def test_accepts_only_the_canonical_protected_waterline_workflow_identity(self) -> None:
        digest = hashlib.sha256(WATERLINE_WORKFLOW_BYTES).hexdigest()
        recovery.verify_recovery_workflow_source("waterline", WATERLINE_WORKFLOW, digest)
        recovery.verify_recovery_workflow_source(
            "waterline", WATERLINE_WORKFLOW.replace("\n", "\r\n"), digest
        )

    def test_rejects_waterline_protection_and_authority_mutations(self) -> None:
        variants = {
            "unprotected environment": self.waterline_mutation(
                "    environment: release-plan-publication",
                "    environment: unprotected",
            ),
            "wrong deploy key": self.waterline_mutation("RELEASE_PLAN_DEPLOY_KEY", "OTHER_DEPLOY_KEY"),
            "broad discovery token": self.waterline_mutation(
                "permissions:\n  attestations: read\n  contents: read",
                "permissions:\n  attestations: read\n  contents: write",
            ),
            "repository-token tag creation": self.waterline_mutation(
                "          python scripts/ci/publish-planned-tag.py \\",
                "          gh api --method POST repos/$GITHUB_REPOSITORY/git/refs\n"
                "          python scripts/ci/publish-planned-tag.py \\",
            ),
            "publication bypass": self.waterline_mutation(
                "    if: >-\n"
                "      github.ref == 'refs/heads/v2' &&\n"
                "      needs.discover.outputs.action == 'publish'",
                "    if: always()",
            ),
            "missing protected ref": self.waterline_mutation(
                "    if: >-\n"
                "      github.ref == 'refs/heads/v2' &&\n"
                "      needs.discover.outputs.action == 'publish'",
                "    if: needs.discover.outputs.action == 'publish'",
            ),
            "wrong protected ref": self.waterline_mutation(
                "github.ref == 'refs/heads/v2' &&",
                "github.ref == 'refs/heads/main' &&",
            ),
            "protected ref OR bypass": self.waterline_mutation(
                "github.ref == 'refs/heads/v2' &&",
                "github.ref == 'refs/heads/v2' ||",
            ),
        }
        for label, source in variants.items():
            with self.subTest(label=label):
                self.assert_waterline_rejected(source)

    def test_rejects_waterline_plan_and_source_binding_mutations(self) -> None:
        variants = {
            "wrong privileged artifact digest binding": self.waterline_mutation(
                "artifact-digest: ${{ steps.privileged-handoff.outputs.artifact-digest }}",
                "artifact-digest: ${{ steps.privileged-handoff.outputs.artifact-id }}",
            ),
            "download can ignore digest mismatch": self.waterline_mutation(
                "          digest-mismatch: error",
                "          digest-mismatch: warn",
            ),
            "wrong producer run identity": self.waterline_mutation(
                "          run-id: ${{ needs.discover.outputs.source-run-id }}",
                "          run-id: ${{ github.run_attempt }}",
            ),
            "wrong plan binding": self.waterline_mutation(
                "          PLAN_TAG: ${{ needs.discover.outputs.plan_tag }}",
                "          PLAN_TAG: ${{ github.ref_name }}",
            ),
            "wrong release tag binding": self.waterline_mutation(
                "          RELEASE_TAG: ${{ needs.discover.outputs.version }}",
                "          RELEASE_TAG: ${{ github.ref_name }}",
            ),
            "wrong commit binding": self.waterline_mutation(
                "          RELEASE_COMMIT: ${{ needs.discover.outputs.commit }}",
                "          RELEASE_COMMIT: ${{ github.sha }}",
            ),
            "wrong publisher argument": self.waterline_mutation(
                '--commit "$RELEASE_COMMIT"',
                '--commit "$GITHUB_SHA"',
            ),
            "publisher moved after registry wait": self.waterline_mutation(
                "      - name: Create or verify the exact planned source tag",
                "      - name: Wait for Packagist before creating the exact planned source tag",
            ),
        }
        for label, source in variants.items():
            with self.subTest(label=label):
                self.assert_waterline_rejected(source)

    def test_rejects_incomplete_or_nonblocking_waterline_public_verification(self) -> None:
        variants = {
            "registry verification skipped": self.waterline_mutation(
                "      - name: Wait for Packagist source identity\n",
                "      - name: Wait for Packagist source identity\n        if: false\n",
            ),
            "registry verification ignored": self.waterline_mutation(
                "--attempts 30 --sleep 20 --evidence registry-publication-evidence.json",
                "--attempts 30 --sleep 20 --evidence registry-publication-evidence.json || true",
            ),
            "GitHub release omitted": self.waterline_mutation("--prerelease", "--draft"),
            "completion verification nonblocking": self.waterline_mutation(
                "      - name: Verify completed public release\n",
                "      - name: Verify completed public release\n        continue-on-error: true\n",
            ),
            "registry-only completion": self.waterline_mutation(
                "--attempts 3 --sleep 5 --evidence release-completion-evidence.json",
                "--registry-only --attempts 3 --sleep 5 --evidence release-completion-evidence.json",
            ),
        }
        for label, source in variants.items():
            with self.subTest(label=label):
                self.assert_waterline_rejected(source)

    def test_requires_the_planned_waterline_github_release_to_be_a_prerelease(self) -> None:
        class ReleaseClient:
            def __init__(self, prerelease: bool) -> None:
                self.prerelease = prerelease

            def json(self, _url: str) -> dict[str, object]:
                return {
                    "id": 136,
                    "html_url": "https://github.com/durable-workflow/waterline/releases/tag/2.0.0-alpha.136",
                    "tag_name": "2.0.0-alpha.136",
                    "draft": False,
                    "prerelease": self.prerelease,
                }

        verified = recovery.verify_github_release(ReleaseClient(True), "waterline", "2.0.0-alpha.136")
        self.assertTrue(verified["prerelease"])
        with self.assertRaises(recovery.RecoveryError) as caught:
            recovery.verify_github_release(ReleaseClient(False), "waterline", "2.0.0-alpha.136")
        self.assertEqual(caught.exception.phase, "github-release")

    def test_accepts_only_the_canonical_public_rust_workflow_identity(self) -> None:
        digest = hashlib.sha256(RUST_WORKFLOW_BYTES).hexdigest()
        recovery.verify_recovery_workflow_source("sdk-rust", RUST_WORKFLOW, digest)
        recovery.verify_recovery_workflow_source(
            "sdk-rust", RUST_WORKFLOW.replace("\n", "\r\n"), digest
        )

    def test_rejects_one_byte_and_line_mutations(self) -> None:
        variants = {
            "one byte": self.mutation("name: Release plan recovery", "name: Release plan recoverx"),
            "one line": self.mutation("    - cron: '47 * * * *'", "    - cron: '48 * * * *'"),
            "bare carriage return": RUST_WORKFLOW.replace("\n", "\r", 1),
        }
        for label, source in variants.items():
            with self.subTest(label=label):
                self.assert_rust_rejected(source)

    def test_rejects_missing_protection_or_tag_authority(self) -> None:
        misplaced_deploy_key = self.mutation(
            "          ssh-key: ${{ secrets.RELEASE_PLAN_DEPLOY_KEY }}\n",
            "",
        ).replace(
            "        env:\n          PLAN_TAG: ${{ needs.discover.outputs.plan_tag }}",
            "        env:\n"
            "          RELEASE_PLAN_DEPLOY_KEY: ${{ secrets.RELEASE_PLAN_DEPLOY_KEY }}\n"
            "          PLAN_TAG: ${{ needs.discover.outputs.plan_tag }}",
            1,
        )
        uninvoked_publisher = self.mutation(
            "          python scripts/ci/publish-planned-tag.py \\\n"
            "            --tag \"$RELEASE_TAG\" --commit \"$RELEASE_COMMIT\" --plan-tag \"$PLAN_TAG\" \\\n"
            "            --evidence release-tag-publication-evidence.json",
            "          publish_planned_tag() {\n"
            "            python scripts/ci/publish-planned-tag.py \\\n"
            "              --tag \"$RELEASE_TAG\" --commit \"$RELEASE_COMMIT\" --plan-tag \"$PLAN_TAG\" \\\n"
            "              --evidence release-tag-publication-evidence.json\n"
            "          }",
        )
        variants = {
            "unprotected environment": self.mutation(
                "    environment: release-plan-publication",
                "    environment: unprotected",
            ),
            "wrong deploy key": self.mutation("RELEASE_PLAN_DEPLOY_KEY", "OTHER_DEPLOY_KEY"),
            "deploy key moved to unrelated env": misplaced_deploy_key,
            "broad repository token": self.mutation(
                "    permissions:\n      actions: write\n      contents: read",
                "    permissions:\n      actions: write\n      contents: write",
            ),
            "repository-token tag creation": self.mutation(
                "            --evidence release-tag-publication-evidence.json",
                "            --evidence release-tag-publication-evidence.json\n"
                "          gh api --method POST repos/$GITHUB_REPOSITORY/git/refs "
                "-f ref=refs/tags/$RELEASE_TAG -f sha=$RELEASE_COMMIT",
            ),
            "publisher skipped": self.mutation(
                "      - name: Create or verify the exact planned source tag\n",
                "      - name: Create or verify the exact planned source tag\n        if: false\n",
            ),
            "publisher non-blocking": self.mutation(
                "      - name: Create or verify the exact planned source tag\n",
                "      - name: Create or verify the exact planned source tag\n        continue-on-error: true\n",
            ),
            "publisher defined but not invoked": uninvoked_publisher,
        }
        for label, source in variants.items():
            with self.subTest(label=label):
                self.assert_rust_rejected(source)

    def test_rejects_mutable_or_mismatched_release_bindings(self) -> None:
        wrong_step_bindings = self.mutation(
            "          RELEASE_TAG: ${{ needs.discover.outputs.version }}\n"
            "          RELEASE_COMMIT: ${{ needs.discover.outputs.commit }}",
            "          RELEASE_TAG: ${{ github.ref_name }}\n"
            "          RELEASE_COMMIT: ${{ github.sha }}",
        )
        wrong_step_bindings = wrong_step_bindings.replace(
            "      - name: Restore the immutable release plan\n",
            "      - name: Restore the immutable release plan\n"
            "        env:\n"
            "          RELEASE_TAG: ${{ needs.discover.outputs.version }}\n"
            "          RELEASE_COMMIT: ${{ needs.discover.outputs.commit }}\n",
            1,
        )
        variants = {
            "mutable publisher tag": self.mutation(
                "          RELEASE_TAG: ${{ needs.discover.outputs.version }}",
                "          RELEASE_TAG: ${{ github.ref_name }}",
            ),
            "mismatched publisher commit": self.mutation(
                "          RELEASE_COMMIT: ${{ needs.discover.outputs.commit }}",
                "          RELEASE_COMMIT: ${{ github.sha }}",
            ),
            "wrong publisher tag argument": self.mutation('--tag "$RELEASE_TAG"', '--tag "$PLAN_TAG"'),
            "wrong publisher commit argument": self.mutation(
                '--commit "$RELEASE_COMMIT"',
                '--commit "$GITHUB_SHA"',
            ),
            "exact bindings moved to unrelated step": wrong_step_bindings,
            "readarray mutates tag": self.mutation(
                "          set -euo pipefail\n",
                "          set -euo pipefail\n"
                "          readarray -t RELEASE_TAG < <(printf '%s\\n' 2.0.0-alpha.999)\n",
            ),
            "assignment mutates tag": self.mutation(
                "          set -euo pipefail\n",
                "          set -euo pipefail\n          RELEASE_TAG=2.0.0-alpha.999\n",
            ),
        }
        for label, source in variants.items():
            with self.subTest(label=label):
                self.assert_rust_rejected(source)

    def test_rejects_publication_control_flow_and_identity_mutations(self) -> None:
        variants = {
            "tag publication after selection": self.mutation(
                "          python scripts/ci/publish-planned-tag.py \\",
                "          python scripts/ci/component-release-recovery.py select-publication-run && "
                "python scripts/ci/publish-planned-tag.py \\",
            ),
            "different listed workflow": self.mutation(
                "gh run list --workflow release.yml",
                "gh run list --workflow other.yml",
            ),
            "different dispatched workflow": self.mutation(
                "gh workflow run release.yml",
                "gh workflow run other.yml",
            ),
            "appended run identity": self.mutation(
                "databaseId,displayTitle,headBranch,headSha,status,conclusion",
                "databaseId,displayTitle,headBranch,headSha,status,conclusion,event",
            ),
            "wrong selector tag": self.mutation(
                '--release-tag "$RELEASE_TAG"',
                '--release-tag "$PLAN_TAG"',
            ),
            "wrong selector commit": self.mutation(
                '--release-commit "$RELEASE_COMMIT"',
                '--release-commit "$GITHUB_SHA"',
            ),
            "wrong dispatch tag": self.mutation(
                '-f release_tag="$RELEASE_TAG"',
                '-f release_tag="$PLAN_TAG"',
            ),
            "successful early exit": self.mutation(
                "          set -euo pipefail\n",
                "          set -euo pipefail\n          exit 0\n",
            ),
            "shadowed gh command": self.mutation(
                "          set -euo pipefail\n",
                "          set -euo pipefail\n\n"
                "          gh() {\n"
                "            command gh workflow run other.yml \"$@\"\n"
                "          }\n",
            ),
        }
        for label, source in variants.items():
            with self.subTest(label=label):
                self.assert_rust_rejected(source)

    def test_rejects_incomplete_or_nonblocking_public_release_verification(self) -> None:
        variants = {
            "registry-only verification": self.mutation(
                "--component sdk-rust --plan recovery-input/release-plan.json",
                "--component sdk-rust --plan recovery-input/release-plan.json --registry-only",
            ),
            "verification skipped": self.mutation(
                "      - name: Verify crates.io source identity and the GitHub Release\n",
                "      - name: Verify crates.io source identity and the GitHub Release\n        if: false\n",
            ),
            "verification non-blocking key": self.mutation(
                "      - name: Verify crates.io source identity and the GitHub Release\n",
                "      - name: Verify crates.io source identity and the GitHub Release\n"
                "        continue-on-error: ${{ always() }}\n",
            ),
            "verification ignores failure": self.mutation(
                "--attempts 6 --sleep 10 --evidence release-completion-evidence.json",
                "--attempts 6 --sleep 10 --evidence release-completion-evidence.json || true",
            ),
        }
        for label, source in variants.items():
            with self.subTest(label=label):
                self.assert_rust_rejected(source)

    def test_preserves_strict_contents_api_path_for_other_components(self) -> None:
        expected_sha256 = hashlib.sha256(SERVER_WORKFLOW.encode("utf-8")).hexdigest()
        recovery.verify_recovery_workflow_source("server", SERVER_WORKFLOW, expected_sha256)
        without_exact_ref = SERVER_WORKFLOW.replace(
            '-f ref="refs/tags/$RELEASE_TAG"',
            '-f ref="refs/tags/latest"',
        )
        with self.assertRaises(recovery.RecoveryError):
            recovery.verify_recovery_workflow_source("server", without_exact_ref, expected_sha256)


class PrivilegedWorkflowBoundaryTest(unittest.TestCase):
    def test_release_discovery_cannot_reach_publication_authority_without_publish_output(
        self,
    ) -> None:
        discovery, publisher = WATERLINE_WORKFLOW.split("\n  publish:\n", 1)
        action_guard = (
            "    if: >-\n"
            "      github.ref == 'refs/heads/v2' &&\n"
            "      needs.discover.outputs.action == 'publish'"
        )

        self.assertNotIn("contents: write", discovery)
        self.assertNotIn("${{ secrets.", discovery)
        self.assertNotIn("gh workflow run", discovery)
        guard_at = publisher.index(action_guard)
        self.assertLess(
            guard_at,
            publisher.index("    environment: release-plan-publication"),
        )
        self.assertLess(
            guard_at,
            publisher.index(
                "      - name: Configure repository publication credential"
            ),
        )

    def test_native_publishers_gate_the_exact_v2_ref_before_authority(self) -> None:
        publishers = {
            "release recovery": WATERLINE_WORKFLOW.split("\n  publish:\n", 1)[1],
            "screenshots": SCREENSHOTS_WORKFLOW.split("\n  publish:\n", 1)[1],
        }
        exact_guard = "    if: >-\n      github.ref == 'refs/heads/v2' &&"
        authority_markers = {
            "release recovery": (
                "    environment: release-plan-publication",
                "    permissions:\n      actions: read\n      contents: write",
                "    steps:",
            ),
            "screenshots": (
                "    permissions:\n      actions: read\n      contents: write",
                "    steps:",
            ),
        }

        for name, publisher in publishers.items():
            with self.subTest(name=name):
                guard_at = publisher.index(exact_guard)
                self.assertEqual(publisher.count("github.ref == 'refs/heads/v2'"), 1)
                for marker in authority_markers[name]:
                    self.assertLess(guard_at, publisher.index(marker))

    def test_native_publishers_validate_handoffs_before_privileged_use(self) -> None:
        boundaries = {
            "release recovery": (
                WATERLINE_WORKFLOW.split("\n  publish:\n", 1)[1],
                "      - name: Configure repository publication credential",
                "      - name: Extract the validated release recovery handoff",
                "      - name: Create or verify the exact planned source tag",
            ),
            "screenshots": (
                SCREENSHOTS_WORKFLOW.split("\n  publish:\n", 1)[1],
                "      - name: Configure protected screenshot publication credentials",
                "      - name: Extract the validated screenshot handoff",
                "      - name: Validate and publish inert PNG assets",
            ),
        }

        for name, (publisher, credential, extraction, consumer) in boundaries.items():
            with self.subTest(name=name):
                download_at = publisher.index("uses: actions/download-artifact@")
                validation_at = publisher.index(
                    "      - name: Validate the exact producer artifact before use"
                )
                self.assertLess(download_at, validation_at)
                self.assertLess(validation_at, publisher.index(credential))
                self.assertLess(validation_at, publisher.index(extraction))
                self.assertLess(validation_at, publisher.index(consumer))

    def test_screenshot_generator_is_read_only_and_drops_checkout_credentials(self) -> None:
        generator = SCREENSHOTS_WORKFLOW.split("\n  publish:\n", 1)[0]

        self.assertIn("    permissions:\n      contents: read", generator)
        self.assertNotIn("contents: write", generator)
        self.assertNotIn("${{ secrets.", generator)
        self.assertEqual(generator.count("          persist-credentials: false"), 3)

    def test_screenshot_publisher_accepts_only_protected_source_inputs(self) -> None:
        publisher = SCREENSHOTS_WORKFLOW.split("\n  publish:\n", 1)[1]

        self.assertIn(
            "    if: >-\n"
            "      github.ref == 'refs/heads/v2' &&\n"
            "      (github.event_name == 'schedule' ||\n"
            "       (github.event_name == 'workflow_dispatch' &&\n"
            "        inputs.publish_readme_assets != false &&\n"
            "        (inputs.waterline_ref == '' || inputs.waterline_ref == 'v2') &&\n"
            "        (inputs.sample_app_ref == '' || inputs.sample_app_ref == 'main') &&\n"
            "        (inputs.workflow_ref == '' || inputs.workflow_ref == 'v2')))\n"
            "    runs-on: ubuntu-latest",
            publisher,
        )
        self.assertIn("          ref: v2", publisher)


class ScreenshotArtifactIdentityTest(unittest.TestCase):
    def setUp(self) -> None:
        self.generator, self.publisher = SCREENSHOTS_WORKFLOW.split(
            "\n  publish:\n", 1
        )
        self.retained_upload = self.generator.split(
            "      - name: Upload screenshots\n", 1
        )[1]
        self.retained_upload = self.retained_upload.split("\n      - name:", 1)[0]
        self.bound_upload = self.generator.split(
            "      - name: Bind the privileged screenshot handoff identity and digest\n",
            1,
        )[1]
        self.restore = self.publisher.split(
            "      - name: Restore the exact generated screenshots\n", 1
        )[1]
        self.restore = self.restore.split("\n      - name:", 1)[0]
        self.validator = self.publisher.split(
            "      - name: Validate the exact producer artifact before use\n", 1
        )[1]
        self.validator = self.validator.split("\n      - name:", 1)[0]

    def test_selective_publisher_retry_uses_the_retained_producer_artifact(
        self,
    ) -> None:
        self.assertIn(
            "    outputs:\n"
            "      artifact-digest: ${{ steps.privileged-handoff.outputs.artifact-digest }}\n"
            "      artifact-id: ${{ steps.privileged-handoff.outputs.artifact-id }}\n"
            "      source-run-attempt: ${{ github.run_attempt }}\n"
            "      source-run-id: ${{ github.run_id }}",
            self.generator,
        )
        self.assertIn("        id: upload_screenshots", self.retained_upload)
        self.assertIn("        id: privileged-handoff", self.bound_upload)
        self.assertIn("          archive: false", self.bound_upload)
        self.assertIn("          if-no-files-found: error", self.bound_upload)
        self.assertIn("          path: screenshot-handoff.tar", self.bound_upload)
        self.assertIn(
            "          artifact-ids: ${{ needs.screenshots.outputs.artifact-id }}\n"
            "          digest-mismatch: error\n"
            "          github-token: ${{ github.token }}\n"
            "          path: isolated-screenshot-handoff\n"
            "          repository: ${{ github.repository }}\n"
            "          run-id: ${{ needs.screenshots.outputs.source-run-id }}",
            self.restore,
        )
        self.assertNotIn("github.run_attempt", self.restore)
        self.assertIn(
            "          EXPECTED_ARTIFACT_DIGEST: "
            "${{ needs.screenshots.outputs.artifact-digest }}\n"
            "          EXPECTED_ARTIFACT_ID: "
            "${{ needs.screenshots.outputs.artifact-id }}\n"
            "          EXPECTED_SOURCE_RUN_ATTEMPT: "
            "${{ needs.screenshots.outputs.source-run-attempt }}\n"
            "          EXPECTED_SOURCE_RUN_ID: "
            "${{ needs.screenshots.outputs.source-run-id }}",
            self.validator,
        )
        self.assertIn('/usr/bin/sha256sum "${entries[0]}"', self.validator)

    def test_full_rerun_uploads_a_fresh_attempt_qualified_artifact(self) -> None:
        template = (
            "waterline-screenshots-${{ github.run_id }}-${{ github.run_attempt }}"
        )

        self.assertIn(f"          name: {template}", self.retained_upload)
        first_attempt = template.replace("${{ github.run_id }}", "1234").replace(
            "${{ github.run_attempt }}", "1"
        )
        full_rerun = template.replace("${{ github.run_id }}", "1234").replace(
            "${{ github.run_attempt }}", "2"
        )
        self.assertEqual("waterline-screenshots-1234-1", first_attempt)
        self.assertEqual("waterline-screenshots-1234-2", full_rerun)
        self.assertNotEqual(first_attempt, full_rerun)


if __name__ == "__main__":
    unittest.main()
