"""Only finite Python stdio marker children run. No browser, SDK or Docker."""

from pathlib import Path
import sys
import unittest

sys.path.insert(0, str(Path(__file__).resolve().parent))
import service_smoke_browser as browser


class Fixture:
    def __init__(self):
        self.modes = []

    def browser_mode(self, value):
        self.modes.append(value)

    def audit(self):
        return {"writes": [{"synthetic": True}], "schedule_writes": []}


class BrowserTransportTest(unittest.TestCase):
    def command(self, program):
        return [sys.executable, "-I", "-c", program]

    def test_owned_request_response_and_success_receipt(self):
        fixture = Fixture()
        program = "import json,sys; print(json.dumps({'operation':'mode','value':{'mode':'healthy'}}),flush=True); " + (
            "answer=json.loads(sys.stdin.readline()); assert answer['ok'] is True; "
            "print(json.dumps({'complete':{'status':'pass','synthetic':True}}),flush=True)"
        )
        result = browser.rpc_process(self.command(program), fixture, lambda: None, timeout=2)
        self.assertTrue(result["report"]["synthetic"])
        self.assertEqual([{"mode": "healthy"}], fixture.modes)

    def test_unselected_operation_cannot_reach_fixture(self):
        fixture = Fixture()
        program = "print('{\"operation\":\"start\",\"value\":\"foreign\"}',flush=True)"
        with self.assertRaises(browser.BrowserError):
            browser.rpc_process(self.command(program), fixture, lambda: None, timeout=2)
        self.assertEqual([], fixture.modes)

    def test_missing_partial_failed_or_duplicate_completion_cannot_pass(self):
        programs = ["print('{}',end='')", "pass", "print('{\"complete\":{\"status\":\"nonpass\"}}')",
                    "print('{\"complete\":{\"status\":\"pass\"}}\\n{\"complete\":{\"status\":\"pass\"}}')"]
        for program in programs:
            with self.subTest(program=program), self.assertRaises(browser.BrowserError):
                browser.rpc_process(self.command(program), Fixture(), lambda: None, timeout=2)

    def test_deadline_and_output_overrun_reap_marker_children(self):
        for program in ("import time;time.sleep(10)", "print('x'*70000)"):
            with self.subTest(program=program), self.assertRaises(RuntimeError):
                browser.rpc_process(self.command(program), Fixture(), lambda: None, timeout=0.1)

    def test_frozen_source_goes_to_data_url_and_phase_is_explicit(self):
        original = Path(__file__).with_name("request-budget-browser.mjs").read_bytes()
        program = browser.browser_program(original, kind="primary", source="a"*40,
                                          phase="cached", base="http://127.0.0.1:41823", output="/proc/self/fd/9/cached",
                                          dependency_root="/selected/tooling")
        self.assertIn("data:text/javascript;base64,", program)
        self.assertIn('"phase":"cached"', program)
        self.assertNotIn("import('/mutable/", program)


if __name__ == "__main__":
    unittest.main()
