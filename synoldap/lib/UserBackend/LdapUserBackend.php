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
            // Même sur un cache hit, on garantit que oc_users.backend est correct.
            // Sans cela, si les credentials étaient cachés AVANT le déploiement de
            // ensureUserRow(), le champ backend ne serait jamais corrigé.
            $this->ensureUserRow($cachedUid);
            return $cachedUid;
        }

        // 2. App config persistant (survit aux redémarrages de container)
        $stored = $this->config->getAppValue(self::APP_PREF, $credKey, '');
        if ($stored !== '') {
            [$storedUid, $expiry] = array_pad(explode('|', $stored, 2), 2, '0');
            if ((int) $expiry > time() && $storedUid !== '') {
                $this->authCache->set($credHash, $storedUid, 3600);
                $this->authCache->set('exists_' . $storedUid, '1', 300);
                $this->ensureUserRow($storedUid);
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
            // ── 1. Lecture de l'état actuel ──────────────────────────────────
            // Chaque opération DB utilise son propre QueryBuilder avec ses propres
            // named parameters. Les réutiliser entre builders différents provoque
            // des erreurs SQL silencieuses.
            $selectQb = $this->db->getQueryBuilder();
            $result   = $selectQb->select('backend')
                ->from('users')
                ->where($selectQb->expr()->eq('uid', $selectQb->createNamedParameter($uid)))
                ->executeQuery();
            $row = $result->fetch();
            $result->closeCursor();

            $ourBackend = self::class; // OCA\SynoLDAP\UserBackend\LdapUserBackend

            // ── 2. Création ou mise à jour ────────────────────────────────────
            if ($row === false) {
                // Première connexion : crée l'entrée oc_users avec le bon backend
                $insertQb = $this->db->getQueryBuilder();
                $insertQb->insert('users')
                    ->setValue('uid',         $insertQb->createNamedParameter($uid))
                    ->setValue('uid_lower',   $insertQb->createNamedParameter(mb_strtolower($uid)))
                    ->setValue('displayname', $insertQb->createNamedParameter(''))
                    ->setValue('password',    $insertQb->createNamedParameter(''))
                    ->setValue('backend',     $insertQb->createNamedParameter($ourBackend))
                    ->executeStatement();
                $this->logger->info('[SynoLDAP] oc_users créé pour ' . $uid);
            } elseif (($row['backend'] ?? '') !== $ourBackend) {
                // Backend incorrect (ex. OCA\User_LDAP\User_Proxy) → correction
                $old = $row['backend'] ?? '?';
                $updateQb = $this->db->getQueryBuilder();
                $updateQb->update('users')
                    ->set('backend', $updateQb->createNamedParameter($ourBackend))
                    ->where($updateQb->expr()->eq('uid', $updateQb->createNamedParameter($uid)))
                    ->executeStatement();
                $this->logger->info('[SynoLDAP] oc_users.backend : ' . $old . ' → ' . $ourBackend . ' pour ' . $uid);
            }
        } catch (\Throwable $e) {
            // Colonne backend absente (NC < 26) ou contrainte unique → non bloquant
            $this->logger->warning('[SynoLDAP] ensureUserRow(' . $uid . '): ' . $e->getMessage());
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
