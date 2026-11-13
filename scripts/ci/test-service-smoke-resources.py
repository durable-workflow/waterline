"""Inert container-identity counterfactuals; no Docker command is executed."""

import json
from pathlib import Path
import subprocess
import sys
import unittest

sys.path.insert(0, str(Path(__file__).resolve().parent))
import service_smoke_resources as resources


OWN_ID = "a" * 64
FOREIGN_ID = "b" * 64
IMAGE = "sha256:" + "c" * 64
RUN = "waterline-service-" + "d" * 32
NETWORK = "f" * 64


class DockerFixture:
    def __init__(self):
        labels = resources.ownership_labels(RUN, "application")
        self.owned = {"Id": OWN_ID, "Image": IMAGE, "Config": {"Labels": labels, "User": "0:0", "Env": ["TMPDIR=/run/waterline"]},
                      "Mounts": [], "HostConfig": {
                          "ReadonlyRootfs": True, "Init": True, "NanoCpus": 2_000_000_000,
                          "Memory": 805306368, "MemorySwap": 805306368, "PidsLimit": 256,
                          "NetworkMode": NETWORK, "Privileged": False, "PidMode": "", "UTSMode": "",
                          "IpcMode": "private", "CgroupnsMode": "private", "Binds": None,
                          "CapDrop": ["ALL"], "CapAdd": ["CHOWN", "DAC_OVERRIDE", "SETGID", "SETUID"],
                          "SecurityOpt": ["no-new-privileges"],
                          "Tmpfs": {"/data": "rw,nosuid,nodev,noexec,size=64m,uid=82,gid=82,mode=0755",
                                    "/run/waterline": "rw,nosuid,nodev,noexec,size=16m,uid=82,gid=82,mode=0700",
                                    "/app/bootstrap/cache": "rw,nosuid,nodev,noexec,size=16m,uid=82,gid=82,mode=0755",
                                    "/app/storage": "rw,nosuid,nodev,noexec,size=64m,uid=82,gid=82,mode=0755"},
                          "RestartPolicy": {"Name": "no", "MaximumRetryCount": 0},
                          "LogConfig": {"Type": "json-file", "Config": {"max-size": "1m", "max-file": "1"}},
                          "PortBindings": {"8080/tcp": [{"HostIp": "127.0.0.1", "HostPort": ""}]},
                      }}
        self.foreign = {"Id": FOREIGN_ID, "Image": IMAGE, "Config": {"Labels": {}}}
        self.containers = {OWN_ID: self.owned, FOREIGN_ID: self.foreign}
        # The historical name has already been reused by an unrelated owner.
        self.names = {"old-smoke-application": FOREIGN_ID}
        self.calls = []
        self.inspect_override = None
        self.missing = subprocess.CompletedProcess([], 1, b"[]\n", b"Error: No such object: " + OWN_ID.encode())

    def __call__(self, arguments):
        self.calls.append(arguments)
        verb = arguments[0]
        identifier = arguments[-1] if verb in {"inspect", "rm"} else arguments[1]
        identifier = self.names.get(identifier, identifier)
        if verb == "inspect":
            if identifier not in self.containers:
                return self.missing
            data = self.inspect_override or json.dumps([self.containers[identifier]]).encode()
            return subprocess.CompletedProcess(arguments, 0, data, b"")
        if verb == "rm":
            self.containers.pop(identifier, None)
        return subprocess.CompletedProcess(arguments, 0, b"", b"")


