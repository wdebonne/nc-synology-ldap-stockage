<?php
declare(strict_types=1);

namespace OCA\SynoLDAP\Service;

use OCP\IConfig;
use Psr\Log\LoggerInterface;

class LdapService {
    private const APP_ID = 'synoldap';

    // Connexion du compte de service mise en cache pour toute la durée de la requête.
    // LdapService est un singleton dans le container NC — une seule connexion par requête PHP.
    private ?\LDAP\Connection $serviceConn = null;

    public function __construct(
        private IConfig $config,
        private LoggerInterface $logger,
    ) {}

    public function __destruct() {
        if ($this->serviceConn !== null) {
            @ldap_unbind($this->serviceConn);
            $this->serviceConn = null;
        }
    }

    // ─── Connexion service ────────────────────────────────────────────────────

    /**
     * Retourne la connexion du compte de service, en la créant si nécessaire.
     * La connexion est réutilisée pour toutes les opérations de la même requête.
     */
    private function connect(): \LDAP\Connection {
        if ($this->serviceConn !== null) {
            return $this->serviceConn;
        }
        $this->serviceConn = $this->openServiceConnection();
        return $this->serviceConn;
    }

    /**
     * Ouvre et retourne une nouvelle connexion LDAP authentifiée avec le compte de service.
     */
    private function openServiceConnection(): \LDAP\Connection {
        $host   = $this->config->getAppValue(self::APP_ID, 'ldap_host', '');
        $port   = (int) $this->config->getAppValue(self::APP_ID, 'ldap_port', '389');
        $useTls = $this->config->getAppValue(self::APP_ID, 'ldap_tls', '0') === '1';

        if (empty($host)) {
            throw new \RuntimeException('Hôte LDAP non configuré.');
        }

        $scheme = $useTls ? 'ldaps' : 'ldap';

        if ($useTls) {
            ldap_set_option(null, LDAP_OPT_X_TLS_REQUIRE_CERT, LDAP_OPT_X_TLS_NEVER);
        }

        $conn = @ldap_connect("{$scheme}://{$host}:{$port}");

        if (!$conn) {
            throw new \RuntimeException("Connexion LDAP impossible vers {$scheme}://{$host}:{$port}");
        }

        ldap_set_option($conn, LDAP_OPT_PROTOCOL_VERSION, 3);
        ldap_set_option($conn, LDAP_OPT_REFERRALS, 0);
        ldap_set_option($conn, LDAP_OPT_NETWORK_TIMEOUT, 10);

        $bindDn  = $this->config->getAppValue(self::APP_ID, 'ldap_bind_dn', '');
        $bindPwd = $this->config->getAppValue(self::APP_ID, 'ldap_bind_password', '');

        if (!empty($bindDn)) {
            if (!@ldap_bind($conn, $bindDn, $bindPwd)) {
                $err = ldap_error($conn);
                ldap_unbind($conn);
                throw new \RuntimeException("Authentification LDAP échouée pour {$bindDn}: {$err}");
            }
        } else {
            if (!@ldap_bind($conn)) {
                $err = ldap_error($conn);
                ldap_unbind($conn);
                throw new \RuntimeException("Connexion LDAP anonyme refusée: {$err}");
            }
        }

        return $conn;
    }

    /**
     * Ouvre une connexion LDAP sans bind (pour tester les credentials utilisateur).
     */
    private function connectRaw(): \LDAP\Connection {
        $host   = $this->config->getAppValue(self::APP_ID, 'ldap_host', '');
        $port   = (int) $this->config->getAppValue(self::APP_ID, 'ldap_port', '389');
        $useTls = $this->config->getAppValue(self::APP_ID, 'ldap_tls', '0') === '1';
        $scheme = $useTls ? 'ldaps' : 'ldap';

        if ($useTls) {
            ldap_set_option(null, LDAP_OPT_X_TLS_REQUIRE_CERT, LDAP_OPT_X_TLS_NEVER);
        }

        $conn = @ldap_connect("{$scheme}://{$host}:{$port}");
        if (!$conn) {
            throw new \RuntimeException("Connexion LDAP impossible vers {$scheme}://{$host}:{$port}");
        }

        ldap_set_option($conn, LDAP_OPT_PROTOCOL_VERSION, 3);
        ldap_set_option($conn, LDAP_OPT_REFERRALS, 0);
        ldap_set_option($conn, LDAP_OPT_NETWORK_TIMEOUT, 10);

        return $conn;
    }

    // ─── Authentification utilisateur ────────────────────────────────────────

    /**
     * Normalise le login en retirant le préfixe domaine Windows (DOMAIN\user → user).
     * Nécessaire car sAMAccountName ne contient pas le préfixe domaine dans l'AD.
     */
    private function stripDomainPrefix(string $login): string {
        $pos = strpos($login, '\\');
        return $pos !== false ? substr($login, $pos + 1) : $login;
    }

