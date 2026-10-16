<?php
declare(strict_types=1);
ini_set('display_errors', '0');
ini_set('log_errors', '0');
define('OC_CONSOLE', 1);
$admin = null;
$owner = null;
$createdTable = false;
$failure = false;
$phase = 'bootstrap';
$errorClass = null;
try {
    require '/opt/nextcloud/current/lib/base.php';
    OC_App::loadApps();
    // APP_SOURCES
    $db = OCP\Server::get(OCP\IDBConnection::class);
    $schema = new OC\DB\SchemaWrapper($db->getInner());
    if ($schema->hasTable('retention_purges')) {
        throw new RuntimeException('Qualification requires absent draft-app table');
    }
    $phase = 'schema';
    $output = new class implements OCP\Migration\IOutput {
        public function debug(string $message): void {}
        public function info($message) {}
        public function warning($message) {}
        public function startProgress($max = 0) {}
        public function advance($step = 1, $description = '') {}
        public function finishProgress() {}
    };
    $migration = new OCA\RetentionLock\Migration\Version0001Date20261015000000();
    $migration->changeSchema($output, fn () => $schema, []);
    $db->migrateToSchema($schema->getWrappedSchema());
    $createdTable = true;
    $users = OCP\Server::get(OCP\IUserManager::class);
    $groups = OCP\Server::get(OCP\IGroupManager::class);
    $session = OCP\Server::get(OCP\IUserSession::class);
    foreach (['n5-audit-admin-fixture', 'n5-audit-owner-fixture'] as $uid) {
        if ($users->userExists($uid)) { throw new RuntimeException('Existing fixture'); }
    }
    $phase = 'fixture';
    $admin = $users->createUser('n5-audit-admin-fixture', bin2hex(random_bytes(32)));
    $owner = $users->createUser('n5-audit-owner-fixture', bin2hex(random_bytes(32)));
    if (!$admin || !$owner) { throw new RuntimeException('Fixture creation failed'); }
    $groups->get('admin')->addUser($admin);
    $session->setUser($owner);
    OC_Util::setupFS($owner->getUID());
    $folder = OCP\Server::get(OCP\Files\IRootFolder::class)->getUserFolder($owner->getUID());
    // DIRECTORY_CASE
    $target = $directoryCase ? $folder->newFolder('audit-directory') : $folder;
    $file = $target->newFile('audit-fixture.txt', 'old fictional content');
    $file->touch(time() - 86400);
    $file->putContent('new fictional content');
    if ($directoryCase) {
        $child = $target->newFolder('nested')->newFile('child.txt', 'old nested content');
        $child->touch(time() - 86400);
        $child->putContent('new nested content');
        $sibling = $folder->newFile('unrelated.txt', 'old unrelated content');
        $sibling->touch(time() - 86400);
        $sibling->putContent('new unrelated content');
        $versionsFolder = $folder->getParent()->get('files_versions');
        $siblingHistory = [];
        foreach ($versionsFolder->getDirectoryListing() as $version) {
            if (str_starts_with($version->getName(), 'unrelated.txt.v')) {
                $siblingHistory[$version->getName()] = $version->getContent();
            }
        }
        if (count($siblingHistory) === 0) { throw new RuntimeException('Sibling history absent'); }
        $target->delete();
    } else {
        $file->delete();
    }
    $trash = OCP\Server::get(OCA\Files_Trashbin\Trash\ITrashManager::class);
    $items = $trash->listTrashRoot($owner);
    if (count($items) !== 1) { throw new RuntimeException('Trash fixture missing'); }
    $item = array_values($items)[0];
    $key = OCA\Files_Trashbin\Trashbin::getTrashFilename($item->getName(), $item->getDeletedTime());
    $trashId = $item->getId();
    $deletedAt = $item->getDeletedTime();
    $expectedEtag = $item->getEtag();
    if ($directoryCase) {
        $historyContents = [];
        $collectHistory = function ($node) use (&$collectHistory, &$historyContents): void {
            if ($node instanceof OCP\Files\Folder) {
                foreach ($node->getDirectoryListing() as $childNode) { $collectHistory($childNode); }
            } else {
                $historyContents[] = $node->getContent();
            }
        };
        $collectHistory($folder->getParent()->get('files_trashbin/versions'));
        foreach (['old fictional content', 'old nested content'] as $expected) {
            if (!in_array($expected, $historyContents, true)) {
                throw new RuntimeException('Deleted descendant history absent before purge');
            }
        }
    }
    $session->setUser($admin);
    $audit = OCP\Server::get(OCA\AdminAudit\IAuditLogger::class);
    $service = new OCA\RetentionLock\Service\PurgeService($db, $groups, $session, $users, $trash, $audit,
        OCP\Server::get(OCP\IConfig::class));
    $id = '12345678-1234-4234-8234-' . bin2hex(random_bytes(6));
    $phase = 'purge';
    if ($service->purge($id, $owner->getUID(), $trashId, $deletedAt, 'Disposable integration qualification', $expectedEtag) !== 'completed') {
        throw new RuntimeException('Purge incomplete');
    }
    if ($service->purge($id, $owner->getUID(), $trashId, $deletedAt, 'Disposable integration qualification', $expectedEtag) !== 'completed') {
        throw new RuntimeException('Repeat differed');
    }
    if (count($trash->listTrashRoot($owner)) !== 0
        || count($folder->getParent()->get('files_trashbin/versions')->getDirectoryListing()) !== 0) {
        throw new RuntimeException('Trash history remained');
    }
    if ($directoryCase) {
        if ($folder->get('unrelated.txt')->getContent() !== 'new unrelated content') {
            throw new RuntimeException('Unrelated file changed');
        }
        foreach ($siblingHistory as $name => $content) {
            if ($versionsFolder->get($name)->getContent() !== $content) {
                throw new RuntimeException('Unrelated history changed');
            }
        }
    }
    $query = $db->getQueryBuilder();
    $rows = $query->select('actor_uid', 'owner_uid', 'reason', 'state')->from('retention_purges')
        ->executeQuery()->fetchAllAssociative();
    if (count($rows) !== 1 || $rows[0]['state'] !== 'completed'
        || $rows[0]['actor_uid'] !== $admin->getUID() || $rows[0]['owner_uid'] !== $owner->getUID()) {
        throw new RuntimeException('Durable audit differed');
    }
    $phase = 'audit-log';
    $config = OCP\Server::get(OCP\IConfig::class);
    if ($config->getSystemValueString('log_type_audit', 'file') !== 'file') {
        throw new RuntimeException('Qualification requires file audit logger');
    }
    $logPath = $config->getSystemValueString('logfile_audit', '');
    if ($logPath === '') {
        $logPath = $config->getAppValue('admin_audit', 'logfile',
            $config->getSystemValueString('datadirectory') . '/audit.log');
    }
    $phase = 'audit-open';
    $handle = fopen($logPath, 'rb');
    if ($handle === false) { throw new RuntimeException('Audit log unavailable'); }
    $count = 0;
    try {
        while (($line = fgets($handle)) !== false) {
            $entry = json_decode($line, true);
            $message = $entry['message'] ?? null;
            $prefix = 'Retention early purge requested: ';
            if (!is_string($message) || !str_starts_with($message, $prefix)) { continue; }
            $record = json_decode(substr($message, strlen($prefix)), true);
            if (($record['operation_id'] ?? null) !== $id) { continue; }
            $phase = 'audit-fields';
            if (($record['actor'] ?? null) !== $admin->getUID()
                || ($record['owner'] ?? null) !== $owner->getUID()
                || ($record['reason'] ?? null) !== 'Disposable integration qualification'
                || !is_int($record['requested_at'] ?? null) || !is_string($record['path'] ?? null)) {
                throw new RuntimeException('Audit fields differed');
            }
            $count++;
        }
    } finally { fclose($handle); }
    $phase = 'audit-count';
    if ($count !== 1) { throw new RuntimeException('Expected exactly one purge audit entry'); }
} catch (Throwable $error) {
    $errorClass = get_class($error);
    $failure = true;
    fwrite(STDERR, 'Purge integration failed during ' . $phase . ' (' . get_class($error) . "); details suppressed\n");
} finally {
    try {
        if ($admin || $owner) {
            OCP\Server::get(OCP\IUserSession::class)->setUser(null);
            OC_Util::tearDownFS();
            $system = OCP\Server::get(OC\SystemConfig::class);
            $native = (new ReflectionProperty(OC\SystemConfig::class, 'config'))->getValue($system);
            $cleanup = Closure::bind(function ($fixture): bool {
                $key = 'files.trash.delete';
                $had = array_key_exists($key, $this->envCache);
                $previous = $this->envCache[$key] ?? null;
                $this->envCache[$key] = true;
                try { return $fixture->delete(); }
                finally {
                    if ($had) { $this->envCache[$key] = $previous; }
                    else { unset($this->envCache[$key]); }
                }
            }, $native, OC\Config::class);
            if ($admin && !$cleanup($admin)) { throw new RuntimeException('Admin cleanup failed'); }
            if ($owner && !$cleanup($owner)) { throw new RuntimeException('Owner cleanup failed'); }
        }
        if ($createdTable) { $db->dropTable('retention_purges'); }
    } catch (Throwable $error) {
        $failure = true;
        fwrite(STDERR, "Fixture cleanup failed; inspect before retry\n");
    }
}
echo json_encode(['status' => $failure ? 'failed' : 'passed', 'phase' => $phase, 'error_class' => $errorClass]) . "\n";
exit($failure ? 1 : 0);
