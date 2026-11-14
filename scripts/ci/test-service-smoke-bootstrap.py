"""Source identities and import-only closure. No acceptance method is invoked."""

import hashlib
import json
import os
from pathlib import Path
import subprocess
import sys
import unittest
from unittest.mock import patch

sys.path.insert(0, str(Path(__file__).resolve().parent))
import service_smoke_bootstrap as bootstrap
import service_smoke_first_stage as first_stage

ROOT = Path(__file__).resolve().parents[2]


class BootstrapSourceTest(unittest.TestCase):
    def test_literal_source_subjects_match_actual_modules_loader_and_invocation(self):
        for name, expected in bootstrap.REVIEWED.items():
            self.assertEqual(expected, hashlib.sha256((ROOT / "scripts/ci" / (name + ".py")).read_bytes()).hexdigest(), name)
        self.assertEqual(bootstrap.LOADING_SHA256, hashlib.sha256((ROOT / "scripts/ci/service_smoke_loading.py").read_bytes()).hexdigest())
        self.assertEqual(first_stage.BOOTSTRAP_SHA256, hashlib.sha256((ROOT / "scripts/ci/service_smoke_bootstrap.py").read_bytes()).hexdigest())

    def test_missing_hosted_job_context_refuses_before_loader_read(self):
        with patch.dict(os.environ, {"GITHUB_ACTIONS": "false"}), patch.object(bootstrap, "read_loading") as reader:
            with self.assertRaises(RuntimeError):
                bootstrap.engine()
            reader.assert_not_called()

    def test_actual_frozen_module_set_loads_in_isolation_without_any_child_or_acceptance_call(self):
        program = '''import hashlib,json,os,subprocess,sys
from pathlib import Path
from unittest.mock import patch
root=Path(sys.argv[1])
raw=(root/'scripts/ci/service_smoke_bootstrap.py').read_bytes()
assert hashlib.sha256(raw).hexdigest()==sys.argv[2]
descriptor=os.open(root,os.O_RDONLY|os.O_DIRECTORY|os.O_NOFOLLOW)
namespace={'__name__':'verified_import_only','__file__':'<verified import-only invocation>',
 '__verified_source__':raw,'__verified_root__':str(root),'__verified_root_fd__':descriptor}
try:
 exec(compile(raw,'<verified import-only invocation>','exec'),namespace)
 with patch.object(subprocess,'Popen',side_effect=AssertionError('Unselected child execution')):
  loader,loaded,captured,_raw=namespace['engine']()
  assert set(loaded)==set(captured)==set(namespace['REVIEWED'])
 print(json.dumps({'modules':len(loaded),'acceptance_calls':0,'child_calls':0}))
finally:
 os.close(descriptor)
'''
        environment = {"PATH": "/usr/bin:/bin", "LANG": "C.UTF-8", "LC_ALL": "C.UTF-8",
                       "GITHUB_ACTIONS": "true", "GITHUB_REPOSITORY": "durable-workflow/waterline", "GITHUB_RETENTION_DAYS": "7"}
        result = subprocess.run([sys.executable, "-I", "-S", "-c", program, str(ROOT), first_stage.BOOTSTRAP_SHA256],
                                capture_output=True, timeout=10, env=environment)
        self.assertEqual(0, result.returncode, result.stderr)
        self.assertEqual({"modules": len(bootstrap.REVIEWED), "acceptance_calls": 0, "child_calls": 0}, json.loads(result.stdout))


if __name__ == "__main__":
    unittest.main()
