<?php
declare(strict_types=1);

namespace OCA\SynoLDAP\Listener;

use OCA\SynoLDAP\Service\GroupSyncService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\User\Events\PostLoginEvent;
use Psr\Log\LoggerInterface;

class UserLoggedInListener implements IEventListener {
    public function __construct(
        private GroupSyncService $groupSyncService,
        private LoggerInterface $logger,
    ) {}

    public function handle(Event $event): void {
        if (!($event instanceof PostLoginEvent)) {
            return;
        }

        // TEST DIAGNOSTIC : PostLoginEvent désactivé pour isoler le problème "Se souvenir de moi"
        // Retirer ce return pour réactiver la synchronisation des groupes au login.
        return;

        $user = $event->getUser();
        try {
            $this->groupSyncService->syncUser($user);
        } catch (\Throwable $e) {
            $this->logger->error('[SynoLDAP] Échec de la synchronisation pour ' . $user->getUID(), [
                'exception' => $e,
            ]);
        }
    }
}