class CapturedContainerTest(unittest.TestCase):
    def setUp(self):
        self.docker = DockerFixture()
        self.record = resources.Container(OWN_ID, RUN, "application", IMAGE, NETWORK)

    def test_removes_recorded_id_and_preserves_foreign_name_replacement(self):
        self.record.remove(self.docker)
        self.assertNotIn(OWN_ID, self.docker.containers)
        self.assertIn(FOREIGN_ID, self.docker.containers)
        self.assertEqual([
            ["inspect", OWN_ID], ["rm", "--force", "--volumes", OWN_ID], ["inspect", OWN_ID],
        ], self.docker.calls)

    def test_name_based_predecessor_deletes_the_foreign_replacement(self):
        self.docker(["rm", "--force", "--volumes", "old-smoke-application"])
        self.assertNotIn(FOREIGN_ID, self.docker.containers)
        self.assertIn(OWN_ID, self.docker.containers)

    def test_ownership_or_image_mismatch_refuses_before_any_effect(self):
        for field in ("Id", "Image", resources.OWNER_KEY, resources.RUN_KEY, resources.ROLE_KEY):
            with self.subTest(field=field):
                docker = DockerFixture()
                if field in {"Id", "Image"}:
                    docker.owned[field] = "changed"
                else:
                    docker.owned["Config"]["Labels"][field] = "changed"
                with self.assertRaises(resources.OwnershipError):
                    self.record.remove(docker)
                self.assertEqual([["inspect", OWN_ID]], docker.calls)
                self.assertIn(FOREIGN_ID, docker.containers)

    def test_malformed_inspection_refuses_without_effect(self):
        for raw in (b"{}", b"[]", b"[{},{}]", b"[{\"Id\":1,\"Id\":2}]", b"[NaN]", b"[1e999]", b"[\xff]", b" " * (1024 * 1024 + 1)):
            with self.subTest(raw=raw[:20]):
                self.docker.inspect_override = raw
                with self.assertRaises(resources.OwnershipError):
                    self.record.command(self.docker, "stop", [], options=("--time", "5"))
                self.assertEqual("inspect", self.docker.calls[-1][0])

    def test_uncertain_absence_never_reports_success(self):
        variants = (
            (0, b"[]", b"No such object: " + OWN_ID.encode()),
            (1, b"[{}]", b"No such object: " + OWN_ID.encode()),
            (1, b"[]", b"daemon unavailable"),
            (1, b"[]", b"No such object: " + FOREIGN_ID.encode()),
        )
        for code, stdout, stderr in variants:
            with self.subTest(code=code, stdout=stdout, stderr=stderr):
                docker = DockerFixture()
                docker.missing = subprocess.CompletedProcess([], code, stdout, stderr)
                with self.assertRaises(resources.OwnershipError):
                    self.record.remove(docker)

    def test_operation_uses_captured_id_after_ownership_check(self):
        seal = "e" * 64
        self.record.command(self.docker, "exec", ["/usr/local/bin/php", "/dev/stdin", "inventory", seal],
                            options=("--interactive", "--user", "82:82"))
        self.assertEqual(["inspect", OWN_ID], self.docker.calls[0])
        self.assertEqual(["exec", "--interactive", "--user", "82:82", OWN_ID,
                          "/usr/local/bin/php", "/dev/stdin", "inventory", seal], self.docker.calls[1])

    def test_extra_selectors_and_commands_refuse_before_transport(self):
        variants = [("start", [FOREIGN_ID], ()),
                    ("stop", [FOREIGN_ID], ("--time", "5")),
                    ("logs", [FOREIGN_ID], ("--tail", "200")),
                    ("port", [FOREIGN_ID], ()),
                    ("exec", ["sh", "-c", "unselected"], ("--interactive", "--user", "82:82")),
                    ("exec", ["/usr/local/bin/php", "/dev/stdin", "inventory", "e" * 64], ("--user", "root")),
                    ("rm", [], ())]
        for verb, args, options in variants:
            with self.subTest(verb=verb, args=args), self.assertRaises(resources.OwnershipError):
                self.record.command(self.docker, verb, args, options=options)
        self.assertEqual([], self.docker.calls)

    def test_names_short_ids_and_cross_run_selectors_are_rejected(self):
        variants = [("application", RUN, "application", IMAGE), (OWN_ID[:12], RUN, "application", IMAGE),
                    (OWN_ID, "other-run", "application", IMAGE), (OWN_ID, RUN, "operator", IMAGE),
                    (OWN_ID, RUN, "application", "image:tag")]
        for args in variants:
            with self.subTest(args=args), self.assertRaises(resources.OwnershipError):
                resources.Container(*args, NETWORK)

    def test_changed_resource_bounds_refuse_but_owned_cleanup_still_works(self):
        changes = {"ReadonlyRootfs": False, "Init": False, "NanoCpus": 0,
                   "Memory": 0, "MemorySwap": -1, "PidsLimit": -1, "NetworkMode": "host",
                   "Privileged": True, "PidMode": "host", "IpcMode": "host", "CgroupnsMode": "host",
                   "UTSMode": "host", "Binds": ["/host:/host"], "CapDrop": None, "CapAdd": ["SYS_ADMIN"],
                   "SecurityOpt": [], "Tmpfs": {"/app": "rw"}, "RestartPolicy": {"Name": "always"},
                   "LogConfig": {"Type": "json-file", "Config": {}},
                   "Devices": [{"PathOnHost": "/dev/sda"}], "Mounts": [{"Type": "bind"}],
                   "DeviceRequests": [{"Count": -1}]}
        for key, value in changes.items():
            with self.subTest(key=key):
                docker = DockerFixture()
                docker.owned["HostConfig"][key] = value
                with self.assertRaises(resources.OwnershipError):
                    self.record.command(docker, "start", [])
                self.assertEqual([["inspect", OWN_ID]], docker.calls)
                self.record.remove(docker)
                self.assertNotIn(OWN_ID, docker.containers)
                self.assertIn(FOREIGN_ID, docker.containers)

    def test_host_mount_and_published_port_escapes_refuse(self):
        variants = [
            ("Mounts", [{"Type": "bind", "Source": "/host", "Destination": "/app"}]),
            ("Mounts", [{"Type": "volume", "Destination": "/data"}]),
            ("Mounts", [{"Type": "tmpfs", "Destination": "/app", "RW": True}]),
            ("Config", {"Labels": self.record.labels, "User": "82:82"}),
        ]
        for key, value in variants:
            with self.subTest(key=key, value=value):
                docker = DockerFixture()
                docker.owned[key] = value
                with self.assertRaises(resources.OwnershipError):
                    self.record.command(docker, "start", [])
                self.assertEqual([["inspect", OWN_ID]], docker.calls)
        for binding in ({"HostIp": "0.0.0.0", "HostPort": "1234"},
                        {"HostIp": "127.0.0.1", "HostPort": "99999"}):
            docker = DockerFixture()
            docker.owned["HostConfig"]["PortBindings"]["8080/tcp"] = [binding]
            with self.assertRaises(resources.OwnershipError):
                self.record.command(docker, "start", [])
            self.assertEqual([["inspect", OWN_ID]], docker.calls)

    def test_private_temporary_directory_is_required_and_cannot_be_widened(self):
        environments = [None, [], ["TMPDIR=/tmp"], ["TMPDIR=/run/waterline", "TMPDIR=/tmp"]]
        for environment in environments:
            with self.subTest(environment=environment):
                docker = DockerFixture()
                docker.owned["Config"]["Env"] = environment
                with self.assertRaises(resources.OwnershipError):
                    self.record.command(docker, "start", [])
                self.assertEqual([["inspect", OWN_ID]], docker.calls)
        docker = DockerFixture()
        docker.owned["HostConfig"]["Tmpfs"]["/run/waterline"] = "rw,size=16m,mode=1777"
        with self.assertRaises(resources.OwnershipError):
            self.record.command(docker, "start", [])
        self.assertEqual([["inspect", OWN_ID]], docker.calls)


if __name__ == "__main__":
    unittest.main()
