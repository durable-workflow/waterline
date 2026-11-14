"""Inert deletion-only recovery vectors. No workspace or Docker is used."""

from pathlib import Path
import runpy
import sys
import unittest

sys.path.insert(0, str(Path(__file__).resolve().parent))
import service_smoke_cleanup as cleanup
import service_smoke_resources as resources

DATA = runpy.run_path(str(Path(__file__).with_name("test-service-smoke-resources.py")), run_name="inert_cleanup_fixture")


class RecoveryTest(unittest.TestCase):
    def setUp(self):
        self.docker = DATA["CreationFixture"]()
        self.selected = cleanup.context(DATA["RUN"], DATA["IMAGE"], "amd64")

    def create_inert(self):
        roles = resources.RoleSet(self.docker, self.docker, run=DATA["RUN"], image=DATA["IMAGE"],
                                  platform="linux/amd64", base_environment=())
        return roles.create("metadata", entrypoint="/usr/local/bin/php", command=["-n"], environment={})

    def test_unreported_owned_id_is_removed_without_starting_or_reading_workspace(self):
        record = self.create_inert()
        result = cleanup.recover(self.docker, self.selected)
        self.assertEqual("pass", result["status"])
        self.assertNotIn(record.identifier, self.docker.containers)
        self.assertIn(DATA["FOREIGN_ID"], self.docker.containers)
        self.assertFalse(any(call[0] == "start" for call in self.docker.calls))

    def test_foreign_same_name_object_is_preserved(self):
        self.create_inert()
        cleanup.recover(self.docker, self.selected)
        self.assertIn(DATA["FOREIGN_ID"], self.docker.containers)

    def test_invalid_context_or_selector_refuses_before_transport(self):
        for selected in ({}, {**self.selected, "run": "foreign"}, {**self.selected, "image": "mutable:tag"}):
            with self.subTest(selected=selected), self.assertRaises(resources.OwnershipError):
                cleanup.recover(self.docker, selected)
        with self.assertRaises(resources.OwnershipError):
            cleanup.recover(self.docker, self.selected, identifiers={"metadata": "short-id"})
        self.assertEqual([], self.docker.calls)

    def test_recorded_id_never_switches_to_foreign_replacement(self):
        self.create_inert()
        result = cleanup.recover(self.docker, self.selected, identifiers={"metadata": DATA["FOREIGN_ID"]})
        self.assertEqual("nonpass", result["status"])
        self.assertIn(DATA["OWN_ID"], self.docker.containers)
        self.assertIn(DATA["FOREIGN_ID"], self.docker.containers)


if __name__ == "__main__":
    unittest.main()
