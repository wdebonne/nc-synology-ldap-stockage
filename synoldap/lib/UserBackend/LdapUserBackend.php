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
 * Reproduction fidèle des capacités déclarées par user_ldap :
 *
 *  GET_HOME  — NC appelle getHome() pour initialiser le stockage de l'utilisateur.
 *              Sans cette déclaration, NC peut ne pas créer l'entrée oc_accounts /
 *              oc_users correctement au premier login, ce qui empêche la création de
 *              dossiers et fichiers.
 *
 *  known     — Stocké dans oc_preferences UNIQUEMENT par userExists() après confirmation
 *              LDAP, jamais par checkPassword(). Cela permet à NC d'auto-provisionner
 *              l'utilisateur (oc_users + oc_accounts) lors du premier appel à
 *              userExists() avant que la préférence n'existe.
 *
 *  Credential cache (checkPassword) — TTL 3600s pour couvrir les re-checks "Se souvenir
 *              de moi" de NC (toutes les 300s). user_ldap utilise le même principe.
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

    /**
     * Retourne le chemin du répertoire home de l'utilisateur.
     *
     * user_ldap déclare Backend::GET_HOME et implémente getHome() — c'est ce qui
     * permet à NC d'initialiser correctement le stockage (oc_accounts, oc_users,
     * home storage) au premier login. Sans cette méthode, NC peut ignorer le
     * provisionnement et l'utilisateur se retrouve sans home → impossible de créer
     * des fichiers ou dossiers.
     */
    public function getHome(string $uid): string|false {
        if (!$this->userExists($uid)) {
            return false;
        }
        $dataDir = rtrim((string) $this->config->getSystemValue('datadirectory', ''), '/');
        if ($dataDir === '') {
            return false;
        }
        return $dataDir . '/' . $uid;
    }

    // ─── Authentification ─────────────────────────────────────────────────────

    /**
     * Vérifie les identifiants contre l'AD Synology.
     * Retourne le UID Nextcloud (= sAMAccountName) en cas de succès, false sinon.
     *
     * IMPORTANT — ordre intentionnel :
     *  checkPassword() met en cache les credentials (TTL 3600s) mais NE pose PAS
     *  "known=1" dans oc_preferences et NE remplit PAS le cache exists_.
     *
     *  Pourquoi ? Au premier login d'un nouvel utilisateur, NC appelle userExists()
     *  juste après checkPassword() pour créer l'entrée oc_users / oc_accounts
     *  (auto-provisionnement). Si exists_ est déjà en cache, userExists() retourne
     *  true immédiatement et NC croit que l'utilisateur est déjà provisionné → il
     *  ne crée JAMAIS l'entrée oc_users → home directory non initialisé → impossible
     *  de créer des fichiers / dossiers.
     *
     *  C'est userExists() qui pose "known=1" après confirmation LDAP, ce qui garantit
     *  que NC a eu le temps d'auto-provisionner l'utilisateur avant.
     */
    public function checkPassword(string $loginName, string $password): string|false {
        if (empty($loginName) || empty($password)) {
            return false;
        }

        // Cache des credentials — couvre les re-checks "Se souvenir de moi" (300s NC).
        // TTL 3600s : LDAP n'est consulté qu'une fois par heure au lieu de chaque re-check.
        $credKey   = hash('sha256', $loginName . ':' . $password);
        $cachedUid = $this->authCache->get($credKey);
        if ($cachedUid !== null) {
            return $cachedUid;
        }

        try {
            $uid = $this->ldapService->authenticate($loginName, $password);
            if ($uid !== null) {
                $this->authCache->set($credKey, $uid, 3600);
                // NE PAS poser known=1 ici — voir docblock ci-dessus.
                return $uid;
            }
        } catch (\Throwable $e) {
            $this->logger->warning('[SynoLDAP] Erreur authentification pour ' . $loginName . ': ' . $e->getMessage());
        }

        return false;
    }

    // ─── Existence / énumération ──────────────────────────────────────────────

    /**
     * Indique si l'utilisateur est reconnu par ce backend.
     *
     * Ordre (même logique que user_ldap / ldap_user_mapping) :
     *  1. Cache distribué (burst de requêtes, ~5 min)
     *  2. oc_preferences "known" — aucun LDAP pour les utilisateurs déjà provisionnés
     *  3. LDAP — premier login ou utilisateur inconnu de NC
     *     → après confirmation, pose "known=1" pour les appels suivants
     *
     * C'est ici que "known=1" est posé, PAS dans checkPassword(), afin de laisser
     * NC créer l'entrée oc_users / oc_accounts lors du premier appel (auto-provisionnement).
     */
    public function userExists($uid): bool {
        // 1. Cache distribué
        $cacheKey = 'exists_' . $uid;
        $cached   = $this->authCache->get($cacheKey);
        if ($cached !== null) {
            return $cached === '1';
        }

        // 2. oc_preferences (persistant, pas de LDAP)
        if ($this->config->getUserValue($uid, self::APP_PREF, self::KNOWN_KEY, '0') === '1') {
            $this->authCache->set($cacheKey, '1', 300);
            return true;
        }

        // 3. LDAP — uniquement au premier login ou si oc_preferences n'est pas encore posé
        try {
            $exists = $this->ldapService->userExists($uid);
            if ($exists) {
                // Pose "known=1" APRÈS que NC ait pu auto-provisionner l'utilisateur.
                $this->config->setUserValue($uid, self::APP_PREF, self::KNOWN_KEY, '1');
                $this->authCache->set($cacheKey, '1', 300);
            }
            return $exists;
        } catch (\Throwable $e) {
            $this->logger->warning('[SynoLDAP] userExists(' . $uid . ') erreur LDAP: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Retourne la liste des UIDs (avec filtre, pagination).
     */
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

    /**
     * @param list<string> $userList
     * @return array<string, string>
     */
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
        return true;
    }

    // ─── État du compte ───────────────────────────────────────────────────────

    /**
     * Retourne l'état activé/désactivé depuis la base NC (identique à user_ldap).
     * Aucun appel LDAP par requête : la révocation AD passe par checkPassword().
     */
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
