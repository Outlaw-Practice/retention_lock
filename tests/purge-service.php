<?php
declare(strict_types=1);

// Minimal dependency doubles keep this suite offline. Real Nextcloud interface,
// database and storage integration is separately required before activation.
namespace OCP {
    interface IDBConnection {}
    interface IConfig {}
    interface IGroupManager {}
    interface IUserSession {}
    interface IUserManager {}
    interface IUser {}
}
namespace OCA\AdminAudit { interface IAuditLogger {} }
namespace OCA\Files_Trashbin\Trash { interface ITrashManager {} interface ITrashItem {} }
namespace RetentionLockTests {
    use OCA\RetentionLock\Service\PurgeService;
    use RuntimeException;

    final class User implements \OCP\IUser {
        public function __construct(private string $uid) {}
        public function getUID(): string { return $this->uid; }
    }
    final class Config implements \OCP\IConfig {
        public array $condition = ['apps' => ['admin_audit']];
        public function getSystemValue(string $key, mixed $default): mixed { return $this->condition; }
    }
    final class Groups implements \OCP\IGroupManager {
        public bool $admin = true;
        public function isAdmin(string $uid): bool { return $this->admin; }
    }
    final class Session implements \OCP\IUserSession {
        public ?User $user;
        public function __construct() { $this->user = new User('admin-fixture'); }
        public function getUser(): ?User { return $this->user; }
    }
    final class Users implements \OCP\IUserManager {
        public function get(string $uid): ?User { return $uid === 'owner-fixture' ? new User($uid) : null; }
    }
    final class Item implements \OCA\Files_Trashbin\Trash\ITrashItem {
        public function __construct(private int $id = 7) {}
        public function getName(): string { return 'fixture-file'; }
        public function getEtag(): string { return 'fixture-etag'; }
        public function getId(): int { return $this->id; }
        public function getDeletedTime(): int { return 100; }
        public function getOriginalLocation(): string { return '/fictional/document.txt'; }
        public function getTrashBackend(): object { return $this; }
    }
    final class Database implements \OCP\IDBConnection {
        public array $rows = [];
        public array $events = [];
        public bool $transaction = false;
        public bool $failInsert = false;
        public bool $failUpdate = false;
        public function inTransaction(): bool { return $this->transaction; }
        public function getQueryBuilder(): Query { return new Query($this); }
    }
    final class Query {
        private string $kind = '';
        private array $values = [];
        private string $id = '';
        public function __construct(private Database $db) {}
        public function select(...$columns): self { $this->kind = 'select'; return $this; }
        public function from(string $table): self { return $this; }
        public function insert(string $table): self { $this->kind = 'insert'; return $this; }
        public function update(string $table): self { $this->kind = 'update'; return $this; }
        public function setValue(string $key, mixed $value): self { $this->values[$key] = $value; return $this; }
        public function set(string $key, mixed $value): self { return $this->setValue($key, $value); }
        public function createNamedParameter(mixed $value): mixed { return $value; }
        public function expr(): self { return $this; }
        public function eq(string $key, string $value): string { return $value; }
        public function where(string $id): self { $this->id = $id; return $this; }
        public function executeQuery(): self { return $this; }
        public function fetchAssociative(): array|false { return $this->db->rows[$this->id] ?? false; }
        public function executeStatement(): int {
            if ($this->kind === 'insert') {
                $id = $this->values['operation_id'];
                if ($this->db->failInsert || isset($this->db->rows[$id])) { throw new RuntimeException('Insert failed'); }
                $this->db->rows[$id] = $this->values;
                $this->db->events[] = 'intent';
            } else {
                if ($this->db->failUpdate) { throw new RuntimeException('Update failed'); }
                $this->db->rows[$this->id] = array_merge($this->db->rows[$this->id], $this->values);
                $this->db->events[] = $this->values['state'];
            }
            return 1;
        }
    }
    final class Audit implements \OCA\AdminAudit\IAuditLogger {
        public array $messages = [];
        public bool $fail = false;
        public function __construct(private Database $db) {}
        public function info(string $message, array $context): void {
            if ($this->fail) { throw new RuntimeException('Audit unavailable'); }
            $this->messages[] = $message;
            $this->db->events[] = 'audit';
        }
    }
    final class Trash implements \OCA\Files_Trashbin\Trash\ITrashManager {
        public bool $exists = true;
        public bool $fail = false;
        public bool $noop = false;
        public int $calls = 0;
        public array $extraItems = [];
        private bool $locked = false;
        public function __construct(private Database $db) {}
        public function withMutationLock(callable $operation): mixed {
            if ($this->locked) { throw new RuntimeException('Unexpected nested entry'); }
            $this->locked = true;
            try { return $operation(); }
            finally { $this->locked = false; }
        }
        public function listTrashRoot(User $owner): array {
            if (!$this->locked) { throw new RuntimeException('Lookup outside lock'); }
            return array_merge($this->exists ? [new Item()] : [], $this->extraItems);
        }
        public function removeItem(Item $item): void {
            if (!$this->locked) { throw new RuntimeException('Deletion outside lock'); }
            $this->calls++;
            $this->db->events[] = 'delete';
            if (!$this->noop) {
                if ($item->getId() === 7) { $this->exists = false; }
                $this->extraItems = array_values(array_filter($this->extraItems,
                    fn ($other) => $other->getId() !== $item->getId()));
            }
            if ($this->fail) { throw new RuntimeException('Partial deletion'); }
        }
    }
    function expect(bool $condition): void {
        if (!$condition) { throw new RuntimeException('Assertion failed'); }
    }
    function refused(callable $call): void {
        try { $call(); } catch (RuntimeException $error) { return; }
        throw new RuntimeException('Expected refusal');
    }
    function fixture(): array {
        $db = new Database(); $groups = new Groups(); $session = new Session();
        $trash = new Trash($db); $audit = new Audit($db);
        $config = new Config();
        $service = new PurgeService($db, $groups, $session, new Users(), $trash, $audit, $config);
        return [$service, $db, $groups, $session, $trash, $audit, $config];
    }
    // The runner loads the production service before this block.
    $id = '12345678-1234-4234-8234-123456789abc';
    $checks = 0;
    foreach (['anonymous', 'nonadmin', 'reason', 'id', 'owner', 'transaction', 'audit-config', 'empty-etag', 'stale-etag'] as $case) {
        [$service, $db, $groups, $session, $trash, $audit, $config] = fixture();
        if ($case === 'audit-config') { $config->condition = []; }
        if ($case === 'anonymous') { $session->user = null; }
        if ($case === 'nonadmin') { $groups->admin = false; }
        if ($case === 'transaction') { $db->transaction = true; }
        refused(fn () => $service->purge($case === 'id' ? 'invalid' : $id,
            $case === 'owner' ? 'missing' : 'owner-fixture', 7, 100, $case === 'reason' ? ' ' : 'fixture reason',
            $case === 'empty-etag' ? '' : ($case === 'stale-etag' ? 'old-etag' : 'fixture-etag')));
        expect($db->rows === [] && $audit->messages === [] && $trash->calls === 0);
        $checks++;
    }
    [$service, $db, $groups, $session, $trash, $audit] = fixture();
    expect($service->purge($id, 'owner-fixture', 7, 100, 'fixture reason', 'fixture-etag') === 'completed');
    expect($db->events === ['intent', 'audit', 'delete', 'completed']);
    expect($service->purge($id, 'owner-fixture', 7, 100, 'fixture reason', 'fixture-etag') === 'completed');
    expect($trash->calls === 1 && count($audit->messages) === 1 && count($db->rows) === 1);
    $record = json_decode(substr($audit->messages[0], strlen('Retention early purge requested: ')), true, flags: JSON_THROW_ON_ERROR);
    expect($record['actor'] === 'admin-fixture' && $record['owner'] === 'owner-fixture'
        && $record['path'] === '/fictional/document.txt' && $record['reason'] === 'fixture reason'
        && is_int($record['requested_at']) && $record['operation_id'] === $id);
    refused(fn () => $service->purge($id, 'owner-fixture', 7, 100, 'different reason', 'fixture-etag'));
    expect($trash->calls === 1);
    $checks++;
    foreach (['insert', 'audit', 'backend', 'noop', 'update'] as $case) {
        [$service, $db, $groups, $session, $trash, $audit] = fixture();
        if ($case === 'insert') { $db->failInsert = true; }
        if ($case === 'audit') { $audit->fail = true; }
        if ($case === 'backend') { $trash->fail = true; }
        if ($case === 'noop') { $trash->noop = true; }
        if ($case === 'update') { $db->failUpdate = true; }
        refused(fn () => $service->purge($id, 'owner-fixture', 7, 100, 'fixture reason', 'fixture-etag'));
        if ($case === 'insert') {
            expect($db->rows === [] && $trash->calls === 0 && $audit->messages === []);
        } else {
            $state = in_array($case, ['backend', 'noop'], true) ? 'uncertain' : 'pending';
            expect($db->rows[$id]['state'] === $state);
            $calls = $trash->calls;
            expect($service->purge($id, 'owner-fixture', 7, 100, 'fixture reason', 'fixture-etag') === $state);
            expect($trash->calls === $calls);
            if ($case === 'audit') { expect($calls === 0); }
        }
        $checks++;
    }
    foreach (['stale', 'ambiguous', 'same-name'] as $case) {
        [$service, $db, $groups, $session, $trash, $audit] = fixture();
        if ($case === 'ambiguous') { $trash->extraItems = [new Item()]; }
        if ($case === 'same-name') {
            $trash->extraItems = [new Item(8)];
            expect($service->purge($id, 'owner-fixture', 8, 100, 'fixture reason', 'fixture-etag') === 'completed');
            expect($trash->exists && $trash->extraItems === [] && $trash->calls === 1);
            refused(fn () => $service->purge($id, 'owner-fixture', 7, 100, 'fixture reason', 'fixture-etag'));
            expect($trash->exists && $trash->calls === 1);
        } else {
            refused(fn () => $service->purge($id, 'owner-fixture', 7,
                $case === 'stale' ? 101 : 100, 'fixture reason', 'fixture-etag'));
            expect($db->rows === [] && $trash->calls === 0 && $audit->messages === []);
        }
        $checks++;
    }
    foreach (['anonymous', 'nonadmin'] as $case) {
        [$service, $db, $groups, $session, $trash] = fixture();
        if ($case === 'anonymous') { $session->user = null; }
        else { $groups->admin = false; }
        refused(fn () => $service->listTrash('owner-fixture'));
        refused(fn () => $service->operation($id));
        expect($db->rows === [] && $trash->calls === 0);
        $checks++;
    }
    [$service, $db, $groups, $session, $trash] = fixture();
    expect($service->listTrash('owner-fixture') === [[
        'trashId' => 7, 'deletedAt' => 100, 'expectedEtag' => 'fixture-etag',
        'name' => 'fixture-file', 'path' => '/fictional/document.txt',
    ]]);
    refused(fn () => $service->listTrash('missing'));
    refused(fn () => $service->operation($id));
    expect($db->rows === [] && $trash->calls === 0);
    $checks++;
    foreach (['completed', 'pending'] as $state) {
        [$service, $db, $groups, $session, $trash, $audit] = fixture();
        $audit->fail = $state === 'pending';
        if ($audit->fail) {
            refused(fn () => $service->purge($id, 'owner-fixture', 7, 100, 'fixture reason', 'fixture-etag'));
        } else {
            $service->purge($id, 'owner-fixture', 7, 100, 'fixture reason', 'fixture-etag');
        }
        $events = $db->events;
        $calls = $trash->calls;
        $record = $service->operation($id);
        expect($record['state'] === $state && $record['actor_uid'] === 'admin-fixture'
            && $record['reason'] === 'fixture reason' && !isset($record['request_hash']));
        expect($db->events === $events && $trash->calls === $calls);
        $checks++;
    }
    echo json_encode(['status' => 'passed', 'cases' => $checks]) . "\n";
}
