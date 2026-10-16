"""Focused verification of the four accepted review corrections only."""
import base64
import json
from pathlib import Path
import subprocess

workspace = Path('/Users/john/Documents/ChatGPT/Nextcloud')
root = Path(__file__).resolve().parent
server = workspace / 'implementation/N5-24-server'
compose = workspace / 'qualification/n5-24-http-candidate-5/http-copy/compose.yml'
command = ['docker', '--context', 'desktop-linux', 'compose', '-f', str(compose),
           'exec', '-T', '--user', 'www-data', 'nextcloud', 'php']

def run(label, source, payload=None, expected_failure=False):
    args = command + (['-r', source] if payload is not None else [])
    result = subprocess.run(args, input=(json.dumps(payload) if payload is not None else source).encode(),
                            capture_output=True, timeout=90)
    try:
        report = json.loads(result.stdout)
    except (ValueError, TypeError):
        report = {}
    passed = (result.returncode != 0 and report.get('status') == 'failed') if expected_failure else (
        result.returncode == 0 and report.get('status') in ('passed', 'created', 'cleaned'))
    print(json.dumps({'case': label, 'status': 'passed' if passed else 'failed', 'checks': report.get('checks')}))
    if not passed:
        raise SystemExit(1)
    return report

def method(path, signature):
    source = path.read_text()
    return source[source.index(signature):source.rfind('\n}')].strip().replace('protected function', 'public function', 1)

job = method(server / 'lib/private/User/BackgroundJobs/CleanupDeletedUsers.php', 'protected function run(')
ldap = method(server / 'apps/user_ldap/lib/Command/ResetUser.php', 'protected function execute(')
ldap = ldap.replace('\\OCP\\Server::get', 'Server::get')
fixture = (root / 'review-fixes.php').read_text()[5:]
fixture = fixture.replace('// JOB_CLASS', 'class Job { public $config, $userManager, $logger; ' + job + ' }')
fixture = fixture.replace('// LDAP_CLASS', 'class Reset extends \\Symfony\\Component\\Console\\Command\\Command { '
    'public $dui, $userManager, $pluginManager; ' + ldap + ' }')
fixture = fixture.replace('namespace RetentionReviewFixes;', 'namespace RetentionReviewFixes; '
    'use Symfony\\Component\\Console\\Input\\InputInterface; use Symfony\\Component\\Console\\Output\\OutputInterface;')
run('cleanup-job-and-ldap-policy', "<?php define('OC_CONSOLE', true); require '/opt/nextcloud/current/lib/base.php'; "
    "eval(base64_decode('" + base64.b64encode(fixture.encode()).decode() + "'));")

integration = (root / 'integration.php').read_text()
teardown = integration[integration.index('        if ($admin || $owner) {'):integration.index('        if ($createdTable)')]
program = r'''<?php
define('OC_CONSOLE', true); require '/opt/nextcloud/current/lib/base.php'; OC_App::loadApps();
$users = OCP\Server::get(OCP\IUserManager::class);
$config = OCP\Server::get(OCP\IConfig::class);
if ($config->getSystemValueBool('files.trash.delete', true)) { exit(1); }
$ids = ['n5-review-teardown-admin', 'n5-review-teardown-owner'];
foreach ($ids as $uid) { if ($users->userExists($uid)) { exit(1); } }
$admin = $users->createUser($ids[0], bin2hex(random_bytes(32)));
$owner = $users->createUser($ids[1], bin2hex(random_bytes(32)));
if (!$admin || !$owner || $admin->delete() !== false || $owner->delete() !== false) { exit(1); }
// TEARDOWN
if ($config->getSystemValueBool('files.trash.delete', true)) { exit(1); }
foreach ($ids as $uid) { if ($users->userExists($uid)) { exit(1); } }
echo json_encode(['status' => 'passed', 'checks' => 5]);
'''.replace('// TEARDOWN', teardown)
run('guarded-installed-fixture-teardown', program)

source = (root / 'http-fixture.php').read_text()[5:]
run('create-owner', source, {'action': 'create', 'password': __import__('secrets').token_hex(32)})
try:
    for label, point in [('after-group', '            $group->addUser($owner);'),
                         ('after-folder', '            $manager->addApplicableGroup($folderId, $groupName);'),
                         ('after-populated-storage', "            echo json_encode(['status' => 'created', 'folderId' => $folderId]);")]:
        if source.count(point) != 1:
            raise SystemExit('Unexpected fixture injection point')
        broken = source.replace(point, "            throw new RuntimeException('Injected fixture failure');\n" + point)
        run('partial-team-' + label, broken, {'action': 'create-team'}, expected_failure=True)
        # Successful immediate recreation proves both group and folder were removed.
        recreated = run('retry-team-' + label, source, {'action': 'create-team'})
        run('cleanup-team-' + label, source, {'action': 'cleanup-team', 'folderId': recreated['folderId']})
finally:
    run('cleanup-owner', source, {'action': 'cleanup'})
