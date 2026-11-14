"""Local inert/stdio controls. No Docker, application or package is executed."""

import base64
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

STATE_PREFIX = "waterline-buildx-state-"


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

    def test_absolute_marker_child_has_only_kernel_home_config_and_helper_path(self):
        config = self.config()
        program = "import json,os; print(json.dumps({key:os.environ[key] for key in ('PATH','HOME','DOCKER_CONFIG','XDG_CONFIG_HOME')}))"
        result = smoke.bounded_process([sys.executable, "-I", "-c", program],
                                       pass_fds=(config.descriptor,), kernel_directory=config.descriptor)
        self.assertEqual(0, result.returncode)
        self.assertEqual({key: config.path for key in ("PATH", "HOME", "DOCKER_CONFIG", "XDG_CONFIG_HOME")}, json.loads(result.stdout))
        with self.assertRaises(smoke.TransportError):
            smoke.child_environment(config.descriptor, ())

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

    def test_cancelled_scope_refuses_ordinary_operations_but_allows_id_cleanup(self):
        def cancelled():
            raise InterruptedError("synthetic cancellation")
        docker = smoke.Docker(self.parent, scope_check=cancelled)
        self.addCleanup(docker.close)
        returned = subprocess.CompletedProcess([], 0, b"[]", b"")
        with patch.object(smoke, "bounded_process", return_value=returned) as command:
            with self.assertRaises(InterruptedError):
                docker(["inspect", "a" * 64])
            command.assert_not_called()
            docker.cleanup(["inspect", "a" * 64])
            self.assertEqual(1, command.call_count)

    def test_acquisition_has_one_closed_index_platform_and_its_own_finite_bound(self):
        docker = smoke.Docker(self.parent)
        self.addCleanup(docker.close)
        returned = subprocess.CompletedProcess([], 0, b"synthetic acquisition output", b"")
        with patch.object(smoke, "execute_bounded", return_value=returned) as command:
            docker.acquire("docker.io/durableworkflow/waterline@sha256:" + "a" * 64, "linux/amd64")
            self.assertEqual(180, command.call_args.kwargs["timeout"])
            self.assertEqual((docker.config.descriptor,), command.call_args.kwargs["pass_fds"])
            command.reset_mock()
            for image, platform in (("foreign/image:tag", "linux/amd64"), ("docker.io/durableworkflow/waterline:latest", "linux/amd64"),
                                    ("docker.io/durableworkflow/waterline@sha256:" + "a" * 64, "emulated")):
                with self.subTest(image=image, platform=platform), self.assertRaises(smoke.TransportError):
                    docker.acquire(image, platform)
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


