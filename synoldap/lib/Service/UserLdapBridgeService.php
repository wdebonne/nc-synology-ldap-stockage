<?php
declare(strict_types=1);

namespace OCA\SynoLDAP\Service;

use OCP\IConfig;
use Psr\Log\LoggerInterface;

/**
 * Configure user_ldap depuis les paramètres synoldap en écrivant directement
 * dans oc_appconfig — sans dépendance aux classes internes de user_ldap.
 *
 * user_ldap lit sa config depuis oc_appconfig[user_ldap]. En écrivant les
 * bons paramètres, user_ldap utilise la connexion Synology AD exactement
 * comme si l'admin l'avait configurée manuellement.
 *
 * Mot de passe : base64_encode() — format utilisé par user_ldap (Configuration.php:347).
 * Préfixe     : '' (premier serveur, sans préfixe) → clés sans préfixe (ldap_host, …)
 */
class UserLdapBridgeService {
    private const APP_ID    = 'synoldap';
    private const UL_APP    = 'user_ldap';
    private const UL_PREFIX = ''; // Premier serveur user_ldap (sans préfixe)

    public function __construct(
        private IConfig $config,
        private LoggerInterface $logger,
    ) {}

    public function isUserLdapAvailable(): bool {
        // Vérifie si user_ldap est installé et activé dans NC
        $apps = $this->config->getSystemValue('apps_paths', []);
        // Approche plus simple : vérifier si la classe est chargeable
        return class_exists('\OCA\User_LDAP\Helper') || $this->isUserLdapEnabled();
    }

    private function isUserLdapEnabled(): bool {
        $enabled = $this->config->getAppValue('core', 'installedversion', '');
        // Vérifier via oc_appconfig si user_ldap est listé comme activé
        $ulVersion = $this->config->getAppValue('user_ldap', 'installed_version', '');
        return !empty($ulVersion);
    }

