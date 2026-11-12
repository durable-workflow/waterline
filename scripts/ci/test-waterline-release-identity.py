#!/usr/bin/env python3
"""Regression coverage for fail-closed Waterline publication identities."""

from __future__ import annotations

import importlib.util
import json
import sys
import unittest
from pathlib import Path

import yaml


SCRIPT = Path(__file__).with_name("waterline_release_identity.py")
ROOT = Path(__file__).parents[2]
SPEC = importlib.util.spec_from_file_location("waterline_release_identity", SCRIPT)
if SPEC is None or SPEC.loader is None:
    raise RuntimeError(f"cannot load {SCRIPT}")
identity = importlib.util.module_from_spec(SPEC)
sys.modules[SPEC.name] = identity
SPEC.loader.exec_module(identity)


CURRENT_PUBLIC = {
    "waterline": "2.0.1",
    "workflow": "2.0.1",
    "sdk-php": "2.0.0",
}
CANDIDATE_WATERLINE = "2.0.2"
CANDIDATE_SDK = "2.1.0"
PUBLIC_SDK_REFERENCE = "0123456789abcdef0123456789abcdef01234567"


def approved() -> dict:
    return {"schema": identity.SCHEMA, "versions": CURRENT_PUBLIC}


def manifest() -> dict:
    return {
        "name": identity.PACKAGE,
        "require": {},
        "require-dev": {
            identity.SDK_PACKAGE: CANDIDATE_SDK,
            identity.WORKFLOW_PACKAGE: CURRENT_PUBLIC["workflow"],
        },
        "extra": {"durable-workflow": {"product-train": CANDIDATE_WATERLINE}},
    }


def standalone() -> dict:
    return {"require": {identity.SDK_PACKAGE: CANDIDATE_SDK}}


def lock() -> dict:
    version = CANDIDATE_SDK
    return {
        "packages": [
            {
                "name": identity.SDK_PACKAGE,
                "version": version,
                "source": {
                    "type": "git",
                    "url": "https://github.com/durable-workflow/sdk-php.git",
                    "reference": PUBLIC_SDK_REFERENCE,
                },
                "dist": {
                    "type": "zip",
                    "url": (
                        "https://api.github.com/repos/durable-workflow/"
                        f"sdk-php/zipball/{PUBLIC_SDK_REFERENCE}"
                    ),
                    "reference": PUBLIC_SDK_REFERENCE,
                    "shasum": "",
                },
            }
        ]
    }


def validate(
    candidate_manifest: dict | None = None,
    candidate_standalone: dict | None = None,
    candidate_lock: dict | None = None,
    *,
    release_version: str | None = None,
) -> dict:
    return identity.validate(
        approved(),
        candidate_manifest or manifest(),
        b"waterline-composer-source",
        candidate_standalone or standalone(),
        b"standalone-composer-source",
        candidate_lock or lock(),
        b"standalone-lock-source",
        release_version=release_version,
    )


def workflow(name: str) -> dict:
    value = yaml.load(
        (ROOT / ".github" / "workflows" / name).read_text(encoding="utf-8"),
        Loader=yaml.BaseLoader,
    )
    if not isinstance(value, dict):
        raise RuntimeError(f"workflow {name} must be a mapping")
    return value


