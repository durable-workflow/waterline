import json
import os
from pathlib import Path
import shutil
import subprocess
import tempfile
import unittest


ROOT = Path(__file__).resolve().parents[2]
LAUNCHER = ROOT / "scripts/ci/run-php-container.sh"


class PhpContainerLauncherTests(unittest.TestCase):
    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory(prefix="waterline-php-launcher-")
        self.addCleanup(self.temporary.cleanup)
        self.state = Path(self.temporary.name)
        self.binary = self.state / "bin"
        self.binary.mkdir()
        self.log = self.state / "docker-arguments.json"
        docker = self.binary / "docker"
        docker.write_text("#!/usr/bin/env python3\nimport json,os,sys\n"
                          "open(os.environ['WATERLINE_PHP_CI_TEST_LOG'],'w').write(json.dumps(sys.argv[1:]))\n")
        docker.chmod(0o755)
        for name in ("php", "composer"):
            (self.binary / name).symlink_to(LAUNCHER)
        self.environment = os.environ.copy()
        self.environment.update({
            "PATH": str(self.binary) + os.pathsep + self.environment["PATH"],
            "WATERLINE_PHP_CI_ROOT": str(ROOT), "WATERLINE_PHP_CI_STATE": str(self.state),
            "WATERLINE_PHP_CI_IMAGE": "sha256:" + "a" * 64,
            "WATERLINE_PHP_CI_TEST_LOG": str(self.log),
        })

    def invoke(self, command, *arguments):
        return subprocess.run([str(self.binary / command), *arguments], cwd=ROOT,
                              env=self.environment, capture_output=True, text=True, timeout=10)

    def arguments(self):
        return json.loads(self.log.read_text())

    def test_config_id_and_caps_are_bound_without_passing_host_credentials(self):
        for name in ("GH_TOKEN", "GITHUB_TOKEN", "COMPOSER_AUTH"):
            self.environment[name] = "synthetic-unforwarded-canary"
        result = self.invoke("php", "-r", "echo 1;")
        self.assertEqual(result.returncode, 0, result.stderr)
        arguments = self.arguments()
        self.assertIn("sha256:" + "a" * 64, arguments)
        self.assertIn("--init", arguments)
        for option, value in (("--cpus", "2"), ("--memory", "2g"),
                              ("--pids-limit", "512"), ("--cap-drop", "ALL"),
                              ("--security-opt", "no-new-privileges")):
            self.assertEqual(arguments[arguments.index(option) + 1], value)
        for name in ("GH_TOKEN", "GITHUB_TOKEN", "COMPOSER_AUTH"):
            self.assertNotIn(name, arguments)
        self.assertNotIn("synthetic-unforwarded-canary", str(arguments))

    def test_mutable_image_selector_refuses_before_docker(self):
        self.environment["WATERLINE_PHP_CI_IMAGE"] = "php:8.4"
        result = self.invoke("php", "--version")
        self.assertNotEqual(result.returncode, 0)
        self.assertFalse(self.log.exists())

    def test_selected_ca_mounts_are_read_only_and_composer_guard_uses_real_phar(self):
        bundle = self.state / "selected.pem"
        bundle.write_text("public certificate fixture\n")
        roots = self.state / "empty-roots"
        roots.mkdir()
        self.environment.update({"SSL_CERT_FILE": str(bundle), "COMPOSER_CAFILE": str(bundle),
                                 "SSL_CERT_DIR": str(roots), "COMPOSER_CAPATH": str(roots)})
        result = self.invoke("php", "/reviewed/verify.php", str(self.binary / "composer"))
        self.assertEqual(result.returncode, 0, result.stderr)
        arguments = self.arguments()
        for selected in (bundle, roots):
            mount = f"type=bind,source={selected},target={selected},readonly"
            self.assertEqual(arguments.count(mount), 1)
        self.assertEqual(arguments[-3:], ["php", "/reviewed/verify.php", "/usr/local/bin/composer"])

    def test_setup_excludes_workspace_python_imports_before_prerequisite_checks(self):
        project = self.state / "project"
        scripts = project / "scripts/ci"
        scripts.mkdir(parents=True)
        for name in ("setup-php-container.sh", "php-container-images.json"):
            shutil.copy2(ROOT / "scripts/ci" / name, scripts / name)
        marker = self.state / "workspace-module-executed"
        (project / "json.py").write_text(
            "from pathlib import Path\n"
            f"Path({str(marker)!r}).write_text('unexpected workspace import')\n"
            "raise SystemExit(42)\n"
        )
        environment = dict(self.environment, PYTHONPATH=str(project))
        result = subprocess.run(["bash", str(scripts / "setup-php-container.sh"), "8.2"],
                                cwd=project, env=environment, capture_output=True,
                                text=True, timeout=10)
        self.assertNotEqual(result.returncode, 0)  # The fixed APT source is absent.
        self.assertFalse(marker.exists(), "Setup executed a workspace Python module")
        self.assertFalse(self.log.exists(), "Setup reached Docker without its APT prerequisite")


if __name__ == "__main__":
    unittest.main()
