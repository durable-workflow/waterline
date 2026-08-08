#!/usr/bin/env python3
"""Focused coverage for the public release-example tuple contract."""

from __future__ import annotations

import importlib.util
import re
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
    waterline="2.0.0-rc.91",
    workflow="2.0.0-rc.120",
    sdk="2.0.0-rc.63",
)


def composer_command(*, upgrade: bool = False) -> str:
    flag = " --with-all-dependencies" if upgrade else ""
    pins = [
        f"{package}:{contract.example_pin(version)}"
        for package, version in RELEASE.packages.items()
    ]
    return f"composer require{flag} " + " ".join(pins)


class ReleaseExampleContractTest(unittest.TestCase):
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
        )

        self.assertEqual(contract.ExampleCounts(install=1, upgrade=1), counts)
        self.assertEqual(3, len(set(RELEASE.packages.values())))

    def test_rejects_a_stale_pin_for_each_tuple_component(self) -> None:
        current = composer_command()

        for package, version in RELEASE.packages.items():
            with self.subTest(package=package):
                stale = current.replace(
                    f"{package}:{contract.example_pin(version)}",
                    f"{package}:2.0.0-rc.1@RC",
                )
                with self.assertRaisesRegex(
                    contract.ContractError,
                    re.escape(package),
                ):
                    contract.validate_composer_examples(
                        "example.md",
                        stale,
                        RELEASE,
                        minimum_install=1,
                        minimum_upgrade=0,
                    )

    def test_rejects_stale_documented_service_image(self) -> None:
        stale = f"docker run {contract.SERVICE_IMAGE}:2.0.0-rc.1"

        with self.assertRaisesRegex(
            contract.ContractError,
            "expected '2.0.0-rc.91'",
        ):
            contract.validate_documented_images(
                "SERVICE_MODE.md",
                stale,
                RELEASE,
                minimum=1,
            )

    def test_rejects_stale_default_service_image(self) -> None:
        stale = (
            "services:\n"
            "  waterline:\n"
            f"    image: ${{WATERLINE_IMAGE:-{contract.SERVICE_IMAGE}:2.0.0-rc.1}}\n"
        )

        with self.assertRaisesRegex(
            contract.ContractError,
            re.escape(f"{contract.SERVICE_IMAGE}:{RELEASE.waterline}"),
        ):
            contract.validate_default_image(
                "deploy/docker-compose.service.yml",
                stale,
                RELEASE,
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
