#!/usr/bin/env python3
"""Focused coverage for the public release-example tuple contract."""

from __future__ import annotations

import importlib.util
import json
import sys
import tempfile
import unittest
from pathlib import Path


SCRIPT = Path(__file__).with_name("release_example_contract.py")


def load_contract():
    specification = importlib.util.spec_from_file_location(
        "release_example_contract",
        SCRIPT,
    )
    if specification is None or specification.loader is None:
        raise RuntimeError(f"unable to load {SCRIPT}")
    module = importlib.util.module_from_spec(specification)
    sys.modules[specification.name] = module
    specification.loader.exec_module(module)
    return module


contract = load_contract()


RELEASE = contract.ReleaseTuple(
    waterline="2.0.0",
    workflow="2.0.1",
    sdk="2.0.2",
)


def composer_command(*, graph: str = "embedded", upgrade: bool = False) -> str:
    flag = " --with-all-dependencies" if upgrade else ""
    pins = [
        f'"{package}:{contract.ONBOARDING_CONSTRAINT}"'
        for package in RELEASE.composer_graphs[graph]
    ]
    return f"composer require{flag} " + " ".join(pins)


class ReleaseExampleContractTest(unittest.TestCase):
    def repository_fixture(self, root: Path) -> None:
        (root / "release").mkdir()
        (root / "deploy").mkdir()
        (root / "composer.json").write_text(json.dumps({
            "name": contract.WATERLINE_PACKAGE,
            "require-dev": {
                contract.WORKFLOW_PACKAGE: RELEASE.workflow,
                contract.SDK_PACKAGE: RELEASE.sdk,
            },
            "extra": {"durable-workflow": {"product-train": "2.1.0"}},
        }), encoding="utf-8")
        (root / "release/current-product-tuple.json").write_text(json.dumps({
            "schema": "durable-workflow.waterline-current-product-tuple/v1",
            "versions": {
                "waterline": RELEASE.waterline,
                "workflow": RELEASE.workflow,
                "sdk-php": RELEASE.sdk,
            },
        }), encoding="utf-8")
        (root / "README.md").write_text(
            composer_command() + "\n" + composer_command(upgrade=True),
            encoding="utf-8",
        )
        (root / "SERVICE_MODE.md").write_text("\n".join((
            composer_command(),
            composer_command(graph="service"),
            f'WATERLINE_IMAGE="{contract.SERVICE_IMAGE}:{RELEASE.waterline}"',
            'docker run --rm "$WATERLINE_IMAGE"',
        )), encoding="utf-8")
        (root / "deploy/docker-compose.service.yml").write_text(
            "services:\n  waterline:\n"
            "    image: ${WATERLINE_IMAGE:?set-exact-waterline-image}\n",
            encoding="utf-8",
        )

    def test_candidate_version_does_not_replace_published_example_authority(self) -> None:
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            self.repository_fixture(root)

            result = contract.validate_repository(root)

            self.assertEqual(RELEASE, result.release)
            self.assertEqual(1, result.resolved_images)

    def test_documenting_an_unpublished_candidate_image_is_refused(self) -> None:
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            self.repository_fixture(root)
            source = root / "SERVICE_MODE.md"
            source.write_text(
                source.read_text(encoding="utf-8").replace(
                    f"{contract.SERVICE_IMAGE}:{RELEASE.waterline}",
                    f"{contract.SERVICE_IMAGE}:2.1.0",
                ),
                encoding="utf-8",
            )

            with self.assertRaisesRegex(contract.ContractError, "must assign WATERLINE_IMAGE"):
                contract.validate_repository(root)

    def test_missing_published_tuple_does_not_fall_back_to_candidate(self) -> None:
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            self.repository_fixture(root)
            (root / "release/current-product-tuple.json").unlink()

            with self.assertRaisesRegex(contract.ContractError, "current-product-tuple"):
                contract.validate_repository(root)

    def test_invalid_published_tuple_is_refused(self) -> None:
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            self.repository_fixture(root)
            source = root / "release/current-product-tuple.json"
            original = json.loads(source.read_text(encoding="utf-8"))
            for value in (
                {**original, "schema": "unknown"},
                {**original, "versions": None},
                {**original, "extra": "unrecognized"},
                {**original, "versions": {"waterline": "2.0.0"}},
                {**original, "versions": {**original["versions"], "waterline": 2}},
            ):
                with self.subTest(value=value):
                    source.write_text(json.dumps(value), encoding="utf-8")
                    with self.assertRaisesRegex(contract.ContractError, "current-product-tuple"):
                        contract.validate_repository(root)

    def test_accepts_independent_tuple_without_prose_or_layout_coupling(self) -> None:
        document = f"""
Arbitrary prose before a reordered upgrade command.

~~~shell
$ {composer_command(upgrade=True)}
~~~

The install command can move and use indented Markdown instead.

    {composer_command()}
"""

        counts = contract.validate_composer_examples(
            "README.md",
            document,
            RELEASE,
            minimum_install=1,
            minimum_upgrade=1,
            required_graphs=("embedded",),
        )

        self.assertEqual(contract.ExampleCounts(install=1, upgrade=1), counts)
        self.assertEqual(3, len(set(RELEASE.packages.values())))

    def test_rejects_a_copied_rc_pin_for_each_tuple_component(self) -> None:
        current = composer_command()

        for package in RELEASE.composer_graphs["embedded"]:
            with self.subTest(package=package):
                stale = current.replace(
                    f'"{package}:{contract.ONBOARDING_CONSTRAINT}"',
                    f'"{package}:2.0.0-rc.1@RC"',
                )
                with self.assertRaisesRegex(
                    contract.ContractError,
                    "copies an exact prerelease pin",
                ):
                    contract.validate_composer_examples(
                        "example.md",
                        stale,
                        RELEASE,
                        minimum_install=1,
                        minimum_upgrade=0,
                    )

        service = composer_command(graph="service")
        stale_sdk = service.replace(
            f'"{contract.SDK_PACKAGE}:{contract.ONBOARDING_CONSTRAINT}"',
            f'"{contract.SDK_PACKAGE}:2.0.0-rc.1@RC"',
        )
        with self.assertRaisesRegex(
            contract.ContractError, "copies an exact prerelease pin"
        ):
            contract.validate_composer_examples(
                "example.md",
                stale_sdk,
                RELEASE,
                minimum_install=1,
                minimum_upgrade=0,
            )

    def test_rejects_a_combined_embedded_and_service_dependency_graph(self) -> None:
        combined = "composer require " + " ".join(
            f'"{package}:{contract.ONBOARDING_CONSTRAINT}"'
            for package in RELEASE.packages
        )

        with self.assertRaisesRegex(contract.ContractError, "exactly the embedded"):
            contract.validate_composer_examples(
                "example.md",
                combined,
                RELEASE,
                minimum_install=1,
                minimum_upgrade=0,
            )

    def test_rejects_copied_documented_service_image(self) -> None:
        stale = f"docker run {contract.SERVICE_IMAGE}:2.0.0-rc.1"

        with self.assertRaisesRegex(
            contract.ContractError,
            "copies a service image reference",
        ):
            contract.validate_documented_images(
                "SERVICE_MODE.md",
                stale,
                RELEASE,
                minimum=1,
            )

    def test_accepts_exact_stable_image_without_prose_coupling(self) -> None:
        document = f"""
```bash
WATERLINE_IMAGE="{contract.SERVICE_IMAGE}:{RELEASE.waterline}"
docker run --rm "$WATERLINE_IMAGE"
```

Retained compatibility evidence names
{contract.SERVICE_IMAGE}:2.0.0-rc.1 as an immutable historical identity.
"""

        self.assertEqual(
            1,
            contract.validate_documented_images(
                "SERVICE_MODE.md",
                document,
                RELEASE,
                minimum=1,
            ),
        )

    def test_image_follows_the_current_release_instead_of_the_original_2_0(self) -> None:
        release = contract.ReleaseTuple(waterline="2.10.0", workflow="2.5.12", sdk="2.3.0")
        original = f'WATERLINE_IMAGE="{contract.SERVICE_IMAGE}:2.0.0"\ndocker run "$WATERLINE_IMAGE"'
        with self.assertRaisesRegex(contract.ContractError, "must assign WATERLINE_IMAGE"):
            contract.validate_documented_images("SERVICE_MODE.md", original, release, minimum=1)
        current = original.replace(":2.0.0", ":2.10.0")
        self.assertEqual(1, contract.validate_documented_images(
            "SERVICE_MODE.md", current, release, minimum=1,
        ))

    def test_rejects_default_or_unqualified_service_image(self) -> None:
        stale = (
            "services:\n"
            "  waterline:\n"
            f"    image: ${{WATERLINE_IMAGE:-{contract.SERVICE_IMAGE}:2.0.0-rc.1}}\n"
        )

        with self.assertRaisesRegex(
            contract.ContractError,
            "must require WATERLINE_IMAGE",
        ):
            contract.validate_default_image(
                "deploy/docker-compose.service.yml",
                stale,
            )

        with self.assertRaisesRegex(
            contract.ContractError,
            "must require WATERLINE_IMAGE",
        ):
            contract.validate_default_image(
                "deploy/docker-compose.service.yml",
                f"services:\n  waterline:\n    image: {contract.SERVICE_IMAGE}:latest\n",
            )

    def test_accepts_compose_image_required_from_release_input(self) -> None:
        compose = (
            "services:\n"
            "  waterline:\n"
            "    image: ${WATERLINE_IMAGE:?set-exact-waterline-image}\n"
        )

        self.assertEqual(
            "${WATERLINE_IMAGE:?set-exact-waterline-image}",
            contract.validate_default_image(
                "deploy/docker-compose.service.yml",
                compose,
            ),
        )

    def test_rejects_missing_upgrade_example_without_reading_headings(self) -> None:
        with self.assertRaisesRegex(
            contract.ContractError,
            "embedded upgrade Composer example",
        ):
            contract.validate_composer_examples(
                "README.md",
                composer_command(),
                RELEASE,
                minimum_install=1,
                minimum_upgrade=1,
            )

    def test_local_documentation_links_validate_targets_without_prose_coupling(
        self,
    ) -> None:
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            asset = root / "docs" / "screenshots" / "dashboard.png"
            asset.parent.mkdir(parents=True)
            asset.write_bytes(b"png")

            count = contract.validate_markdown_links(
                Path("README.md"),
                "\n".join(
                    (
                        "![Dashboard](docs/screenshots/dashboard.png)",
                        "[External](https://example.com/docs)",
                        "[Section](#section)",
                    )
                ),
                root,
            )

            self.assertEqual(1, count)

    def test_local_documentation_links_reject_missing_or_escaping_targets(
        self,
    ) -> None:
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            for target in ("docs/missing.png", "../outside.md"):
                with self.subTest(target=target):
                    with self.assertRaises(contract.ContractError):
                        contract.validate_markdown_links(
                            Path("README.md"),
                            f"[Target]({target})",
                            root,
                        )


if __name__ == "__main__":
    unittest.main()
