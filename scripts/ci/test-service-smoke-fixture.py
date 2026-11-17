"""Fixture source bounds and optional PHP header-only controls. No application or Docker."""

from pathlib import Path
from types import SimpleNamespace
import base64
import hashlib
import json
import shutil
import subprocess
import sys
import unittest
from unittest.mock import patch

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

    def original_proxy(self):
        return Path(__file__).with_name("request-budget-host.php").read_bytes()

    def test_browser_proxy_transformation_preserves_the_owned_target_and_remaining_program(self):
        raw = self.original_proxy()
        compiled = fixture.browser_proxy_source(raw)
        self.assertEqual(fixture.BROWSER_PROXY_ORIGINAL, hashlib.sha256(raw).hexdigest())
        self.assertEqual(fixture.BROWSER_PROXY_COMPILED, hashlib.sha256(compiled).hexdigest())
        prefix, suffix = raw.split(b"        $headers = ['Host: 127.0.0.1:8080'];")
        self.assertTrue(compiled.startswith(prefix))
        self.assertTrue(compiled.endswith(suffix))
        self.assertIn(b"$headers = ['Host: '.$browserAuthority];", compiled)

    def test_changed_original_or_actual_browser_proxy_refuses(self):
        raw = self.original_proxy()
        for changed in (raw + b"\n", b"unselected proxy"):
            with self.subTest(changed=changed[-20:]), self.assertRaises(resources.OwnershipError):
                fixture.browser_proxy_source(changed)
        with patch.object(fixture, "BROWSER_PROXY_COMPILED", "0" * 64):
            with self.assertRaises(resources.OwnershipError):
                fixture.browser_proxy_source(raw)

    @unittest.skipUnless(shutil.which("php"), "PHP language-only proxy header control is unavailable")
    def test_actual_php_header_uses_only_a_numeric_loopback_browser_port(self):
        compiled = fixture.browser_proxy_source(self.original_proxy())
        block = compiled[compiled.index(b"        $browserAuthority ="):compiled.index(b"        foreach (getallheaders()")]
        accepted = ["127.0.0.1:8080", "127.0.0.1:49153", "127.0.0.1:65535"]
        refused = ["127.0.0.1", "localhost:49153", "127.0.0.2:49153", "[::1]:49153",
                   "127.0.0.1:0", "127.0.0.1:65536", "127.0.0.1:04915",
                   "127.0.0.1:49153\nHost: foreign", None, []]
        for authority in accepted + refused:
            encoded = base64.b64encode(json.dumps(authority).encode()).decode()
            program = ("final class NativeBudgetFixtureException extends RuntimeException {}\n"
                       "$_SERVER['HTTP_HOST'] = json_decode(base64_decode('" + encoded + "'),true,16,JSON_THROW_ON_ERROR);\n"
                       "try {\n" + block.decode() + "\necho json_encode(['headers'=>$headers],JSON_THROW_ON_ERROR);\n"
                       "} catch (NativeBudgetFixtureException $error) { echo '{\"refused\":true}'; }\n")
            result = subprocess.run([shutil.which("php"), "-n", "-r", program], capture_output=True, timeout=5)
            with self.subTest(authority=authority):
                self.assertEqual(0, result.returncode, result.stderr)
                self.assertEqual(b"", result.stderr)
                self.assertEqual({"headers": ["Host: " + authority]} if authority in accepted else {"refused": True},
                                 json.loads(result.stdout))

    def test_fixture_uid_cannot_be_used_for_an_application_probe(self):
        with self.assertRaises(resources.OwnershipError):
            resources.validate_command("application", "exec", ["/usr/local/bin/php", "-n", "/dev/stdin", "service-uid"],
                                       ("--interactive", "--user", "1000:1000"))


if __name__ == "__main__":
    unittest.main()
