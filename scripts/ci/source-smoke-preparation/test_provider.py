"""The provider path is bounded, host-only and held before a live request."""
import pathlib
import sys
import unittest
from unittest import mock

sys.path.insert(0, str(pathlib.Path(__file__).parent))
import contract as c
from provider import Provider
import test_preparation as fixtures


class ProviderTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        fixtures.PreparationTests.setUpClass()

    def test_no_provider_access_while_held(self):
        evidence = mock.Mock()
        reader = Provider(evidence, 10 ** 20)
        self.assertRaisesRegex(c.Refusal, 'HELD', reader.api, 'repos/' + c.REPOSITORY + '/actions/runs/1', 10)
        evidence.command.assert_not_called()

    def test_exact_attempt_and_artifact_endpoints_precede_bounded_download(self):
        model = fixtures.PreparationTests().model()
        context, archive, run, artifact = model[0].context, model[4], model[5], model[6]
        evidence = mock.Mock()
        reader = Provider(evidence, 10 ** 20)
        with mock.patch.object(reader, 'api', side_effect=[c.canonical(run), c.canonical(artifact), archive]) as api:
            self.assertEqual(reader.read(context, artifact['id']), (run, artifact, archive))
            prefix = 'repos/' + c.REPOSITORY + '/actions/'
            self.assertEqual(api.call_args_list, [mock.call(prefix + 'runs/301/attempts/1', 262144),
                mock.call(prefix + 'artifacts/701', 262144), mock.call(prefix + 'artifacts/701/zip', c.MAX_ARCHIVE)])
        for changed in ({**artifact, 'expired': True}, {**artifact, 'name': 'old-attempt'}):
            with mock.patch.object(reader, 'api', side_effect=[c.canonical(run), c.canonical(changed)]) as api:
                self.assertRaises(c.Refusal, reader.read, context, artifact['id'])
                self.assertEqual(api.call_count, 2)


if __name__ == '__main__':
    unittest.main()
