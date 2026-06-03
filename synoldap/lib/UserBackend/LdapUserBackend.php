<?php
declare(strict_types=1);

namespace OCA\SynoLDAP\UserBackend;

use OCA\SynoLDAP\Service\LdapService;
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
 * Backend d'authentification Nextcloud basé sur l'Active Directory Synology.
 *
 * Cause racine des 401 persistants avec NC AIO :
 * NC 30+ lit d'abord oc_users.backend pour savoir quel backend gère l'utilisateur.
 * Si ce champ contient l'ancienne valeur user_ldap (OCA\User_LDAP\User_Proxy) et que
 * user_ldap n'est plus enregistré, NC ne trouve plus l'utilisateur → 401 partout.
 *
 * Fix : ensureUserRow() écrit/met à jour oc_users.backend = self::class après chaque
 * authentification LDAP réussie, garantissant que NC route les requêtes vers synoldap.
 *
 * Ordre checkPassword() (identique à user_ldap::checkPassword + markLogin) :
 *  1. Cache Redis (rapide)
 *  2. App config NC (persistant cross-processus, pour "Se souvenir de moi")
 *  3. LDAP → succès → mise à jour oc_users.backend + known=1 + caches
 */
class LdapUserBackend extends ABackend implements
    UserInterface,
    ICheckPasswordBackend,
    IGetDisplayNameBackend,
    IGetHomeBackend,
    ICountUsersBackend,
    IProvideEnabledStateBackend
{
    private const KNOWN_KEY   = 'known';
    private const APP_PREF    = 'synoldap';
    private const CRED_PREFIX = 'cr_';
    private const CRED_LEN    = 61;

    private ICache $authCache;

    public function __construct(
        private LdapService $ldapService,
        private LoggerInterface $logger,
        ICacheFactory $cacheFactory,
        private IConfig $config,
        private IDBConnection $db,
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

    public function checkPassword(string $loginName, string $password): string|false {
        if (empty($loginName) || empty($password)) {
            return false;
        }

        $credHash = hash('sha256', $loginName . ':' . $password);
        $credKey  = self::CRED_PREFIX . substr($credHash, 0, self::CRED_LEN);

        // 1. Cache Redis (partagé entre tous les workers NC AIO)
        $cachedUid = $this->authCache->get($credHash);
        if ($cachedUid !== null) {
            return $cachedUid;
        }

        // 2. App config persistant (survit aux redémarrages de container)
        $stored = $this->config->getAppValue(self::APP_PREF, $credKey, '');
        if ($stored !== '') {
            [$storedUid, $expiry] = array_pad(explode('|', $stored, 2), 2, '0');
            if ((int) $expiry > time() && $storedUid !== '') {
                $this->authCache->set($credHash, $storedUid, 3600);
                $this->authCache->set('exists_' . $storedUid, '1', 300);
                return $storedUid;
            }
        }

        // 3. Authentification LDAP
        try {
            $uid = $this->ldapService->authenticate($loginName, $password);
            if ($uid !== null) {
                $expiry = time() + 3600;

                // Caches (Redis + app config)
                $this->authCache->set($credHash, $uid, 3600);
                $this->authCache->set('exists_' . $uid, '1', 3600);
                $this->config->setAppValue(self::APP_PREF, $credKey, $uid . '|' . $expiry);

                // known=1 persistant (≡ markLogin + cacheUserExists de user_ldap)
                $this->config->setUserValue($uid, self::APP_PREF, self::KNOWN_KEY, '1');

                // ── Correction NC AIO ─────────────────────────────────────────────
                // NC 30+ lit oc_users.backend pour router les requêtes vers le bon
                // backend. Si cette valeur contient encore 'OCA\User_LDAP\User_Proxy'
                // (user_ldap était actif avant synoldap), NC ne trouve plus le backend
                // et retourne 401 sur toutes les opérations (partages, fichiers, sessions).
                // On met à jour ce champ immédiatement après chaque auth réussie.
                $this->ensureUserRow($uid);

                $this->logger->info('[SynoLDAP] Auth réussie : ' . $loginName . ' → ' . $uid);
                return $uid;
            }
        } catch (\Throwable $e) {
            $this->logger->warning('[SynoLDAP] Erreur auth pour ' . $loginName . ': ' . $e->getMessage());
        }

        return false;
    }

    /**
     * Crée ou met à jour l'entrée oc_users pour s'assurer que backend = self::class.
     *
     * Sans cela, NC 30 peut router les requêtes vers l'ancien backend stocké dans
     * oc_users.backend (ex. OCA\User_LDAP\User_Proxy si user_ldap était actif avant),
     * ce qui provoque des 401 sur les partages, les sessions et la création de fichiers.
     */
    private function ensureUserRow(string $uid): void {
        try {
            $qb  = $this->db->getQueryBuilder();
            $row = $qb->select('backend')
                ->from('users')
                ->where($qb->expr()->eq('uid', $qb->createNamedParameter($uid)))
                ->executeQuery()
                ->fetch();

            if ($row === false) {
                // Premier login : crée l'entrée oc_users
                $this->db->getQueryBuilder()
                    ->insert('users')
                    ->setValue('uid',         $qb->createNamedParameter($uid))
                    ->setValue('uid_lower',   $qb->createNamedParameter(mb_strtolower($uid)))
                    ->setValue('displayname', $qb->createNamedParameter(''))
                    ->setValue('password',    $qb->createNamedParameter(''))
                    ->setValue('backend',     $qb->createNamedParameter(self::class))
                    ->executeStatement();
                $this->logger->info('[SynoLDAP] Entrée oc_users créée pour ' . $uid);
            } elseif (($row['backend'] ?? '') !== self::class) {
                // Backend incorrect (ex. user_ldap) → correction
                $old = $row['backend'] ?? '?';
                $this->db->getQueryBuilder()
                    ->update('users')
                    ->set('backend', $qb->createNamedParameter(self::class))
                    ->where($qb->expr()->eq('uid', $qb->createNamedParameter($uid)))
                    ->executeStatement();
                $this->logger->info('[SynoLDAP] oc_users.backend corrigé pour ' . $uid . ' : ' . $old . ' → ' . self::class);
            }
        } catch (\Throwable $e) {
            // La table n'a pas de colonne backend (NC < 26) ou autre erreur → non bloquant
            $this->logger->debug('[SynoLDAP] ensureUserRow(' . $uid . '): ' . $e->getMessage());
        }
    }

    // ─── Existence / énumération ──────────────────────────────────────────────

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
