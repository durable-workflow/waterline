"""Explicit source controls: cached PHP only, synthetic files, no application.

Run with: python3 -m unittest discover -s tests/ci -p test_current_runtime_probe_source.py
No image pull/build, package install, actual cache command or runtime is selected.
"""

import base64
from datetime import datetime, timezone
import hashlib
import json
import os
from pathlib import Path
import subprocess
import tempfile
import unittest
import uuid


ROOT = Path(__file__).resolve().parents[2]
IMAGE = "php@sha256:232331112d3a7c5b98edeeddc3b85f610172f99352c0635fe6b3e166e47062cb"
CHILD = "sha256:b2d2be78de4de803e20d1ae3df91c196e332a40cc851754472be4fab0fac1f03"
LOCK_SHA = "c2181f466bcbb799e3ce183d58531574d32de0cf9eba2f78dd9c18b211c60466"


class CurrentRuntimeProbeSourceTest(unittest.TestCase):
    def setUp(self):
        self.state = tempfile.TemporaryDirectory(prefix="waterline-probe-source-")
        self.addCleanup(self.state.cleanup)
        self.root = Path(self.state.name)
        (self.root / "docker").mkdir(mode=0o700)
        self.prefix = ["docker", "--host", "unix:///var/run/docker.sock", "--config", str(self.root / "docker")]
        try:
            result = self.docker(["image", "inspect", "--platform", "linux/amd64", IMAGE])
        except (FileNotFoundError, subprocess.TimeoutExpired) as error:
            self.skipTest(f"cached source-control engine unavailable: {type(error).__name__}")
        if result.returncode != 0:
            message = result.stderr.decode().lower()
            if "no such image:" in message:
                self.skipTest("cached fixed PHP source-control image absent; no acquisition fallback")
            self.fail("source-control image inspection refused: " + result.stderr.decode())
        image = json.loads(result.stdout)[0]
        self.assertEqual(CHILD, image["Descriptor"]["digest"])
        self.assertEqual("amd64", image["Architecture"])
        self.assertEqual("linux", image["Os"])
        wrapper = (ROOT / "scripts/ci/service-mode-image-smoke.sh").read_text()
        self.source = wrapper.split("cat <<'WATERLINE_CURRENT_RUNTIME_PHP'\n", 1)[1].split("\nWATERLINE_CURRENT_RUNTIME_PHP", 1)[0].encode()
        self.fixture = self.root / "fixture"
        for name in ("vendor/composer", "bootstrap/cache"):
            (self.fixture / name).mkdir(mode=0o700, parents=True)
        self.markers = self.root / "markers"
        self.markers.mkdir(mode=0o700)
        self.lock = (ROOT / "standalone/composer.lock").read_bytes()
        self.assertEqual(LOCK_SHA, hashlib.sha256(self.lock).hexdigest())
        (self.fixture / "composer.lock").write_bytes(self.lock)
        self.installed = json.dumps({"dev": False, "dev-package-names": [],
                                    "packages": json.loads(self.lock)["packages"]}).encode()
        (self.fixture / "vendor/composer/installed.json").write_bytes(self.installed)
        (self.fixture / "vendor/autoload.php").write_text("<?php file_put_contents('/markers/autoload', 'synthetic marker');\n")

    def docker(self, arguments, *, data=None):
        return subprocess.run(self.prefix + arguments, input=data, capture_output=True,
                              timeout=30, env={"PATH": "/usr/bin:/bin"})

    def run_source(self, source=None, mode="metadata"):
        run = "waterline-probe-source-" + uuid.uuid4().hex
        started = datetime.now(timezone.utc).isoformat()
        created = self.docker([
            "create", "--name", run, "--label", "dev.durable-workflow.owner=waterline",
            "--label", "dev.durable-workflow.run=" + run, "--platform", "linux/amd64", "--pull", "never",
            "--init", "--read-only", "--network", "none", "--cpus", "0.25", "--memory", "128m",
            "--pids-limit", "64", "--cap-drop=ALL", "--security-opt=no-new-privileges",
            "--log-driver", "none", "--user", f"{os.getuid()}:{os.getgid()}", "--interactive",
            "--mount", f"type=bind,src={self.fixture},dst=/app,readonly",
            "--mount", f"type=bind,src={self.markers},dst=/markers",
            "--entrypoint", "/usr/local/bin/php", IMAGE, "-n", "/dev/stdin", mode,
        ])
        self.assertEqual(0, created.returncode, created.stderr.decode())
        cid = created.stdout.decode().strip()
        self.assertRegex(cid, r"\A[a-f\d]{64}\Z")
        try:
            inspected = self.docker(["inspect", cid])
            self.assertEqual(0, inspected.returncode)
            info = json.loads(inspected.stdout)[0]
            self.assertEqual(cid, info["Id"])
            self.assertEqual("waterline", info["Config"]["Labels"]["dev.durable-workflow.owner"])
            self.assertEqual(run, info["Config"]["Labels"]["dev.durable-workflow.run"])
            self.assertEqual(IMAGE, info["Config"]["Image"])
            self.assertEqual(CHILD, info["ImageManifestDescriptor"]["digest"])
            self.assertTrue(info["HostConfig"]["ReadonlyRootfs"])
            self.assertTrue(info["HostConfig"]["Init"])
            self.assertEqual("none", info["HostConfig"]["NetworkMode"])
            self.assertEqual(250000000, info["HostConfig"]["NanoCpus"])
            self.assertEqual(134217728, info["HostConfig"]["Memory"])
            self.assertEqual(64, info["HostConfig"]["PidsLimit"])
            self.assertEqual(["ALL"], info["HostConfig"]["CapDrop"])
            self.assertIn("no-new-privileges", info["HostConfig"]["SecurityOpt"])
            self.assertEqual(f"{os.getuid()}:{os.getgid()}", info["Config"]["User"])
            mounts = {mount["Destination"]: mount for mount in info["Mounts"]}
            self.assertEqual({"/app", "/markers"}, set(mounts))
            self.assertFalse(mounts["/app"]["RW"])
            self.assertEqual(str(self.fixture), mounts["/app"]["Source"])
            self.assertTrue(mounts["/markers"]["RW"])
            self.assertEqual(str(self.markers), mounts["/markers"]["Source"])
            result = self.docker(["start", "--attach", "--interactive", cid], data=source or self.source)
            self.assertLessEqual(len(result.stdout), 1024 * 1024)
            self.assertLessEqual(len(result.stderr), 65536)
            final = json.loads(self.docker(["inspect", cid]).stdout)[0]
            self.assertFalse(final["State"]["OOMKilled"])
            return result
        finally:
            inspected = self.docker(["inspect", cid])
            self.assertEqual(0, inspected.returncode)
            info = json.loads(inspected.stdout)[0]
            self.assertEqual(cid, info["Id"])
            self.assertEqual("waterline", info["Config"]["Labels"]["dev.durable-workflow.owner"])
            self.assertEqual(run, info["Config"]["Labels"]["dev.durable-workflow.run"])
            removed = self.docker(["rm", "--force", "--volumes", cid])
            self.assertEqual(0, removed.returncode)
            absent = self.docker(["inspect", cid])
            self.assertEqual(1, absent.returncode)
            self.assertEqual([], json.loads(absent.stdout))
            self.assertIn("no such object: " + cid, absent.stderr.decode().lower())
            print(json.dumps({"scope": "synthetic-source-control-only", "container_id": cid,
                              "index": IMAGE, "child": CHILD,
                              "source_sha256": hashlib.sha256(source or self.source).hexdigest(),
                              "started_at": started, "finished_at": datetime.now(timezone.utc).isoformat(),
                              "absent": True}, sort_keys=True))

    def test_synthetic_metadata_never_loads_autoload(self):
        result = self.run_source()
        self.assertEqual(0, result.returncode, result.stderr.decode())
        report = json.loads(result.stdout)
        self.assertEqual(self.lock, base64.b64decode(report["lock_base64"], validate=True))
        self.assertEqual(self.installed, base64.b64decode(report["installed_base64"], validate=True))
        self.assertEqual(os.getuid(), report["effective_uid"])
        self.assertFalse(report["cache"]["present"])
        self.assertFalse((self.markers / "autoload").exists())

    def test_predecessor_autoload_effect_is_visible_to_control(self):
        result = self.run_source(b"<?php require '/app/vendor/autoload.php';\n")
        self.assertEqual(0, result.returncode, result.stderr.decode())
        self.assertTrue((self.markers / "autoload").is_file())

    def test_stale_cache_refuses_without_executing_it(self):
        (self.fixture / "bootstrap/cache/routes-v7.php").write_text("<?php file_put_contents('/markers/cache', 'unexpected');")
        result = self.run_source()
        self.assertNotEqual(0, result.returncode)
        self.assertEqual(b"", result.stdout)
        self.assertFalse((self.markers / "cache").exists())
        self.assertFalse((self.markers / "autoload").exists())

    def test_other_bootstrap_cache_output_also_refuses_before_autoload(self):
        (self.fixture / "bootstrap/cache/config.php").write_text("<?php file_put_contents('/markers/config', 'unexpected');")
        result = self.run_source()
        self.assertNotEqual(0, result.returncode)
        self.assertEqual(b"", result.stdout)
        self.assertFalse((self.markers / "config").exists())
        self.assertFalse((self.markers / "autoload").exists())

    def test_parent_symlink_refuses_even_when_target_contains_valid_metadata(self):
        path = self.fixture / "vendor/composer/installed.json"
        path.unlink()
        path.parent.rmdir()
        (self.markers / "installed.json").write_bytes(self.installed)
        path.parent.symlink_to("/markers", target_is_directory=True)
        result = self.run_source()
        self.assertNotEqual(0, result.returncode)
        self.assertEqual(b"", result.stdout)
        self.assertFalse((self.markers / "autoload").exists())

    def test_unknown_mode_refuses_before_reading_or_echoing_arguments(self):
        result = self.run_source(mode="../../unselected")
        self.assertNotEqual(0, result.returncode)
        self.assertEqual(b"", result.stdout)
        self.assertEqual("invalid", json.loads(result.stderr)["mode"])

    def test_wrong_source_lock_refuses_before_autoload(self):
        (self.fixture / "composer.lock").write_bytes(self.lock + b'\n')
        result = self.run_source()
        self.assertNotEqual(0, result.returncode)
        self.assertEqual(b"", result.stdout)
        self.assertFalse((self.markers / "autoload").exists())


if __name__ == "__main__":
    unittest.main()
