<?php
declare(strict_types=1);

namespace OCA\SynoLDAP\Service;

use OCP\IConfig;
use Psr\Log\LoggerInterface;

/**
 * Configure user_ldap automatiquement depuis les paramètres synoldap.
 *
 * Principe : user_ldap fonctionne parfaitement avec NC 33 (sessions, DAV_AUTHENTICATED,
 * remember me, etc.). Au lieu de réimplémenter tout ça, synoldap écrit ses paramètres
 * LDAP dans l'espace de configuration de user_ldap — user_ldap gère l'authentification,
 * synoldap ajoute les fonctionnalités Synology (groupes, montages SMB, ACL).
 *
 * La configuration user_ldap est mise à jour à chaque saveConfig() ET à chaque boot
 * (si la valeur a changé). user_ldap lit sa config depuis oc_appconfig à chaque démarrage
 * → synchronisation automatique.
 *
 * Préfixe utilisé : vide (première configuration user_ldap, la plus simple).
 */
class UserLdapBridgeService {
    private const APP_ID     = 'synoldap';
    private const UL_APP_ID  = 'user_ldap';

    // Préfixe user_ldap : vide = première configuration (le plus courant)
    private const UL_PREFIX  = '';

    public function __construct(
        private IConfig $config,
        private LoggerInterface $logger,
    ) {}

    /**
     * Vérifie si user_ldap est disponible (classes présentes dans l'instance NC).
     */
    public function isUserLdapAvailable(): bool {
        return class_exists('\OCA\User_LDAP\Configuration');
    }

