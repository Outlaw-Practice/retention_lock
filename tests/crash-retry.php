<?php
$id = '12345678-1234-4234-8234-' . bin2hex(random_bytes(6));
$phase = 'purge';
$snapshotNode = function ($node) use (&$snapshotNode): array {
    if ($node instanceof OCP\Files\Folder) {
        $result = [];
        foreach ($node->getDirectoryListing() as $child) { $result[$child->getName()] = $snapshotNode($child); }
        ksort($result);
        return $result;
    }
    return ['content' => $node->getContent()];
};
$snapshotTrash = function ($entry) use (&$snapshotTrash, $trash): array {
    if ($entry->getType() === 'dir') {
        $result = [];
        foreach ($trash->listTrashFolder($entry) as $child) { $result[$child->getName()] = $snapshotTrash($child); }
        ksort($result);
        return $result;
    }
    $bytes = $entry instanceof OCA\GroupFolders\Trash\GroupTrashItem
        ? $entry->getTrashNode()->getContent()
        : $entry->getTrashBackend()->getTrashNodeById($entry->getUser(), $entry->getId())->getContent();
    if ($bytes === false) { throw new RuntimeException('Fixture content unreadable'); }
    return ['content' => $bytes];
};
$snapshot = function () use ($trash, $owner, $folder, &$versionRoot, $snapshotNode, $snapshotTrash): array {
    $result = [];
    foreach ($trash->listTrashRoot($owner) as $entry) { $result[$entry->getId()] = $snapshotTrash($entry); }
    ksort($result);
    $history = isset($versionRoot) ? $versionRoot : $folder->getParent()->get('files_trashbin/versions');
    return [$result, $snapshotNode($history)];
};
$phase = 'crash-snapshot';
$before = $snapshot();
$fixture = base64_encode(json_encode(['owner' => $owner->getUID(), 'actor' => $admin->getUID(),
    'id' => $trashId, 'deleted' => $deletedAt, 'operation' => $id, 'mode' => $crashMode, 'etag' => $expectedEtag], JSON_THROW_ON_ERROR));
$source = str_replace('// FIXTURE', '$fixture = json_decode(base64_decode(\'' . $fixture . '\'), true);', $crashWorkerTemplate);
$phase = 'crash-worker';
$process = proc_open(['php'], [0 => ['pipe', 'r'], 1 => ['file', '/dev/null', 'w'],
    2 => ['file', '/dev/null', 'w']], $pipes);
if (!is_resource($process)) { throw new RuntimeException('Crash worker unavailable'); }
fwrite($pipes[0], $source);
fclose($pipes[0]);
if (proc_close($process) !== 73) { throw new RuntimeException('Expected interruption did not occur'); }
$phase = 'crash-after';
$after = $snapshot();
if ($crashMode === 'after-intent' && $after !== $before) { throw new RuntimeException('Intent-only interruption changed files'); }
if ($crashMode === 'after-delete' && $after[0] !== []) { throw new RuntimeException('Deletion interruption left trash'); }
if ($crashMode === 'after-delete' && isset($versionRoot) && $after[1] !== $before[1]) {
    throw new RuntimeException('Expected retained Teamfolders history at interruption');
}
if ($crashMode === 'after-history') {
    $removed = array_diff_key($before[1], $after[1]);
    if ($after[0] !== [] || count($removed) !== 1
        || array_intersect_key($before[1], $after[1]) !== $after[1]) {
        throw new RuntimeException('Expected exactly one deleted history and unchanged remaining history');
    }
    if (isset($outsideId) && (!isset($after[1][$outsideId]) || count($after[1]) < 2)) {
        throw new RuntimeException('Partial cleanup did not preserve sibling and unfinished descendant history');
    }
}
$phase = 'crash-retry';
for ($retry = 0; $retry < 2; $retry++) {
    if ($service->purge($id, $owner->getUID(), $trashId, $deletedAt, 'Disposable integration qualification', $expectedEtag) !== 'pending'
        || $snapshot() !== $after) { throw new RuntimeException('Pending operation was replayed'); }
}
$query = $db->getQueryBuilder();
$records = $query->select('operation_id', 'state')->from('retention_purges')->executeQuery()->fetchAllAssociative();
if (count($records) !== 1 || $records[0]['operation_id'] !== $id || $records[0]['state'] !== 'pending') {
    throw new RuntimeException('Interrupted intent was not preserved');
}
