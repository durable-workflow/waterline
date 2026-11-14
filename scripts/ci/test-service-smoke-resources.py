"""Inert container-identity counterfactuals; no Docker command is executed."""

import copy
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
        self.owned = {"Id": OWN_ID, "Image": IMAGE, "Config": {"Labels": labels, "User": "0:0", "Env": ["TMPDIR=/run/waterline"],
                                                               "Entrypoint": ["/app/entrypoint.sh"], "Cmd": [], "Healthcheck": {"Test": ["NONE"]}},
                      "Mounts": [], "HostConfig": {
                          "ReadonlyRootfs": True, "Init": True, "NanoCpus": 2_000_000_000,
                          "Memory": 805306368, "MemorySwap": 805306368, "PidsLimit": 256,
                          "NetworkMode": NETWORK, "Privileged": False, "PidMode": "", "UTSMode": "",
                          "IpcMode": "private", "CgroupnsMode": "private", "Binds": None,
                          "CapDrop": ["ALL"], "CapAdd": ["CHOWN", "DAC_OVERRIDE", "SETGID", "SETUID"],
                          "SecurityOpt": ["no-new-privileges"],
                          "Tmpfs": {"/data": "rw,nosuid,nodev,noexec,size=64m,uid=0,gid=0,mode=0755",
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
        self.missing = subprocess.CompletedProcess([], 1, b"[]\n", b"Error: No such object: " + OWN_ID.encode() + b"\n")

    def __call__(self, arguments, **_inputs):
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
        creation = resources.CreationSpec(("/app/entrypoint.sh",), (), ("TMPDIR=/run/waterline",))
        self.record = resources.Container(OWN_ID, RUN, "application", IMAGE, NETWORK, creation)

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
                            options=("--interactive", "--user", "82:82"), data=b"fixed source")
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

    def test_cleanup_does_not_need_a_refused_or_missing_execution_profile(self):
        record = resources.Container(OWN_ID, RUN, "application", IMAGE)
        with self.assertRaises(resources.OwnershipError):
            record.command(self.docker, "start", [])
        self.assertFalse(any(call[0] == "start" for call in self.docker.calls))
        record.remove(self.docker)
        self.assertNotIn(OWN_ID, self.docker.containers)

    def test_program_or_environment_swap_refuses_before_start(self):
        variants = [("Entrypoint", ["/bin/sh"]), ("Cmd", ["unselected"]), ("Healthcheck", {"Test": ["CMD", "unselected"]}),
                    ("Env", ["TMPDIR=/run/waterline", "EXTRA=unselected"]),
                    ("Env", ["TMPDIR=/run/waterline", "TMPDIR=/run/waterline"])]
        for key, value in variants:
            with self.subTest(key=key, value=value):
                docker = DockerFixture()
                docker.owned["Config"][key] = value
                with self.assertRaises(resources.OwnershipError):
                    self.record.command(docker, "start", [])
                self.assertEqual([["inspect", OWN_ID]], docker.calls)
                self.record.remove(docker)
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


class CreationFixture(DockerFixture):
    def __init__(self):
        super().__init__()
        self.template = copy.deepcopy(self.owned)
        self.containers = {FOREIGN_ID: self.foreign}
        self.next_ids = iter([OWN_ID, "c" * 64])
        self.create_output = None
        self.create_code = 0
        self.listing_fault = None
        self.after_remove = None

    def __call__(self, arguments, **inputs):
        if arguments[0] == "create":
            return self.create(arguments)
        if arguments[0] == "ps":
            self.calls.append(arguments)
            role = arguments[8].split("=", 2)[2]
            if self.listing_fault and role == "metadata":
                return self.listing_fault
            self.assert_listing(arguments, role)
            labels = resources.ownership_labels(RUN, role)
            matches = [key for key, value in self.containers.items()
                       if all(value["Config"]["Labels"].get(k) == v for k, v in labels.items())]
            return subprocess.CompletedProcess(arguments, 0, "".join(key + "\n" for key in matches).encode(), b"")
        if arguments[0] == "inspect" and arguments[-1] not in self.containers:
            self.calls.append(arguments)
            return subprocess.CompletedProcess(arguments, 1, b"[]\n", b"Error: No such object: " + arguments[-1].encode() + b"\n")
        result = super().__call__(arguments, **inputs)
        if arguments[0] == "rm" and self.after_remove:
            self.after_remove(arguments[-1])
        return result

    @staticmethod
    def assert_listing(arguments, role):
        if arguments != resources.role_listing_arguments(RUN, role):
            raise AssertionError("cleanup query lost its ownership filters")

    def create(self, arguments):
        self.calls.append(arguments)
        identity = next(self.next_ids)
        labels = dict(arguments[i + 1].split("=", 1) for i, value in enumerate(arguments) if value == "--label")
        role = labels[resources.ROLE_KEY]
        selected = resources.profile(role, NETWORK if role in {"fixture", "application"} else None)
        record = copy.deepcopy(self.template)
        record.update({"Id": identity, "Image": IMAGE})
        image_offset = arguments.index(IMAGE)
        record["Config"] = {"Labels": labels, "User": selected["user"],
                            "Healthcheck": {"Test": ["NONE"]},
                            "Entrypoint": [arguments[arguments.index("--entrypoint") + 1]],
                            "Cmd": arguments[image_offset + 1:],
                            "Env": [arguments[i + 1] for i, value in enumerate(arguments) if value == "--env"]}
        record["HostConfig"].update({"NanoCpus": selected["cpus"], "Memory": selected["memory"],
                                     "MemorySwap": selected["memory"], "PidsLimit": selected["pids"],
                                     "NetworkMode": selected["network"], "CapAdd": selected["cap_add"] or None,
                                     "Tmpfs": selected["tmpfs"], "PortBindings": {} if role != "application" else {
                                         "8080/tcp": [{"HostIp": "127.0.0.1", "HostPort": ""}],
                                     }})
        self.containers[identity] = record
        if isinstance(self.create_output, Exception):
            raise self.create_output
        output = (identity + "\n").encode() if self.create_output is None else self.create_output
        return subprocess.CompletedProcess(arguments, self.create_code, output, b"")


class CreationCleanupTest(unittest.TestCase):
    def setUp(self):
        self.docker = CreationFixture()
        self.roles = resources.RoleSet(self.docker, self.docker, run=RUN, image=IMAGE,
                                       platform="linux/amd64", base_environment=(), network=NETWORK)

    def create(self, role="metadata"):
        return self.roles.create(role, entrypoint="/usr/local/bin/php", command=["-n", "/dev/stdin", "metadata"], environment={})

    def test_lost_or_ambiguous_stdout_recovers_deletion_only(self):
        for output in (b"", b"ambiguous\n", (OWN_ID + "\n" + OWN_ID + "\n").encode(), RuntimeError("synthetic timeout")):
            with self.subTest(output=type(output).__name__):
                self.setUp()
                self.docker.create_output = output
                with self.assertRaises((resources.OwnershipError, RuntimeError)):
                    self.create()
                self.assertIsNone(self.roles.attempted["metadata"])
                result = self.roles.cleanup()
                self.assertTrue(result["absent"])
                self.assertEqual(OWN_ID, result["roles"]["metadata"]["recovered_id"])
                self.assertEqual({FOREIGN_ID}, set(self.docker.containers))
                self.assertFalse(any(call[0] == "start" for call in self.docker.calls))

    def test_error_after_id_capture_keeps_the_full_id_for_cleanup(self):
        self.docker.create_code = 1
        with self.assertRaises(resources.OwnershipError):
            self.create()
        self.assertEqual(OWN_ID, self.roles.attempted["metadata"].identifier)
        result = self.roles.cleanup()
        self.assertTrue(result["absent"])
        self.assertIsNone(result["roles"]["metadata"]["recovered_id"])

    def test_role_can_be_created_only_once_and_is_not_started_by_create(self):
        captured = self.create()
        with self.assertRaises(resources.OwnershipError):
            self.create()
        self.assertEqual(OWN_ID, captured.identifier)
        self.assertEqual(1, sum(call[0] == "create" for call in self.docker.calls))
        self.assertFalse(any(call[0] == "start" for call in self.docker.calls))

    def test_loader_overrides_and_mutable_image_environment_refuse_before_create(self):
        for environment in ({"LD_PRELOAD": "unselected"}, {"TMPDIR": "/tmp"}, {"APP_ROUTES_CACHE": "unselected"}):
            with self.subTest(environment=environment), self.assertRaises(resources.OwnershipError):
                self.roles.create("metadata", entrypoint="/usr/local/bin/php", command=["-n"], environment=environment)
        self.assertEqual([], self.docker.calls)
        for base in (["PATH=/usr/bin"], ("LD_PRELOAD=unselected",), ("PATH=a", "PATH=b")):
            with self.subTest(base=base), self.assertRaises(resources.OwnershipError):
                resources.RoleSet(self.docker, self.docker, run=RUN, image=IMAGE, platform="linux/amd64", base_environment=base)
        self.assertEqual([], self.docker.calls)

    def test_ambiguous_candidate_preserves_both_and_cleans_the_other_role(self):
        self.create()
        self.create("trust")
        duplicate = copy.deepcopy(self.docker.containers[OWN_ID])
        duplicate["Id"] = "d" * 64
        self.docker.containers[duplicate["Id"]] = duplicate
        result = self.roles.cleanup()
        self.assertFalse(result["absent"])
        self.assertIn(OWN_ID, self.docker.containers)
        self.assertIn("d" * 64, self.docker.containers)
        self.assertNotIn("c" * 64, self.docker.containers)

    def test_known_id_never_switches_to_a_same_role_replacement(self):
        self.create()
        replacement = self.docker.containers.pop(OWN_ID)
        replacement["Id"] = "e" * 64
        self.docker.containers[replacement["Id"]] = replacement
        result = self.roles.cleanup()
        self.assertFalse(result["absent"])
        self.assertIn("e" * 64, self.docker.containers)
        self.assertFalse(any(call[0] == "rm" for call in self.docker.calls))

    def test_changed_owner_is_preserved_even_when_role_listing_is_empty(self):
        self.create()
        self.docker.containers[OWN_ID]["Config"]["Labels"][resources.OWNER_KEY] = "foreign"
        result = self.roles.cleanup()
        self.assertFalse(result["absent"])
        self.assertIn(OWN_ID, self.docker.containers)

    def test_late_replacement_is_detected_by_the_final_all_role_audit(self):
        self.create()
        self.create("trust")
        trust = copy.deepcopy(self.docker.containers["c" * 64])
        trust["Id"] = "e" * 64
        self.docker.after_remove = lambda identity: self.docker.containers.update({trust["Id"]: trust}) if identity == OWN_ID else None
        result = self.roles.cleanup()
        self.assertFalse(result["absent"])
        self.assertFalse(result["roles"]["trust"]["absent"])
        self.assertIn("e" * 64, self.docker.containers)
        self.assertFalse(any(call[0] == "rm" and call[-1] == "e" * 64 for call in self.docker.calls))

    def test_failed_partial_or_oversized_listing_cannot_report_absence(self):
        for code, raw, stderr in ((1, b"", b"daemon unavailable"), (0, b"", b"warning"),
                                  (0, b"a" * 12 + b"\n", b""), (0, b"a" * 131, b"")):
            with self.subTest(code=code, size=len(raw)):
                self.setUp()
                self.create()
                self.create("trust")
                self.docker.listing_fault = subprocess.CompletedProcess([], code, raw, stderr)
                result = self.roles.cleanup()
                self.assertFalse(result["absent"])
                self.assertIn(OWN_ID, self.docker.containers)
                self.assertNotIn("c" * 64, self.docker.containers)


if __name__ == "__main__":
    unittest.main()