class WaterlineReleaseIdentityTest(unittest.TestCase):
    def test_sdk3_selection_is_allowed_without_expanding_other_component_majors(self) -> None:
        candidate = manifest()
        service = standalone()
        locked = lock()
        candidate['require-dev'][identity.SDK_PACKAGE] = '3.4.1'
        service['require'][identity.SDK_PACKAGE] = '3.4.1'
        locked['packages'][0]['version'] = '3.4.1'
        evidence = validate(candidate, service, locked)
        self.assertEqual('3.4.1', evidence['candidate_source']['sdk-php'])
        self.assertEqual(CURRENT_PUBLIC, evidence['current_public_artifacts'])
        self.assertGreater(identity.version_order('3.0.0'), identity.version_order('2.99.99'))
        for package in (identity.SDK_PACKAGE, identity.WORKFLOW_PACKAGE):
            rejected = manifest()
            rejected['require-dev'][package] = '4.0.0' if package == identity.SDK_PACKAGE else '3.0.0'
            with self.assertRaises(identity.IdentityError):
                validate(candidate_manifest=rejected)

    def test_candidate_source_is_distinct_from_current_public_artifact(self) -> None:
        evidence = validate()

        self.assertEqual("verified", evidence["outcome"])
        self.assertEqual(CURRENT_PUBLIC, evidence["current_public_artifacts"])
        self.assertEqual(
            {
                "waterline": CANDIDATE_WATERLINE,
                "workflow": CURRENT_PUBLIC["workflow"],
                "sdk-php": CANDIDATE_SDK,
            },
            evidence["candidate_source"],
        )
        self.assertIsNone(evidence["release_tag"])

    def test_deliberately_stale_php_sdk_pin_is_rejected(self) -> None:
        stale = manifest()
        stale["require-dev"][identity.SDK_PACKAGE] = "2.0.0-rc.11"

        with self.assertRaisesRegex(
            identity.IdentityError, "precedes the current public dependency"
        ):
            validate(candidate_manifest=stale)

    def test_php_sdk_minor_downgrade_is_rejected(self) -> None:
        current = approved()
        current["versions"] = {**current["versions"], "sdk-php": "2.1.0"}
        candidate = manifest()
        candidate["require-dev"][identity.SDK_PACKAGE] = "2.0.1"

        with self.assertRaisesRegex(
            identity.IdentityError, "precedes the current public dependency"
        ):
            identity.validate(
                current,
                candidate,
                b"waterline-composer-source",
                standalone(),
                b"standalone-composer-source",
                lock(),
                b"standalone-lock-source",
            )

    def test_deliberately_stale_workflow_pin_is_rejected(self) -> None:
        stale = manifest()
        stale["require-dev"][identity.WORKFLOW_PACKAGE] = "2.0.0-rc.13"

        with self.assertRaisesRegex(
            identity.IdentityError, "precedes the current public dependency"
        ):
            validate(candidate_manifest=stale)

    def test_stale_service_image_sdk_source_is_rejected(self) -> None:
        stale_manifest = standalone()
        stale_manifest["require"][identity.SDK_PACKAGE] = "2.0.0-rc.11"

        with self.assertRaisesRegex(
            identity.IdentityError, "standalone service PHP SDK"
        ):
            validate(candidate_standalone=stale_manifest)

        stale_lock = lock()
        stale_lock["packages"][0]["version"] = "2.0.0-rc.11"
        with self.assertRaisesRegex(identity.IdentityError, "standalone service lock"):
            validate(candidate_lock=stale_lock)

    def test_mutable_mismatched_or_local_service_lock_reference_is_rejected(
        self,
    ) -> None:
        stale_lock = lock()
        stale_lock["packages"][0]["source"]["reference"] = "2.0.0-rc.40"

        with self.assertRaisesRegex(identity.IdentityError, "immutable public"):
            validate(candidate_lock=stale_lock)

        mismatched_lock = lock()
        mismatched_lock["packages"][0]["dist"]["reference"] = "f" * 40

        with self.assertRaisesRegex(identity.IdentityError, "immutable public"):
            validate(candidate_lock=mismatched_lock)

        local_lock = lock()
        local_lock["packages"][0].pop("source")
        local_lock["packages"][0]["dist"] = {
            "type": "path",
            "url": "../../sdk-php",
            "reference": "worker-commit",
        }

        with self.assertRaisesRegex(identity.IdentityError, "immutable public"):
            validate(candidate_lock=local_lock)

    def test_release_tag_must_match_source_not_prior_published_artifact(self) -> None:
        evidence = validate(release_version=CANDIDATE_WATERLINE)

        self.assertEqual(CANDIDATE_WATERLINE, evidence["release_tag"])
        self.assertEqual(
            CURRENT_PUBLIC["waterline"],
            evidence["current_public_artifacts"]["waterline"],
        )

        with self.assertRaisesRegex(identity.IdentityError, "does not match source-declared"):
            validate(release_version="2.0.0-rc.14")

    def test_forward_minor_candidate_preserves_current_published_authority(self) -> None:
        candidate = manifest()
        candidate["extra"]["durable-workflow"]["product-train"] = "2.1.0-rc.1"
        candidate["require-dev"][identity.WORKFLOW_PACKAGE] = "2.4.0-rc.1"
        candidate["require-dev"][identity.SDK_PACKAGE] = "2.2.0-rc.1"
        service = standalone()
        service["require"][identity.SDK_PACKAGE] = "2.2.0-rc.1"
        service_lock = lock()
        service_lock["packages"][0]["version"] = "2.2.0-rc.1"

        evidence = validate(candidate, service, service_lock, release_version="2.1.0-rc.1")
        self.assertEqual(CURRENT_PUBLIC, evidence["current_public_artifacts"])
        self.assertEqual("2.4.0-rc.1", evidence["candidate_source"]["workflow"])
        self.assertEqual("2.2.0-rc.1", evidence["candidate_source"]["sdk-php"])

    def test_release_identity_rejects_versions_outside_supported_2_x(self) -> None:
        for version in ("3.0.0", "2.1", "2.1.0-rc.0", "2.1.0-preview.1", "2.01.0"):
            with self.subTest(version=version):
                unsupported = manifest()
                unsupported["extra"]["durable-workflow"]["product-train"] = version
                with self.assertRaisesRegex(identity.IdentityError, "exact supported 2.x release"):
                    validate(candidate_manifest=unsupported)

    def test_every_branch_route_runs_current_identity_qualification(self) -> None:
        document = workflow("php.yml")
        job = document["jobs"]["release-contracts"]
        matching = [
            step
            for step in job["steps"]
            if "--approved-tuple release/current-product-tuple.json"
            in step.get("run", "")
        ]

        self.assertNotIn("if", job)
        self.assertEqual(1, len(matching))
        self.assertIn("release/current-product-tuple.json", matching[0]["run"])
        self.assertIn("--candidate-source", matching[0]["run"])
        self.assertIn("composer.json", matching[0]["run"])
        self.assertIn("standalone/composer.lock", matching[0]["run"])

    def test_service_image_publication_needs_tag_source_qualification(self) -> None:
        build = workflow("service-image.yml")
        qualification = build["jobs"]["native"]
        matching = [
            step
            for step in qualification["steps"]
            if "scripts/ci/waterline_release_identity.py" in step.get("run", "")
        ]

        self.assertEqual(1, len(matching))
        self.assertIn('--release-version "$RELEASE_VERSION"', matching[0]["run"])
        self.assertNotIn("if", matching[0])
        self.assertEqual("read", build["permissions"]["contents"])
        self.assertNotIn("environment", qualification)
        self.assertNotIn("secrets.", json.dumps(qualification))
        self.assertEqual(
            {"amd64", "arm64"},
            {cell["architecture"] for cell in qualification["strategy"]["matrix"]["include"]},
        )
        scripts = [step.get("run", "") for step in qualification["steps"]]
        self.assertTrue(any("scripts/ci/service-mode-image-smoke.sh" in run for run in scripts))
        self.assertTrue(any("scripts/ci/oci_release.py native" in run for run in scripts))

        publication = workflow("service-image-publish.yml")
        publisher = publication["jobs"]["publish"]
        self.assertEqual("github.ref == 'refs/heads/main'", publisher["if"])
        self.assertEqual("release-publication", publisher["environment"])
        self.assertEqual("publish", publication["jobs"]["qualify-published"]["needs"])
        self.assertNotIn("docker/build-push-action", json.dumps(publisher))
        verification = next(index for index, step in enumerate(publisher["steps"])
                            if "scripts/ci/prepare-oci-publication.sh" in step.get("run", ""))
        login = next(index for index, step in enumerate(publisher["steps"])
                     if "DOCKERHUB_TOKEN" in step.get("env", {}))
        self.assertLess(verification, login)

