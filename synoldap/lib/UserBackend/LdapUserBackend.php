<?php
declare(strict_types=1);

namespace OCA\SynoLDAP\UserBackend;

use OCA\SynoLDAP\Service\LdapService;
use OC\User\Backend;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\User\Backend\ABackend;
use OCP\User\Backend\ICheckPasswordBackend;
use OCP\User\Backend\ICountUsersBackend;
use OCP\User\Backend\IGetDisplayNameBackend;
use OCP\User\Backend\IGetHomeBackend;
use OCP\User\Backend\IProvideEnabledStateBackend;
use OCP\UserInterface;
use Psr\Log\LoggerInterface;

/**
 * Backend utilisateur SynoLDAP — basé sur les patterns éprouvés de user_ldap.
 *
 * Différence fondamentale avec les versions précédentes :
 * userExists() consulte d'abord la table `oc_synoldap_users` (mapping persistant),
 * exactement comme user_ldap consulte `oc_ldap_user_mapping`.
 * → Aucun appel LDAP pour valider la session des utilisateurs connus.
 * → Aucune dépendance au cache Redis/APCu.
 * → Aucun problème de dirty table reads NC 33.
 *
 * implementsActions() utilise le bitmask manuel (comme user_ldap::User_LDAP),
 * pas les interfaces OCP — compatible NC 25 à 33.
 */
