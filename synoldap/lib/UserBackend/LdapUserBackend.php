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
 * Logique identique à user_ldap :
 *
 * checkPassword() :
 *   - Valide les credentials contre LDAP
 *   - Pose immédiatement known=1 (≡ markLogin() + cacheUserExists() de user_ldap)
 *   - Met en cache les credentials dans deux couches :
 *       • Cache distribué (rapide, mais APCu = par processus PHP)
 *       • App config NC (persistant, DB-backed, survit aux redémarrages PHP/APCu)
 *     → couvre le re-check "Se souvenir de moi" (toutes les 300s) même avec cache froid
 *
 * userExists() :
 *   - Cache distribué → oc_preferences (known=1) → LDAP (uniquement si inconnu)
 *   - Après known=1 posé par checkPassword, JAMAIS d'appel LDAP pour cet utilisateur
 *
 * getHome() :
 *   - Retourne directement {datadirectory}/{uid} si known=1 ou cache chaud
 *   - N'appelle PAS userExists() dans le chemin rapide → pas de dépendance LDAP
 *
 * deleteUser() :
 *   - Nettoie known=1 et le cache persistant pour éviter qu'un utilisateur supprimé
 *     soit encore reconnu par le backend
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
    // Longueur du fragment de hash utilisé comme clé app config (64 chars max, préfixe inclus).
    private const CRED_PREFIX = 'cr_';
    private const CRED_LEN    = 61; // len('cr_') + 61 = 64

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
     * Retourne le chemin home de l'utilisateur (≡ user_ldap::getHome).
     *
     * user_ldap déclare GET_HOME et l'implémente — NC utilise cette méthode pour
     * initialiser le stockage home au premier login (oc_accounts, home storage).
     *
     * Chemin rapide : si l'utilisateur est connu (known=1 ou cache), on ne touche
     * pas LDAP. Seuls les utilisateurs complètement inconnus déclenchent une vérif.
     */
    public function getHome(string $uid): string|false {
        $dataDir = rtrim((string) $this->config->getSystemValue('datadirectory', ''), '/');
        if ($dataDir === '') {
            return false;
        }

        // Chemin rapide : cache ou oc_preferences
        if ($this->authCache->get('exists_' . $uid) === '1') {
            return $dataDir . '/' . $uid;
        }
        if ($this->config->getUserValue($uid, self::APP_PREF, self::KNOWN_KEY, '0') === '1') {
            return $dataDir . '/' . $uid;
        }

        // Chemin lent : vérifie LDAP uniquement si l'utilisateur est totalement inconnu
        if (!$this->userExists($uid)) {
            return false;
        }
        return $dataDir . '/' . $uid;
    }

    // ─── Authentification ─────────────────────────────────────────────────────

    /**
     * Vérifie les identifiants contre l'AD Synology.
     *
     * Équivalent de user_ldap::checkPassword() + markLogin() + cacheUserExists() :
     *  - known=1 est posé immédiatement (persistant, visible par userExists sur tous
     *    les processus PHP dès que la transaction est commitée)
     *  - double cache credentials : distribué (rapide) + app config (persistant)
     *    → le re-check "Se souvenir de moi" de NC (toutes les 300s) ne touche LDAP
     *    qu'une fois par heure même si APCu est vide (nouveau processus PHP)
     */
    public function checkPassword(string $loginName, string $password): string|false {
        if (empty($loginName) || empty($password)) {
            return false;
        }

        $credHash = hash('sha256', $loginName . ':' . $password);
        $credKey  = self::CRED_PREFIX . substr($credHash, 0, self::CRED_LEN);

        // 1. Cache distribué (rapide, APCu par processus ou Redis si configuré)
        $cachedUid = $this->authCache->get($credHash);
        if ($cachedUid !== null) {
            return $cachedUid;
        }

        // 2. App config persistant (DB-backed, survit aux redémarrages de processus PHP)
        //    Stocke : uid|timestamp_expiry
        $stored = $this->config->getAppValue(self::APP_PREF, $credKey, '');
        if ($stored !== '') {
            [$storedUid, $expiry] = array_pad(explode('|', $stored, 2), 2, '0');
            if ((int) $expiry > time() && $storedUid !== '') {
                // Re-remplit le cache distribué pour les requêtes suivantes du même processus
                $this->authCache->set($credHash, $storedUid, 3600);
                $this->authCache->set('exists_' . $storedUid, '1', 300);
                return $storedUid;
            }
            // Entrée expirée → on la supprimera après re-auth LDAP réussie
        }

        // 3. Authentification LDAP
        try {
            $uid = $this->ldapService->authenticate($loginName, $password);
            if ($uid !== null) {
                $expiry = time() + 3600;

                // Cache distribué (rapide, par processus)
                $this->authCache->set($credHash, $uid, 3600);
                $this->authCache->set('exists_' . $uid, '1', 3600);

                // Cache persistant (DB, survit aux redémarrages — équivalent markLogin)
                $this->config->setAppValue(self::APP_PREF, $credKey, $uid . '|' . $expiry);

                // Enregistrement de l'utilisateur (≡ markLogin + cacheUserExists user_ldap)
                // known=1 posé ici pour que userExists() ne rappelle jamais LDAP pour
                // cet utilisateur après cette authentification réussie.
                $this->config->setUserValue($uid, self::APP_PREF, self::KNOWN_KEY, '1');

                $this->logger->info('[SynoLDAP] Authentification réussie : ' . $loginName . ' → ' . $uid);
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
     * Ordre (identique à user_ldap via ldap_user_mapping) :
     *  1. Cache distribué (burst de requêtes, par processus)
     *  2. oc_preferences[synoldap][known] — aucun LDAP pour les utilisateurs connus
     *     (posé par checkPassword ≡ cacheUserExists + markLogin de user_ldap)
     *  3. LDAP — uniquement pour un utilisateur jamais authentifié via ce backend
     */
    public function userExists($uid): bool {
        // 1. Cache distribué
        $cached = $this->authCache->get('exists_' . $uid);
        if ($cached !== null) {
            return $cached === '1';
        }

        // 2. oc_preferences (persistant, DB-backed)
        if ($this->config->getUserValue($uid, self::APP_PREF, self::KNOWN_KEY, '0') === '1') {
            $this->authCache->set('exists_' . $uid, '1', 300);
            return true;
        }

        // 3. LDAP (premier login ou utilisateur inconnu)
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

    /**
     * Supprime les données synoldap de l'utilisateur lors d'une suppression NC.
     * Nettoie known=1 et le cache persistant pour éviter que le backend continue
     * à reconnaître un utilisateur supprimé.
     */
    public function deleteUser($uid): bool {
        $this->config->deleteUserValue($uid, self::APP_PREF, self::KNOWN_KEY);
        $this->authCache->remove('exists_' . $uid);
        // Les entrées cr_* de l'app config expirent naturellement (1h) ou lors
        // de la prochaine tentative de connexion avec ce compte.
        return true;
    }

    // ─── État du compte ───────────────────────────────────────────────────────

    /**
     * Retourne l'état activé/désactivé depuis la base NC (identique à user_ldap).
     * Aucun appel LDAP par requête.
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
