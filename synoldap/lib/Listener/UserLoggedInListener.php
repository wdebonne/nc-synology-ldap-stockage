<?php
declare(strict_types=1);

namespace OCA\SynoLDAP\Listener;

use OCA\SynoLDAP\Service\GroupSyncService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\ISession;
use OCP\User\Events\PostLoginEvent;
use Psr\Log\LoggerInterface;

/**
 * Gère le PostLoginEvent pour les utilisateurs SynoLDAP.
 *
 * Fix NC 33 — "Cannot authenticate over ajax calls" sur PROPFIND :
 * NC 33 régénère l'ID de session au login mais COPIE les données de l'ancienne
 * session, dont `DAV_AUTHENTICATED` ('AUTHENTICATED_TO_DAV_BACKEND'). Si cette
 * clé contient l'UID d'un utilisateur précédent, les deux conditions de
 * `OCA\DAV\Connector\Sabre\Auth::auth()` échouent pour le nouvel utilisateur :
 *   1. is_null(DAV_AUTHENTICATED) = false  (n'est pas null)
 *   2. DAV_AUTHENTICATED === e.berthy = false  (c'est l'ancien UID)
 * → parent::check() (Basic Auth) échoue → AJAX check → 401.
 *
 * Correction : supprimer `DAV_AUTHENTICATED` de la session au PostLoginEvent.
 * Cela force la condition 1 à être vraie dès le prochain PROPFIND.
 */
class UserLoggedInListener implements IEventListener {
    // Même constante que OCA\DAV\Connector\Sabre\Auth::DAV_AUTHENTICATED
    private const DAV_AUTHENTICATED = 'AUTHENTICATED_TO_DAV_BACKEND';

    public function __construct(
        private GroupSyncService $groupSyncService,
        private LoggerInterface $logger,
        private ISession $session,
    ) {}

    public function handle(Event $event): void {
        if (!($event instanceof PostLoginEvent)) {
            return;
        }

        $user = $event->getUser();

        // Ne traiter que les utilisateurs authentifiés par le backend SynoLDAP.
        if ($user->getBackendClassName() !== 'SynoLDAP') {
            return;
        }

        $uid = $user->getUID();

        // ── Fix NC 33 : vider la valeur DAV_AUTHENTICATED héritée ────────────
        // NC copie les données de session lors de la régénération d'ID. Si
        // l'ancienne session avait DAV_AUTHENTICATED = 'admin', la nouvelle session
        // d'e.berthy l'hérite aussi, faisant échouer les checks DAV → 401.
        // Supprimer cette clé force NC à utiliser la condition 1 (is_null = true)
        // qui valide le PROPFIND uniquement avec le cookie de session.
        if ($this->session->get(self::DAV_AUTHENTICATED) !== null) {
            $this->logger->debug('[SynoLDAP] Suppression DAV_AUTHENTICATED hérité pour ' . $uid);
            $this->session->remove(self::DAV_AUTHENTICATED);
        }

        // ── Synchronisation des groupes et montages SMB ──────────────────────
        try {
            $this->groupSyncService->syncUser($user);
        } catch (\Throwable $e) {
            $this->logger->error('[SynoLDAP] Échec de la synchronisation pour ' . $uid, [
                'exception' => $e,
            ]);
        }
    }
}
