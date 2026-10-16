<?php
declare(strict_types=1);
ini_set('display_errors', '0');
ini_set('log_errors', '0');
define('OC_CONSOLE', 1);
$admin = null;
$owner = null;
$group = null;
$folderId = null;
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
    foreach (['n5-team-audit-admin', 'n5-team-audit-owner'] as $uid) {
        if ($users->userExists($uid)) { throw new RuntimeException('Existing fixture'); }
    }
    $manager = OCP\Server::get(OCA\GroupFolders\Folder\FolderManager::class);
    if (count($manager->getAllFolders()) !== 0 || $groups->groupExists('n5-team-audit-group')) {
        throw new RuntimeException('Requires empty Teamfolders and absent fixture group');
    }
    $phase = 'fixture';
    $admin = $users->createUser('n5-team-audit-admin', bin2hex(random_bytes(32)));
    $owner = $users->createUser('n5-team-audit-owner', bin2hex(random_bytes(32)));
    if (!$admin || !$owner) { throw new RuntimeException('Fixture creation failed'); }
    $groups->get('admin')->addUser($admin);
    $group = $groups->createGroup('n5-team-audit-group');
    if (!$group) { throw new RuntimeException('Group creation failed'); }
    $group->addUser($owner);
    $mount = 'N5 Admin Purge Qualification';
    $folderId = $manager->createFolder($mount);
    $manager->addApplicableGroup($folderId, $group->getGID());
    $manager->setGroupPermissions($folderId, $group->getGID(), OCP\Constants::PERMISSION_ALL);
    $manager->setFolderQuota($folderId, -3);
    $session->setUser($owner);
    OC_Util::setupFS($owner->getUID());
    $folder = OCP\Server::get(OCP\Files\IRootFolder::class)->getUserFolder($owner->getUID())->get($mount);
    $file = $folder->newFile('audit-fixture.txt', 'old fictional content');
    $file->touch(time() - 86400);
    $file->putContent('new fictional content');
    $fileId = (string)$file->getId();
    $versionRoot = OCP\Server::get(OCA\GroupFolders\Versions\VersionsBackend::class)
        ->getVersionsFolder($manager->getFolder($folderId));
    if (!$versionRoot->nodeExists($fileId)) { throw new RuntimeException('Missing version fixture'); }
    $file->delete();
    $trash = OCP\Server::get(OCA\Files_Trashbin\Trash\ITrashManager::class);
    $items = $trash->listTrashRoot($owner);
    if (count($items) !== 1) { throw new RuntimeException('Trash fixture missing'); }
    $item = array_values($items)[0];
    $key = OCA\Files_Trashbin\Trashbin::getTrashFilename($item->getName(), $item->getDeletedTime());
    $trashId = $item->getId();
    $deletedAt = $item->getDeletedTime();
    $expectedEtag = $item->getEtag();
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
        || $versionRoot->nodeExists($fileId)) {
        throw new RuntimeException('Trash history remained');
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
    foreach (['Trash item unavailable' => 'lookup',
              'Purge outcome requires administrator inspection' => 'backend',
              'Trash history remained' => 'history', 'Durable audit differed' => 'record'] as $message => $stage) {
        if ($error->getMessage() === $message) { $phase = $stage; }
    }
    $failure = true;
    fwrite(STDERR, 'Purge integration failed during ' . $phase . ' (' . get_class($error) . "); details suppressed\n");
} finally {
    try {
        if ($admin || $owner) {
            OCP\Server::get(OCP\IUserSession::class)->setUser(null);
            OC_Util::tearDownFS();
            if ($folderId !== null) {
                $definition = $manager->getFolder($folderId);
                OCP\Server::get(OCA\GroupFolders\Mount\FolderStorageManager::class)->deleteStoragesForFolder($definition);
                $manager->removeFolder($folderId);
            }
            if ($group && !$group->delete()) { throw new RuntimeException('Group cleanup failed'); }
            if ($admin && !$admin->delete()) { throw new RuntimeException('Admin cleanup failed'); }
            if ($owner && !$owner->delete()) { throw new RuntimeException('Owner cleanup failed'); }
        }
        if ($createdTable) { $db->dropTable('retention_purges'); }
    } catch (Throwable $error) {
        $failure = true;
        fwrite(STDERR, "Fixture cleanup failed; inspect before retry\n");
    }
}
echo json_encode(['status' => $failure ? 'failed' : 'passed', 'phase' => $phase, 'error_class' => $errorClass]) . "\n";
exit($failure ? 1 : 0);
