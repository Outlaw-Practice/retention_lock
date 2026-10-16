<?php
class ForcedRollbackController extends OCA\Provisioning_API\Controller\QualifiedRollbackController {
    public function editUser(string $userId, string $key, string $value): OCP\AppFramework\Http\DataResponse {
        throw new OCP\AppFramework\OCS\OCSException('Fixture display-name failure', 103);
    }
}
$phase = 'bootstrap';
$checks = 0;
$uid = 'n5-retention-rollback-fixture';
$users = OCP\Server::get(OCP\IUserManager::class);
$created = false;
$system = OCP\Server::get(OC\SystemConfig::class);
$property = new ReflectionProperty(OC\SystemConfig::class, 'config');
$config = $property->getValue($system);
// Override only this PHP process for the baseline and exact-fixture teardown.
// Never write the shared configuration or relax another worker's policy.
$override = Closure::bind(function (?bool $value): void {
    if ($value === null) { unset($this->envCache['files.trash.delete']); }
    else { $this->envCache['files.trash.delete'] = $value; }
}, $config, OC\Config::class);
$cache = (new ReflectionProperty(OC\Config::class, 'envCache'))->getValue($config);
$previous = $cache['files.trash.delete'] ?? null;
try {
    if ($users->userExists($uid)) { throw new RuntimeException('Existing fixture'); }
    OCP\Server::get(OCP\IUserSession::class)->setUser($users->get('admin'));
    $app = new OCP\AppFramework\App('provisioning_api');
    $parameters = (new ReflectionMethod(ForcedRollbackController::class, '__construct'))->getParameters();
    $controller = new ForcedRollbackController(...array_map(
        fn ($parameter) => $parameter->getName() === 'appName' ? 'provisioning_api'
            : $app->getContainer()->get($parameter->getType()->getName()), $parameters));
    foreach ([true, false] as $deletionAllowed) {
        $phase = $deletionAllowed ? 'ordinary-rollback' : 'retained-rollback';
        $override($deletionAllowed);
        $created = true;
        try {
            $controller->addUser($uid, bin2hex(random_bytes(32)), 'Fixture');
            throw new RuntimeException('Expected provisioning failure');
        } catch (OCP\AppFramework\OCS\OCSException $error) {
            if ($error->getCode() !== 103) { throw $error; }
            $checks++;
        }
        $user = $users->get($uid);
        if ($deletionAllowed ? $user !== null : ($user === null || $user->isEnabled())) {
            throw new RuntimeException('Unsafe rollback outcome');
        }
        $checks++;
        if ($user !== null) {
            $override(true);
            if (!$user->delete()) { throw new RuntimeException('Fixture cleanup refused'); }
        }
        $created = false;
    }
    echo json_encode(['status' => 'passed', 'checks' => $checks]);
} catch (Throwable $error) {
    echo json_encode(['status' => 'failed', 'phase' => $phase, 'checks' => $checks,
        'error_class' => get_class($error), 'error_code' => $error->getCode(), 'error_line' => $error->getLine()]);
} finally {
    if ($created && ($user = $users->get($uid)) !== null) {
        $override(true);
        $user->delete();
    }
    $override($previous);
}
