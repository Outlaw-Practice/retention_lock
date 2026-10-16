<?php
declare(strict_types=1);

namespace OCA\RetentionLock\Service;

use OCA\AdminAudit\IAuditLogger;
use OCA\Files_Trashbin\Trash\ITrashManager;
use OCA\Files_Trashbin\Trash\ITrashItem;
use OCP\IDBConnection;
use OCP\IConfig;
use OCP\IGroupManager;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\IUser;
use RuntimeException;

class PurgeService {
    public function __construct(
        private IDBConnection $db,
        private IGroupManager $groups,
        private IUserSession $session,
        private IUserManager $users,
        private ITrashManager $trash,
        private IAuditLogger $audit,
        private IConfig $config,
    ) {}

    /** Return selection tokens, never accept a browser-supplied path for deletion. */
    public function listTrash(string $ownerUid): array {
        $this->requireAdmin();
        $owner = $this->users->get($ownerUid);
        if ($owner === null) { throw new RuntimeException('Owner unavailable'); }
        return $this->trash->withMutationLock(function () use ($owner): array {
            return array_map(static fn (ITrashItem $item): array => [
                'trashId' => $item->getId(), 'deletedAt' => $item->getDeletedTime(),
                'expectedEtag' => $item->getEtag(), 'name' => $item->getName(),
                'path' => $item->getOriginalLocation(),
            ], array_values($this->trash->listTrashRoot($owner)));
        });
    }

    public function operation(string $operationId): array {
        $this->requireAdmin();
        $query = $this->db->getQueryBuilder();
        $row = $query->select('operation_id', 'actor_uid', 'owner_uid', 'target', 'reason', 'requested_at', 'state')
            ->from('retention_purges')
            ->where($query->expr()->eq('operation_id', $query->createNamedParameter($operationId)))
            ->executeQuery()->fetchAssociative();
        if ($row === false) { throw new RuntimeException('Operation unavailable'); }
        // Explicit projection also keeps internal request fingerprints out of responses.
        return array_intersect_key($row, array_flip([
            'operation_id', 'actor_uid', 'owner_uid', 'target', 'reason', 'requested_at', 'state',
        ]));
    }

    private function requireAdmin(): IUser {
        $actor = $this->session->getUser();
        if ($actor === null || !$this->groups->isAdmin($actor->getUID())) {
            throw new RuntimeException('Administrator access required');
        }
        return $actor;
    }

    /** A pending/uncertain operation is never automatically replayed. */
    public function purge(string $operationId, string $ownerUid, int $trashId, int $deletedAt, string $reason, string $expectedEtag): string {
        // Requires the matching server-fork mutation boundary. A stock server
        // fails before any intent or deletion rather than silently omitting it.
        return $this->trash->withMutationLock(
            fn () => $this->purgeLocked($operationId, $ownerUid, $trashId, $deletedAt, $reason, $expectedEtag));
    }

