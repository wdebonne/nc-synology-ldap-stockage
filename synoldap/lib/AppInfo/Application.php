<?php
declare(strict_types=1);

namespace OCA\SynoLDAP\AppInfo;

use OCA\SynoLDAP\Listener\UserLoggedInListener;
use OCA\SynoLDAP\UserBackend\LdapUserBackend;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\IUserManager;
use OCP\User\Events\PostLoginEvent;

class Application extends App implements IBootstrap {
    public const APP_ID = 'synoldap';

    public function __construct() {
        parent::__construct(self::APP_ID);
    }

    public function register(IRegistrationContext $context): void {
        $context->registerEventListener(PostLoginEvent::class, UserLoggedInListener::class);
    }

    public function boot(IBootContext $context): void {
        // Enregistre le backend LDAP Synology comme source d'authentification Nextcloud.
        // Les utilisateurs AD Synology peuvent se connecter sans app user_ldap externe.
        $context->injectFn(function (IUserManager $userManager, LdapUserBackend $backend): void {
            $userManager->registerBackend($backend);
        });
    }
}
