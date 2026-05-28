<?php
declare(strict_types=1);

namespace OCA\SynoLDAP\Settings;

use OCP\AppFramework\Http\TemplateResponse;
use OCP\IConfig;
use OCP\Settings\ISettings;

class AdminSettings implements ISettings {
    private const APP_ID = 'synoldap';

    public function __construct(
        private IConfig $config,
    ) {}

    public function getForm(): TemplateResponse {
        return new TemplateResponse(self::APP_ID, 'admin', [], 'blank');
    }

    public function getSection(): string {
        return 'synoldap';
    }

    public function getPriority(): int {
        return 10;
    }
}
