<?php
// Observe real backend identity reuse using only this case's disposable fixture.
$phase = 'purge';
$session->setUser($owner);
$identityCollision = false;
$etagCollision = false;
for ($attempt = 0; $attempt < 8; $attempt++) {
    $previous = $item;
    $previousId = $previous->getId();
    $previousDeleted = $previous->getDeletedTime();
    $previousEtag = $previous->getEtag();
    $name = $previous->getName();
    $trash->restoreItem($previous);
    $folder->get($name)->delete();
    $items = $trash->listTrashRoot($owner);
    if (count($items) !== 1) { throw new RuntimeException('Unexpected identity fixture'); }
    $item = array_values($items)[0];
    if ($item->getId() === $previousId && $item->getDeletedTime() === $previousDeleted) {
        $identityCollision = true;
        $etagCollision = $item->getEtag() === $previousEtag;
        break;
    }
}
if (!$identityCollision || $etagCollision) { throw new RuntimeException('Deletion generation is not distinguishable'); }
$session->setUser($admin);
$operation = '12345678-1234-4234-8234-' . bin2hex(random_bytes(6));
$refused = false;
try {
    $service->purge($operation, $owner->getUID(), $previousId, $previousDeleted,
        'Stale same-second selection', $previousEtag);
} catch (RuntimeException $error) {
    $refused = $error->getMessage() === 'Trash selection is stale';
}
$query = $db->getQueryBuilder();
if (!$refused || count($trash->listTrashRoot($owner)) !== 1
    || $query->select('operation_id')->from('retention_purges')->executeQuery()->fetchAllAssociative() !== []) {
    throw new RuntimeException('Stale selection was not refused before intent');
}
