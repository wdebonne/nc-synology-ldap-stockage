<?php
declare(strict_types=1);

namespace OCA\SynoLDAP\Listener;

use OCA\SynoLDAP\Service\GroupSyncService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IConfig;
use OCP\User\Events\PostLoginEvent;
use Psr\Log\LoggerInterface;

class UserLoggedInListener implements IEventListener {
    public function __construct(
        private GroupSyncService $groupSyncService,
        private LoggerInterface $logger,
        private IConfig $config,
    ) {}

    public function handle(Event $event): void {
        if (!($event instanceof PostLoginEvent)) {
            return;
        }

        $user = $event->getUser();
        $uid  = $user->getUID();

        // Ne synchroniser que les utilisateurs authentifiés par le backend SynoLDAP.
        // Sans ce garde-fou, les comptes NC natifs (ex. 'admin') passent ici aussi :
        // getUserGroups() retourne [] → syncAdminStatus() les retire du groupe admin NC.
        // user_ldap protège ses utilisateurs natifs via Group_Proxy qui ignore les non-LDAP.
        // Ici on utilise la préférence persistante 'known' posée par checkPassword().
        if ($this->config->getUserValue($uid, 'synoldap', 'known', '0') !== '1') {
            return;
        }

        try {
            $this->groupSyncService->syncUser($user);
        } catch (\Throwable $e) {
            $this->logger->error('[SynoLDAP] Échec de la synchronisation pour ' . $uid, [
                'exception' => $e,
            ]);
        }
    }
}
