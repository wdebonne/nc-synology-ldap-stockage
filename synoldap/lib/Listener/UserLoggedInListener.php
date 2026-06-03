<?php
declare(strict_types=1);

namespace OCA\SynoLDAP\Listener;

use OCA\SynoLDAP\Service\GroupSyncService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\User\Events\PostLoginEvent;
use Psr\Log\LoggerInterface;

/**
 * Gère le PostLoginEvent pour les utilisateurs SynoLDAP.
 *
 * Filtre : getBackendClassName() === 'SynoLDAP'
 *   - Protège les utilisateurs NC natifs (admin, etc.) dont le backend est 'Database'
 *   - Fonctionne sans colonne oc_users.backend (PostgreSQL NC AIO)
 *
 * known=1 est déjà posé par checkPassword() — le listener se concentre sur la
 * synchronisation des groupes et des montages SMB.
 *
 * ensureUserRow() supprimé : oc_users n'a pas de colonne backend en PostgreSQL.
 * NC 33 détermine le backend en itérant userExists() sur tous les backends enregistrés.
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

        // Ne traiter que les utilisateurs authentifiés par le backend SynoLDAP.
        // getBackendClassName() retourne getBackendName() = 'SynoLDAP' pour notre backend.
        // Protège les comptes NC natifs : leur backend est 'Database', pas 'SynoLDAP'.
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
