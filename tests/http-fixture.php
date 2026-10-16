<?php
declare(strict_types=1);
ini_set('display_errors', '0');
ini_set('log_errors', '0');
define('OC_CONSOLE', true);
try {
    require '/opt/nextcloud/current/lib/base.php';
    OC_App::loadApps();
    $input = json_decode(stream_get_contents(STDIN), true, flags: JSON_THROW_ON_ERROR);
    $users = OCP\Server::get(OCP\IUserManager::class);
    $uid = 'n5-retention-http-owner';
    if (in_array($input['action'], ['create-team', 'snapshot-team', 'cleanup-team'], true)) {
        $manager = OCP\Server::get(OCA\GroupFolders\Folder\FolderManager::class);
        $groups = OCP\Server::get(OCP\IGroupManager::class);
        $groupName = 'n5-retention-http-team';
        $mount = 'N5 Retention HTTP Team Fixture';
        $removeFixtureFolder = function (int $id) use ($manager, $mount): void {
            $definition = $manager->getFolder($id);
            if ($definition === null || $definition->mountPoint !== $mount) {
                throw new RuntimeException('Fixture identity mismatch');
            }
            $storageManager = OCP\Server::get(OCA\GroupFolders\Mount\FolderStorageManager::class);
            foreach (['files', 'trash', 'versions', ''] as $type) {
                $storage = $storageManager->getBaseStorageForFolder($id,
                    $definition->useSeparateStorage(), $definition, null, false, $type);
                $storage->getCache()->clear();
                $storage->rmdir('');
            }
            $manager->removeFolder($id);
        };
        if ($input['action'] === 'create-team') {
            if (count($manager->getAllFolders()) !== 0 || $groups->groupExists($groupName)) {
                throw new RuntimeException('Requires absent Teamfolders fixtures');
            }
            $owner = $users->get($uid);
            if ($owner === null) { throw new RuntimeException('Owner fixture missing'); }
            $group = null;
            $folderId = null;
            try {
            $group = $groups->createGroup($groupName);
            $group->addUser($owner);
            $folderId = $manager->createFolder($mount);
            $manager->addApplicableGroup($folderId, $groupName);
            $manager->setGroupPermissions($folderId, $groupName, OCP\Constants::PERMISSION_ALL);
            $manager->setFolderQuota($folderId, -3);
            OCP\Server::get(OCP\IUserSession::class)->setUser($owner);
            OC_Util::setupFS($uid);
            $folder = OCP\Server::get(OCP\Files\IRootFolder::class)->getUserFolder($uid)->get($mount);
            foreach (['live.txt', 'deleted.txt'] as $name) {
                $file = $folder->newFile($name, 'old fictional bytes');
                $file->touch(time() - 86400);
                $file->putContent('new fictional bytes');
                if ($name === 'deleted.txt') { $file->delete(); }
            }
            echo json_encode(['status' => 'created', 'folderId' => $folderId]);
            } catch (Throwable $error) {
                try {
                    if ($folderId !== null) { $removeFixtureFolder($folderId); }
                } finally {
                    if ($group !== null && !$group->delete()) {
                        throw new RuntimeException('Partial fixture group cleanup failed');
                    }
                }
                throw $error;
            }
        } else {
            $definition = $manager->getFolder((int)$input['folderId']);
            if ($definition === null || $definition->mountPoint !== $mount) {
                throw new RuntimeException('Fixture identity mismatch');
            }
            if ($input['action'] === 'cleanup-team') {
                // Fixture teardown only, outside product routes. Do not toggle
                // global retention settings just to remove disposable data.
                $removeFixtureFolder($definition->id);
                $group = $groups->get($groupName);
                if ($group !== null && !$group->delete()) { throw new RuntimeException('Group cleanup failed'); }
                echo json_encode(['status' => 'cleaned']);
            } else {
                $owner = $users->get($uid);
                OCP\Server::get(OCP\IUserSession::class)->setUser($owner);
                OC_Util::setupFS($uid);
                $folder = OCP\Server::get(OCP\Files\IRootFolder::class)->getUserFolder($uid)->get($mount);
                $snapshot = function ($node) use (&$snapshot): array {
                    if ($node instanceof OCP\Files\Folder) {
                        $entries = [];
                        foreach ($node->getDirectoryListing() as $child) { $entries[$child->getName()] = $snapshot($child); }
                        ksort($entries);
                        return $entries;
                    }
                    return ['content' => base64_encode($node->getContent())];
                };
                $trash = [];
                foreach (OCP\Server::get(OCA\Files_Trashbin\Trash\ITrashManager::class)->listTrashRoot($owner) as $item) {
                    if ($item instanceof OCA\GroupFolders\Trash\GroupTrashItem && $item->folder->id === $definition->id) {
                        $trash[$item->getId()] = $snapshot($item->getTrashNode());
                    }
                }
                echo json_encode(['live' => $snapshot($folder), 'trash' => $trash,
                    'history' => $snapshot(OCP\Server::get(OCA\GroupFolders\Versions\VersionsBackend::class)->getVersionsFolder($definition))], JSON_THROW_ON_ERROR);
            }
        }
    } elseif ($input['action'] === 'create') {
        if ($users->userExists($uid)) { throw new RuntimeException('Existing fixture'); }
        $config = OCP\Server::get(OCP\IConfig::class);
        $config->setSystemValue('log.condition', ['apps' => ['admin_audit']]);
        $config->setSystemValue('files.trash.delete', false);
        $config->setSystemValue('enable_version_deletion', false);
        $owner = $users->createUser($uid, $input['password']);
        if ($owner === false) { throw new RuntimeException('Fixture creation failed'); }
        OCP\Server::get(OCP\IUserSession::class)->setUser($owner);
        OC_Util::setupFS($uid);
        $folder = OCP\Server::get(OCP\Files\IRootFolder::class)->getUserFolder($uid);
        $file = $folder->newFile('fictional-http-document.txt', 'older fictional version');
        $file->touch(time() - 86400);
        $file->putContent('current fictional version');
        $file->delete();
        echo json_encode(['status' => 'created']);
    } elseif ($input['action'] === 'account-exists') {
        echo json_encode(['exists' => $users->userExists($uid)]);
    } elseif ($input['action'] === 'snapshot-account') {
        $owner = $users->get($uid);
        if ($owner === null) { throw new RuntimeException('Fixture owner missing'); }
        OCP\Server::get(OCP\IUserSession::class)->setUser($owner);
        OC_Util::setupFS($uid);
        $snapshot = function ($node) use (&$snapshot): array {
            if ($node instanceof OCP\Files\Folder) {
                $entries = [];
                foreach ($node->getDirectoryListing() as $child) { $entries[$child->getName()] = $snapshot($child); }
                ksort($entries);
                return $entries;
            }
            return ['content' => base64_encode($node->getContent())];
        };
        $folder = OCP\Server::get(OCP\Files\IRootFolder::class)->getUserFolder($uid);
        echo json_encode(['enabled' => $owner->isEnabled(), 'files' => $snapshot($folder->getParent())], JSON_THROW_ON_ERROR);
    } elseif ($input['action'] === 'cleanup') {
        $owner = $users->get($uid);
        if ($owner !== null) {
            // Privileged test teardown of this fixed fixture only. Override the
            // retention flag in this PHP process's environment-value cache; no
            // configuration write or change to another worker is permitted.
            $system = OCP\Server::get(OC\SystemConfig::class);
            $native = (new ReflectionProperty(OC\SystemConfig::class, 'config'))->getValue($system);
            $cleanup = Closure::bind(function ($fixtureOwner): bool {
                $key = 'files.trash.delete';
                $had = array_key_exists($key, $this->envCache);
                $previous = $this->envCache[$key] ?? null;
                $this->envCache[$key] = true;
                try { return $fixtureOwner->delete(); }
                finally {
                    if ($had) { $this->envCache[$key] = $previous; }
                    else { unset($this->envCache[$key]); }
                }
            }, $native, OC\Config::class);
            if (!$cleanup($owner)) { throw new RuntimeException('Fixture cleanup failed'); }
        }
        $db = OCP\Server::get(OCP\IDBConnection::class);
        $query = $db->getQueryBuilder();
        $query->delete('retention_purges')->where($query->expr()->eq('owner_uid', $query->createNamedParameter($uid)))
            ->executeStatement();
        echo json_encode(['status' => 'cleaned']);
    } else { throw new RuntimeException('Unknown fixture action'); }
} catch (Throwable $error) {
    echo json_encode(['status' => 'failed', 'error_class' => get_class($error), 'source_line' => $error->getLine()]);
    exit(1);
}