class PublishedEmbeddedCohortTest(unittest.TestCase):
    def run_binding(self, *, missing_pin: bool = False, wrong_source: bool = False):
        import os
        import subprocess
        import tempfile

        steps = workflow("published-image-qualification.yml")["jobs"]["embedded"]["steps"]
        script = next(step["run"] for step in steps if "EXPECTED_WORKFLOW_VERSION" in step.get("run", ""))

        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            origin = root / "origin"
            origin.mkdir()
            (origin / "public").mkdir()
            (origin / "public" / "asset.js").write_text("release fixture\n")
            (origin / "release").mkdir()
            (origin / "release" / "current-product-tuple.json").write_text(json.dumps(approved()))
            candidate = manifest()
            candidate["require-dev"][identity.WORKFLOW_PACKAGE] = "2.4.0"
            if missing_pin:
                del candidate["require-dev"][identity.WORKFLOW_PACKAGE]
            (origin / "composer.json").write_text(json.dumps(candidate))
            environment = {
                "PATH": os.environ["PATH"],
                "GIT_CONFIG_NOSYSTEM": "1",
                "GIT_CONFIG_GLOBAL": os.devnull,
            }

            def git(*arguments, cwd=origin):
                return subprocess.run(
                    ["git", *arguments], cwd=cwd, env=environment,
                    capture_output=True, text=True, check=True,
                ).stdout.strip()

            git("-c", "init.templateDir=", "init", "--quiet")
            git("add", ".")
            git("-c", "user.name=Release fixture", "-c", "user.email=fixture@example.test",
                "commit", "--quiet", "-m", "Published fixture")
            source = git("rev-parse", "HEAD")
            git("tag", CANDIDATE_WATERLINE)
            checkout = root / "checkout"
            git("clone", "--quiet", "--no-hardlinks", str(origin), str(checkout), cwd=root)
            # The runner's current source and accepted public tuple must not
            # replace the dependency selected by the immutable release tag.
            candidate["require-dev"][identity.WORKFLOW_PACKAGE] = "2.5.0"
            (checkout / "composer.json").write_text(json.dumps(candidate))
            runner_temp = root / "runner"
            runner_temp.mkdir()
            output = root / "environment"
            output.touch()
            result = subprocess.run(
                ["bash", "-c", script], cwd=checkout, capture_output=True, text=True,
                env={**environment, "EXPECTED_WATERLINE_VERSION": CANDIDATE_WATERLINE,
                     "EXPECTED_WATERLINE_SOURCE": "0" * 40 if wrong_source else source,
                     "RUNNER_TEMP": str(runner_temp), "GITHUB_ENV": str(output)},
            )
            return result, output.read_text()

    def test_selects_workflow_from_tag_instead_of_public_tuple_or_runner(self):
        result, environment = self.run_binding()
        self.assertEqual(0, result.returncode, result.stderr)
        self.assertIn("EXPECTED_WORKFLOW_VERSION=2.4.0\n", environment)

    def test_missing_tagged_workflow_pin_refuses_acceptance(self):
        result, environment = self.run_binding(missing_pin=True)
        self.assertNotEqual(0, result.returncode)
        self.assertNotIn("EXPECTED_WORKFLOW_VERSION=", environment)

    def test_wrong_tag_source_refuses_acceptance(self):
        result, environment = self.run_binding(wrong_source=True)
        self.assertNotEqual(0, result.returncode)
        self.assertNotIn("EXPECTED_WORKFLOW_VERSION=", environment)


