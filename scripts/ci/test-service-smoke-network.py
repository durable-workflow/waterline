"""Inert network observations. No Docker or application execution."""

import copy
import json
from pathlib import Path
import subprocess
import sys
import unittest

sys.path.insert(0, str(Path(__file__).resolve().parent))
import service_smoke_network as network

RUN = "waterline-service-" + "a" * 32
ID = "b" * 64
FOREIGN = "c" * 64


class Fixture:
    def __init__(self):
        self.record = {"Id": ID, "Labels": network.labels(RUN), "Driver": "bridge", "Scope": "local",
                       "Internal": False, "Attachable": False, "Ingress": False, "EnableIPv6": False,
                       "Options": dict(network.OPTIONS), "Containers": {}}
        self.objects = {}
        self.create_output = ID.encode() + b"\n"
        self.calls = []

    def __call__(self, arguments, **_inputs):
        self.calls.append(arguments)
        output, error, status = b"", b"", 0
        if arguments[:2] == ["network", "create"]:
            self.objects[ID] = self.record
            output = self.create_output
        elif arguments[:2] == ["network", "ls"]:
            output = b"".join(key.encode() + b"\n" for key in self.objects)
        elif arguments[:2] == ["network", "inspect"]:
            selected = self.objects.get(arguments[-1])
            output = json.dumps([selected] if selected else []).encode()
            if selected is None:
                status, error = 1, b"Error response from daemon: network " + arguments[-1].encode() + b" not found\n"
        elif arguments[:2] == ["network", "rm"]:
            del self.objects[arguments[-1]]
        return subprocess.CompletedProcess(arguments, status, output, error)


class NetworkTest(unittest.TestCase):
    def test_fixed_profile_and_captured_id_cleanup(self):
        fixture = Fixture()
        owned = network.Network(fixture, fixture, RUN)
        self.assertEqual(ID, owned.create())
        self.assertTrue(owned.cleanup()["absent"])
        self.assertEqual({}, fixture.objects)
        self.assertIn(["network", "rm", ID], fixture.calls)

    def test_lost_create_stdout_recovers_deletion_only(self):
        fixture = Fixture()
        fixture.create_output = b""
        owned = network.Network(fixture, fixture, RUN)
        with self.assertRaises(network.OwnershipError):
            owned.create()
        self.assertIsNone(owned.identifier)
        self.assertEqual(ID, owned.cleanup()["recovered_id"])
        self.assertEqual({}, fixture.objects)

    def test_foreign_replacement_and_ambiguous_objects_are_preserved(self):
        for duplicate in (False, True):
            fixture = Fixture()
            owned = network.Network(fixture, fixture, RUN)
            owned.create()
            fixture.objects[FOREIGN] = copy.deepcopy(fixture.record)
            fixture.objects[FOREIGN]["Id"] = FOREIGN
            if not duplicate:
                del fixture.objects[ID]
            with self.subTest(duplicate=duplicate), self.assertRaises(network.OwnershipError):
                owned.cleanup()
            self.assertIn(FOREIGN, fixture.objects)
            self.assertFalse(any(call[:2] == ["network", "rm"] for call in fixture.calls))

    def test_changed_profile_refuses_execution_but_retains_owned_cleanup(self):
        fixture = Fixture()
        owned = network.Network(fixture, fixture, RUN)
        owned.create()
        fixture.record["Options"] = {}
        with self.assertRaises(network.OwnershipError):
            owned.check(set())
        self.assertTrue(owned.cleanup()["absent"])

    def test_unexpected_attachment_or_changed_owner_refuses_removal(self):
        for key in ("Containers", "Labels"):
            fixture = Fixture()
            owned = network.Network(fixture, fixture, RUN)
            owned.create()
            fixture.record[key] = {FOREIGN: {}}
            with self.subTest(key=key), self.assertRaises(network.OwnershipError):
                owned.cleanup()
            self.assertIn(ID, fixture.objects)

    def test_membership_and_ipv6_are_part_of_execution_profile(self):
        fixture = Fixture()
        record = fixture.record
        with self.assertRaises(network.OwnershipError):
            network.validate(record, {FOREIGN})
        record["EnableIPv6"] = True
        with self.assertRaises(network.OwnershipError):
            network.validate(record, set())


if __name__ == "__main__":
    unittest.main()