class LdapUserBackend extends ABackend implements
    UserInterface,
    ICheckPasswordBackend,
    IGetDisplayNameBackend,
    IGetHomeBackend,
    ICountUsersBackend,
    IProvideEnabledStateBackend
{
    private ICache $cache;

    public function __construct(
        private LdapService $ldapService,
        private LoggerInterface $logger,
        ICacheFactory $cacheFactory,
        private IConfig $config,
        private IDBConnection $db,
    ) {
        $this->cache = $cacheFactory->createDistributed('synoldap_');
    }

    // ─── Capacités déclarées (comme user_ldap::implementsActions) ────────────

    /**
     * Déclare les capacités avec le bitmask manuel de user_ldap.
     * ABackend::implementsActions() via interfaces ne suffit pas en NC 33 :
     * user_ldap surcharge cette méthode avec un bitmask explicite incluant
     * Backend::GET_HOME — sans ça, NC peut ne pas créer le home storage.
     */
    public function implementsActions($actions): bool {
        return (bool)(
            (Backend::CHECK_PASSWORD | Backend::GET_HOME | Backend::GET_DISPLAYNAME | Backend::COUNT_USERS)
            & $actions
        );
    }

    public function getBackendName(): string {
        return 'SynoLDAP';
    }

    // ─── Home directory ───────────────────────────────────────────────────────

    /**
     * Retourne le chemin home — déclaré via implementsActions(GET_HOME).
     * user_ldap retourne false si pas de règle home → NC utilise le chemin par défaut.
     * On fait pareil : retourne directement {datadirectory}/{uid}.
     */
    public function getHome(string $uid): string|false {
        if (!$this->userExists($uid)) {
            return false;
        }
        $dataDir = rtrim((string) $this->config->getSystemValue('datadirectory', ''), '/');
        return $dataDir !== '' ? $dataDir . '/' . $uid : false;
    }

    // ─── Authentification ─────────────────────────────────────────────────────

    /**
     * Équivalent de user_ldap::checkPassword() + User::markLogin() :
     *  1. Résout le DN depuis le sAMAccountName (compte de service)
     *  2. Bind LDAP avec les credentials utilisateur
     *  3. Écrit dans oc_synoldap_users (équivalent markLogin + cacheUserExists)
     *
     * La table oc_synoldap_users n'est PAS dans les dirty tables trackées par NC 33
     * → Aucun problème de transaction.
     */
    public function checkPassword(string $loginName, string $password): string|false {
        if (empty($loginName) || empty($password)) {
            return false;
        }

        // Cache distribué (performance — évite LDAP sur re-check "Se souvenir de moi")
        $credKey  = 'cred_' . hash('sha256', $loginName . ':' . $password);
        $cachedUid = $this->cache->get($credKey);
        if ($cachedUid !== null) {
            return $cachedUid;
        }

        try {
            $uid = $this->ldapService->authenticate($loginName, $password);
            if ($uid !== null) {
                // ── markLogin équivalent : écrire dans notre table de mapping ──
                // Comme user_ldap écrit dans ldap_user_mapping, on écrit dans
                // synoldap_users. Cette table est le fondement de userExists() :
                // une fois l'utilisateur ici, aucun appel LDAP n'est nécessaire.
                $dn = $this->ldapService->getUserDn($uid);
                $this->upsertMapping($uid, $dn ?? '');

                $this->cache->set($credKey, $uid, 3600);
                $this->logger->info('[SynoLDAP] Auth réussie : ' . $loginName . ' → ' . $uid);
                return $uid;
            }
        } catch (\Throwable $e) {
            $this->logger->warning('[SynoLDAP] Erreur auth pour ' . $loginName . ': ' . $e->getMessage());
        }

        return false;
    }

    // ─── Existence / énumération ──────────────────────────────────────────────

    /**
     * Vérifie l'existence — même ordre que user_ldap::userExists() :
     *  1. Cache distribué (rapide, même processus)
     *  2. Table oc_synoldap_users (persistant, DB — jamais de LDAP pour les utilisateurs connus)
     *  3. LDAP (uniquement au premier login, avant que la table ne soit peuplée)
     *
     * C'est ce mécanisme qui rend user_ldap stable : après le premier login,
     * userExists() ne touche plus jamais LDAP pour cet utilisateur.
     */
    public function userExists($uid): bool {
        // 1. Cache distribué
        $cacheKey = 'exists_' . $uid;
        $cached   = $this->cache->get($cacheKey);
        if ($cached !== null) {
            return $cached === '1';
        }

        // 2. Table de mapping (équivalent ldap_user_mapping de user_ldap)
        if ($this->existsInMapping($uid)) {
            $this->cache->set($cacheKey, '1', 300);
            return true;
        }

        // 3. LDAP (premier login uniquement)
        try {
            $exists = $this->ldapService->userExists($uid);
            if ($exists) {
                $dn = $this->ldapService->getUserDn($uid);
                $this->upsertMapping($uid, $dn ?? '');
                $this->cache->set($cacheKey, '1', 300);
            }
            return $exists;
        } catch (\Throwable $e) {
            $this->logger->warning('[SynoLDAP] userExists(' . $uid . ') erreur LDAP: ' . $e->getMessage());
            return false;
        }
    }

    public function getUsers($search = '', $limit = null, $offset = null): array {
        try {
            return $this->ldapService->getAllUserUids($search, $limit, $offset);
        } catch (\Throwable $e) {
            $this->logger->warning('[SynoLDAP] getUsers() erreur: ' . $e->getMessage());
            return [];
        }
    }

    // ─── Affichage ────────────────────────────────────────────────────────────

    public function getDisplayName($uid): string {
        try {
            return $this->ldapService->getUserDisplayName($uid);
        } catch (\Throwable) {
            return $uid;
        }
    }

    public function getDisplayNames($search = '', $limit = null, $offset = null): array {
        $names = [];
        foreach ($this->getUsers($search, $limit, $offset) as $uid) {
            $names[$uid] = $this->getDisplayName($uid);
        }
        return $names;
    }

    // ─── Comptage ────────────────────────────────────────────────────────────

    public function countUsers(): int {
        try {
            return count($this->ldapService->getAllUserUids());
        } catch (\Throwable) {
            return 0;
        }
    }

    // ─── Capacités ───────────────────────────────────────────────────────────

    public function hasUserListings(): bool {
        return true;
    }

    public function deleteUser($uid): bool {
        $this->removeFromMapping($uid);
        $this->cache->remove('exists_' . $uid);
        return true;
    }

    // ─── État du compte ───────────────────────────────────────────────────────

    public function isUserEnabled(string $uid, callable $queryDatabaseValue): bool {
        return (bool) $queryDatabaseValue();
    }

    public function setUserEnabled(string $uid, bool $enabled, callable $queryDatabaseValue, callable $setDatabaseValue): bool {
        $setDatabaseValue($enabled);
        return $enabled;
    }

    public function getDisabledUserList(?int $limit = null, int $offset = 0, string $search = ''): array {
        return [];
    }

    // ─── Table de mapping (équivalent ldap_user_mapping) ─────────────────────

    /**
     * Vérifie la présence dans oc_synoldap_users.
     * Lecture simple et rapide — pas de LDAP, pas de cache externe.
     */
    private function existsInMapping(string $uid): bool {
        try {
            $qb     = $this->db->getQueryBuilder();
            $result = $qb->select('uid')
                ->from('synoldap_users')
                ->where($qb->expr()->eq('uid', $qb->createNamedParameter($uid)))
                ->setMaxResults(1)
                ->executeQuery();
            $row = $result->fetch();
            $result->closeCursor();
            return $row !== false;
        } catch (\Throwable $e) {
            // Table pas encore créée (avant occ upgrade) — fallback LDAP
            $this->logger->debug('[SynoLDAP] existsInMapping: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Insère ou met à jour l'entrée dans oc_synoldap_users.
     * Équivalent de user_ldap::cacheUserExists() + User::markLogin().
     */
    private function upsertMapping(string $uid, string $dn): void {
        try {
            $now = time();
            if ($this->existsInMapping($uid)) {
                $qb = $this->db->getQueryBuilder();
                $qb->update('synoldap_users')
                    ->set('dn',          $qb->createNamedParameter($dn))
                    ->set('verified_at', $qb->createNamedParameter($now))
                    ->where($qb->expr()->eq('uid', $qb->createNamedParameter($uid)))
                    ->executeStatement();
            } else {
                $qb = $this->db->getQueryBuilder();
                $qb->insert('synoldap_users')
                    ->setValue('uid',         $qb->createNamedParameter($uid))
                    ->setValue('dn',          $qb->createNamedParameter($dn))
                    ->setValue('verified_at', $qb->createNamedParameter($now))
                    ->executeStatement();
                $this->logger->info('[SynoLDAP] Utilisateur ajouté au mapping: ' . $uid);
            }
        } catch (\Throwable $e) {
            $this->logger->warning('[SynoLDAP] upsertMapping(' . $uid . '): ' . $e->getMessage());
        }
    }

    private function removeFromMapping(string $uid): void {
        try {
            $qb = $this->db->getQueryBuilder();
            $qb->delete('synoldap_users')
                ->where($qb->expr()->eq('uid', $qb->createNamedParameter($uid)))
                ->executeStatement();
        } catch (\Throwable) {
        }
    }
}
