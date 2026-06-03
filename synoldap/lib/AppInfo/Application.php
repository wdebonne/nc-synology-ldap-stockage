<?php
declare(strict_types=1);

namespace OCA\SynoLDAP\AppInfo;

use OCA\SynoLDAP\Listener\UserLoggedInListener;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\User\Events\PostLoginEvent;

/**
 * Application synoldap — companion app pour user_ldap + Synology.
 *
 * Architecture v3.0 :
 *  • user_ldap gère ENTIÈREMENT l'authentification (éprouvé, stable, compatible NC 33).
 *    → Plus de LdapUserBackend, plus de gestion de session, plus de DAV_AUTHENTICATED.
 *  • synoldap écoute le PostLoginEvent et, pour les utilisateurs LDAP (backend 'LDAP'),
 *    synchronise les groupes AD → NC et crée/met à jour les montages SMB Synology.
 *
 * Prérequis : l'app user_ldap doit être installée et configurée pour pointer vers
 *             l'Active Directory Synology.
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
        // Rien à enregistrer au boot :
        // user_ldap fournit le backend utilisateur et de groupe.
        // synoldap n'enregistre plus de backend — il s'appuie sur user_ldap.
    }
}
