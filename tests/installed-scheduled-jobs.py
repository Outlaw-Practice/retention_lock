"""Bounded installed-job qualification; no source substitution or host-clock change."""
import json
import os
import re
import subprocess
from pathlib import Path

ARTIFACT = Path(os.environ['NEXTCLOUD_ARTIFACT'])
COMPOSE = Path(os.environ['NEXTCLOUD_COMPOSE_FILE'])
SOURCE = Path(os.environ['NEXTCLOUD_PLATFORM_SOURCE']) / 'deploy/nextcloud/retention'
record = json.loads((ARTIFACT / 'build-record.json').read_text())
if not re.fullmatch(r'[a-f0-9]{64}', record['artifact_sha256']):
    raise SystemExit('Invalid selected artifact digest')
BUILD = record['inputs']['release']['version'] + '-outlaw.' + record['artifact_sha256'][:16]
if not re.fullmatch(r'[0-9]+\.[0-9]+\.[0-9]+(?:[A-Za-z]+[0-9]+)?-outlaw\.[a-f0-9]{16}', BUILD):
    raise SystemExit('Invalid selected build identifier')
BOOT = r'''
    $fixtureDelete = static function ($owner): bool {
        $system = OCP\Server::get(OC\SystemConfig::class);
        $native = (new ReflectionProperty(OC\SystemConfig::class, 'config'))->getValue($system);
        return (Closure::bind(function ($owner): bool {
            $had = array_key_exists('files.trash.delete', $this->envCache);
            $old = $this->envCache['files.trash.delete'] ?? null;
            $this->envCache['files.trash.delete'] = true;
            try { return $owner->delete(); }
            finally {
                if ($had) { $this->envCache['files.trash.delete'] = $old; }
                else { unset($this->envCache['files.trash.delete']); }
            }
        }, $native, OC\Config::class))($owner);
    };
    $savedOffsets = [];
    $config = OCP\Server::get(OCP\IConfig::class);
    foreach ([['files_trashbin', 'background_job_expire_trash_offset'], ['groupfolders', 'cron_last_folder_index']] as [$app, $key]) {
        $savedOffsets[] = [$app, $key, $config->getAppValue($app, $key, '__absent__')];
    }
    $jobClasses = [
        OCA\Files_Trashbin\BackgroundJob\ExpireTrash::class,
        OCA\Files_Versions\BackgroundJob\ExpireVersions::class,
        OCA\GroupFolders\BackgroundJob\ExpireGroupTrash::class,
        OCA\GroupFolders\BackgroundJob\ExpireGroupVersions::class,
    ];
    $jobs = OCP\Server::get(OCP\BackgroundJob\IJobList::class);
    foreach ($jobClasses as $class) {
        if (!$jobs->has($class, null)) { throw new RuntimeException('Scheduled job missing'); }
        $path = (new ReflectionClass($class))->getFileName();
        if (!str_starts_with($path, '/opt/nextcloud/releases/__EXPECTED_RELEASE__/')) {
            throw new RuntimeException('Job is not loaded from assembled release');
        }
    }
    $invokeJob = static function ($job): void {
        (new ReflectionMethod($job, 'run'))->invoke($job, null);
    };
'''
BOOT = BOOT.replace('__EXPECTED_RELEASE__', BUILD)
CLEANUP = r'''
        // Allow deletion only for disposable fixture cleanup in this process.
        $system = OCP\Server::get(OC\SystemConfig::class);
        $native = (new ReflectionProperty(OC\SystemConfig::class, 'config'))->getValue($system);
        (Closure::bind(function () { $this->envCache['files.trash.delete'] = true; }, $native, OC\Config::class))();
'''
results = {}
for kind in ('personal', 'team'):
    program = (SOURCE / f'qualify-{kind}-retention.php').read_text()
    program = program.replace('    OC_App::loadApps();', '    OC_App::loadApps();' + BOOT)
    program = program.replace('    $failure = true;\n    fwrite(STDERR,', r'''    $failure = true;
    echo json_encode(['status' => 'failed', 'phase' => $phase,
        'trash_job_disabled' => OCP\Server::get(OCP\IAppConfig::class)->getValueString('files_trashbin', 'background_job_expire_trash', 'yes') === 'no',
        'expiry_eligible' => isset($expiration, $deleted) && $expiration->isExpired($deleted),
        'configured_sixty_days' => OCP\Server::get(OCP\IConfig::class)->getSystemValue('trashbin_retention_obligation', '') === '60,60',
        'clock_age_seconds' => isset($clock, $deleted) ? $clock->getTime() - $deleted : null,
        'expiry_max_delta_seconds' => isset($expiration, $deleted) ? $expiration->getMaxAgeAsTimestamp() - $deleted : null,
        'remaining_trash' => isset($uid) ? count(OCA\Files_Trashbin\Helper::getTrashFiles('/', $uid, 'mtime')) : null,
    ]);
    fwrite(STDERR,''')
    program = program.replace('} finally {', r'''} finally {
    foreach ($savedOffsets ?? [] as [$app, $key, $value]) {
        if ($value === '__absent__') { $config->deleteAppValue($app, $key); }
        else { $config->setAppValue($app, $key, $value); }
    }
''')
    if kind == 'personal':
        program = program.replace('    if ($users->userExists($uid)) {',
                                  '    if ($users->userExists($uid)) { $fixtureDelete($users->get($uid)); }\n    if ($users->userExists($uid)) {')
        program = program.replace('    OC::$server->registerService(OCA\\Files_Trashbin\\Expiration::class, fn () => $expiration);',
                                  '    $expiration = OCP\\Server::get(OCA\\Files_Trashbin\\Expiration::class);\n    (new ReflectionProperty($expiration, "timeFactory"))->setValue($expiration, $clock);')
        program = program.replace('    OCA\\Files_Versions\\Storage::expire($name, $uid);',
                                  '    $invokeJob(OCP\\Server::get(OCA\\Files_Versions\\BackgroundJob\\ExpireVersions::class));')
        marker = "    $phase = 'before-cutoff';"
        setup = r'''
    // Scope the installed personal job's enumeration to this fixture only.
    $fixtureUsers = new class($created) extends OC\User\Manager {
        public function __construct(private OCP\IUser $fixture) {}
        public function getSeenUsers(int $offset = 0, ?int $limit = null): \Iterator {
            return new ArrayIterator([$this->fixture]);
        }
    };
    $job = new OCA\Files_Trashbin\BackgroundJob\ExpireTrash(
        OCP\Server::get(OCP\IAppConfig::class), $fixtureUsers, $expiration,
        OCP\Server::get(Psr\Log\LoggerInterface::class),
        OCP\Server::get(OCP\Files\ISetupManager::class),
        OCP\Server::get(OCP\Lock\ILockingProvider::class),
        OCP\Server::get(OCP\Files\IRootFolder::class), $clock);
'''
        program = program.replace(marker, setup + marker)
        program = program.replace('OCA\\Files_Trashbin\\Trashbin::expire($trash, $created);', '$invokeJob($job);')
        program = program.replace('            if (!$created->delete()) {', CLEANUP + '            if (!$created->delete()) {')
    else:
        program = program.replace('    OCP\\Server::get(OCA\\GroupFolders\\Versions\\GroupVersionsExpireManager::class)->expireFolder($definition);',
                                  '    $invokeJob(OCP\\Server::get(OCA\\GroupFolders\\BackgroundJob\\ExpireGroupVersions::class));')
        setup = r'''
    $job = new OCA\GroupFolders\BackgroundJob\ExpireGroupTrash(
        $trash, $expiration, OCP\Server::get(OCP\IAppConfig::class),
        OCP\Server::get(OCA\GroupFolders\ACL\UserMapping\IUserMappingManager::class), $clock);
'''
        program = program.replace("    $phase = 'before-cutoff';", setup + "    $phase = 'before-cutoff';")
        program = program.replace('$trash->expire($expiration);', '$invokeJob($job);')
        program = program.replace('        if ($folderId !== null) {', CLEANUP + '        if ($folderId !== null) {')
    process = subprocess.run(['docker', '--context', os.environ.get('DOCKER_CONTEXT', 'desktop-linux'), 'compose', '-f', str(COMPOSE),
                              'exec', '-T', '--user', 'www-data', 'nextcloud', 'php'],
                             input=program, text=True, capture_output=True, timeout=180)
    try:
        result = json.loads(process.stdout)
    except ValueError:
        safe = re.findall(r'(?:Personal retention qualification|Teamfolders retention) failed during [a-z-]+ \([A-Za-z\\]+\); details suppressed|Fixture cleanup failed; manual inspection required', process.stderr)
        result = {'status': 'failed', 'diagnostic': safe or ['Output suppressed; inspect with safe diagnostics'],
                  'exit_code': process.returncode, 'stdout_bytes': len(process.stdout), 'stderr_bytes': len(process.stderr),
                  'php_lines': re.findall(r'on line (\d+)', process.stdout + process.stderr),
                  'docker_service_not_running': 'is not running' in process.stderr,
                  'docker_connection_error': 'Cannot connect' in process.stderr,
                  'syntax_error': 'Parse error' in process.stdout + process.stderr,
                  'declaration_error': 'Declaration of' in process.stdout + process.stderr}
    results[kind] = result
report = {'status': 'passed' if len(results) == 2 and all(r['status'] == 'passed' for r in results.values()) else 'failed',
          'build': BUILD,
          'method': 'Actual installed registered job run methods; personal enumeration scoped to disposable user; Teamfolders requires otherwise empty copy; process-local clock; fixture cleanup only process-local purge override',
          'results': results}
(ARTIFACT.parent / 'scheduled-jobs-result.json').write_text(json.dumps(report, indent=2) + '\n')
print(json.dumps(report))
raise SystemExit(0 if report['status'] == 'passed' else 1)
