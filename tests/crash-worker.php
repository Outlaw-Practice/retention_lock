<?php
declare(strict_types=1);
ini_set('display_errors', '0');
ini_set('log_errors', '0');
define('OC_CONSOLE', 1);
try {
    // Bound the deliberately interrupted child even if a backend stops responding.
    pcntl_alarm(30);
    require '/opt/nextcloud/current/lib/base.php';
    OC_App::loadApps();
    // APP_SOURCES
    // FIXTURE
    if ($fixture['mode'] === 'after-history') {
        // Interrupt only after deleting a real history directory. Directory IDs
        // without versions must not turn this into another post-trash-only test.
        class InterruptedVersionsBackend extends OCA\GroupFolders\Versions\VersionsBackend {
            public function deleteAllVersionsForFile(OCA\GroupFolders\Folder\FolderDefinition $folder, int $fileId): void {
                $hadHistory = $this->getVersionsFolder($folder)->nodeExists((string)$fileId);
                parent::deleteAllVersionsForFile($folder, $fileId);
                if ($hadHistory) { exit(73); }
            }
        }
        $versions = OCP\Server::get(InterruptedVersionsBackend::class);
        OC::$server->registerService(OCA\GroupFolders\Versions\VersionsBackend::class, fn () => $versions);
    }
    $users = OCP\Server::get(OCP\IUserManager::class);
    $session = OCP\Server::get(OCP\IUserSession::class);
    $session->setUser($users->get($fixture['owner']));
    OC_Util::setupFS($fixture['owner']);
    $session->setUser($users->get($fixture['actor']));
    $original = OCP\Server::get(OCA\Files_Trashbin\Trash\ITrashManager::class);
    $trash = new class extends OCA\Files_Trashbin\Trash\QualifiedTrashManager {
        public bool $exitAfterDelete = false;
        public function removeItem(OCA\Files_Trashbin\Trash\ITrashItem $item) {
            parent::removeItem($item);
            if ($this->exitAfterDelete) { exit(73); }
        }
    };
    $trash->exitAfterDelete = $fixture['mode'] === 'after-delete';
    $property = new ReflectionProperty(OCA\Files_Trashbin\Trash\TrashManager::class, 'backends');
    foreach ($property->getValue($original) as $type => $backend) { $trash->registerBackend($type, $backend); }
    $audit = new class(OCP\Server::get(OCA\AdminAudit\IAuditLogger::class), $fixture['mode'])
        extends Psr\Log\AbstractLogger implements OCA\AdminAudit\IAuditLogger {
        public function __construct(private OCA\AdminAudit\IAuditLogger $delegate, private string $mode) {}
        public function log($level, string|Stringable $message, array $context = []): void {
            $this->delegate->log($level, $message, $context);
            if ($this->mode === 'after-intent') { exit(73); }
        }
    };
    $service = new OCA\RetentionLock\Service\PurgeService(OCP\Server::get(OCP\IDBConnection::class),
        OCP\Server::get(OCP\IGroupManager::class), $session, $users, $trash, $audit,
        OCP\Server::get(OCP\IConfig::class));
    $service->purge($fixture['operation'], $fixture['owner'], $fixture['id'], $fixture['deleted'],
        'Disposable integration qualification', $fixture['etag']);
    exit(2); // The intended interruption must actually occur.
} catch (Throwable $error) { exit(1); }
