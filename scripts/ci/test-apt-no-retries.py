#!/usr/bin/env python3
"""Every networked APT path in a workflow job runs with APT retries off.

CVE-2026-109518 (durable-workflow/.github#295): vulnerable APT can follow a
redirect on a retried package download and install a swapped package as root.
Writing Acquire::Retries "0" before the first APT-capable step removes that
precondition. shivammathur/setup-php can run apt-get itself, so it counts.
"""

from pathlib import Path
import re
import unittest

import yaml

ROOT = Path(__file__).resolve().parents[2]
WORKFLOWS = ROOT / '.github/workflows'
NO_RETRIES = "echo 'Acquire::Retries \"0\";' | sudo tee /etc/apt/apt.conf.d/99-no-retries"
APT = re.compile(r'\bapt(?:-get)?\b[^\n]{0,80}\b(?:update|install|upgrade|dist-upgrade)\b|--with-deps|install-deps|install-sqlserver-odbc')


def apt_capable(step):
    return str(step.get('uses', '')).startswith('shivammathur/setup-php@') or bool(APT.search(str(step.get('run') or '')))


def unprotected(document):
    for name, job in (document.get('jobs') or {}).items():
        for step in job.get('steps') or []:
            if NO_RETRIES in str(step.get('run') or ''):
                break
            if apt_capable(step):
                yield name, step.get('name') or step.get('uses') or step.get('run')
                break


DOCKERFILES = ('.devcontainer/docker/Dockerfile', '.devcontainer/docker/mssql/Dockerfile')
IMAGE_LINE = "RUN echo 'Acquire::Retries \"0\";' > /etc/apt/apt.conf.d/99-no-retries"
SCRIPTS = ('scripts/ci/install-sqlserver-odbc.sh',)


def apt_before_line(text, line):
    text = re.sub(r'(?m)^\s*#.*$', '', text)
    first = re.search(r'apt-get (?:update|install|upgrade)', text)
    return first is not None and (line not in text or text.index(line) > first.start())


class AptNoRetriesTest(unittest.TestCase):
    def test_every_apt_capable_job_disables_retries_first(self):
        found = []
        for path in sorted(WORKFLOWS.glob('*.y*ml')):
            for job, step in unprotected(yaml.safe_load(path.read_text())):
                found.append(f'{path.name}:{job}: {step}')
        self.assertEqual([], found)

    def test_images_and_helpers_disable_retries_before_apt(self):
        for name in DOCKERFILES:
            for stage in re.split(r'(?m)^FROM ', (ROOT / name).read_text())[1:]:
                self.assertFalse(apt_before_line(stage, IMAGE_LINE), name)
        for name in SCRIPTS:
            self.assertFalse(apt_before_line((ROOT / name).read_text(), NO_RETRIES), name)
        self.assertTrue(apt_before_line('RUN apt-get update\n' + IMAGE_LINE, IMAGE_LINE))
        self.assertTrue(apt_before_line('sudo apt-get update\n', NO_RETRIES))

    def test_checker_refuses_a_late_or_missing_line(self):
        setup = {'uses': 'shivammathur/setup-php@' + 'a' * 40}
        line = {'run': NO_RETRIES + ' >/dev/null'}
        self.assertEqual([], list(unprotected({'jobs': {'ok': {'steps': [line, setup]}}})))
        self.assertEqual(1, len(list(unprotected({'jobs': {'late': {'steps': [setup, line]}}}))))
        self.assertEqual(1, len(list(unprotected({'jobs': {'none': {'steps': [{'run': 'sudo apt-get update'}]}}}))))
        self.assertEqual(1, len(list(unprotected({'jobs': {'deps': {'steps': [{'run': 'npx playwright install --with-deps chromium'}]}}}))))


if __name__ == '__main__':
    unittest.main()