    /**
     * Construit le filtre LDAP pour rechercher un utilisateur par son nom de connexion.
     * Gère les trois formats courants : sAMAccountName, DOMAIN\user, et UPN (user@domain).
     */
    private function buildUserSearchFilter(string $login, string $attr): string {
        $escaped = ldap_escape($login, '', LDAP_ESCAPE_FILTER);

        // Format UPN (user@domain.com) : chercher aussi par userPrincipalName
        if (strpos($login, '@') !== false) {
            return "(|({$attr}={$escaped})(userPrincipalName={$escaped}))";
        }

        return "({$attr}={$escaped})";
    }

    /**
     * Valide le login/mot de passe d'un utilisateur contre l'AD Synology.
     *
     * Flux :
     *  1. Trouve le DN de l'utilisateur via le compte de service
     *  2. Ouvre une connexion séparée et tente un bind avec ses identifiants
     *  3. Retourne l'UID Nextcloud (= sAMAccountName) en cas de succès, null sinon
     *
     * Le mot de passe vide est toujours refusé (protection contre le bind anonyme LDAP).
     */
    public function authenticate(string $loginName, string $password): ?string {
        if (empty($password)) {
            return null;
        }

        // 1. Trouver le DN via le compte de service
        $info = $this->getUserInfo($loginName);
        if ($info === null) {
            $this->logger->warning("[SynoLDAP] Authenticate: utilisateur '{$loginName}' introuvable dans l'AD");
            return null;
        }

        // 2. Tenter le bind avec les identifiants de l'utilisateur
        // @ est intentionnel : comme user_ldap, on supprime le warning PHP (que NC peut convertir
        // en exception) et on lit l'erreur via ldap_errno() après le bind.
        $conn = $this->connectRaw();
        try {
            $bound = @ldap_bind($conn, $info['dn'], $password);
            if (!$bound) {
                $errno = ldap_errno($conn);
                $this->logger->warning(sprintf(
                    '[SynoLDAP] Bind échoué pour %s (DN: %s) — errno %d : %s',
                    $loginName, $info['dn'], $errno, ldap_error($conn)
                ));
            }
        } finally {
            ldap_unbind($conn);
        }

        if ($bound) {
            $this->logger->info("[SynoLDAP] Authentification réussie : {$loginName} → {$info['uid']}");
            return $info['uid'];
        }

        return null;
    }

