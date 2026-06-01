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
        private StorageConfigService $storageConfigService,
        private SynologyApiService $synoApiService,
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

    /** Retourne les entrées auto (mode nom ou ACL) depuis la configuration. */
    private function getAutoEntries(): array {
        $entries = [];
        foreach ($this->getMappings() as $m) {
            if (!empty($m['auto_mode']) && !empty($m['storage_share'])) {
                $entries[] = $m;
            }
        }
        return $entries;
    }

    /**
     * Synchronise le profil (displayName, email) et les groupes Nextcloud d'un utilisateur
     * d'après l'AD au moment de la connexion.
     */
    public function syncUser(IUser $user): void {
        $uid = $user->getUID();

        // Mettre à jour le nom complet et l'email depuis l'AD
        $this->syncProfile($user);

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

    /**
     * Pousse le displayName et l'email depuis l'AD vers le compte Nextcloud.
     * Appelé à chaque connexion pour maintenir le profil NC à jour.
     */
    private function syncProfile(IUser $user): void {
        $uid = $user->getUID();
        try {
            $info = $this->ldapService->getUserInfo($uid);
            if ($info === null) {
                return;
            }
            if (!empty($info['displayName']) && $info['displayName'] !== $uid) {
                $user->setDisplayName($info['displayName']);
            }
            if (!empty($info['email'])) {
                $user->setEMailAddress($info['email']);
            }
        } catch (\Throwable $e) {
            $this->logger->warning("[SynoLDAP] Impossible de synchroniser le profil pour {$uid}: " . $e->getMessage());
        }
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
            $admins = $adminNcGroup->getUsers();
            if (count($admins) > 1) {
                $adminNcGroup->removeUser($user);
                $this->logger->info("[SynoLDAP] {$user->getUID()} retiré des administrateurs Nextcloud");
            }
        }
    }

    private function syncGroupMemberships(IUser $user, array $ldapGroups): void {
        $mappings     = $this->getMappings();
        $autoEntries  = $this->getAutoEntries();

        // ── Mappings manuels ──────────────────────────────────────────────────
        foreach ($mappings as $mapping) {
            if (!empty($mapping['auto_mode'])) {
                continue;
            }

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
                    $this->logger->info("[SynoLDAP] Groupe NC créé: {$ncGroupName}");
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

        // ── Entrées auto ──────────────────────────────────────────────────────
        if (!empty($autoEntries)) {
            $this->syncAutoEntries($user, $ldapGroups, $autoEntries);
        }
    }

    /**
     * Pour chaque entrée auto, détermine les dossiers accessibles à l'utilisateur
     * (par nom de groupe ou par ACL Synology) et crée les montages correspondants.
     *
     * Comportement transparent Windows / Nextcloud :
     *  - le préfixe de montage (ex. "NAS") reproduit la racine du lecteur réseau
     *  - l'utilisateur voit /NAS/Compta/... exactement comme sur son PC
     */
    private function syncAutoEntries(IUser $user, array $ldapGroups, array $autoEntries): void {
        // Groupes NC gérés manuellement (ne pas toucher en mode auto)
        $manualNcGroups = [];
        foreach ($this->getMappings() as $m) {
            if (empty($m['auto_mode'])) {
                $ng = trim($m['nc_group'] ?? ($m['ldap_group'] ?? ''));
                if ($ng !== '') {
                    $manualNcGroups[] = $ng;
                }
            }
        }

        foreach ($autoEntries as $entry) {
            $rootShare = trim($entry['storage_share'] ?? '');
            $prefix    = trim($entry['mount_prefix'] ?? '');
            $isAcl     = ($entry['auto_mode'] === 'acl');

            if ($isAcl) {
                $this->syncAclEntry($user, $ldapGroups, $rootShare, $prefix, $manualNcGroups);
            } else {
                $this->syncNameEntry($user, $ldapGroups, $rootShare, $prefix, $manualNcGroups);
            }
        }
    }

    /**
     * Mode ACL : interroge Synology pour savoir quels dossiers l'utilisateur peut voir.
     * Aurélie (groupe Responsable) → ACL Compta contient Responsable → montage /NAS/Compta.
     */
    private function syncAclEntry(IUser $user, array $ldapGroups, string $rootShare, string $prefix, array $manualNcGroups): void {
        try {
            $aclMappings = $this->synoApiService->discoverAclMappings($rootShare);
        } catch (\Throwable $e) {
            $this->logger->warning("[SynoLDAP] ACL discovery '{$rootShare}': " . $e->getMessage());
            return;
        }

        foreach ($aclMappings as $folderName => $aclGroups) {
            foreach ($ldapGroups as $ldapGroup) {
                if (!in_array($ldapGroup, $aclGroups, true)) {
                    continue;
                }
                if (in_array($ldapGroup, $manualNcGroups, true)) {
                    continue;
                }

                $this->ensureNcGroupMember($user, $ldapGroup);
                $this->storageConfigService->ensureGroupMount($ldapGroup, $rootShare, $folderName, $prefix);
                break; // un seul montage par dossier même si plusieurs groupes de l'user y ont accès
            }
        }
    }

    /**
     * Mode nom : nom du groupe AD = nom du sous-dossier sur Synology.
     */
    private function syncNameEntry(IUser $user, array $ldapGroups, string $rootShare, string $prefix, array $manualNcGroups): void {
        foreach ($ldapGroups as $ldapGroup) {
            if (in_array($ldapGroup, ['admin', 'disabled'], true)) {
                continue;
            }
            if (in_array($ldapGroup, $manualNcGroups, true)) {
                continue;
            }

            $this->ensureNcGroupMember($user, $ldapGroup);
            $this->storageConfigService->ensureGroupMount($ldapGroup, $rootShare, $ldapGroup, $prefix);
        }
    }

    private function ensureNcGroupMember(IUser $user, string $groupName): void {
        $ncGroup = $this->groupManager->get($groupName);
        if (!$ncGroup) {
            $ncGroup = $this->groupManager->createGroup($groupName);
            $this->logger->info("[SynoLDAP] Groupe auto créé: {$groupName}");
        }
        if ($ncGroup && !$ncGroup->inGroup($user)) {
            $ncGroup->addUser($user);
            $this->logger->info("[SynoLDAP] {$user->getUID()} ajouté au groupe auto {$groupName}");
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
