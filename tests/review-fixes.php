<?php
namespace RetentionReviewFixes;
class Config {
    public function __construct(public bool $allowed) {}
    public function getSystemValueBool($key, $default) { return $this->allowed; }
}
class Server {
    public static Config $config;
    public static function get($name) { return $name === \OCP\IConfig::class ? self::$config : new \stdClass(); }
}
interface IUser {}
class User_Proxy {}
class User implements IUser {
    public static bool $result = false;
    public static int $deletes = 0;
    public function __construct(...$arguments) {}
    public function delete() { self::$deletes++; return self::$result; }
    public function getBackend() { return new User_Proxy(); }
}
class PartiallyDeletedUsersBackend {
    public function __construct($config) {}
    public function getUsers() { return ['fixture']; }
    public function unmarkUser($id) { throw new \RuntimeException('Unexpected unmark'); }
}
class Manager {
    public int $lookups = 0;
    public function userExists($id) { return false; }
    public function get($id) { $this->lookups++; return new User(); }
}
class Logger {
    public array $messages = [];
    public function info($message, $context = []) { $this->messages[] = $message; }
    public function warning(...$arguments) { throw new \RuntimeException('Unexpected warning'); }
}
class Marks {
    public int $count = 0;
    public function markUser($uid) { $this->count++; }
}
class Plugins {
    public bool $suppressed = false;
    public int $calls = 0;
    public function setSuppressDeletion($value) {
        $before = $this->suppressed; $this->suppressed = $value; $this->calls++; return $before;
    }
}
// JOB_CLASS
// LDAP_CLASS
$checks = 0;
function check($condition): void {
    global $checks;
    if (!$condition) { throw new \RuntimeException('Focused assertion failed'); }
    $checks++;
}
foreach ([false, true] as $allowed) {
    User::$result = $allowed; User::$deletes = 0;
    $job = new Job();
    $job->config = new Config($allowed);
    $job->userManager = new Manager();
    $job->logger = new Logger();
    $job->run(null);
    check(User::$deletes === 1);
    check(count($job->logger->messages) === 1);
    check(str_starts_with($job->logger->messages[0], $allowed ? 'Cleaned up' : 'Skipping deleted'));
    Server::$config = new Config($allowed);
    $command = new Reset();
    $command->userManager = new Manager(); $command->dui = new Marks(); $command->pluginManager = new Plugins();
    $input = new \Symfony\Component\Console\Input\ArrayInput(['uid' => 'fixture', '--yes' => true],
        new \Symfony\Component\Console\Input\InputDefinition([
            new \Symfony\Component\Console\Input\InputArgument('uid'),
            new \Symfony\Component\Console\Input\InputOption('yes', null, \Symfony\Component\Console\Input\InputOption::VALUE_NONE),
        ]));
    $output = new \Symfony\Component\Console\Output\BufferedOutput();
    $before = User::$deletes;
    check($command->execute($input, $output) === ($allowed ? 0 : 1));
    check($command->dui->count === ($allowed ? 1 : 0));
    check(User::$deletes - $before === ($allowed ? 1 : 0));
    check($command->pluginManager->suppressed === false);
    check($command->pluginManager->calls === ($allowed ? 2 : 0));
    check($allowed || str_contains($output->fetch(), 'retention policy'));
}
echo json_encode(['status' => 'passed', 'checks' => $checks]);
