"""Resolve candidate app classes against the disposable runtime without activation."""
import base64
import json
from pathlib import Path
import subprocess

root = Path(__file__).resolve().parents[1]
program = """<?php
define('OC_CONSOLE', true);
require_once '/opt/nextcloud/current/lib/base.php';
try {
"""
for relative in ['lib/Service/PurgeService.php', 'lib/Controller/PurgeController.php',
                 'lib/Settings/Admin.php', 'lib/AppInfo/Application.php']:
    source = (root / relative).read_bytes()
    if not source.startswith(b'<?php'):
        raise SystemExit('Unexpected PHP source header')
    program += "eval(base64_decode('" + base64.b64encode(source[5:]).decode() + "'));\n"
program += r"""
    $app = new \OCA\RetentionLock\AppInfo\Application();
    $container = $app->getContainer();
    $controller = $container->get(\OCA\RetentionLock\Controller\PurgeController::class);
    $settings = $container->get(\OCA\RetentionLock\Settings\Admin::class);
    if (!$controller instanceof \OCP\AppFramework\Controller
        || !$settings instanceof \OCP\Settings\ISettings
        || !$settings->getForm() instanceof \OCP\AppFramework\Http\TemplateResponse) {
        throw new \RuntimeException('Interface mismatch');
    }
    echo json_encode(['status' => 'passed', 'checks' => 3]);
} catch (\Throwable $error) {
    echo json_encode(['status' => 'failed']);
    exit(1);
}
"""
result = subprocess.run(['docker', '--context', 'desktop-linux', 'compose', '-f',
    '/Users/john/projects/outlaw/docker/nextcloud-test/compose.yml', 'exec', '-T',
    '--user', 'www-data', 'nextcloud', 'php'], input=program.encode(),
    capture_output=True, timeout=60)
try:
    passed = result.returncode == 0 and json.loads(result.stdout) == {'status': 'passed', 'checks': 3}
except (ValueError, TypeError):
    passed = False
print(json.dumps({'status': 'passed' if passed else 'failed', 'checks': 3 if passed else 0}))
raise SystemExit(0 if passed else 1)
