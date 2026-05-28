<?php
declare(strict_types=1);

namespace OCA\SynoLDAP\Service;

use OCP\IConfig;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;

class GroupSyncService {
    private const APP_ID = 'synoldap';

    public function __construct(
        private LdapService $ldapService,
        private IGroupManager $groupManager,
        private IUserManager $userManager,
        private IConfig $config,
        private LoggerInterface $logger,
    ) {}

    private function getMappings(): array {
        return json_decode(
            $this->config->getAppValue(self::APP_ID, 'group_mappings', '[]'),
            true
        ) ?? [];
    }

    private function getAdminLdapGroup(): string {
        return $this->config->getAppValue(self::APP_ID, 'admin_ldap_group', 'ADMIN_NEXTCLOUD');
    }

    /**
     * Synchronise les groupes Nextcloud et le statut admin d'un utilisateur
     * d'après ses groupes AD au moment de la connexion.
     */
    public function syncUser(IUser $user): void {
        $uid = $user->getUID();

        try {
            $ldapGroups = $this->ldapService->getUserGroups($uid);
        } catch (\Throwable $e) {
            $this->logger->warning("[SynoLDAP] Groupes LDAP inaccessibles pour {$uid}: " . $e->getMessage());
            return;
        }

        $this->logger->info("[SynoLDAP] {$uid} → groupes AD: " . implode(', ', $ldapGroups));

        $this->syncAdminStatus($user, $ldapGroups);
        $this->syncGroupMemberships($user, $ldapGroups);
    }

    private function syncAdminStatus(IUser $user, array $ldapGroups): void {
        $adminLdapGroup = $this->getAdminLdapGroup();
        if (empty($adminLdapGroup)) {
            return;
        }

        $adminNcGroup = $this->groupManager->get('admin');
        if (!$adminNcGroup) {
            return;
        }

        $shouldBeAdmin    = in_array($adminLdapGroup, $ldapGroups, true);
        $isCurrentlyAdmin = $adminNcGroup->inGroup($user);

        if ($shouldBeAdmin && !$isCurrentlyAdmin) {
            $adminNcGroup->addUser($user);
            $this->logger->info("[SynoLDAP] {$user->getUID()} promu administrateur Nextcloud");
        } elseif (!$shouldBeAdmin && $isCurrentlyAdmin) {
            // Garde-fou : ne jamais retirer le dernier admin
            $admins = $adminNcGroup->getUsers();
            if (count($admins) > 1) {
                $adminNcGroup->removeUser($user);
                $this->logger->info("[SynoLDAP] {$user->getUID()} retiré des administrateurs Nextcloud");
            }
        }
    }

    private function syncGroupMemberships(IUser $user, array $ldapGroups): void {
        $mappings = $this->getMappings();

        foreach ($mappings as $mapping) {
            $ldapGroupName = trim($mapping['ldap_group'] ?? '');
            $ncGroupName   = trim($mapping['nc_group'] ?? $ldapGroupName);

            if (empty($ldapGroupName) || empty($ncGroupName)) {
                continue;
            }

            $inLdapGroup = in_array($ldapGroupName, $ldapGroups, true);
            $ncGroup     = $this->groupManager->get($ncGroupName);

            if ($inLdapGroup) {
                if (!$ncGroup) {
                    $ncGroup = $this->groupManager->createGroup($ncGroupName);
                    $this->logger->info("[SynoLDAP] Groupe Nextcloud créé: {$ncGroupName}");
                }
                if ($ncGroup && !$ncGroup->inGroup($user)) {
                    $ncGroup->addUser($user);
                    $this->logger->info("[SynoLDAP] {$user->getUID()} ajouté au groupe {$ncGroupName}");
                }
            } else {
                if ($ncGroup && $ncGroup->inGroup($user)) {
                    $ncGroup->removeUser($user);
                    $this->logger->info("[SynoLDAP] {$user->getUID()} retiré du groupe {$ncGroupName}");
                }
            }
        }
    }

    /**
     * Synchronise tous les utilisateurs LDAP connus dans Nextcloud.
     */
    public function syncAllUsers(): array {
        $results = ['synced' => 0, 'skipped' => 0, 'errors' => []];

        try {
            $ldapUids = $this->ldapService->getAllUserUids();
        } catch (\Throwable $e) {
            return ['synced' => 0, 'skipped' => 0, 'errors' => [$e->getMessage()]];
        }

        foreach ($ldapUids as $uid) {
            $user = $this->userManager->get($uid);
            if (!$user) {
                $results['skipped']++;
                continue;
            }
            try {
                $this->syncUser($user);
                $results['synced']++;
            } catch (\Throwable $e) {
                $results['errors'][] = "{$uid}: " . $e->getMessage();
            }
        }

        return $results;
    }
}
