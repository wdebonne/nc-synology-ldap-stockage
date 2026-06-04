<?php
declare(strict_types=1);

namespace OCA\SynoLDAP\AppInfo;

use OCA\SynoLDAP\Listener\UserLoggedInListener;
use OCA\SynoLDAP\Service\UserLdapBridgeService;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\User\Events\PostLoginEvent;

/**
 * Application synoldap — s'appuie sur user_ldap pour l'authentification.
 *
 * Architecture définitive :
 *  • user_ldap gère l'authentification LDAP (éprouvé, stable, compatible NC 33).
 *    DAV_AUTHENTICATED, sessions, "Se souvenir de moi" : tout géré par user_ldap.
 *  • synoldap configure user_ldap automatiquement depuis son propre panneau admin
 *    via UserLdapBridgeService → l'admin ne configure qu'un seul endroit.
 *  • UserLoggedInListener intercepte les logins des utilisateurs LDAP (backend 'LDAP')
 *    et gère la synchronisation des groupes AD → NC + création des montages SMB.
 */
class Application extends App implements IBootstrap {
    public const APP_ID = 'synoldap';

    public function __construct() {
        parent::__construct(self::APP_ID);
    }

    public function register(IRegistrationContext $context): void {
        $context->registerEventListener(PostLoginEvent::class, UserLoggedInListener::class);
    }

    public function boot(IBootContext $context): void {
        // Synchroniser la config LDAP de synoldap vers user_ldap à chaque boot.
        // IConfig::setAppValue() n'écrit en DB que si la valeur a changé → pas coûteux.
        $context->injectFn(function (UserLdapBridgeService $bridge): void {
            $bridge->sync();
        });
    }
}
