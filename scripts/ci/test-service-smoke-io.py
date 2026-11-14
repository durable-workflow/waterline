"""Local inert/stdio controls. No Docker, application or package is executed."""

import json
import os
import signal
from pathlib import Path
import subprocess
import sys
import tempfile
import time
import unittest
from unittest.mock import patch

sys.path.insert(0, str(Path(__file__).resolve().parent))
import service_smoke_io as smoke
import service_smoke_resources as resources


class FiniteProcessTest(unittest.TestCase):
    def test_exact_bytes_and_nonzero_status_are_retained(self):
        result = smoke.bounded_process([sys.executable, "-I", "-c",
                                        "import sys; sys.stdout.buffer.write(sys.stdin.buffer.read()); "
                                        "sys.stderr.write('synthetic'); sys.exit(7)"], data=b"fixed\x00bytes")
        self.assertEqual(7, result.returncode)
        self.assertEqual(b"fixed\x00bytes", result.stdout)
        self.assertEqual(b"synthetic", result.stderr)

    def test_incomplete_stdin_delivery_refuses(self):
        self.assert_refused_and_reaped([sys.executable, "-I", "-c", "print('done')"], data=b"x" * 1024 * 1024)

    def test_unselected_environment_is_not_inherited(self):
        with patch.dict(os.environ, {"WATERLINE_SYNTHETIC_SENTINEL": "marker"}):
            result = smoke.bounded_process([sys.executable, "-I", "-c",
                                            "import os; print(os.getenv('WATERLINE_SYNTHETIC_SENTINEL'))"])
        self.assertEqual(b"None\n", result.stdout)

    def assert_refused_and_reaped(self, command, **bounds):
        created = []
        real_popen = subprocess.Popen

        def capture(*args, **kwargs):
            child = real_popen(*args, **kwargs)
            created.append(child.pid)
            return child

        started = time.monotonic()
        with patch.object(smoke.subprocess, "Popen", side_effect=capture):
            with self.assertRaises(smoke.TransportError):
                smoke.bounded_process(command, **bounds)
        self.assertLess(time.monotonic() - started, 3)
        self.assertEqual(1, len(created))
        with self.assertRaises(ProcessLookupError):
            os.kill(created[0], 0)
        return created[0]

    def test_stdout_overrun_refuses_and_reaps_the_process(self):
        self.assert_refused_and_reaped([sys.executable, "-I", "-c", "print('x'*65536)"], stdout_limit=32)

    def test_stderr_overrun_refuses_and_reaps_the_process(self):
        self.assert_refused_and_reaped([sys.executable, "-I", "-c", "import sys; sys.stderr.write('x'*65536)"], stderr_limit=32)

    def test_deadline_refuses_and_reaps_the_process(self):
        self.assert_refused_and_reaped([sys.executable, "-I", "-c", "import time; time.sleep(10)"], timeout=0.1)

    def test_exited_leader_does_not_leave_a_live_process_group(self):
        program = "import os,time; child=os.fork(); os._exit(0) if child else time.sleep(10)"
        with patch.object(smoke.os, "killpg", wraps=os.killpg) as group_signal:
            leader = self.assert_refused_and_reaped([sys.executable, "-I", "-c", program], timeout=0.1)
        group_signal.assert_called_once_with(leader, signal.SIGKILL)

    def test_closed_output_does_not_remove_the_process_deadline(self):
        program = "import os,time; os.close(1); os.close(2); time.sleep(10)"
        self.assert_refused_and_reaped([sys.executable, "-I", "-c", program], timeout=0.1)

    def test_invalid_bounds_refuse_before_process_creation(self):
        variants = [{"data": b"x" * (1024 * 1024 + 1)}, {"timeout": 31}, {"timeout": 0},
                    {"stdout_limit": 0}, {"stderr_limit": 65537}]
        with patch.object(smoke.subprocess, "Popen") as popen:
            for options in variants:
                with self.subTest(options=list(options)), self.assertRaises(smoke.TransportError):
                    smoke.bounded_process(["unselected"], **options)
            popen.assert_not_called()


