<?php
declare(strict_types=1);
ini_set('display_errors', '0');
ini_set('log_errors', '0');
define('OC_CONSOLE', 1);
$stage = 'bootstrap';
try {
    require '/opt/nextcloud/current/lib/base.php';
    OC_App::loadApps();
    // MANAGER_SOURCES
    // EXPIRY_SOURCE
    // FIXTURE
    $owner = OCP\Server::get(OCP\IUserManager::class)->get($fixture['owner']);
    if (!$owner) { throw new RuntimeException(); }
    OCP\Server::get(OCP\IUserSession::class)->setUser($owner);
    OC_Util::setupFS($owner->getUID());
    $original = OCP\Server::get(OCA\Files_Trashbin\Trash\ITrashManager::class);
    $trash = new OCA\Files_Trashbin\Trash\QualifiedTrashManager();
    $property = new ReflectionProperty(OCA\Files_Trashbin\Trash\TrashManager::class, 'backends');
    foreach ($property->getValue($original) as $type => $backend) { $trash->registerBackend($type, $backend); }
    $matches = array_values(array_filter($trash->listTrashRoot($owner),
        fn ($item) => $item->getId() === $fixture['id'] && $item->getDeletedTime() === $fixture['deleted']));
    if (count($matches) !== 1) { throw new RuntimeException(); }
    $targets = [$matches[0]];
    for ($index = 0; $index < count($targets); $index++) {
        if (count($targets) > 16) { throw new RuntimeException('Unexpected fixture size'); }
        if ($targets[$index]->getType() !== 'dir') { continue; }
        $children = $trash->listTrashFolder($targets[$index]);
        if ($children === []) { throw new RuntimeException(); }
        foreach ($children as $child) { $targets[] = $child; }
    }
    foreach ($targets as $target) {
        $stage = 'manager';
        $blocked = false;
        try { $trash->restoreItem($target); }
        catch (OCP\Lock\LockedException) { $blocked = true; }
        if (!$blocked) { throw new RuntimeException(); }
        $stage = 'dav';
        $node = new class($trash, $target) extends OCA\Files_Trashbin\Sabre\QualifiedAbstractTrash {};
        $blocked = false;
        try { $node->restore(); }
        catch (Sabre\DAV\Exception\Locked $error) { $blocked = $error->getHTTPCode() === 423; }
        if (!$blocked) { throw new RuntimeException('DAV contention was not retryable'); }
    }
    $stage = 'bulk';
    $command = new OCA\Files_Trashbin\Command\QualifiedRestoreAllFiles(
        OCP\Server::get(OCP\Files\IRootFolder::class), OCP\Server::get(OCP\IUserManager::class),
        OCP\Server::get(OCP\IDBConnection::class), $trash, OCP\Server::get(OC\Files\SetupManager::class),
        OCP\Server::get(OCP\IUserSession::class), OCP\Server::get(OCP\L10N\IFactory::class));
    $application = new Symfony\Component\Console\Application();
    $application->setAutoExit(false);
    $application->add($command);
    $output = new Symfony\Component\Console\Output\BufferedOutput();
    $application->run(new Symfony\Component\Console\Input\ArrayInput([
        'command' => 'trashbin:restore', 'user_id' => [$owner->getUID()], '--scope' => 'all']), $output);
    if (!str_contains($output->fetch(), 'Successfully restored 0 out of') || count($trash->listTrashRoot($owner)) !== 1) {
        throw new RuntimeException('Bulk command bypassed mutation boundary');
    }
    if (!$matches[0] instanceof OCA\GroupFolders\Trash\GroupTrashItem) {
        $stage = 'expiry';
        $trashRoot = OCP\Server::get(OCP\Files\IRootFolder::class)->getUserFolder($owner->getUID())
            ->getParent()->get('files_trashbin/files');
        $blocked = false;
        try { OCA\Files_Trashbin\QualifiedTrashbin::expire($trashRoot, $owner); }
        catch (OCP\Lock\LockedException) { $blocked = true; }
        if (!$blocked) { throw new RuntimeException('Expiry bypassed mutation boundary'); }
    } else {
        $stage = 'expiry';
        $backend = OCP\Server::get(OCA\GroupFolders\Trash\QualifiedTrashBackend::class);
        $backend->setVersionsBackend(OCP\Server::get(OCA\GroupFolders\Versions\VersionsBackend::class));
        $blocked = false;
        try { $backend->expire(OCP\Server::get(OCA\Files_Trashbin\Expiration::class)); }
        catch (OCP\Lock\LockedException) { $blocked = true; }
        if (!$blocked) { throw new RuntimeException('Teamfolders expiry bypassed mutation boundary'); }
        $blocked = false;
        try { $backend->cleanTrashFolder($matches[0]->folder); }
        catch (OCP\Files\NotPermittedException) { $blocked = true; }
        if (!$blocked || count($trash->listTrashRoot($owner)) !== 1) {
            throw new RuntimeException('Teamfolders manual cleanup bypassed retention');
        }
    }
    echo 'restore-blocked';
} catch (Throwable $error) {
    echo 'failed:' . $stage;
    exit(1);
}
