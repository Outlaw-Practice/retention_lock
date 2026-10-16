"""Exercise failed provisioning against the disposable installed runtime."""
import argparse
import base64
import json
from pathlib import Path
import subprocess

parser = argparse.ArgumentParser()
parser.add_argument('--server-source', type=Path, required=True)
parser.add_argument('--compose', type=Path, required=True)
args = parser.parse_args()
source = (args.server_source / 'apps/provisioning_api/lib/Controller/UsersController.php').read_text()
source = source.replace('class UsersController extends', 'class QualifiedRollbackController extends', 1)
fixture = Path(__file__).with_suffix('.php').read_text()
program = "<?php\ndefine('OC_CONSOLE', true);\nrequire_once '/opt/nextcloud/current/lib/base.php';\n"
program += "eval(base64_decode('" + base64.b64encode(source[5:].encode()).decode() + "'));\n"
program += fixture[5:]
result = subprocess.run(['docker', '--context', 'desktop-linux', 'compose', '-f', str(args.compose),
    'exec', '-T', '--user', 'www-data', 'nextcloud', 'php'], input=program.encode(),
    capture_output=True, timeout=90)
try:
    report = json.loads(result.stdout)
except (ValueError, TypeError):
    report = {'status': 'failed', 'phase': 'runtime'}
print(json.dumps({key: report[key] for key in ('status', 'phase', 'checks', 'error_class', 'error_code', 'error_line') if key in report}))
raise SystemExit(0 if result.returncode == 0 and report.get('status') == 'passed' else 1)
