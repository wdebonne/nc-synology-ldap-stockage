<?php
declare(strict_types=1);

namespace OCA\SynoLDAP\UserBackend;

use OCA\SynoLDAP\Service\LdapService;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\User\Backend\ABackend;
use OCP\User\Backend\ICheckPasswordBackend;
use OCP\User\Backend\ICountUsersBackend;
use OCP\User\Backend\IGetDisplayNameBackend;
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
    ICountUsersBackend
{
    // Cache d'authentification : évite les appels LDAP répétés lors de la re-validation
    // de session par NC (checkTokenCredentials toutes les 5 minutes).
    private ICache $authCache;

    public function __construct(
        private LdapService $ldapService,
        private LoggerInterface $logger,
        ICacheFactory $cacheFactory,
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
        $this->logger->warning("[SynoLDAP] checkPassword appelé: login={$loginName} pwLen=" . strlen($password));
        if (empty($loginName) || empty($password)) {
            $this->logger->warning("[SynoLDAP] checkPassword: refusé (login ou mdp vide)");
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
     * Indique si l'utilisateur existe dans l'AD.
     * Utilisé par Nextcloud pour la complétion automatique et le partage.
     */
    public function userExists($uid): bool {
        try {
            return $this->ldapService->userExists($uid);
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
}
