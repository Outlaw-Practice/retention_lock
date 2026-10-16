"""Explicit disposable-copy integration; never run against live data."""
import base64
import argparse
import json
import re
from pathlib import Path
import subprocess

root = Path(__file__).resolve().parents[1]
parser = argparse.ArgumentParser()
parser.add_argument('--server-source', type=Path, required=True)
parser.add_argument('--groupfolders-source', type=Path, required=True)
parser.add_argument('--scenario', choices=['all', 'purge-first', 'restore-first', 'after-intent', 'after-delete', 'after-history', 'identity', 'ambiguity', 'folder-delete', 'history-failure', 'account-delete'], default='all')
args = parser.parse_args()
loaders = []
for name in ['ITrashManager', 'TrashManager']:
    source = (args.server_source / 'apps/files_trashbin/lib/Trash' / (name + '.php')).read_text()
    source = source.replace('ITrashManager', 'QualifiedITrashManager')
    if name == 'ITrashManager':
        source = source.replace('extends ITrashBackend', 'extends ITrashManager')
    else:
        source = source.replace('class TrashManager ', 'class QualifiedTrashManager ')
    loaders.append("eval(base64_decode('" + base64.b64encode(source[5:].encode()).decode() + "'));")
for relative, original, qualified in [
    ('Sabre/AbstractTrash.php', 'abstract class AbstractTrash ', 'abstract class QualifiedAbstractTrash '),
    ('Command/RestoreAllFiles.php', 'class RestoreAllFiles ', 'class QualifiedRestoreAllFiles '),
]:
    source = (args.server_source / 'apps/files_trashbin/lib' / relative).read_text().replace(original, qualified)
    loaders.append("eval(base64_decode('" + base64.b64encode(source[5:].encode()).decode() + "'));")
team_source = (args.groupfolders_source / 'lib/Trash/TrashBackend.php').read_text()
team_source = team_source.replace('class TrashBackend ', 'class QualifiedTrashBackend ')
team_source = team_source.replace('\\OCP\\Server::get(\\OCA\\Files_Trashbin\\Trash\\ITrashManager::class)',
                                '\\OCP\\Server::get(\\OCA\\Files_Trashbin\\Trash\\QualifiedTrashManager::class)')
loaders.append("eval(base64_decode('" + base64.b64encode(team_source[5:].encode()).decode() + "'));")
for relative, original, qualified in [
    ('Mount/FolderStorageManager.php', 'class FolderStorageManager {', 'class QualifiedFolderStorageManager extends FolderStorageManager {'),
    ('Command/Delete.php', 'class Delete extends ', 'class QualifiedDelete extends '),
    ('Controller/FolderController.php', 'class FolderController extends ', 'class QualifiedFolderController extends '),
    ('Command/Trashbin/Cleanup.php', 'class Cleanup extends ', 'class QualifiedCleanup extends '),
]:
    source = (args.groupfolders_source / 'lib' / relative).read_text().replace(original, qualified)
    if relative == 'Command/Trashbin/Cleanup.php':
        source = source.replace('use OCA\\GroupFolders\\Trash\\TrashBackend;',
                                'use OCA\\GroupFolders\\Trash\\QualifiedTrashBackend as TrashBackend;')
    loaders.append("eval(base64_decode('" + base64.b64encode(source[5:].encode()).decode() + "'));")
helper_source = (args.server_source / 'apps/files_trashbin/lib/Helper.php').read_bytes()
loaders.append("eval(base64_decode('" + base64.b64encode(helper_source[5:]).decode() + "'));")
restore_worker = (root / 'tests/restore-contender.php').read_text().replace('// MANAGER_SOURCES', '\n'.join(loaders))
expiry_source = (args.server_source / 'apps/files_trashbin/lib/Trashbin.php').read_text()
expiry_source = expiry_source.replace('class Trashbin ', 'class QualifiedTrashbin ')
expiry_source = expiry_source.replace('Server::get(\\OCA\\Files_Trashbin\\Trash\\ITrashManager::class)',
                                     'Server::get(\\OCA\\Files_Trashbin\\Trash\\QualifiedTrashManager::class)')
expiry_loader = "eval(base64_decode('" + base64.b64encode(expiry_source[5:].encode()).decode() + "'));"
restore_worker = restore_worker.replace('// EXPIRY_SOURCE', expiry_loader)
audit_hook = (root / 'tests/contending-audit.php').read_text()[5:]
for name in ['lib/Service/PurgeService.php', 'lib/Migration/Version0001Date20261015000000.php']:
    source = (root / name).read_bytes()
    if not source.startswith(b'<?php'):
        raise SystemExit('Unexpected source header')
    loaders.append("eval(base64_decode('" + base64.b64encode(source[5:]).decode() + "'));")
