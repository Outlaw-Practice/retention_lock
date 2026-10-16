<?php
declare(strict_types=1);

namespace OCA\RetentionLock\AppInfo;

use OCP\AppFramework\App;

class Application extends App {
    public const APP_ID = 'retention_lock';

    public function __construct(array $urlParams = []) {
        parent::__construct(self::APP_ID, $urlParams);
    }
}
