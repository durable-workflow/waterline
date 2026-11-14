"""Inert fixture command and source bounds. No PHP or Docker execution."""

from pathlib import Path
from types import SimpleNamespace
import sys
import unittest

sys.path.insert(0, str(Path(__file__).resolve().parent))
import service_smoke_fixture as fixture
import service_smoke_resources as resources


class InertRole:
    role = "fixture"

    def __init__(self):
        self.calls = []

    def command(self, _docker, verb, arguments, *, options, data):
        resources.validate_command(self.role, verb, arguments, options)
        resources.validate_input(self.role, verb, options, data)
        self.calls.append((verb, arguments, data))
        return SimpleNamespace(returncode=0, stdout=b'{}', stderr=b"")


class FixtureTest(unittest.TestCase):
    def test_closed_mode_and_phase_commands_do_not_accept_paths(self):
        role = InertRole()
        control = fixture.Fixture(None, role)
        control.phase("cached")
        control.mode("healthy")
        for operation, value in (("mode", "foreign"), ("phase", "borrowed"), ("read", "/private"), ("audit", "extra")):
            with self.subTest(operation=operation, value=value), self.assertRaises(resources.OwnershipError):
                control.command(operation, value)
        self.assertEqual(2, len(role.calls))
        self.assertTrue(all(raw == fixture.CONTROL for _verb, _arguments, raw in role.calls))

    def test_only_fixed_fixture_sources_can_form_the_start_program(self):
        selected = {name: b"synthetic readonly bytes" for name in fixture.FILES}
        startup = fixture.startup(selected)
        self.assertEqual(["-eu", "-c"], startup[:2])
        self.assertIn("exec timeout -s TERM -k 5 600", startup[2])
        self.assertNotIn("/app/vendor/autoload.php", startup[2])
        for inputs in ({}, {**selected, "foreign.php": b"unselected"},
                       {**selected, "server.php": b"x" * 65537}):
            with self.subTest(names=list(inputs)), self.assertRaises(resources.OwnershipError):
                fixture.startup(inputs)

    def test_source_hashes_change_when_captured_fixture_bytes_change(self):
        before = fixture.source_hashes({"server.php": b"first"})
        after = fixture.source_hashes({"server.php": b"replacement"})
        self.assertNotEqual(before, after)

    def test_browser_proxy_accepts_only_its_exact_owned_application_authority(self):
        selected = {name: b"synthetic readonly bytes" for name in fixture.FILES}
        selected["host.php"] = b"synthetic proxy source"
        fixture.startup(selected, "waterline-service-" + "a" * 32 + "-application")
        for authority in ("foreign.example", "waterline-service-" + "a" * 32 + "-application;unselected"):
            with self.subTest(authority=authority), self.assertRaises(resources.OwnershipError):
                fixture.startup(selected, authority)

    def test_fixture_uid_cannot_be_used_for_an_application_probe(self):
        with self.assertRaises(resources.OwnershipError):
            resources.validate_command("application", "exec", ["/usr/local/bin/php", "-n", "/dev/stdin", "service-uid"],
                                       ("--interactive", "--user", "1000:1000"))


if __name__ == "__main__":
    unittest.main()