cases = ['integration.php', 'personal-directory', 'team-integration.php', 'team-directory-integration.php']
crash_worker = (root / 'tests/crash-worker.php').read_text().replace('// APP_SOURCES', '\n'.join(loaders))
modes = ['purge-first', 'restore-first', 'after-intent', 'after-delete', 'after-history', 'identity', 'ambiguity', 'folder-delete', 'history-failure', 'account-delete'] if args.scenario == 'all' else [args.scenario]
for case, mode in [(case, mode) for mode in modes for case in cases
                   if (mode not in ['ambiguity', 'after-history', 'folder-delete'] or case.startswith('team-'))
                   and (mode not in ['history-failure', 'account-delete'] or not case.startswith('team-'))]:
    source_case = 'integration.php' if case == 'personal-directory' else case
    program = (root / 'tests' / source_case).read_text()
    program = program.replace('// DIRECTORY_CASE', '$directoryCase = true;' if case == 'personal-directory' else '$directoryCase = false;')
    program = program.replace('// APP_SOURCES', '\n'.join(loaders))
    audit_binding = '$audit = OCP\\Server::get(OCA\\AdminAudit\\IAuditLogger::class);'
    if program.count(audit_binding) != 1:
        raise SystemExit('Expected exactly one audit fixture binding')
    worker_binding = "$restoreWorkerTemplate = base64_decode('" + base64.b64encode(restore_worker.encode()).decode() + "');"
    if mode == 'purge-first':
        program = program.replace(audit_binding, audit_binding + '\n' + worker_binding + '\n' + audit_hook)
    elif mode in ['restore-first', 'identity', 'ambiguity', 'folder-delete', 'history-failure', 'account-delete']:
        start = "    $id = '12345678-1234-4234-8234-'"
        end = '} catch (Throwable $error) {'
        if program.count(start) != 1:
            raise SystemExit('Expected one normal purge case')
        begin = program.index(start)
        finish = program.index(end, begin)
        helper = {'identity': 'identity-observation.php', 'restore-first': 'restore-first.php', 'ambiguity': 'ambiguous-history.php', 'folder-delete': 'folder-delete.php', 'history-failure': 'personal-history-failure.php', 'account-delete': 'account-delete.php'}[mode]
        program = program[:begin] + (root / 'tests' / helper).read_text()[5:] + '\n' + program[finish:]
        if mode == 'history-failure':
            failing_source = expiry_source.replace('use OC\\Files\\View;', 'use QualifiedFailingHistoryView as View;')
            program = program.replace('// PERSONAL_DELETE_SOURCE', "eval(base64_decode('" + base64.b64encode(failing_source[5:].encode()).decode() + "'));")
            backend_source = (args.server_source / 'apps/files_trashbin/lib/Trash/LegacyTrashBackend.php').read_text()
            backend_source = backend_source.replace('class LegacyTrashBackend implements', 'class QualifiedLegacyTrashBackend implements')
            backend_source = backend_source.replace('use OCA\\Files_Trashbin\\Trashbin;', 'use OCA\\Files_Trashbin\\QualifiedTrashbin as Trashbin;')
            program = program.replace('// PERSONAL_BACKEND_SOURCE', "eval(base64_decode('" + base64.b64encode(backend_source[5:].encode()).decode() + "'));")
        if mode == 'account-delete':
            user_source = (args.server_source / 'lib/private/User/User.php').read_text().replace('class User implements IUser {', 'class QualifiedUser extends User {')
            program = program.replace('// ACCOUNT_SOURCE', "eval(base64_decode('" + base64.b64encode(user_source[5:].encode()).decode() + "'));")
        if mode == 'identity':
            program = program.replace("['status' => $failure", "['identity_collision' => $identityCollision ?? null, 'etag_collision' => $etagCollision ?? null, 'status' => $failure")
    else:
        start = "    $id = '12345678-1234-4234-8234-'"
        end = "    $phase = 'audit-log';"
        if program.count(start) != 1 or program.count(end) != 1:
            raise SystemExit('Expected one purge and audit verification block')
        begin, finish = program.index(start), program.index(end)
        worker_binding = "$crashWorkerTemplate = base64_decode('" + base64.b64encode(crash_worker.encode()).decode() + "');"
        binding = worker_binding + "\n$crashMode = '" + mode + "';\n"
        program = program[:begin] + binding + (root / 'tests/crash-retry.php').read_text()[5:] + '\n' + program[finish:]
    original = '$trash = OCP\\Server::get(OCA\\Files_Trashbin\\Trash\\ITrashManager::class);'
    if program.count(original) != 1:
        raise SystemExit('Expected exactly one trash-manager fixture binding')
    program = program.replace(original, original + r'''
    // Preserve registered real backends; use the candidate manager only in this process.
    $candidate = new OCA\Files_Trashbin\Trash\QualifiedTrashManager();
    $property = new ReflectionProperty(OCA\Files_Trashbin\Trash\TrashManager::class, 'backends');
    foreach ($property->getValue($trash) as $storageType => $backend) {
        $candidate->registerBackend($storageType, $backend);
    }
    $trash = $candidate;
''')
    result = subprocess.run(['docker', '--context', 'desktop-linux', 'compose', '-f',
                             '/Users/john/projects/outlaw/docker/nextcloud-test/compose.yml',
                             'exec', '-T', '-u', 'www-data', 'nextcloud', 'php'],
                            input=program.encode(), capture_output=True, timeout=180)
    try:
        data = json.loads(result.stdout)
        passed = result.returncode == 0 and data['status'] == 'passed'
        phase = data.get('phase')
        error_class = data.get('error_class')
        if not isinstance(error_class, str) or not re.fullmatch(r'[A-Za-z_\\]+', error_class):
            error_class = None
        if phase not in ['bootstrap', 'schema', 'fixture', 'purge', 'audit-log', 'audit-open', 'audit-fields', 'audit-count', 'lookup', 'backend', 'history', 'record', 'crash-snapshot', 'crash-worker', 'crash-after', 'crash-retry', 'contender-bootstrap', 'contender-manager', 'contender-dav', 'contender-bulk', 'contender-expiry']:
            phase = 'unknown'
    except (ValueError, KeyError, TypeError):
        passed, phase, error_class = False, 'unknown', None
    report = {'status': 'passed' if passed else 'failed', 'phase': phase, 'error_class': error_class, 'case': case, 'ordering': mode}
    if mode == 'identity' and passed:
        report.update({key: data.get(key) is True for key in ['identity_collision', 'etag_collision']})
    print(json.dumps(report))

    if not passed:
        raise SystemExit(1)
