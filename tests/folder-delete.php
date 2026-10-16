<?php
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
$before = [$snapshotNode($item->getTrashNode()), $snapshotNode($versionRoot), $snapshotNode($folder)];
$storageManager = OCP\Server::get(OCA\GroupFolders\Mount\QualifiedFolderStorageManager::class);
try {
    $storageManager->deleteStoragesForFolder($manager->getFolder($folderId));
    throw new RuntimeException('Whole-folder deletion was allowed');
} catch (OCP\Files\NotPermittedException $expected) {}
$root = OCP\Server::get(OCP\Files\IRootFolder::class);
$command = new OCA\GroupFolders\Command\QualifiedDelete($manager, $root,
    OCP\Server::get(OCA\GroupFolders\Mount\MountProvider::class), $storageManager);
$application = new Symfony\Component\Console\Application();
$application->addCommand($command);
$tester = new Symfony\Component\Console\Tester\CommandTester($command);
if ($tester->execute(['folder_id' => (string)$folderId, '--force' => true]) !== 1) {
    throw new RuntimeException('Whole-folder command did not refuse deletion');
}
$controller = new OCA\GroupFolders\Controller\QualifiedFolderController('groupfolders',
    OCP\Server::get(OCP\IRequest::class), $manager, $root, $session,
    OCP\Server::get(OCA\GroupFolders\Service\FoldersFilter::class),
    OCP\Server::get(OCA\GroupFolders\Service\DelegationService::class), $groups, $storageManager);
try {
    $controller->removeFolder($folderId);
    throw new RuntimeException('Whole-folder API did not refuse deletion');
} catch (OCP\AppFramework\OCS\OCSForbiddenException $expected) {}
$cleanup = new OCA\GroupFolders\Command\Trashbin\QualifiedCleanup($manager);
$application->addCommand($cleanup);
$cleanupTester = new Symfony\Component\Console\Tester\CommandTester($cleanup);
foreach ([['folder_id' => (string)$folderId, '--force' => true], ['--force' => true]] as $arguments) {
    if ($cleanupTester->execute($arguments) !== 1
        || !str_contains($cleanupTester->getDisplay(), 'Trash cleanup refused by retention policy.')) {
        throw new RuntimeException('Trash cleanup command did not report policy refusal');
    }
}
if ($manager->getFolder($folderId) === null
    || [$snapshotNode($item->getTrashNode()), $snapshotNode($versionRoot), $snapshotNode($folder)] !== $before) {
    throw new RuntimeException('Refused folder removal changed retained data');
}
$query = $db->getQueryBuilder();
if ($query->select('operation_id')->from('retention_purges')->executeQuery()->fetchAllAssociative() !== []) {
    throw new RuntimeException('Refused folder removal created purge intent');
}
