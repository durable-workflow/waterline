import hashlib
import json
import os
from pathlib import Path
import shutil
import subprocess
import tempfile
import unittest


ROOT = Path(__file__).resolve().parents[2]


@unittest.skipUnless(os.environ.get("WATERLINE_PHP_CI_TEST_IMAGE"), "requires the built PHP CI image")
class PhpContainerIsolationTests(unittest.TestCase):
    def test_container_cannot_replace_host_launchers_or_control_source(self):
        # Use disposable controls so the predecessor can demonstrate its escape
        # without changing the checkout or executing a substituted host wrapper.
        with tempfile.TemporaryDirectory(prefix="waterline-php-isolation-") as directory:
            fixture = Path(directory)
            source = fixture / "source"
            scripts = source / "scripts/ci"
            scripts.mkdir(parents=True)
            launcher = scripts / "run-php-container.sh"
            shutil.copy2(ROOT / "scripts/ci/run-php-container.sh", launcher)
            state = fixture / "state"
            (state / "bin").mkdir(parents=True)
            (state / "data/tmp").mkdir(parents=True)
            (source / "vendor").mkdir()
            (source / "composer.lock").write_text("{}\n")
            (source / "composer.json").write_text('{"name":"waterline/isolation-control"}\n')
            subprocess.run(["git", "init", "--quiet", str(source)], check=True, timeout=10)
            for name in ("php", "composer"):
                (state / "bin" / name).symlink_to(launcher)
            expected = hashlib.sha256(launcher.read_bytes()).hexdigest()
            environment = {name: os.environ[name] for name in ("PATH", "DOCKER_CONFIG") if name in os.environ}
            environment.update({"WATERLINE_PHP_CI_ROOT": str(source), "WATERLINE_PHP_CI_STATE": str(state),
                                "WATERLINE_PHP_CI_IMAGE": os.environ["WATERLINE_PHP_CI_TEST_IMAGE"]})
            targets = [launcher, state / "bin/php", state / "bin/composer",
                       source / "composer.json", source / "json.py", state / "bin/new-wrapper"]
            program = r'''
                $targets = json_decode($argv[1], true, 512, JSON_THROW_ON_ERROR);
                $data = $argv[2]; $results = [];
                foreach ($targets as $target) {
                    $replacement = $data.'/replacement';
                    file_put_contents($replacement, 'synthetic replacement');
                    $results[] = [@file_put_contents($target, 'synthetic overwrite') !== false,
                                  @rename($replacement, $target)];
                    @unlink($replacement);
                }
                foreach ([$data.'/canary', $argv[3].'/vendor/canary', $argv[3].'/composer.lock'] as $output) {
                    if (file_put_contents($output, '{}') === false) { throw new RuntimeException('Writable output refused'); }
                }
                echo json_encode($results, JSON_THROW_ON_ERROR);
            '''
            result = subprocess.run([str(state / "bin/php"), "-r", program,
                                     json.dumps([str(path) for path in targets]), str(state / "data/tmp"), str(source)],
                                    cwd=source, env=environment, capture_output=True, text=True, timeout=60)
            self.assertEqual(result.returncode, 0, result.stderr)
            self.assertEqual(json.loads(result.stdout), [[False, False]] * len(targets),
                             "Container code replaced a host launcher or control path")
            self.assertEqual(hashlib.sha256(launcher.read_bytes()).hexdigest(), expected)
            for name in ("php", "composer"):
                self.assertTrue((state / "bin" / name).is_symlink())
                self.assertEqual((state / "bin" / name).resolve(), launcher)
            # Only invoke the next host command after verifying controls survived.
            followup = subprocess.run([str(state / "bin/php"), "-r", 'echo "original-container-php";'],
                                      cwd=source, env=environment, capture_output=True, text=True, timeout=30)
            self.assertEqual(followup.returncode, 0, followup.stderr)
            self.assertEqual(followup.stdout, "original-container-php")


if __name__ == "__main__":
    unittest.main()
