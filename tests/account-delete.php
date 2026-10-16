<?php
$phase = 'purge';
// ACCOUNT_SOURCE
$snapshotNode = function ($node) use (&$snapshotNode): array {
    if ($node instanceof OCP\Files\Folder) {
        $result = [];
        foreach ($node->getDirectoryListing() as $child) { $result[$child->getName()] = $snapshotNode($child); }
        ksort($result);
        return $result;
    }
    return ['content' => $node->getContent()];
};
$before = $snapshotNode($folder->getParent());
$hooks = 0;
$dispatcher = OCP\Server::get(OCP\EventDispatcher\IEventDispatcher::class);
$dispatcher->addListener(OCP\User\Events\BeforeUserDeletedEvent::class,
    function () use (&$hooks): void { $hooks++; });
$users->listen('\OC\User', 'preDelete', function () use (&$hooks): void { $hooks++; });
$candidate = new OC\User\QualifiedUser($owner->getUID(), $owner->getBackend(), $dispatcher, $users);
$cacheProperty = new ReflectionProperty(OC\User\Manager::class, 'cachedUsers');
$oldCache = $cacheProperty->getValue($users);
$cacheProperty->setValue($users, array_merge($oldCache, [$owner->getUID() => $candidate]));
try {
    if ($candidate->delete() !== false) { throw new RuntimeException('Account deletion was allowed'); }
    $command = new OC\Core\Command\User\Delete($users);
    $application = new Symfony\Component\Console\Application();
    $application->addCommand($command);
    $tester = new Symfony\Component\Console\Tester\CommandTester($command);
    if ($tester->execute(['uid' => $owner->getUID()]) !== 1) {
        throw new RuntimeException('Account-delete command did not fail');
    }
    $app = new OCP\AppFramework\App('provisioning_api');
    $controller = $app->getContainer()->get(OCA\Provisioning_API\Controller\UsersController::class);
    try {
        $controller->deleteUser($owner->getUID());
        throw new RuntimeException('Account-delete API did not fail');
    } catch (OCP\AppFramework\OCS\OCSException $expected) {
        if ($expected->getCode() !== 101) { throw $expected; }
    }
    if ($hooks !== 0 || !$users->userExists($owner->getUID())
        || OCP\Server::get(OCP\IConfig::class)->getUserValue($owner->getUID(), 'core', 'deleted', '') !== ''
        || count($trash->listTrashRoot($owner)) !== 1
        || $snapshotNode($folder->getParent()) !== $before) {
        throw new RuntimeException('Account deletion reached hooks or changed retained state');
    }
    $candidate->setEnabled(false);
    if ($candidate->isEnabled() || $snapshotNode($folder->getParent()) !== $before) {
        throw new RuntimeException('Account disable did not preserve retained data');
    }
    $candidate->setEnabled(true);
} finally {
    $cacheProperty->setValue($users, $oldCache);
}