    private function purgeLocked(string $operationId, string $ownerUid, int $trashId, int $deletedAt, string $reason, string $expectedEtag): string {
        $actor = $this->requireAdmin();
        $logCondition = $this->config->getSystemValue('log.condition', []);
        if (!is_array($logCondition) || !in_array('admin_audit', $logCondition['apps'] ?? [], true)) {
            throw new RuntimeException('Required audit logging is not configured');
        }
        $reason = trim($reason);
        if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', $operationId)
            || $reason === '' || strlen($reason) > 2000 || !preg_match('//u', $reason)
            || $ownerUid === '' || strlen($ownerUid) > 64 || $trashId <= 0 || $deletedAt <= 0
            || $expectedEtag === '' || strlen($expectedEtag) > 255) {
            throw new RuntimeException('Invalid purge request');
        }
        // Intent must commit before filesystem mutation, not join an outer transaction.
        if ($this->db->inTransaction()) {
            throw new RuntimeException('Purge requires an independent committed intent');
        }
        $fingerprint = hash('sha256', json_encode([$actor->getUID(), $ownerUid, $trashId, $deletedAt, $reason, $expectedEtag], JSON_THROW_ON_ERROR));
        $query = $this->db->getQueryBuilder();
        $row = $query->select('request_hash', 'state')->from('retention_purges')
            ->where($query->expr()->eq('operation_id', $query->createNamedParameter($operationId)))
            ->executeQuery()->fetchAssociative();
        if ($row !== false) {
            if (!hash_equals($row['request_hash'], $fingerprint)) {
                throw new RuntimeException('Operation ID already belongs to another request');
            }
            return $row['state'];
        }
        $owner = $this->users->get($ownerUid);
        if ($owner === null) { throw new RuntimeException('Owner unavailable'); }
        $item = $this->findItem($owner, $trashId, $deletedAt);
        if ($item === null) { throw new RuntimeException('Trash item unavailable'); }
        if (!hash_equals($expectedEtag, $item->getEtag())) {
            throw new RuntimeException('Trash selection is stale');
        }
        $teamVersions = null;
        $versionFileId = null;
        $versionFileIds = [];
        if ($item instanceof \OCA\GroupFolders\Trash\GroupTrashItem) {
            $rows = \OCP\Server::get(\OCA\GroupFolders\Trash\TrashManager::class)
                ->listTrashItemForFolders([$item->folder->id], $item->getName());
            foreach ($rows as $row) {
                if ((int)$row['folder_id'] === $item->folder->id
                    && (int)$row['deleted_time'] === $item->getDeletedTime()
                    && $row['name'] === $item->getName() && $row['file_id'] !== null) {
                    if ($versionFileId !== null) {
                        throw new RuntimeException('Ambiguous Teamfolders history identity');
                    }
                    $versionFileId = (int)$row['file_id'];
                }
            }
            if ($versionFileId === null) { throw new RuntimeException('Missing Teamfolders history identity'); }
            $root = $item->getTrashNode();
            if ($root->getId() !== $versionFileId) {
                throw new RuntimeException('Teamfolders storage did not preserve history identity');
            }
            // Enumerate the actual trash subtree, not the permission-filtered
            // user listing, before the backend removes its cache entries.
            $pending = [$root];
            while ($pending !== []) {
                $node = array_pop($pending);
                $id = $node->getId();
                if ($id === null || $id <= 0 || isset($versionFileIds[$id])) {
                    throw new RuntimeException('Invalid Teamfolders history subtree');
                }
                $versionFileIds[$id] = $id;
                if ($node instanceof \OCP\Files\Folder) {
                    foreach ($node->getDirectoryListing() as $child) { $pending[] = $child; }
                }
            }
            $teamVersions = \OCP\Server::get(\OCA\GroupFolders\Versions\VersionsBackend::class);
        }
        $target = json_encode(['name' => $item->getName(), 'id' => $item->getId(),
            'deleted_at' => $item->getDeletedTime(), 'path' => $item->getOriginalLocation(),
            'backend' => get_class($item->getTrashBackend()),
            'version_file_ids' => array_values($versionFileIds)], JSON_THROW_ON_ERROR);
        $at = time();
        $query = $this->db->getQueryBuilder();
        $values = ['operation_id' => $operationId, 'actor_uid' => $actor->getUID(),
            'owner_uid' => $ownerUid, 'request_hash' => $fingerprint, 'target' => $target,
            'reason' => $reason, 'requested_at' => $at, 'state' => 'pending'];
        $query->insert('retention_purges');
        foreach ($values as $key => $value) {
            $query->setValue($key, $query->createNamedParameter($value));
        }
        // Unique operation ID also prevents two simultaneous requests deleting twice.
        $query->executeStatement();
        $this->audit->info('Retention early purge requested: ' . json_encode([
            'operation_id' => $operationId, 'actor' => $actor->getUID(),
            'owner' => $ownerUid, 'path' => $item->getOriginalLocation(), 'reason' => $reason,
            'requested_at' => $at,
        ], JSON_THROW_ON_ERROR), ['app' => 'admin_audit']);
        try {
            $this->trash->removeItem($item);
            if ($this->findItem($owner, $trashId, $deletedAt) !== null) {
                throw new RuntimeException('Trash item remained after purge');
            }
            if ($teamVersions !== null) {
                foreach ($versionFileIds as $fileId) {
                    $teamVersions->deleteAllVersionsForFile($item->folder, $fileId);
                    if ($teamVersions->getVersionsFolder($item->folder)->nodeExists((string)$fileId)) {
                        throw new RuntimeException('Teamfolders history remained after purge');
                    }
                }
            }
        } catch (\Throwable $error) {
            // A backend may already have deleted part of the item. Preserve intent;
            // do not retry or falsely report rollback/completion.
            $this->setState($operationId, 'uncertain');
            throw new RuntimeException('Purge outcome requires administrator inspection');
        }
        $this->setState($operationId, 'completed');
        return 'completed';
    }

    private function findItem(IUser $owner, int $id, int $deletedAt): ?ITrashItem {
        $matches = array_values(array_filter($this->trash->listTrashRoot($owner),
            fn (ITrashItem $item) => $item->getId() === $id && $item->getDeletedTime() === $deletedAt));
        if (count($matches) > 1) { throw new RuntimeException('Ambiguous trash identity'); }
        return $matches[0] ?? null;
    }

    private function setState(string $operationId, string $state): void {
        $query = $this->db->getQueryBuilder();
        $query->update('retention_purges')->set('state', $query->createNamedParameter($state))
            ->where($query->expr()->eq('operation_id', $query->createNamedParameter($operationId)))
            ->executeStatement();
    }
}
