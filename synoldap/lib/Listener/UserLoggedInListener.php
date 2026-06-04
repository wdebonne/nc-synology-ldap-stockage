<?php
declare(strict_types=1);

namespace OCA\SynoLDAP\Listener;

use OCA\SynoLDAP\Service\GroupSyncService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\User\Events\PostLoginEvent;
use Psr\Log\LoggerInterface;

/**
 * Synchronise les groupes AD → NC et les montages SMB pour les utilisateurs LDAP.
 *
 * Filtre : getBackendClassName() === 'LDAP'
 * → user_ldap retourne 'LDAP' depuis User_LDAP::getBackendName()
 * → Les comptes NC natifs (backend 'Database') sont ignorés
 * → Plus aucune manipulation de session — user_ldap gère DAV_AUTHENTICATED
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

        // Seuls les utilisateurs authentifiés par user_ldap (backend 'LDAP')
        if ($user->getBackendClassName() !== 'LDAP') {
            return;
        }

        try {
            $this->groupSyncService->syncUser($user);
        } catch (\Throwable $e) {
            $this->logger->error('[SynoLDAP] Échec sync pour ' . $user->getUID(), [
                'exception' => $e,
            ]);
        }
    }
}
