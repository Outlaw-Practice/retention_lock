<?php
// The real restore completes before a request with the old trash identity arrives.
$phase = 'purge';
$session->setUser($owner);
$trash->restoreItem($item);
$restored = $folder->get($item->getName());
$snapshot = function ($node) use (&$snapshot): array {
    if ($node instanceof OCP\Files\Folder) {
        $children = [];
        foreach ($node->getDirectoryListing() as $child) {
            $children[$child->getName()] = $snapshot($child);
        }
        ksort($children);
        return $children;
    }
    return ['content' => $node->getContent()];
};
$history = isset($versionRoot) ? $versionRoot : $folder->getParent()->get('files_versions');
$beforeContent = $snapshot($restored);
$beforeHistory = $snapshot($history);
if ($beforeContent === [] || $beforeHistory === []) { throw new RuntimeException('Empty restored fixture'); }
$session->setUser($admin);
$staleId = '12345678-1234-4234-8234-' . bin2hex(random_bytes(6));
$refused = false;
try {
    $service->purge($staleId, $owner->getUID(), $trashId, $deletedAt, 'Stale request qualification', $expectedEtag);
} catch (RuntimeException $error) {
    $refused = $error->getMessage() === 'Trash item unavailable';
}
if (!$refused || $snapshot($restored) !== $beforeContent || $snapshot($history) !== $beforeHistory) {
    throw new RuntimeException('Stale purge affected restored data');
}
$query = $db->getQueryBuilder();
$records = $query->select('operation_id')->from('retention_purges')->executeQuery()->fetchAllAssociative();
if ($records !== [] || $trash->listTrashRoot($owner) !== []) {
    throw new RuntimeException('Stale purge created intent or trash remained');
}