    /**
     * Synchronise les paramètres LDAP de synoldap vers user_ldap.
     * Idempotent : n'écrit en DB que si les valeurs ont changé.
     * Retourne true si la synchronisation a réussi, false sinon.
     */
    public function sync(): bool {
        if (!$this->isUserLdapAvailable()) {
            $this->logger->debug('[SynoLDAP] user_ldap non disponible — bridge non activé');
            return false;
        }

        $host = $this->config->getAppValue(self::APP_ID, 'ldap_host', '');
        if (empty($host)) {
            return false; // Pas encore configuré
        }

        try {
            $this->writeUserLdapConfig();
            return true;
        } catch (\Throwable $e) {
            $this->logger->warning('[SynoLDAP] Erreur sync user_ldap: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Écrit la configuration user_ldap via sa propre classe Configuration.
     * user_ldap::Configuration gère le chiffrement du mot de passe (base64),
     * la sérialisation des tableaux, et l'écriture en DB.
     */
    private function writeUserLdapConfig(): void {
        $syno = $this->getSynoldapConfig();

        /** @var \OCA\User_LDAP\Configuration $conf */
        $conf = new \OCA\User_LDAP\Configuration(self::UL_PREFIX, false);

        // Connexion LDAP
        $conf->ldapHost            = $syno['host'];
        $conf->ldapPort            = $syno['port'];
        $conf->ldapTLS             = $syno['tls'];
        $conf->turnOffCertCheck    = '1'; // Synology AD : certificat auto-signé fréquent
        $conf->ldapAgentName       = $syno['bind_dn'];
        $conf->ldapAgentPassword   = $syno['bind_password'];

        // Base DN
        $conf->ldapBase            = $syno['user_base_dn'];
        $conf->ldapBaseUsers       = $syno['user_base_dn'];
        $conf->ldapBaseGroups      = $syno['group_base_dn'] ?: $syno['user_base_dn'];

        // Filtres utilisateurs
        $conf->ldapUserFilter          = '(objectClass=' . $syno['user_object_class'] . ')';
        $conf->ldapUserFilterObjectclass = $syno['user_object_class'];
        $conf->ldapUserFilterMode      = '1';
        $conf->ldapUserDisplayName     = 'displayName';

        // Attribut UID (sAMAccountName pour Synology AD)
        $conf->ldapExpertUsernameAttr  = $syno['user_attr'];
        $conf->ldapLoginFilter         = '(&(objectClass=' . $syno['user_object_class'] . ')('
                                         . $syno['user_attr'] . '=%uid))';
        $conf->ldapLoginFilterUsername  = '1';
        $conf->ldapLoginFilterMode      = '0';

        // Filtres groupes
        $conf->ldapGroupFilter          = '(objectClass=group)';
        $conf->ldapGroupFilterObjectclass = 'group';
        $conf->ldapGroupFilterMode      = '0';
        $conf->ldapGroupMemberAssocAttr = 'member';
        $conf->ldapGroupDisplayName     = 'cn';

        // UUID (objectGUID pour Active Directory)
        $conf->ldapUuidUserAttribute    = 'objectGUID';
        $conf->ldapUuidGroupAttribute   = 'objectGUID';

        // Performances
        $conf->ldapCacheTTL             = '600';
        $conf->ldapPagingSize           = '500';
        $conf->ldapNestedGroups         = '0';
        $conf->useMemberOfToDetectMembership = '1';

        // Activer cette configuration
        $conf->ldapConfigurationActive  = '1';

        $conf->saveConfiguration();

        // Enregistrer le préfixe dans la liste user_ldap (pour NC 33)
        $this->registerPrefix();

        $this->logger->info('[SynoLDAP] user_ldap configuré automatiquement depuis synoldap ('
                            . $syno['host'] . ':' . $syno['port'] . ')');
    }

    /**
     * Enregistre notre préfixe dans la liste de préfixes connue de user_ldap.
     * En NC 33, user_ldap lit cette liste depuis oc_appconfig[user_ldap][configuration_prefixes].
     */
    private function registerPrefix(): void {
        $currentJson = $this->config->getAppValue(self::UL_APP_ID, 'configuration_prefixes', 'UNFILLED');

        if ($currentJson === 'UNFILLED') {
            // Première configuration : utiliser le préfixe vide
            $this->config->setAppValue(self::UL_APP_ID, 'configuration_prefixes', '[""]');
        } else {
            // Ajouter notre préfixe si pas déjà présent
            $prefixes = json_decode($currentJson, true);
            if (is_array($prefixes) && !in_array(self::UL_PREFIX, $prefixes, true)) {
                $prefixes[] = self::UL_PREFIX;
                $this->config->setAppValue(self::UL_APP_ID, 'configuration_prefixes', json_encode($prefixes));
            }
        }
    }

    /**
     * Lit et retourne la configuration LDAP de synoldap.
     */
    private function getSynoldapConfig(): array {
        return [
            'host'             => $this->config->getAppValue(self::APP_ID, 'ldap_host', ''),
            'port'             => $this->config->getAppValue(self::APP_ID, 'ldap_port', '389'),
            'tls'              => $this->config->getAppValue(self::APP_ID, 'ldap_tls', '0'),
            'bind_dn'          => $this->config->getAppValue(self::APP_ID, 'ldap_bind_dn', ''),
            'bind_password'    => $this->config->getAppValue(self::APP_ID, 'ldap_bind_password', ''),
            'user_base_dn'     => $this->config->getAppValue(self::APP_ID, 'ldap_user_base_dn', ''),
            'group_base_dn'    => $this->config->getAppValue(self::APP_ID, 'ldap_group_base_dn', ''),
            'user_attr'        => $this->config->getAppValue(self::APP_ID, 'ldap_user_attr', 'sAMAccountName'),
            'user_object_class'=> $this->config->getAppValue(self::APP_ID, 'ldap_user_object_class', 'user'),
        ];
    }

    /**
     * Retourne des informations sur la configuration user_ldap courante.
     */
    public function getStatus(): array {
        if (!$this->isUserLdapAvailable()) {
            return ['available' => false, 'message' => 'App user_ldap non disponible'];
        }

        $host = $this->config->getAppValue(self::APP_ID, 'ldap_host', '');
        if (empty($host)) {
            return ['available' => true, 'configured' => false, 'message' => 'LDAP non configuré dans synoldap'];
        }

        $ulHost = $this->config->getAppValue(self::UL_APP_ID, self::UL_PREFIX . 'ldap_host', '');
        $active = $this->config->getAppValue(self::UL_APP_ID, self::UL_PREFIX . 'ldap_configuration_active', '0');

        return [
            'available'  => true,
            'configured' => ($ulHost === $host && $active === '1'),
            'ul_host'    => $ulHost,
            'ul_active'  => $active === '1',
            'message'    => $active === '1' && $ulHost === $host
                ? "user_ldap configuré sur {$ulHost}"
                : "user_ldap non synchronisé (host: {$ulHost}, actif: {$active})",
        ];
    }
}
