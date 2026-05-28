<?php
declare(strict_types=1);

namespace OCA\SynoLDAP\UserBackend;

use OCA\SynoLDAP\Service\LdapService;
use OCP\User\Backend\ABackend;
use OCP\User\Backend\ICheckPasswordBackend;
use OCP\User\Backend\ICountUsersBackend;
use OCP\User\Backend\IGetDisplayNameBackend;
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
    ICheckPasswordBackend,
    IGetDisplayNameBackend,
    ICountUsersBackend
{
    public function __construct(
        private LdapService $ldapService,
        private LoggerInterface $logger,
    ) {}

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

        try {
            $uid = $this->ldapService->authenticate($loginName, $password);
            if ($uid !== null) {
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
    public function userExists(string $uid): bool {
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
    public function getUsers(string $search = '', ?int $limit = null, ?int $offset = null): array {
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
    public function getDisplayName(string $uid): string {
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
    public function getDisplayNames(string $search = '', ?int $limit = null, ?int $offset = null): array {
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
    public function deleteUser($uid): bool {
        return false;
    }
}