class RegistryVersionCheckTest(unittest.TestCase):
    """The fixed-tag check must work with only hosted-runner tools and fail closed."""

    CHECK = ROOT / "scripts/ci/check-registry-version.sh"
    EXPECTED_INDEX = b'{"schemaVersion":2}'

    def run_check(self, *, exit_code: int, stdout: bytes = b"", stderr: str = "", expected: str | None = None):
        import hashlib
        import os
        import subprocess
        import tempfile

        with tempfile.TemporaryDirectory() as tmp:
            tmp_path = Path(tmp)
            bin_dir = tmp_path / "bin"
            bin_dir.mkdir()
            (tmp_path / "index.out").write_bytes(stdout)
            (tmp_path / "index.err").write_text(stderr)
            skopeo = bin_dir / "skopeo"
            skopeo.write_text(
                "#!/bin/sh\n"
                f'cat "{tmp_path}/index.out"\n'
                f'cat "{tmp_path}/index.err" >&2\n'
                f"exit {exit_code}\n"
            )
            skopeo.chmod(0o755)
            digest = expected or "sha256:" + hashlib.sha256(self.EXPECTED_INDEX).hexdigest()
            # Only the stub directory and the base system paths: no ripgrep available.
            env = {"PATH": f"{bin_dir}:/usr/bin:/bin"}
            return subprocess.run(
                ["bash", str(self.CHECK), str(tmp_path), digest],
                env=env, capture_output=True, text=True, check=False,
            )

    def test_absent_tag_with_manifest_unknown_is_allowed(self) -> None:
        for message in (
            "Error: reading manifest 2.17.0: manifest unknown",
            'errors: [{"code":"MANIFEST_UNKNOWN"}]',
        ):
            with self.subTest(message=message):
                self.assertEqual(self.run_check(exit_code=1, stderr=message).returncode, 0)

    def test_present_tag_with_the_expected_index_is_allowed(self) -> None:
        self.assertEqual(self.run_check(exit_code=0, stdout=self.EXPECTED_INDEX).returncode, 0)

    def test_present_tag_with_a_different_index_is_refused(self) -> None:
        result = self.run_check(exit_code=0, stdout=b'{"schemaVersion":2,"other":true}')
        self.assertNotEqual(result.returncode, 0)
        self.assertIn("publication refused", result.stderr)

    def test_ambiguous_registry_errors_are_refused(self) -> None:
        for message in ("unauthorized: authentication required", "net/http: TLS handshake timeout", ""):
            with self.subTest(message=message):
                result = self.run_check(exit_code=1, stderr=message)
                self.assertNotEqual(result.returncode, 0)
                self.assertIn("Cannot establish fixed registry tag absence", result.stderr)

    def test_malformed_expected_digest_is_refused(self) -> None:
        result = self.run_check(exit_code=1, stderr="manifest unknown", expected="not-a-digest")
        self.assertNotEqual(result.returncode, 0)

    def test_publication_scripts_do_not_require_ripgrep(self) -> None:
        import re

        for script in ("scripts/ci/prepare-oci-publication.sh", "scripts/ci/check-registry-version.sh"):
            with self.subTest(script=script):
                source = (ROOT / script).read_text()
                self.assertIsNone(re.search(r"(^|[\s|;&(!])rg\s", source, re.MULTILINE))


if __name__ == "__main__":
    unittest.main()
