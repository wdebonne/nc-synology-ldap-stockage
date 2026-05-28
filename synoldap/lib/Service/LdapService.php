<?php
declare(strict_types=1);

namespace OCA\SynoLDAP\Service;

use OCP\IConfig;
use Psr\Log\LoggerInterface;

class LdapService {
    private const APP_ID = 'synoldap';

    public function __construct(
        private IConfig $config,
        private LoggerInterface $logger,
    ) {}

    /**
     * Ouvre et retourne une connexion LDAP authentifiée.
     */
    private function connect(): \LDAP\Connection {
        $host = $this->config->getAppValue(self::APP_ID, 'ldap_host', '');
        $port = (int) $this->config->getAppValue(self::APP_ID, 'ldap_port', '389');
        $useTls = $this->config->getAppValue(self::APP_ID, 'ldap_tls', '0') === '1';

        if (empty($host)) {
            throw new \RuntimeException('Hôte LDAP non configuré.');
        }

        $scheme = $useTls ? 'ldaps' : 'ldap';
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
     * Retourne la liste des groupes AD dont l'utilisateur est membre.
     * Mode AD (memberOf sur l'objet user) ou posixGroup (memberUid), configurable.
     */
    public function getUserGroups(string $uid): array {
        $conn = $this->connect();

        $mode         = $this->config->getAppValue(self::APP_ID, 'ldap_membership_mode', 'memberof');
        $userBaseDn   = $this->config->getAppValue(self::APP_ID, 'ldap_user_base_dn', '');
        $userNameAttr = $this->config->getAppValue(self::APP_ID, 'ldap_user_attr', 'sAMAccountName');

        try {
            if ($mode === 'memberof') {
                return $this->getGroupsViaMemberOf($conn, $uid, $userBaseDn, $userNameAttr);
            }
            return $this->getGroupsViaSearch($conn, $uid);
        } finally {
            ldap_unbind($conn);
        }
    }

    /**
     * Mode AD : lit l'attribut memberOf directement sur l'objet utilisateur.
     */
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
            $this->logger->debug("[SynoLDAP] Utilisateur {$uid} non trouvé dans LDAP (base: {$userBaseDn})");
            return [];
        }

        $entry    = ldap_first_entry($conn, $search);
        $memberOf = @ldap_get_values($conn, $entry, 'memberof');

        if (!$memberOf || (int)($memberOf['count'] ?? 0) === 0) {
            return [];
        }

        $groups = [];
        for ($i = 0; $i < (int)$memberOf['count']; $i++) {
            // Extrait le CN depuis le DN : CN=Compta,CN=Users,DC=domain,DC=local → Compta
            if (preg_match('/^CN=([^,]+)/i', $memberOf[$i], $m)) {
                $groups[] = $m[1];
            }
        }

        return $groups;
    }

    /**
     * Mode posixGroup : cherche les groupes par memberUid.
     */
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

        $entries = ldap_get_entries($conn, $search);
        $groups  = [];

        for ($i = 0; $i < (int)$entries['count']; $i++) {
            if (!empty($entries[$i][$groupNameAttr][0])) {
                $groups[] = $entries[$i][$groupNameAttr][0];
            }
        }

        return $groups;
    }

    /**
     * Retourne tous les UIDs des utilisateurs LDAP (pour la synchro en masse).
     */
    public function getAllUserUids(): array {
        $conn = $this->connect();

        $userBaseDn   = $this->config->getAppValue(self::APP_ID, 'ldap_user_base_dn', '');
        $userObjClass = $this->config->getAppValue(self::APP_ID, 'ldap_user_object_class', 'user');
        $userNameAttr = $this->config->getAppValue(self::APP_ID, 'ldap_user_attr', 'sAMAccountName');

        if (empty($userBaseDn)) {
            ldap_unbind($conn);
            return [];
        }

        $filter = "(&(objectClass={$userObjClass})(!(userAccountControl:1.2.840.113556.1.4.803:=2)))";
        $search = @ldap_search($conn, $userBaseDn, $filter, [$userNameAttr], 0, 1000);

        if (!$search) {
            ldap_unbind($conn);
            return [];
        }

        $entries = ldap_get_entries($conn, $search);
        ldap_unbind($conn);

        $uids = [];
        for ($i = 0; $i < (int)$entries['count']; $i++) {
            if (!empty($entries[$i][$userNameAttr][0])) {
                $uids[] = $entries[$i][$userNameAttr][0];
            }
        }

        return $uids;
    }

    /**
     * Teste la connexion et retourne un rapport.
     */
    public function testConnection(): array {
        try {
            $conn = $this->connect();

            $host        = $this->config->getAppValue(self::APP_ID, 'ldap_host', '');
            $port        = $this->config->getAppValue(self::APP_ID, 'ldap_port', '389');
            $groupBaseDn = $this->config->getAppValue(self::APP_ID, 'ldap_group_base_dn', '');

            $details = "Connecté à {$host}:{$port}";
            $groups  = [];

            if (!empty($groupBaseDn)) {
                $mode = $this->config->getAppValue(self::APP_ID, 'ldap_membership_mode', 'memberof');
                $objClass = $mode === 'memberof' ? 'group' : $this->config->getAppValue(self::APP_ID, 'ldap_group_filter', 'posixGroup');

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

            ldap_unbind($conn);

            return [
                'success' => true,
                'message' => $details,
                'groups'  => $groups,
            ];
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'message' => $e->getMessage(),
                'groups'  => [],
            ];
        }
    }
}
