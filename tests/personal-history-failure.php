<?php
$phase = 'purge';
// PERSONAL_DELETE_SOURCE
// PERSONAL_BACKEND_SOURCE
class QualifiedFailingHistoryView extends OC\Files\View {
    public static int $attempts = 0;
    public function unlink($path) {
        if (str_contains($path, 'files_trashbin/versions/')) {
            self::$attempts++;
            return false;
        }
        return parent::unlink($path);
    }
}
$snapshotNode = function ($node) use (&$snapshotNode): array {
    if ($node instanceof OCP\Files\Folder) {
        $result = [];
        foreach ($node->getDirectoryListing() as $child) { $result[$child->getName()] = $snapshotNode($child); }
        ksort($result);
        return $result;
    }
    return ['content' => $node->getContent()];
};
$retained = $folder->getParent()->get('files_trashbin');
$before = $snapshotNode($retained);
$view = new QualifiedFailingHistoryView('/' . $owner->getUID());
$method = new ReflectionMethod(OCA\Files_Trashbin\QualifiedTrashbin::class, 'deleteVersions');
try {
    $method->invoke(null, $view, $key, $item->getName(), $deletedAt, $owner->getUID());
    throw new RuntimeException('Version deletion failure was ignored');
} catch (OCP\Files\NotPermittedException $error) {
    if ($error->getMessage() !== 'Could not remove retained trash history') { throw $error; }
}
if (QualifiedFailingHistoryView::$attempts !== 1 || $snapshotNode($retained) !== $before) {
    throw new RuntimeException('Failed history deletion did not preserve retained data');
}
QualifiedFailingHistoryView::$attempts = 0;
$candidateBackend = new OCA\Files_Trashbin\Trash\QualifiedLegacyTrashBackend(
    OCP\Server::get(OCP\Files\IRootFolder::class), $users,
    OCP\Server::get(OCP\Federation\ICloudIdManager::class));
$backends = (new ReflectionProperty(get_class($trash), 'backends'))->getValue($trash);
foreach ($backends as $storageType => $backend) {
    if ($backend instanceof OCA\Files_Trashbin\Trash\LegacyTrashBackend) {
        $trash->registerBackend($storageType, $candidateBackend);
    }
}
$id = '12345678-1234-4234-8234-' . bin2hex(random_bytes(6));
try {
    $service->purge($id, $owner->getUID(), $trashId, $deletedAt, 'Disposable unlink failure', $expectedEtag);
    throw new RuntimeException('Full purge ignored history unlink failure');
} catch (RuntimeException $error) {
    if ($error->getMessage() !== 'Purge outcome requires administrator inspection') { throw $error; }
}
if (QualifiedFailingHistoryView::$attempts !== 1
    || $snapshotNode($retained) !== $before || count($trash->listTrashRoot($owner)) !== 1) {
    throw new RuntimeException('Full purge failed to preserve retained data');
}
for ($retry = 0; $retry < 2; $retry++) {
    if ($service->purge($id, $owner->getUID(), $trashId, $deletedAt, 'Disposable unlink failure', $expectedEtag) !== 'uncertain') {
        throw new RuntimeException('Failed purge retry lost uncertain state');
    }
}
$rows = $db->getQueryBuilder()->select('state')->from('retention_purges')->executeQuery()->fetchAllAssociative();
if ($rows !== [['state' => 'uncertain']] || QualifiedFailingHistoryView::$attempts !== 1
    || $snapshotNode($retained) !== $before) {
    throw new RuntimeException('Failed purge replayed or lost its durable state');
}
// Run the real expiry loop with the first item's unlink failure and a second
// eligible item. Advance only Expiration's process-local clock, not timestamps
// or the host clock, so the real 60-day eligibility calculation still runs.
$session->setUser($owner);
$folder->newFile('expiry-following.txt', 'Disposable following item')->delete();
$following = array_values(array_filter($trash->listTrashRoot($owner),
    fn ($entry) => $entry->getName() === 'expiry-following.txt'));
if (count($following) !== 1) { throw new RuntimeException('Following expiry fixture missing'); }
$expiration = OCP\Server::get(OCA\Files_Trashbin\Expiration::class);
$clockProperty = new ReflectionProperty(OCA\Files_Trashbin\Expiration::class, 'timeFactory');
$originalClock = $clockProperty->getValue($expiration);
$clockProperty->setValue($expiration, new class extends OC\AppFramework\Utility\TimeFactory {
    public function getTime(): int { return time() + 61 * 86400; }
});
try {
    $expired = OCA\Files_Trashbin\QualifiedTrashbin::deleteExpiredFiles([
        ['name' => $item->getName(), 'mtime' => $deletedAt],
        ['name' => $following[0]->getName(), 'mtime' => $following[0]->getDeletedTime()],
    ], $owner->getUID());
} finally {
    $clockProperty->setValue($expiration, $originalClock);
    $session->setUser($admin);
}
if ($expired[1] !== 1 || QualifiedFailingHistoryView::$attempts !== 2
    || count($trash->listTrashRoot($owner)) !== 1 || $snapshotNode($retained) !== $before) {
    throw new RuntimeException('Expiry did not continue safely after history failure');
}
