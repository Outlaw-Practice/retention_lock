<?php
declare(strict_types=1);

namespace OCA\RetentionLock\Settings;

use OCP\AppFramework\Http\TemplateResponse;
use OCP\Settings\ISettings;

class Admin implements ISettings {
    public function getForm(): TemplateResponse {
        return new TemplateResponse('retention_lock', 'admin', [], '');
    }
    public function getSection(): string { return 'server'; }
    public function getPriority(): int { return 80; }
}