    /**
     * Vérifie si un utilisateur existe dans l'AD.
     */
    public function userExists(string $uid): bool {
        try {
            return $this->getUserInfo($uid) !== null;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Retourne le nom d'affichage (displayName > cn > prénom+nom > uid).
     */
    public function getUserDisplayName(string $uid): string {
        try {
            $info = $this->getUserInfo($uid);
            return $info['displayName'] ?? $uid;
        } catch (\Throwable) {
            return $uid;
        }
    }

    /**
     * Retourne DN, uid et displayName d'un utilisateur, ou null s'il n'existe pas.
     *
     * Accepte les formats : sAMAccountName, DOMAIN\user, user@domain.com.
     * PHP retourne les noms d'attributs LDAP en minuscules — on accède donc via strtolower().
     *
     * @return array{dn: string, uid: string, displayName: string}|null
     */
    public function getUserInfo(string $uid): ?array {
        $conn         = $this->connect();
        $userBaseDn   = $this->config->getAppValue(self::APP_ID, 'ldap_user_base_dn', '');
        $userNameAttr = $this->config->getAppValue(self::APP_ID, 'ldap_user_attr', 'sAMAccountName');
        $lcAttr       = strtolower($userNameAttr);

        if (empty($userBaseDn)) {
            throw new \RuntimeException('Base DN des utilisateurs non configurée.');
        }

        // Retirer le préfixe domaine Windows si présent (DOMAIN\user → user)
        $login  = $this->stripDomainPrefix($uid);
        $filter = $this->buildUserSearchFilter($login, $userNameAttr);
        $search = @ldap_search(
            $conn, $userBaseDn, $filter,
            [$userNameAttr, 'cn', 'displayName', 'givenName', 'sn', 'mail'],
            0, 1
        );

        if (!$search || ldap_count_entries($conn, $search) === 0) {
            return null;
        }

        $entry = ldap_first_entry($conn, $search);
        $dn    = ldap_get_dn($conn, $entry);

        // ldap_get_attributes() renvoie la casse du serveur (ex. Synology AD : "displayName", "givenName").
        // On normalise immédiatement toutes les clés en minuscules pour des accès cohérents.
        $raw   = ldap_get_attributes($conn, $entry);
        $attrs = [];
        foreach ($raw as $key => $value) {
            if (is_string($key)) {
                $attrs[strtolower($key)] = $value;
            }
        }

        // Nom d'affichage : displayName > cn > givenName sn > uid
        $displayName = $attrs['displayname'][0] ?? $attrs['cn'][0] ?? null;

        if ($displayName === null) {
            $first = $attrs['givenname'][0] ?? '';
            $last  = $attrs['sn'][0] ?? '';
            $displayName = trim("{$first} {$last}") ?: $login;
        }

        return [
            'dn'          => $dn,
            'uid'         => $attrs[$lcAttr][0] ?? $login,
            'displayName' => $displayName,
            'email'       => $attrs['mail'][0] ?? '',
        ];
    }

    // ─── Groupes ──────────────────────────────────────────────────────────────

    /**
     * Retourne la liste des groupes AD dont l'utilisateur est membre.
     */
    public function getUserGroups(string $uid): array {
        $conn = $this->connect();

        $mode         = $this->config->getAppValue(self::APP_ID, 'ldap_membership_mode', 'memberof');
        $userBaseDn   = $this->config->getAppValue(self::APP_ID, 'ldap_user_base_dn', '');
        $userNameAttr = $this->config->getAppValue(self::APP_ID, 'ldap_user_attr', 'sAMAccountName');

        if ($mode === 'memberof') {
            return $this->getGroupsViaMemberOf($conn, $uid, $userBaseDn, $userNameAttr);
        }
        return $this->getGroupsViaSearch($conn, $uid);
    }

    private function getGroupsViaMemberOf(
        \LDAP\Connection $conn,
        string $uid,
        string $userBaseDn,
        string $userNameAttr,
    ): array {
        if (empty($userBaseDn)) {
            throw new \RuntimeException('Base DN des utilisateurs non configurée.');
        }

        $escaped = ldap_escape($uid, '', LDAP_ESCAPE_FILTER);
        $filter  = "({$userNameAttr}={$escaped})";
        $search  = @ldap_search($conn, $userBaseDn, $filter, ['memberof']);

        if (!$search || ldap_count_entries($conn, $search) === 0) {
            return [];
        }

        $entry    = ldap_first_entry($conn, $search);
        $memberOf = @ldap_get_values($conn, $entry, 'memberof');

        if (!$memberOf || (int)($memberOf['count'] ?? 0) === 0) {
            return [];
        }

        $groups = [];
        for ($i = 0; $i < (int)$memberOf['count']; $i++) {
            if (preg_match('/^CN=([^,]+)/i', $memberOf[$i], $m)) {
                $groups[] = $m[1];
            }
        }

        return $groups;
    }

    private function getGroupsViaSearch(\LDAP\Connection $conn, string $uid): array {
        $groupBaseDn   = $this->config->getAppValue(self::APP_ID, 'ldap_group_base_dn', '');
        $groupObjClass = $this->config->getAppValue(self::APP_ID, 'ldap_group_filter', 'posixGroup');
        $memberAttr    = $this->config->getAppValue(self::APP_ID, 'ldap_member_attr', 'memberUid');
        $groupNameAttr = $this->config->getAppValue(self::APP_ID, 'ldap_group_name_attr', 'cn');

        if (empty($groupBaseDn)) {
            throw new \RuntimeException('Base DN des groupes non configurée.');
        }

        $escaped = ldap_escape($uid, '', LDAP_ESCAPE_FILTER);
        $filter  = "(&(objectClass={$groupObjClass})({$memberAttr}={$escaped}))";
        $search  = @ldap_search($conn, $groupBaseDn, $filter, [$groupNameAttr]);

        if (!$search) {
            throw new \RuntimeException('Erreur recherche LDAP groupes: ' . ldap_error($conn));
        }

        $entries   = ldap_get_entries($conn, $search);
        $lcNameAttr = strtolower($groupNameAttr);
        $groups    = [];

        for ($i = 0; $i < (int)$entries['count']; $i++) {
            if (!empty($entries[$i][$lcNameAttr][0])) {
                $groups[] = $entries[$i][$lcNameAttr][0];
            }
        }

        return $groups;
    }

    // ─── Enumération ─────────────────────────────────────────────────────────

    /**
     * Retourne les UIDs des utilisateurs LDAP (avec recherche, limite, offset).
     * Utilisé par le backend NC pour la liste d'utilisateurs et le partage.
     */
    public function getAllUserUids(string $search = '', ?int $limit = null, ?int $offset = null): array {
        $conn = $this->connect();

        $userBaseDn   = $this->config->getAppValue(self::APP_ID, 'ldap_user_base_dn', '');
        $userObjClass = $this->config->getAppValue(self::APP_ID, 'ldap_user_object_class', 'user');
        $userNameAttr = $this->config->getAppValue(self::APP_ID, 'ldap_user_attr', 'sAMAccountName');

        if (empty($userBaseDn)) {
            ldap_unbind($conn);
            return [];
        }

        // Exclure les comptes désactivés (userAccountControl bit 2)
        $strictFilter   = "(&(objectClass={$userObjClass})(!(userAccountControl:1.2.840.113556.1.4.803:=2)))";
        $fallbackFilter = "(objectClass={$userObjClass})";

        if (!empty($search)) {
            $esc = ldap_escape($search, '', LDAP_ESCAPE_FILTER);
            $searchPart   = "({$userNameAttr}=*{$esc}*)";
            $strictFilter   = "(&{$strictFilter}{$searchPart})";
            $fallbackFilter = "(&{$fallbackFilter}{$searchPart})";
        }

        $sizelimit = $limit !== null ? (int)$limit + (int)($offset ?? 0) : 1000;
        $result    = @ldap_search($conn, $userBaseDn, $strictFilter, [$userNameAttr], 0, $sizelimit);

        // Samba 4 peut retourner un résultat vide (count=0) au lieu de false pour
        // le filtre OID userAccountControl non supporté — fallback dans les deux cas.
        $isEmpty = !$result || ldap_count_entries($conn, $result) === 0;
        if ($isEmpty) {
            $result = @ldap_search($conn, $userBaseDn, $fallbackFilter, [$userNameAttr], 0, $sizelimit);
        }

        if (!$result) {
            return [];
        }

        $entries = ldap_get_entries($conn, $result);

        // ldap_get_entries() retourne les noms d'attributs en minuscules
        $lcAttr = strtolower($userNameAttr);
        $uids   = [];
        for ($i = 0; $i < (int)$entries['count']; $i++) {
            if (!empty($entries[$i][$lcAttr][0])) {
                $uids[] = $entries[$i][$lcAttr][0];
            }
        }

        if ($offset !== null) {
            $uids = array_slice($uids, $offset);
        }
        if ($limit !== null) {
            $uids = array_slice($uids, 0, $limit);
        }

        return $uids;
    }

    // ─── Vérification groupe ─────────────────────────────────────────────────

    /**
     * Vérifie si un groupe existe dans l'AD LDAP (par son cn).
     * Utilisé pour ne retirer un utilisateur NC d'un groupe que si ce groupe
     * est géré par l'AD (évite de toucher aux groupes NC purement locaux).
     */
    public function isKnownLdapGroup(string $groupName): bool {
        $groupBaseDn   = $this->config->getAppValue(self::APP_ID, 'ldap_group_base_dn', '');
        $groupObjClass = $this->config->getAppValue(self::APP_ID, 'ldap_group_filter', 'group');

        if (empty($groupBaseDn)) {
            return false;
        }

        try {
            $conn    = $this->connect();
            $escaped = ldap_escape($groupName, '', LDAP_ESCAPE_FILTER);
            $filter  = "(&(objectClass={$groupObjClass})(cn={$escaped}))";
            $result  = @ldap_search($conn, $groupBaseDn, $filter, ['cn'], 0, 1);
            return $result && ldap_count_entries($conn, $result) > 0;
        } catch (\Throwable) {
            return false;
        }
    }

    // ─── Test connexion ───────────────────────────────────────────────────────

    public function testConnection(): array {
        try {
            $conn = $this->connect();

            $host        = $this->config->getAppValue(self::APP_ID, 'ldap_host', '');
            $port        = $this->config->getAppValue(self::APP_ID, 'ldap_port', '389');
            $groupBaseDn = $this->config->getAppValue(self::APP_ID, 'ldap_group_base_dn', '');

            $details = "Connecté à {$host}:{$port}";
            $groups  = [];

            if (!empty($groupBaseDn)) {
                $mode     = $this->config->getAppValue(self::APP_ID, 'ldap_membership_mode', 'memberof');
                $objClass = $mode === 'memberof'
                    ? 'group'
                    : $this->config->getAppValue(self::APP_ID, 'ldap_group_filter', 'posixGroup');

                $s = @ldap_search($conn, $groupBaseDn, "(objectClass={$objClass})", ['cn'], 0, 50);
                if ($s) {
                    $entries = ldap_get_entries($conn, $s);
                    for ($i = 0; $i < min((int)$entries['count'], 15); $i++) {
                        if (!empty($entries[$i]['cn'][0])) {
                            $groups[] = $entries[$i]['cn'][0];
                        }
                    }
                    $details .= ' — ' . (int)$entries['count'] . ' groupe(s) trouvé(s)';
                }
            }

            return ['success' => true, 'message' => $details, 'groups' => $groups];
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => $e->getMessage(), 'groups' => []];
        }
    }
}
