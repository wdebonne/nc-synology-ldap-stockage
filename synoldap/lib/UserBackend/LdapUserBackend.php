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
use OCP\User\Backend\IProvideEnabledStateBackend;
use OCP\UserInterface;
use Psr\Log\LoggerInterface;

/**
 * Backend d'authentification Nextcloud basé sur l'Active Directory Synology.
 *
 * Même logique de base que user_ldap :
 *  - userExists() vérifie d'abord un enregistrement persistant (oc_preferences) avant
 *    tout appel LDAP, exactement comme user_ldap vérifie ldap_user_mapping.
 *  - isUserEnabled() délègue à $queryDatabaseValue() — pas d'appel LDAP par requête.
 *  - La révocation AD passe par checkPassword() : bind échoué = plus de nouvelle session.
 */
class LdapUserBackend extends ABackend implements
    UserInterface,
    ICheckPasswordBackend,
    IGetDisplayNameBackend,
    ICountUsersBackend,
    IProvideEnabledStateBackend
{
    private const KNOWN_KEY  = 'known';
    private const APP_PREF   = 'synoldap';

    /** Cache distribué — évite les lectures répétées de oc_preferences sur un même burst. */
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

    // ─── Authentification ─────────────────────────────────────────────────────

    /**
     * Vérifie les identifiants contre l'AD Synology.
     * Retourne le UID Nextcloud (= sAMAccountName) en cas de succès, false sinon.
     *
     * Après un bind réussi, on mémorise l'utilisateur dans oc_preferences (clé "known").
     * C'est l'équivalent de l'insertion dans ldap_user_mapping de user_ldap : dès lors,
     * userExists() répondra sans jamais toucher le LDAP.
     */
    public function checkPassword(string $loginName, string $password): string|false {
        if (empty($loginName) || empty($password)) {
            return false;
        }

        // Cache distribué (évite les appels LDAP lors des re-validations de session NC).
        $cacheKey  = hash('sha256', $loginName . ':' . $password);
        $cachedUid = $this->authCache->get($cacheKey);
        if ($cachedUid !== null) {
            return $cachedUid;
        }

        try {
            $uid = $this->ldapService->authenticate($loginName, $password);
            if ($uid !== null) {
                // Marquer l'utilisateur comme "connu" de façon persistante (oc_preferences).
                // Même rôle que ldap_user_mapping dans user_ldap : userExists() n'a plus
                // besoin du LDAP pour cet utilisateur, quelle que soit la durée de session.
                $this->config->setUserValue($uid, self::APP_PREF, self::KNOWN_KEY, '1');
                // TTL 3600s : NC re-valide les tokens "Se souvenir de moi" toutes les 300s.
                // Avec 360s, le cache expirait entre deux re-checks → LDAP appelé → si LDAP
                // lent, le token était invalidé et l'utilisateur déconnecté. Avec 3600s (1h),
                // LDAP n'est consulté qu'une fois par heure, identique au cache de user_ldap.
                $this->authCache->set($cacheKey, $uid, 3600);
                $this->authCache->set('exists_' . $uid, '1', 3600);
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
     * Ordre identique à user_ldap :
     *  1. Cache distribué (burst de requêtes, ~5 min)
     *  2. oc_preferences (clé "known") — aucun LDAP pour les utilisateurs déjà connus,
     *     équivalent de ldap_user_mapping. TTL illimité : tant que le compte existe dans NC.
     *  3. LDAP — uniquement pour un utilisateur jamais vu par NC (première connexion).
     */
    public function userExists($uid): bool {
        // 1. Cache distribué
        $cacheKey = 'exists_' . $uid;
        $cached   = $this->authCache->get($cacheKey);
        if ($cached !== null) {
            return $cached === '1';
        }

        // 2. oc_preferences — persistant, pas de LDAP (même logique que ldap_user_mapping)
        if ($this->config->getUserValue($uid, self::APP_PREF, self::KNOWN_KEY, '0') === '1') {
            $this->authCache->set($cacheKey, '1', 300);
            return true;
        }

        // 3. Premier login : utilisateur inconnu de NC → vérifier l'AD
        try {
            $exists = $this->ldapService->userExists($uid);
            if ($exists) {
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
     * Retourne l'état activé/désactivé depuis la base NC — identique à user_ldap.
     * Aucun appel LDAP : la révocation AD passe par l'échec de checkPassword() au login.
     */
    public function isUserEnabled(string $uid, callable $queryDatabaseValue): bool {
        return (bool) $queryDatabaseValue();
    }

    /**
     * Lecture seule : l'AD Synology contrôle les comptes.
     */
    public function setUserEnabled(string $uid, bool $enabled, callable $queryDatabaseValue, callable $setDatabaseValue): bool {
        $setDatabaseValue($enabled);
        return $enabled;
    }

    public function getDisabledUserList(?int $limit = null, int $offset = 0, string $search = ''): array {
        return [];
    }
}
