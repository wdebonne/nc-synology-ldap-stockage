<?php
declare(strict_types=1);

namespace OCA\SynoLDAP\Settings;

use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\Settings\IIconSection;

class AdminSection implements IIconSection {
    public function __construct(
        private IURLGenerator $urlGenerator,
        private IL10N $l,
    ) {}

    public function getID(): string {
        return 'synoldap';
    }

    public function getName(): string {
        return 'Synology LDAP';
    }

    public function getPriority(): int {
        return 75;
    }

    public function getIcon(): string {
        return $this->urlGenerator->imagePath('synoldap', 'app.svg');
    }
}
