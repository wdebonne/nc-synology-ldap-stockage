<?php
declare(strict_types=1);

namespace OCA\SynoLDAP\UserBackend;

use OCA\SynoLDAP\Service\LdapService;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\IDBConnection;
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
 * Ce backend remplace l'app user_ldap officielle : les utilisateurs créés sur
 * le Synology peuvent se connecter à Nextcloud sans configuration supplémentaire.
 *
 * Flux de connexion :
 *  1. L'utilisateur saisit son login Windows (sAMAccountName) et son mot de passe.
 *  2. checkPassword() trouve son DN dans l'AD et tente un bind LDAP avec ses identifiants.
 *  3. En cas de succès, Nextcloud crée automatiquement son compte s'il n'existe pas encore.
 *  4. Le PostLoginEvent déclenche la synchro des groupes et la création des montages SMB.
 */
class LdapUserBackend extends ABackend implements
    UserInterface,
    ICheckPasswordBackend,
    IGetDisplayNameBackend,
    ICountUsersBackend,
    IProvideEnabledStateBackend
{
    private ICache $authCache;

    public function __construct(
        private LdapService $ldapService,
        private LoggerInterface $logger,
        ICacheFactory $cacheFactory,
        private IDBConnection $db,
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
     * Un mot de passe vide est toujours refusé (protection contre le bind anonyme LDAP).
     */
    public function checkPassword(string $loginName, string $password): string|false {
        if (empty($loginName) || empty($password)) {
            return false;
        }

        // Vérifier le cache avant tout appel LDAP (NC re-valide toutes les 5 minutes).
        $cacheKey = hash('sha256', $loginName . ':' . $password);
        $cachedUid = $this->authCache->get($cacheKey);
        if ($cachedUid !== null) {
            return $cachedUid;
        }

        try {
            $uid = $this->ldapService->authenticate($loginName, $password);
            if ($uid !== null) {
                // Cache pendant 360s (légèrement > fenêtre 5 min de NC).
                $this->authCache->set($cacheKey, $uid, 360);
                // Met en cache l'existence de l'utilisateur pour éviter les appels LDAP
                // répétés lors de la validation de session WebDAV (burst de requêtes au login).
                $this->authCache->set('exists_' . hash('sha256', $uid), '1', 360);
                return $uid;
            }
        } catch (\Throwable $e) {
            $this->logger->warning(
                '[SynoLDAP] Erreur authentification pour ' . $loginName . ': ' . $e->getMessage()
            );
        }

        return false;
    }

    // ─── Existence / énumération ──────────────────────────────────────────────

    /**
     * Indique si l'utilisateur est reconnu par ce backend.
     *
     * Ordre identique à user_ldap :
     *  1. Cache distribué (rapide, < 5 min)
     *  2. Base de données NC (oc_users) — aucun appel LDAP pour les utilisateurs connus
     *  3. LDAP — uniquement pour les nouveaux utilisateurs jamais vus par NC
     *
     * Ce comportement évite les 401 causés par un LDAP momentanément lent : dès qu'un
     * utilisateur s'est connecté une fois, son compte est dans oc_users et userExists()
     * répond instantanément sans toucher le LDAP, quelle que soit la durée de la session.
     */
    public function userExists($uid): bool {
        $cacheKey = 'exists_' . hash('sha256', $uid);
        $cached = $this->authCache->get($cacheKey);
        if ($cached !== null) {
            return $cached === '1';
        }

        // Vérification DB (même approche que user_ldap avec ldap_user_mapping).
        // Tout utilisateur ayant déjà été provisionné par ce backend est dans oc_users.
        if ($this->existsInDatabase($uid)) {
            $this->authCache->set($cacheKey, '1', 300);
            return true;
        }

        // Première connexion : le compte n'est pas encore dans NC → vérifier l'AD.
        try {
            $exists = $this->ldapService->userExists($uid);
            if ($exists) {
                $this->authCache->set($cacheKey, '1', 300);
            }
            return $exists;
        } catch (\Throwable $e) {
            $this->logger->warning('[SynoLDAP] userExists(' . $uid . ') erreur LDAP: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Vérifie si l'utilisateur est dans la table oc_users avec notre backend.
     * Requête DB simple, sans aucun appel LDAP — miroir de user_ldap::userManager->exists().
     */
    private function existsInDatabase(string $uid): bool {
        try {
            $qb = $this->db->getQueryBuilder();
            $qb->select('uid')
               ->from('users')
               ->where($qb->expr()->eq('uid', $qb->createNamedParameter($uid)))
               ->andWhere($qb->expr()->eq('backend', $qb->createNamedParameter(self::class)))
               ->setMaxResults(1);
            $result = $qb->executeQuery();
            $row    = $result->fetch();
            $result->closeCursor();
            return $row !== false;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Retourne la liste des UIDs (avec filtre, pagination).
     * Utilisé dans le panel admin NC et pour la saisie semi-automatique.
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

    /**
     * Retourne le nom complet de l'utilisateur (displayName ou cn depuis l'AD).
     * Nextcloud l'utilise à la première connexion pour pré-remplir le profil.
     */
    public function getDisplayName($uid): string {
        try {
            return $this->ldapService->getUserDisplayName($uid);
        } catch (\Throwable) {
            return $uid;
        }
    }

    /**
     * Retourne les noms complets de plusieurs utilisateurs d'un coup (optimisation).
     *
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

    /**
     * Ce backend est en lecture seule : la création/suppression d'utilisateurs
     * se fait sur le Synology, pas dans Nextcloud.
     */
    public function hasUserListings(): bool {
        return true;
    }

    public function deleteUser($uid): bool {
        return true;
    }

    // ─── État du compte ───────────────────────────────────────────────────────

    /**
     * Retourne l'état activé/désactivé depuis la base NC (comme user_ldap).
     * On ne sonde pas l'AD ici : userExists() capture les exceptions et retourne false,
     * ce qui rendrait queryDatabaseValue() inatteignable et invaliderait chaque session
     * dès que le cache LDAP expire. La révocation AD passe par checkPassword() au prochain login.
     */
    public function isUserEnabled(string $uid, callable $queryDatabaseValue): bool {
        return (bool) $queryDatabaseValue();
    }

    /**
     * Lecture seule : c'est l'AD Synology qui contrôle l'état des comptes.
     * On délègue uniquement la persistance NC (nécessaire pour l'interface).
     */
    public function setUserEnabled(string $uid, bool $enabled, callable $queryDatabaseValue, callable $setDatabaseValue): bool {
        $setDatabaseValue($enabled);
        return $enabled;
    }

    /** L'AD Synology gère les comptes désactivés — on retourne une liste vide. */
    public function getDisabledUserList(?int $limit = null, int $offset = 0, string $search = ''): array {
        return [];
    }
}
