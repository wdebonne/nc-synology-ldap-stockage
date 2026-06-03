<?php
declare(strict_types=1);

namespace OCA\SynoLDAP\UserBackend;

use OCA\SynoLDAP\Service\LdapService;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\IConfig;
use OCP\User\Backend\ABackend;
use OCP\User\Backend\ICheckPasswordBackend;
use OCP\User\Backend\ICountUsersBackend;
use OCP\User\Backend\IGetDisplayNameBackend;
use OCP\User\Backend\IGetHomeBackend;
use OCP\User\Backend\IProvideEnabledStateBackend;
use OCP\UserInterface;
use Psr\Log\LoggerInterface;

/**
 * Backend d'authentification Nextcloud basé sur l'Active Directory Synology.
 *
 * Compatible NC 33 + PostgreSQL (NC AIO) :
 * - oc_users n'a PAS de colonne backend en PostgreSQL → pas d'ensureUserRow().
 *   NC 33 détermine le backend en itérant tous les backends via userExists().
 * - known=1 est posé dans checkPassword() (login ET re-check token "Se souvenir de moi")
 *   ET dans UserLoggedInListener (PostLoginEvent) comme sécurité.
 * - Aucune écriture DB dans la transaction d'auth NC 33 : setUserValue() est safe
 *   (oc_preferences n'est pas dans les tables "dirty" trackées par NC 33).
 */
class LdapUserBackend extends ABackend implements
    UserInterface,
    ICheckPasswordBackend,
    IGetDisplayNameBackend,
    IGetHomeBackend,
    ICountUsersBackend,
    IProvideEnabledStateBackend
{
    private const KNOWN_KEY = 'known';
    private const APP_PREF  = 'synoldap';

    private ICache $authCache;

    public function __construct(
        private LdapService $ldapService,
        private LoggerInterface $logger,
        ICacheFactory $cacheFactory,
        private IConfig $config,
    ) {
        $this->authCache = $cacheFactory->createDistributed('synoldap_auth_');
    }

    public function getBackendName(): string {
        return 'SynoLDAP';
    }

    // ─── Home directory ───────────────────────────────────────────────────────

    public function getHome(string $uid): string|false {
        $dataDir = rtrim((string) $this->config->getSystemValue('datadirectory', ''), '/');
        if ($dataDir === '') {
            return false;
        }
        if ($this->authCache->get('exists_' . $uid) === '1') {
            return $dataDir . '/' . $uid;
        }
        if ($this->config->getUserValue($uid, self::APP_PREF, self::KNOWN_KEY, '0') === '1') {
            return $dataDir . '/' . $uid;
        }
        if (!$this->userExists($uid)) {
            return false;
        }
        return $dataDir . '/' . $uid;
    }

    // ─── Authentification ─────────────────────────────────────────────────────

    /**
     * Vérifie les identifiants contre l'AD Synology.
     *
     * known=1 est posé ICI (pas seulement dans le listener) pour deux raisons :
     * 1. Le listener PostLoginEvent ne s'exécute qu'au login réel, pas lors du
     *    re-check "Se souvenir de moi" (toutes les 300s NC appelle checkPassword).
     * 2. Quand le cache Redis expire (>3600s), NC appelle checkPassword → LDAP →
     *    on repose known=1 + on refait le cache. Sans ça, userExists() appellerait
     *    LDAP pour chaque validation de session → si LDAP lent → 401.
     *
     * oc_preferences (setUserValue) est safe dans NC 33 : pas dans les tables "dirty"
     * trackées par NC 33 (oc_jobs, oc_appconfig, oc_oauth2_*, oc_filecache*).
     */
    public function checkPassword(string $loginName, string $password): string|false {
        if (empty($loginName) || empty($password)) {
            return false;
        }

        // Cache Redis (partagé entre workers NC AIO via Redis)
        $credHash  = hash('sha256', $loginName . ':' . $password);
        $cachedUid = $this->authCache->get($credHash);
        if ($cachedUid !== null) {
            // known=1 assuré même sur cache hit (Redis peut avoir été peuplé
            // avant que le listener PostLoginEvent n'ait pu le poser)
            $this->config->setUserValue($cachedUid, self::APP_PREF, self::KNOWN_KEY, '1');
            return $cachedUid;
        }

        // Authentification LDAP
        try {
            $uid = $this->ldapService->authenticate($loginName, $password);
            if ($uid !== null) {
                // Cache Redis (TTL 3600s pour couvrir les re-checks NC de 300s)
                $this->authCache->set($credHash, $uid, 3600);
                $this->authCache->set('exists_' . $uid, '1', 3600);
                // Persistant : survit à Redis, permet à userExists() d'éviter LDAP
                $this->config->setUserValue($uid, self::APP_PREF, self::KNOWN_KEY, '1');
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
     * Indique si l'utilisateur est reconnu par ce backend.
     *
     * En NC 33 PostgreSQL (sans colonne oc_users.backend), NC itère tous les backends
     * pour chaque get(uid). Cet ordre garantit un retour rapide pour les utilisateurs
     * connus sans appel LDAP :
     *  1. Cache Redis (ms)
     *  2. oc_preferences known=1 (DB locale, ~ms)
     *  3. LDAP (premier login uniquement)
     */
    public function userExists($uid): bool {
        $cached = $this->authCache->get('exists_' . $uid);
        if ($cached !== null) {
            return $cached === '1';
        }
        if ($this->config->getUserValue($uid, self::APP_PREF, self::KNOWN_KEY, '0') === '1') {
            $this->authCache->set('exists_' . $uid, '1', 300);
            return true;
        }
        try {
            $exists = $this->ldapService->userExists($uid);
            if ($exists) {
                $this->config->setUserValue($uid, self::APP_PREF, self::KNOWN_KEY, '1');
                $this->authCache->set('exists_' . $uid, '1', 300);
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
        $this->config->deleteUserValue($uid, self::APP_PREF, self::KNOWN_KEY);
        $this->authCache->remove('exists_' . $uid);
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
}
