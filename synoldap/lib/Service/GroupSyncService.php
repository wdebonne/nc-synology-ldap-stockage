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
     * Retourne le groupe Nextcloud correspondant à un nom, en réutilisant un groupe
     * existant plutôt qu'en créant un doublon.
     *
     * Cas critique : user_ldap expose un groupe AD avec un GID suffixé ("Nom_2")
     * lorsque le GID "Nom" est déjà pris. Une recherche par displayName permet de
     * réutiliser ce groupe LDAP au lieu de créer un groupe base de données "Nom"
     * (ce qui provoquait des doublons impossibles à purger).
     */
    private function resolveNcGroup(string $name): ?\OCP\IGroup {
        $group = $this->groupManager->get($name);
        if ($group !== null) {
            return $group;
        }

        // Réutiliser un groupe existant de même displayName (privilégier le backend LDAP).
        $fallback = null;
        foreach ($this->groupManager->search($name) as $candidate) {
            if ($candidate->getDisplayName() !== $name) {
                continue;
            }
            if ($this->isLdapBackedGroup($candidate)) {
                return $candidate;
            }
            $fallback ??= $candidate;
        }
        if ($fallback !== null) {
            return $fallback;
        }

        $group = $this->groupManager->createGroup($name);
        if ($group !== null) {
            $this->logger->info("[SynoLDAP] Groupe NC créé: {$name}");
        }
        return $group;
    }

    private function isLdapBackedGroup(\OCP\IGroup $group): bool {
        foreach ($group->getBackendNames() as $backend) {
            if (stripos($backend, 'ldap') !== false) {
                return true;
            }
        }
        return false;
    }

    /**
     * Ajoute l'utilisateur au groupe uniquement si le backend l'autorise.
     * Un groupe LDAP refuse addUser (l'appartenance vient de l'AD) — on l'ignore alors
     * silencieusement pour éviter l'exception "Could not add user to group in LDAP backend".
     */
    private function addUserSafely(\OCP\IGroup $group, IUser $user): bool {
        if ($group->inGroup($user) || !$group->canAddUser()) {
            return false;
        }
        $group->addUser($user);
        return true;
    }

    private function removeUserSafely(\OCP\IGroup $group, IUser $user): bool {
        if (!$group->inGroup($user) || !$group->canRemoveUser()) {
            return false;
        }
        $group->removeUser($user);
        return true;
    }

    /**
     * Synchronise le profil (displayName, email) et les groupes Nextcloud d'un utilisateur
     * d'après l'AD au moment de la connexion.
     */
    /**
     * Synchronise uniquement les groupes à la connexion.
     * syncProfile() est volontairement absent ici : appeler setDisplayName/setEMailAddress
     * pendant le PostLoginEvent interfère avec la création du token "Se souvenir de moi" en NC 33.
     * La synchronisation du profil se fait via syncAllUsers() (bouton admin).
     */
    public function syncUser(IUser $user): void {
        $uid = $user->getUID();

        try {
            $ldapGroups = $this->ldapService->getUserGroups($uid);
            // Mise en cache persistante des groupes dans oc_preferences.
            // Si LDAP est indisponible au prochain login, on utilisera cette liste
            // pour maintenir les groupes NC et les montages SMB existants.
            $this->config->setUserValue($uid, self::APP_ID, 'last_groups', json_encode($ldapGroups));
        } catch (\Throwable $e) {
            $this->logger->warning("[SynoLDAP] Groupes LDAP inaccessibles pour {$uid}: " . $e->getMessage());
            // Fallback : utiliser les groupes du dernier sync réussi.
            // Sans ce fallback, une indisponibilité LDAP au moment du PostLoginEvent
            // supprimerait tous les montages SMB et l'utilisateur verrait "aucun fichier".
            $cached = $this->config->getUserValue($uid, self::APP_ID, 'last_groups', '');
            if ($cached === '') {
                $this->logger->warning("[SynoLDAP] Aucun groupe en cache pour {$uid} — sync abandonnée");
                return;
            }
            $ldapGroups = json_decode($cached, true) ?? [];
            $this->logger->info("[SynoLDAP] {$uid} → groupes depuis cache (LDAP indisponible): " . implode(', ', $ldapGroups));
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
                $this->logger->warning("[SynoLDAP] syncProfile: {$uid} introuvable dans l'AD");
                return;
            }
            if (!empty($info['displayName']) && $info['displayName'] !== $uid) {
                $user->setDisplayName($info['displayName']);
                $this->logger->info("[SynoLDAP] syncProfile: {$uid} → displayName = \"{$info['displayName']}\"");
            }
            if (!empty($info['email'])) {
                $user->setEMailAddress($info['email']);
                $this->logger->info("[SynoLDAP] syncProfile: {$uid} → email = {$info['email']}");
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
        $mappings    = $this->getMappings();
        $autoEntries = $this->getAutoEntries();

        // Groupes AD couverts par un mapping manuel (pour éviter les doublons)
        $mappedLdapGroups = [];

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

            $mappedLdapGroups[] = $ldapGroupName;
            $inLdapGroup = in_array($ldapGroupName, $ldapGroups, true);

            if ($inLdapGroup) {
                $ncGroup = $this->resolveNcGroup($ncGroupName);
                if ($ncGroup && $this->addUserSafely($ncGroup, $user)) {
                    $this->logger->info("[SynoLDAP] {$user->getUID()} ajouté au groupe {$ncGroupName}");
                }
            } else {
                $ncGroup = $this->groupManager->get($ncGroupName);
                if ($ncGroup && $this->removeUserSafely($ncGroup, $user)) {
                    $this->logger->info("[SynoLDAP] {$user->getUID()} retiré du groupe {$ncGroupName}");
                }
            }
        }

        // ── Sync directe : groupes AD → groupes NC du même nom ───────────────
        // Tout groupe AD non couvert par un mapping manuel est automatiquement
        // reflété comme groupe Nextcloud (création si besoin).
        // Le retrait n'est pas effectué ici (évite N connexions LDAP au login) ;
        // utiliser "Synchronisation de tous les utilisateurs" pour nettoyer.
        $uid = $user->getUID();
        foreach ($ldapGroups as $ldapGroup) {
            if (in_array($ldapGroup, $mappedLdapGroups, true)) {
                continue; // déjà géré par mapping manuel
            }
            $ncGroup = $this->resolveNcGroup($ldapGroup);
            if ($ncGroup && $this->addUserSafely($ncGroup, $user)) {
                $this->logger->info("[SynoLDAP] {$uid} ajouté au groupe AD direct: {$ldapGroup}");
            }
        }

        // ── Entrées auto (SMB) ────────────────────────────────────────────────
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

                $ncGroup = $this->ensureNcGroupMember($user, $ldapGroup);
                $mountGid = $ncGroup ? $ncGroup->getGID() : $ldapGroup;
                $this->storageConfigService->ensureGroupMount($mountGid, $rootShare, $folderName, $prefix);
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

            $ncGroup = $this->ensureNcGroupMember($user, $ldapGroup);
            $mountGid = $ncGroup ? $ncGroup->getGID() : $ldapGroup;
            $this->storageConfigService->ensureGroupMount($mountGid, $rootShare, $ldapGroup, $prefix);
        }
    }

    private function ensureNcGroupMember(IUser $user, string $groupName): ?\OCP\IGroup {
        $ncGroup = $this->resolveNcGroup($groupName);
        if ($ncGroup && $this->addUserSafely($ncGroup, $user)) {
            $this->logger->info("[SynoLDAP] {$user->getUID()} ajouté au groupe auto {$groupName}");
        }
        return $ncGroup;
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
                // syncAllUsers met à jour profil + groupes (sans contrainte de timing)
                $this->syncProfile($user);
                $this->syncUser($user);
                $results['synced']++;
            } catch (\Throwable $e) {
                $results['errors'][] = "{$uid}: " . $e->getMessage();
            }
        }

        return $results;
    }
}
