<?php
declare(strict_types=1);

namespace OCA\SynoLDAP\Listener;

use OCA\SynoLDAP\Service\GroupSyncService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\User\Events\PostLoginEvent;
use Psr\Log\LoggerInterface;

/**
 * Synchronise les groupes et montages SMB pour les utilisateurs LDAP Synology.
 *
 * Filtre : getBackendClassName() === 'LDAP'
 *   → user_ldap retourne 'LDAP' depuis getBackendName()
 *   → Les comptes NC natifs (admin, etc.) ont le backend 'Database' → ignorés
 *   → Aucune manipulation de session (user_ldap gère DAV_AUTHENTICATED correctement)
 */
class UserLoggedInListener implements IEventListener {
    public function __construct(
        private GroupSyncService $groupSyncService,
        private LoggerInterface $logger,
    ) {}

    public function handle(Event $event): void {
        if (!($event instanceof PostLoginEvent)) {
            return;
        }

        $user = $event->getUser();

        // Ne traiter que les utilisateurs authentifiés par user_ldap.
        // user_ldap::User_LDAP::getBackendName() retourne 'LDAP'.
        // Tous les autres backends (Database, SynoLDAP si encore chargé, etc.) sont ignorés.
        if ($user->getBackendClassName() !== 'LDAP') {
            return;
        }

        try {
            $this->groupSyncService->syncUser($user);
        } catch (\Throwable $e) {
            $this->logger->error('[SynoLDAP] Échec de la synchronisation pour ' . $user->getUID(), [
                'exception' => $e,
            ]);
        }
    }
}
