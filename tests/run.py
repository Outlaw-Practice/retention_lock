"""Offline purge-service tests in the already-built PHP runtime, with networking disabled."""
import base64
import json
from pathlib import Path
import subprocess

root = Path(__file__).resolve().parents[1]
program = '<?php\n'
for path in [root / 'lib/Service/PurgeService.php', root / 'tests/purge-service.php']:
    source = path.read_bytes()
    if not source.startswith(b'<?php'):
        raise SystemExit('Unexpected PHP source header')
    encoded = base64.b64encode(source[5:]).decode()
    program += "eval(base64_decode('" + encoded + "'));\n"
result = subprocess.run(['docker', '--context', 'desktop-linux', 'run', '--rm', '-i',
                         '--network', 'none', '--read-only', '--entrypoint', 'php',
                         'outlaw-nextcloud-test:local'],
                        input=program.encode(), capture_output=True, timeout=60)
try:
    report = json.loads(result.stdout)
    passed = result.returncode == 0 and report == {'status': 'passed', 'cases': 23}
except (ValueError, TypeError):
    passed = False
print(json.dumps({'status': 'passed' if passed else 'failed', 'cases': 23 if passed else 0}))
raise SystemExit(0 if passed else 1)
