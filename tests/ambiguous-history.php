<?php
$phase = 'purge';
$originalHistory = OCP\Server::get(OCA\GroupFolders\Trash\TrashManager::class);
$duplicateHistory = new class($originalHistory) extends OCA\GroupFolders\Trash\TrashManager {
    public function __construct(private OCA\GroupFolders\Trash\TrashManager $original) {}
    public function listTrashItemForFolders(array $folderIds, string $name): array {
        $rows = $this->original->listTrashItemForFolders($folderIds, $name);
        if (count($rows) !== 1) { throw new RuntimeException('Expected one real fixture history row'); }
        return [$rows[0], $rows[0]];
    }
};
OC::$server->registerService(OCA\GroupFolders\Trash\TrashManager::class, fn () => $duplicateHistory);
$refused = false;
try {
    $service->purge('12345678-1234-4234-8234-' . bin2hex(random_bytes(6)),
        $owner->getUID(), $trashId, $deletedAt, 'Duplicate history qualification', $expectedEtag);
} catch (RuntimeException $error) {
    $refused = $error->getMessage() === 'Ambiguous Teamfolders history identity';
} finally {
    OC::$server->registerService(OCA\GroupFolders\Trash\TrashManager::class, fn () => $originalHistory);
}
$query = $db->getQueryBuilder();
if (!$refused || count($trash->listTrashRoot($owner)) !== 1
    || !$versionRoot->nodeExists($fileId)
    || $query->select('operation_id')->from('retention_purges')->executeQuery()->fetchAllAssociative() !== []) {
    throw new RuntimeException('Ambiguous history was not refused before mutation');
}