class AnonymousConfigTest(unittest.TestCase):
    def setUp(self):
        self.directory = tempfile.TemporaryDirectory(prefix="waterline-io-source-")
        self.addCleanup(self.directory.cleanup)
        self.parent = Path(self.directory.name)

    def config(self):
        value = smoke.AnonymousConfig(self.parent)
        self.addCleanup(value.close)
        return value

    def test_unlinked_configuration_is_empty_and_cannot_gain_files(self):
        config = self.config()
        self.assertEqual([], list(self.parent.iterdir()))
        self.assertEqual(0, os.fstat(config.descriptor).st_nlink)
        self.assertEqual([], os.listdir(config.descriptor))
        with self.assertRaises(FileNotFoundError):
            os.open("config.json", os.O_CREAT | os.O_WRONLY, 0o600, dir_fd=config.descriptor)
        config.check()

    def test_path_predecessor_reads_replacement_while_held_config_preserves_it(self):
        config = self.config()
        original = os.readlink(config.path)
        self.assertTrue(original.endswith(" (deleted)"))
        replacement = Path(original.removesuffix(" (deleted)"))
        replacement.mkdir(mode=0o700)
        (replacement / "config.json").write_text('{"synthetic_marker":true}')
        # This is the old path-based lookup, and exposes the synthetic marker.
        self.assertTrue(json.loads((replacement / "config.json").read_text())["synthetic_marker"])
        # The actual config namespace is the retained, already-unlinked inode.
        config.check()
        self.assertFalse(Path(config.path, "config.json").exists())
        config.close()
        self.assertTrue((replacement / "config.json").is_file())

    def test_symlink_or_group_writable_anchor_refuses(self):
        actual = self.parent / "actual"
        actual.mkdir(mode=0o700)
        alias = self.parent / "alias"
        alias.symlink_to(actual, target_is_directory=True)
        with self.assertRaises((OSError, smoke.TransportError)):
            smoke.AnonymousConfig(alias)
        actual.chmod(0o770)
        try:
            with self.assertRaises(smoke.TransportError):
                smoke.AnonymousConfig(actual)
        finally:
            actual.chmod(0o700)

    def test_cleanup_transport_survives_expired_run_and_parent_rename(self):
        docker = smoke.Docker(self.parent)
        self.addCleanup(docker.close)
        docker.started -= 601
        renamed = self.parent.with_name(self.parent.name + "-moved")
        self.parent.rename(renamed)
        try:
            with patch.object(smoke, "bounded_process", return_value=subprocess.CompletedProcess([], 0, b"[]", b"")) as command:
                with self.assertRaises(smoke.TransportError):
                    docker(["inspect", "a" * 64])
                result = docker.cleanup(["inspect", "a" * 64])
                self.assertEqual(0, result.returncode)
                args = command.call_args.args[0]
                self.assertEqual(["/usr/bin/docker", "--host", "unix:///var/run/docker.sock", "--config", docker.config.path], args[:5])
                self.assertEqual((docker.config.descriptor,), command.call_args.kwargs["pass_fds"])
        finally:
            renamed.rename(self.parent)

    def test_cleanup_accepts_no_name_extra_selector_or_new_effect(self):
        docker = smoke.Docker(self.parent)
        self.addCleanup(docker.close)
        variants = [["inspect", "foreign-name"], ["rm", "--force", "--volumes", "a" * 12],
                    ["rm", "--force", "--volumes", "a" * 64, "b" * 64],
                    ["run", "a" * 64], ["network", "create", "a" * 64]]
        with patch.object(smoke, "bounded_process") as command:
            for arguments in variants:
                with self.subTest(arguments=arguments), self.assertRaises(smoke.TransportError):
                    docker.cleanup(arguments)
            command.assert_not_called()

    def test_cleanup_listing_requires_the_exact_owner_run_role_filter_set(self):
        docker = smoke.Docker(self.parent)
        self.addCleanup(docker.close)
        arguments = resources.role_listing_arguments("waterline-service-" + "a" * 32, "metadata")
        with patch.object(smoke, "bounded_process", return_value=subprocess.CompletedProcess([], 0, b"", b"")) as command:
            docker.cleanup(arguments)
            self.assertEqual(arguments, command.call_args.args[0][5:])
            variants = [arguments[:-2], arguments + ["another-selector"],
                        [value.replace("owner=waterline", "owner=foreign") for value in arguments],
                        [value.replace("role=metadata", "role=unselected") for value in arguments]]
            for changed in variants:
                with self.subTest(changed=changed), self.assertRaises(smoke.TransportError):
                    docker.cleanup(changed)
            self.assertEqual(1, command.call_count)


if __name__ == "__main__":
    unittest.main()
