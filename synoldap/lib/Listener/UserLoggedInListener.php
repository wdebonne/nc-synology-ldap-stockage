<?php
declare(strict_types=1);

namespace OCA\SynoLDAP\Listener;

use OCA\SynoLDAP\Service\GroupSyncService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\User\Events\PostLoginEvent;
use Psr\Log\LoggerInterface;

/**
 * Synchronise les groupes et montages SMB pour les utilisateurs SynoLDAP.
 *
 * Filtre : getBackendClassName() === 'SynoLDAP'
 * → Les comptes NC natifs (backend 'Database') sont ignorés.
 * → Aucune manipulation de session nécessaire : le mapping oc_synoldap_users
 *   garantit que userExists() ne fait plus d'appels LDAP pour les utilisateurs
 *   connus, ce qui élimine les dirty table reads et les problèmes de session NC 33.
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

        if ($user->getBackendClassName() !== 'SynoLDAP') {
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