    /**
     * Synchronise la config LDAP de synoldap vers user_ldap.
     * Utilise IConfig::setAppValue() directement → simple, fiable, pas de dépendance interne.
     */
    public function sync(): bool {
        $host = $this->config->getAppValue(self::APP_ID, 'ldap_host', '');
        if (empty($host)) {
            $this->logger->debug('[SynoLDAP] Bridge: ldap_host non configuré');
            return false;
        }

        try {
            $this->writeConfig();
            $this->logger->info('[SynoLDAP] user_ldap configuré automatiquement (' . $host . ')');
            return true;
        } catch (\Throwable $e) {
            $this->logger->error('[SynoLDAP] Bridge sync échoué: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Écrit tous les paramètres LDAP dans oc_appconfig[user_ldap].
     */
    private function writeConfig(): void {
        $c = $this->getSynoldapConfig();
        $p = self::UL_PREFIX;
        $a = self::UL_APP;

        // ── Serveur ───────────────────────────────────────────────────────────
        $this->set($a, $p . 'ldap_host',                 $c['host']);
        $this->set($a, $p . 'ldap_port',                 $c['port']);
        $this->set($a, $p . 'ldap_tls',                  $c['tls']);
        $this->set($a, $p . 'ldap_turn_off_cert_check',  '1'); // Synology : cert auto-signé fréquent

        // ── Compte de service ─────────────────────────────────────────────────
        $this->set($a, $p . 'ldap_agent_name',           $c['bind_dn']);
        // Mot de passe : base64_encode comme user_ldap::Configuration::saveConfiguration()
        if (!empty($c['bind_password'])) {
            $this->set($a, $p . 'ldap_agent_password',   base64_encode($c['bind_password']));
        }

        // ── Base DNs ──────────────────────────────────────────────────────────
        $groupBase = $c['group_base_dn'] ?: $c['user_base_dn'];
        $this->set($a, $p . 'ldap_base',                 $c['user_base_dn']);
        $this->set($a, $p . 'ldap_base_users',           $c['user_base_dn']);
        $this->set($a, $p . 'ldap_base_groups',          $groupBase);

        // ── Filtres utilisateurs ──────────────────────────────────────────────
        $oc   = $c['user_object_class']; // 'user' pour Synology AD
        $attr = $c['user_attr'];         // 'sAMAccountName'
        $this->set($a, $p . 'ldap_userlist_filter',      "(objectClass={$oc})");
        $this->set($a, $p . 'ldap_userfilter_objectclass', $oc);
        $this->set($a, $p . 'ldap_user_filter_mode',     '1');
        $this->set($a, $p . 'ldap_display_name',         'displayName');
        $this->set($a, $p . 'ldap_email_attr',           'mail');

        // ── Filtre de connexion (login) ───────────────────────────────────────
        $this->set($a, $p . 'ldap_login_filter',         "(&(objectClass={$oc})({$attr}=%uid))");
        $this->set($a, $p . 'ldap_login_filter_mode',    '0');
        $this->set($a, $p . 'ldap_login_filter_username','1');
        $this->set($a, $p . 'ldap_login_filter_email',   '0');

        // ── Attribut UID (sAMAccountName pour Synology AD) ───────────────────
        $this->set($a, $p . 'ldap_expert_username_attr', $attr);

        // ── Groupes ───────────────────────────────────────────────────────────
        $this->set($a, $p . 'ldap_group_filter',         '(objectClass=group)');
        $this->set($a, $p . 'ldap_group_filter_mode',    '0');
        $this->set($a, $p . 'ldap_group_filter_objectclass', 'group');
        $this->set($a, $p . 'ldap_group_display_name',   'cn');
        $this->set($a, $p . 'ldap_group_member_assoc_attribute', 'member');

        // ── UUID (objectGUID pour Active Directory) ───────────────────────────
        $this->set($a, $p . 'ldap_uuid_user_attribute',  'objectGUID');
        $this->set($a, $p . 'ldap_uuid_group_attribute', 'objectGUID');

        // ── Performances ──────────────────────────────────────────────────────
        $this->set($a, $p . 'ldap_cache_ttl',            '600');
        $this->set($a, $p . 'ldap_paging_size',          '500');
        $this->set($a, $p . 'ldap_nested_groups',        '0');
        $this->set($a, $p . 'ldap_use_member_of_to_detect_membership', '1');

        // ── Activer cette configuration ───────────────────────────────────────
        $this->set($a, $p . 'ldap_configuration_active', '1');

        // ── Enregistrer le préfixe pour que user_ldap le détecte ─────────────
        // En NC 33, user_ldap cherche d'abord 'configuration_prefixes'.
        // S'il ne trouve pas, il utilise le fallback (scan des clés _ldap_configuration_active).
        // On écrit les deux pour maximiser la compatibilité.
        $this->registerPrefixLegacy();
    }

    /**
     * Méthode legacy : écrit le préfixe dans configuration_prefixes (format JSON string).
     * user_ldap NC 33 lit ce champ via IAppConfig::getValueArray().
     * En cas de mismatch de type, il utilise le fallback (scan des clés actives) → OK.
     */
    private function registerPrefixLegacy(): void {
        $current = $this->config->getAppValue(self::UL_APP, 'configuration_prefixes', '');
        if ($current === '' || $current === 'UNFILLED') {
            // Première config : préfixe vide
            $this->config->setAppValue(self::UL_APP, 'configuration_prefixes', json_encode(['']));
        } else {
            $prefixes = @json_decode($current, true);
            if (is_array($prefixes) && !in_array(self::UL_PREFIX, $prefixes, true)) {
                $prefixes[] = self::UL_PREFIX;
                $this->config->setAppValue(self::UL_APP, 'configuration_prefixes', json_encode($prefixes));
            }
        }
    }

    private function set(string $app, string $key, string $value): void {
        $this->config->setAppValue($app, $key, $value);
    }

    private function getSynoldapConfig(): array {
        return [
            'host'              => $this->config->getAppValue(self::APP_ID, 'ldap_host', ''),
            'port'              => $this->config->getAppValue(self::APP_ID, 'ldap_port', '389'),
            'tls'               => $this->config->getAppValue(self::APP_ID, 'ldap_tls', '0'),
            'bind_dn'           => $this->config->getAppValue(self::APP_ID, 'ldap_bind_dn', ''),
            'bind_password'     => $this->config->getAppValue(self::APP_ID, 'ldap_bind_password', ''),
            'user_base_dn'      => $this->config->getAppValue(self::APP_ID, 'ldap_user_base_dn', ''),
            'group_base_dn'     => $this->config->getAppValue(self::APP_ID, 'ldap_group_base_dn', ''),
            'user_attr'         => $this->config->getAppValue(self::APP_ID, 'ldap_user_attr', 'sAMAccountName'),
            'user_object_class' => $this->config->getAppValue(self::APP_ID, 'ldap_user_object_class', 'user'),
        ];
    }

    public function getStatus(): array {
        $ulEnabled = $this->isUserLdapEnabled();
        if (!$ulEnabled) {
            return ['available' => false, 'message' => "App user_ldap non installée ou désactivée.\nActivez-la via Administration → Applications."];
        }

        $host   = $this->config->getAppValue(self::APP_ID, 'ldap_host', '');
        $ulHost = $this->config->getAppValue(self::UL_APP, self::UL_PREFIX . 'ldap_host', '');
        $active = $this->config->getAppValue(self::UL_APP, self::UL_PREFIX . 'ldap_configuration_active', '0');

        $synced = ($ulHost === $host && $active === '1' && !empty($host));

        return [
            'available'  => true,
            'configured' => $synced,
            'ul_host'    => $ulHost,
            'ul_active'  => $active === '1',
            'message'    => $synced
                ? "✓ user_ldap configuré sur {$ulHost}"
                : ($ulHost !== $host
                    ? "⚠️ user_ldap pointe sur '{$ulHost}' au lieu de '{$host}' — cliquez Sauvegarder"
                    : "⚠️ user_ldap non actif — cliquez Sauvegarder"),
        ];
    }
}