class BuildxStateTest(unittest.TestCase):
    def setUp(self):
        self.directory = tempfile.TemporaryDirectory(prefix="waterline-buildx-source-")
        self.addCleanup(self.directory.cleanup)
        self.parent = Path(self.directory.name)

    def assert_staged_failure_cleaned(self, stage):
        actual_open, actual_fstat = os.open, os.fstat
        opened = []

        def intercept_open(name, flags, *args, **kwargs):
            if isinstance(name, str) and name.startswith(STATE_PREFIX):
                if stage == "open":
                    raise OSError("synthetic staged open refusal")
                descriptor = actual_open(name, flags, *args, **kwargs)
                opened.append(descriptor)
                return descriptor
            return actual_open(name, flags, *args, **kwargs)

        def intercept_fstat(descriptor):
            if stage == "fstat" and descriptor in opened:
                raise OSError("synthetic first held fstat refusal")
            return actual_fstat(descriptor)

        event = {}
        with patch.object(smoke.os, "open", side_effect=intercept_open), patch.object(smoke.os, "fstat", side_effect=intercept_fstat):
            with self.assertRaises(OSError):
                smoke.BuildxConfig(self.parent, event=event)
        self.assertEqual([], list(self.parent.iterdir()))
        self.assertEqual("pass", event["cleanup"])
        self.assertIn("creation_failure", event)
        self.assertIn("private_state_identity", event)

    def test_post_mkdir_open_failure_removes_only_captured_empty_state(self):
        self.assert_staged_failure_cleaned("open")

    def test_first_held_fstat_failure_removes_only_captured_empty_state(self):
        self.assert_staged_failure_cleaned("fstat")

    def test_staged_foreign_replacement_is_preserved_and_reported(self):
        actual_open = os.open
        moved = self.parent / "owned-moved"
        foreign = []

        def replacement(name, flags, *args, **kwargs):
            if isinstance(name, str) and name.startswith(STATE_PREFIX):
                original = self.parent / name
                original.rename(moved)
                original.mkdir(mode=0o700)
                marker = original / "foreign-marker"
                marker.write_text("preserve")
                foreign.append(marker)
                raise OSError("synthetic open refusal after replacement")
            return actual_open(name, flags, *args, **kwargs)

        event = {}
        with patch.object(smoke.os, "open", side_effect=replacement):
            with self.assertRaises(smoke.TransportError):
                smoke.BuildxConfig(self.parent, event=event)
        self.assertEqual("preserve", foreign[0].read_text())
        self.assertTrue(moved.is_dir())
        self.assertEqual("nonpass", event["cleanup"])
        self.assertIn("creation_failure", event)
        self.assertIn("cleanup_failure", event)

    def test_failed_initial_identity_capture_preserves_unknown_state_as_nonpass(self):
        actual_stat = os.stat

        def failure(name, *args, **kwargs):
            if isinstance(name, str) and name.startswith(STATE_PREFIX):
                raise OSError("synthetic initial identity refusal")
            return actual_stat(name, *args, **kwargs)

        event = {}
        with patch.object(smoke.os, "stat", side_effect=failure):
            with self.assertRaises(smoke.TransportError):
                smoke.BuildxConfig(self.parent, event=event)
        self.assertEqual(1, len(list(self.parent.iterdir())))
        self.assertEqual("nonpass", event["cleanup"])
        self.assertIn("cleanup_failure", event)

    def test_manifest_records_attempt_before_constructor_refuses(self):
        docker = smoke.Docker(self.parent)
        self.addCleanup(docker.close)
        actual_open = os.open

        def failure(name, flags, *args, **kwargs):
            if isinstance(name, str) and name.startswith(STATE_PREFIX):
                raise OSError("synthetic staged open refusal")
            return actual_open(name, flags, *args, **kwargs)

        with patch.object(smoke.os, "open", side_effect=failure), patch.object(smoke, "bounded_process") as command:
            with self.assertRaises(OSError):
                docker.manifest("docker.io/durableworkflow/waterline@sha256:" + "a" * 64)
            command.assert_not_called()
        self.assertEqual(1, len(docker.manifest_events))
        self.assertEqual("pass", docker.manifest_events[0]["cleanup"])
        self.assertIn("creation_failure", docker.manifest_events[0])
        self.assertEqual([], list(self.parent.iterdir()))

    def test_staged_nonempty_state_is_preserved_as_nonpass(self):
        actual_open = os.open
        markers = []

        def failure(name, flags, *args, **kwargs):
            if isinstance(name, str) and name.startswith(STATE_PREFIX):
                marker = self.parent / name / "unadmitted-marker"
                marker.write_text("preserve")
                markers.append(marker)
                raise OSError("synthetic open refusal after unexpected write")
            return actual_open(name, flags, *args, **kwargs)

        event = {}
        with patch.object(smoke.os, "open", side_effect=failure):
            with self.assertRaises(OSError):
                smoke.BuildxConfig(self.parent, event=event)
        self.assertEqual("preserve", markers[0].read_text())
        self.assertEqual("nonpass", event["cleanup"])

    def test_old_noncreatable_default_fails_while_actual_separate_state_writes_and_is_removed(self):
        # This child exercises the actual mkdir/FD boundary without running a
        # Docker plugin, registry request, package, application or container.
        program = """import json,os,pathlib,sys
state=pathlib.Path(os.environ.get('BUILDX_CONFIG',os.environ['DOCKER_CONFIG']+'/buildx'))
try:
 state.mkdir(exist_ok=True)
 (state/'synthetic-marker').write_text('state')
 print(json.dumps({'state':str(state),'home':os.environ['HOME']}))
except OSError as error:
 print(str(error),file=sys.stderr)
 sys.exit(7)
"""
        docker = smoke.Docker(self.parent)
        self.addCleanup(docker.close)
        predecessor = smoke.bounded_process([sys.executable, "-I", "-c", program],
                                            pass_fds=(docker.config.descriptor,), kernel_directory=docker.config.descriptor)
        self.assertEqual(7, predecessor.returncode)
        self.assertIn(b"No such file or directory", predecessor.stderr)
        actual = smoke.bounded_process
        calls = []

        def marker(command, **options):
            calls.append((command, options))
            return actual([sys.executable, "-I", "-c", program], **options)

        with patch.object(smoke, "bounded_process", side_effect=marker):
            result = docker.manifest("docker.io/durableworkflow/waterline@sha256:" + "a" * 64)
        self.assertEqual(0, result.returncode, result.stderr)
        observed = json.loads(result.stdout)
        self.assertTrue(observed["state"].startswith("/proc/" + str(os.getpid()) + "/fd/"))
        self.assertEqual(docker.config.path, observed["home"])
        self.assertEqual([], list(self.parent.iterdir()))
        self.assertEqual("pass", docker.manifest_events[0]["cleanup"])
        self.assertEqual(["buildx", "imagetools", "inspect", "--raw"], calls[0][0][5:9])
        docker.config.check()

    def test_stderr_is_retained_and_state_removed_on_failed_manifest(self):
        docker = smoke.Docker(self.parent)
        self.addCleanup(docker.close)
        response = subprocess.CompletedProcess([], 7, b"", b"synthetic Buildx diagnostic")
        with patch.object(smoke, "bounded_process", return_value=response):
            result = docker.manifest("docker.io/durableworkflow/waterline@sha256:" + "a" * 64)
        self.assertEqual(7, result.returncode)
        self.assertEqual(response.stderr, base64.b64decode(docker.manifest_events[0]["stderr_base64"]))
        self.assertEqual("pass", docker.manifest_events[0]["cleanup"])
        self.assertEqual([], list(self.parent.iterdir()))

    def test_parent_rename_refuses_work_but_cleanup_uses_held_parent(self):
        state = smoke.BuildxConfig(self.parent)
        renamed = self.parent.with_name(self.parent.name + "-moved")
        self.parent.rename(renamed)
        try:
            with self.assertRaises(FileNotFoundError):
                state.check()
            state.close()
            self.assertEqual([], list(renamed.iterdir()))
        finally:
            renamed.rename(self.parent)

    def test_transport_refusal_still_removes_state_and_retains_original_failure(self):
        docker = smoke.Docker(self.parent)
        self.addCleanup(docker.close)
        with patch.object(smoke, "bounded_process", side_effect=smoke.TransportError("synthetic deadline")):
            with self.assertRaises(smoke.TransportError):
                docker.manifest("docker.io/durableworkflow/waterline@sha256:" + "a" * 64)
        self.assertEqual([], list(self.parent.iterdir()))
        self.assertEqual("synthetic deadline", docker.manifest_events[0]["failure"]["message"])
        self.assertEqual("pass", docker.manifest_events[0]["cleanup"])

    def test_nested_state_link_cleanup_preserves_its_foreign_target(self):
        target = self.parent / "foreign-target"
        target.mkdir()
        marker = target / "marker"
        marker.write_text("preserve")
        state = smoke.BuildxConfig(self.parent)
        nested = self.parent / state.name / "nested"
        nested.mkdir()
        (nested / "link").symlink_to(target, target_is_directory=True)
        state.close()
        self.assertEqual("preserve", marker.read_text())
        self.assertEqual([target], list(self.parent.iterdir()))

    def test_foreign_state_name_replacement_is_preserved_and_cleanup_is_nonpass(self):
        state = smoke.BuildxConfig(self.parent)
        original = self.parent / state.name
        moved = self.parent / "owned-moved"
        original.rename(moved)
        original.mkdir(mode=0o700)
        marker = original / "foreign-marker"
        marker.write_text("preserve")
        with self.assertRaises(smoke.TransportError):
            state.check()
        with self.assertRaises(smoke.TransportError):
            state.close()
        self.assertEqual("preserve", marker.read_text())
        self.assertTrue(moved.is_dir())

    def test_ordinary_docker_and_id_cleanup_never_inherit_buildx_state(self):
        docker = smoke.Docker(self.parent)
        self.addCleanup(docker.close)
        response = subprocess.CompletedProcess([], 0, b"[]", b"")
        with patch.object(smoke, "bounded_process", return_value=response) as command:
            docker(["inspect", "a" * 64])
            self.assertNotIn("buildx_directory", command.call_args.kwargs)
            docker.cleanup(["inspect", "a" * 64])
            self.assertNotIn("buildx_directory", command.call_args.kwargs)
            with self.assertRaises(smoke.TransportError):
                docker(["buildx", "imagetools", "inspect", "--raw", "unselected"])
        self.assertEqual([], list(self.parent.iterdir()))


if __name__ == "__main__":
    unittest.main()
