"""Offline checks for binding the harness to its explicitly selected artifact."""
import io
import json
import os
from pathlib import Path
import runpy
import subprocess
import tempfile
import unittest
from unittest.mock import patch


class ScheduledJobInputTests(unittest.TestCase):
    def test_selected_build_is_used_in_job_provenance_checks_and_report(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            artifact = root / 'artifact'
            artifact.mkdir()
            (artifact / 'build-record.json').write_text(json.dumps({
                'inputs': {'release': {'version': '35.0.3RC1'}}, 'artifact_sha256': 'a' * 64}))
            source = root / 'deploy/nextcloud/retention'
            source.mkdir(parents=True)
            for kind in ('personal', 'team'):
                (source / ('qualify-' + kind + '-retention.php')).write_text('<?php\n    OC_App::loadApps();\n')
            env = {'NEXTCLOUD_ARTIFACT': str(artifact), 'NEXTCLOUD_COMPOSE_FILE': str(root / 'compose.yml'),
                   'NEXTCLOUD_PLATFORM_SOURCE': str(root), 'DOCKER_CONTEXT': 'offline-fixture-context'}
            with patch.dict(os.environ, env), patch('subprocess.run', return_value=
                    subprocess.CompletedProcess([], 0, '{"status":"passed"}', '')) as command, \
                    patch('sys.stdout', new_callable=io.StringIO), self.assertRaises(SystemExit) as result:
                runpy.run_path(str(Path(__file__).with_name('installed-scheduled-jobs.py')))
            self.assertEqual(result.exception.code, 0)
            self.assertEqual(command.call_count, 2)
            for call in command.call_args_list:
                self.assertEqual(call.args[0][2], 'offline-fixture-context')
                self.assertIn('/opt/nextcloud/releases/35.0.3RC1-outlaw.aaaaaaaaaaaaaaaa/', call.kwargs['input'])
            report = json.loads((root / 'scheduled-jobs-result.json').read_text())
            self.assertEqual(report['build'], '35.0.3RC1-outlaw.aaaaaaaaaaaaaaaa')


if __name__ == '__main__':
    unittest.main()
