<?php
declare(strict_types=1);

namespace OCA\SynoLDAP\Controller;

use OCA\SynoLDAP\Service\GroupSyncService;
use OCA\SynoLDAP\Service\LdapService;
use OCA\SynoLDAP\Service\StorageConfigService;
use OCA\SynoLDAP\Service\SynologyApiService;
use OCA\SynoLDAP\Service\UserLdapBridgeService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\AdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IConfig;
use OCP\IRequest;

class AdminController extends Controller {
    private const APP_ID = 'synoldap';

    private const CONFIG_KEYS = [
        'ldap_host',
        'ldap_port',
        'ldap_tls',
        'ldap_bind_dn',
        'ldap_user_base_dn',
        'ldap_user_attr',
        'ldap_user_object_class',
        'ldap_group_base_dn',
        'ldap_group_filter',
        'ldap_member_attr',
        'ldap_group_name_attr',
        'ldap_membership_mode',
        'admin_ldap_group',
        'synology_host',
        'synology_smb_user',
        'synology_smb_domain',
        // API DSM
        'synology_api_port',
        'synology_api_ssl',
        'synology_api_user',
    ];

    public function __construct(
        IRequest $request,
        private IConfig $config,
        private LdapService $ldapService,
        private GroupSyncService $groupSyncService,
        private StorageConfigService $storageConfigService,
        private SynologyApiService $synoApiService,
        private UserLdapBridgeService $userLdapBridge,
    ) {
        parent::__construct(self::APP_ID, $request);
    }

    /**
     * @AdminRequired
     * @NoCSRFRequired
     */
    #[AdminRequired]
    #[NoCSRFRequired]
    public function getConfig(): JSONResponse {
        $data = [];
        foreach (self::CONFIG_KEYS as $key) {
            $data[$key] = $this->config->getAppValue(self::APP_ID, $key, $this->getDefault($key));
        }
        $data['group_mappings'] = json_decode(
            $this->config->getAppValue(self::APP_ID, 'group_mappings', '[]'),
            true
        ) ?? [];
        // Mots de passe toujours masqués en lecture
        $data['ldap_bind_password']      = '';
        $data['synology_smb_password']   = '';
        $data['synology_api_password']   = '';

        return new JSONResponse($data);
    }

    /**
     * @AdminRequired
     */
    #[AdminRequired]
    public function saveConfig(): JSONResponse {
        $params = $this->request->getParams();

        foreach (self::CONFIG_KEYS as $key) {
            if (array_key_exists($key, $params)) {
                $this->config->setAppValue(self::APP_ID, $key, (string) $params[$key]);
            }
        }

        if (!empty($params['ldap_bind_password'])) {
            $this->config->setAppValue(self::APP_ID, 'ldap_bind_password', $params['ldap_bind_password']);
        }
        if (!empty($params['synology_smb_password'])) {
            $this->config->setAppValue(self::APP_ID, 'synology_smb_password', $params['synology_smb_password']);
        }
        if (!empty($params['synology_api_password'])) {
            $this->config->setAppValue(self::APP_ID, 'synology_api_password', $params['synology_api_password']);
        }

        if (array_key_exists('group_mappings', $params)) {
            $mappings = is_string($params['group_mappings'])
                ? json_decode($params['group_mappings'], true)
                : $params['group_mappings'];
            $this->config->setAppValue(self::APP_ID, 'group_mappings', json_encode($mappings ?? []));
        }

        // Synchroniser automatiquement user_ldap avec les nouveaux paramètres LDAP.
        // user_ldap gère l'authentification → cette sync est critique.
        $synced = $this->userLdapBridge->sync();

        $message = 'Configuration sauvegardée avec succès.';
        if ($synced) {
            $message .= ' user_ldap configuré automatiquement.';
        } elseif (!$this->userLdapBridge->isUserLdapAvailable()) {
            $message .= ' ⚠️ App user_ldap non disponible — installez-la pour l\'authentification LDAP.';
        }

        return new JSONResponse(['success' => true, 'message' => $message, 'user_ldap_synced' => $synced]);
    }

    /**
     * Retourne le statut de la configuration user_ldap.
     * @AdminRequired
     * @NoCSRFRequired
     */
    #[AdminRequired]
    #[NoCSRFRequired]
    public function getUserLdapStatus(): JSONResponse {
        return new JSONResponse($this->userLdapBridge->getStatus());
    }

    /**
     * Force la synchronisation de la config LDAP vers user_ldap.
     * @AdminRequired
     */
    #[AdminRequired]
    public function syncUserLdap(): JSONResponse {
        $synced = $this->userLdapBridge->sync();
        $status = $this->userLdapBridge->getStatus();
        return new JSONResponse([
            'success' => $synced,
            'message' => $synced
                ? 'user_ldap configuré avec succès. Rechargez la page pour prendre en compte les changements.'
                : ('Échec : ' . ($status['message'] ?? 'erreur inconnue')),
            'status' => $status,
        ]);
    }

    /**
     * @AdminRequired
     */
    #[AdminRequired]
    public function testLdap(): JSONResponse {
        return new JSONResponse($this->ldapService->testConnection());
    }

    /**
     * @AdminRequired
     */
    #[AdminRequired]
    public function testDsmApi(): JSONResponse {
        return new JSONResponse($this->synoApiService->testConnection());
    }

    /**
     * Teste la connexion SMB vers le Synology.
     * Essaie de lister les partages disponibles avec les credentials configurés.
     *
     * @AdminRequired
     */
    #[AdminRequired]
    public function testSmb(): JSONResponse {
        $host   = $this->config->getAppValue(self::APP_ID, 'synology_host', '');
        $user   = $this->config->getAppValue(self::APP_ID, 'synology_smb_user', '');
        $pass   = $this->config->getAppValue(self::APP_ID, 'synology_smb_password', '');
        $domain = $this->config->getAppValue(self::APP_ID, 'synology_smb_domain', 'WORKGROUP');

        if (empty($host)) {
            return new JSONResponse(['success' => false, 'message' => 'Hôte Synology non configuré.']);
        }
        if (empty($user)) {
            return new JSONResponse(['success' => false, 'message' => 'Utilisateur SMB non configuré.']);
        }

        // Test 1 : connectivité réseau sur le port SMB
        $socket = @fsockopen($host, 445, $errno, $errstr, 5);
        if (!$socket) {
            return new JSONResponse([
                'success' => false,
                'message' => "Port SMB (445) inaccessible sur {$host} — {$errstr} ({$errno})",
            ]);
        }
        fclose($socket);

        // Test 2 : authentification SMB via la bibliothèque icewind/smb (bundlée avec files_external)
        if (!class_exists(\Icewind\SMB\BasicAuth::class)) {
            return new JSONResponse([
                'success' => true,
                'message' => "Port 445 accessible sur {$host}. (Test auth ignoré — files_external non activée)",
            ]);
        }

        try {
            $auth    = new \Icewind\SMB\BasicAuth($user, $domain, $pass);
            $factory = new \Icewind\SMB\ServerFactory();
            $server  = $factory->createServer($host, $auth);
            $shares  = $server->listShares();
            $names   = array_filter(
                array_map(fn($s) => $s->getName(), $shares),
                fn($n) => !str_starts_with($n, 'IPC') && !str_ends_with($n, '$')
            );
            $count = count($names);
            return new JSONResponse([
                'success' => true,
                'message' => "Connexion SMB réussie — {$count} partage(s) visible(s) : " . implode(', ', array_values($names)),
                'shares'  => array_values($names),
            ]);
        } catch (\Throwable $e) {
            return new JSONResponse([
                'success' => false,
                'message' => "Authentification SMB échouée : " . $e->getMessage(),
            ]);
        }
    }

    /**
     * Prévisualise les mappings ACL découverts sur un partage Synology.
     * Résultat : ['Compta' => ['Responsable', 'Compta'], 'RH' => ['RH'], ...]
     *
     * @AdminRequired
     * @NoCSRFRequired
     */
    #[AdminRequired]
    #[NoCSRFRequired]
    public function discoverAcl(): JSONResponse {
        $share = trim($this->request->getParam('share', ''));
        if (empty($share)) {
            return new JSONResponse(['success' => false, 'message' => 'Paramètre "share" manquant.']);
        }

        try {
            $mappings = $this->synoApiService->discoverAclMappings($share);
            return new JSONResponse(['success' => true, 'mappings' => $mappings]);
        } catch (\Throwable $e) {
            return new JSONResponse(['success' => false, 'message' => $e->getMessage()]);
        }
    }

    /**
     * Vide le cache ACL (à utiliser après modification des droits sur Synology).
     *
     * @AdminRequired
     */
    #[AdminRequired]
    public function clearAclCache(): JSONResponse {
        $this->synoApiService->clearAclCache();
        return new JSONResponse(['success' => true, 'message' => 'Cache ACL vidé. Les droits seront relus depuis Synology.']);
    }

    /**
     * @AdminRequired
     */
    #[AdminRequired]
    public function syncAll(): JSONResponse {
        $results = $this->groupSyncService->syncAllUsers();
        $message = "Synchronisation terminée : {$results['synced']} utilisateur(s) synchronisé(s)";
        if ($results['skipped'] > 0) {
            $message .= ", {$results['skipped']} ignoré(s)";
        }
        if (!empty($results['errors'])) {
            $message .= ", " . count($results['errors']) . " erreur(s)";
        }

        return new JSONResponse([
            'success' => empty($results['errors']),
            'message' => $message,
            'details' => $results,
        ]);
    }

    /**
     * @AdminRequired
     */
    #[AdminRequired]
    public function applyStorage(): JSONResponse {
        $mappings = json_decode(
            $this->config->getAppValue(self::APP_ID, 'group_mappings', '[]'),
            true
        ) ?? [];

        $results = $this->storageConfigService->applyMounts($mappings);
        $errors  = array_filter($results, fn($r) => $r['status'] === 'error');

        return new JSONResponse([
            'success' => empty($errors),
            'results' => $results,
        ]);
    }

    /**
     * @AdminRequired
     * @NoCSRFRequired
     */
    #[AdminRequired]
    #[NoCSRFRequired]
    public function getLdapGroups(): JSONResponse {
        $result = $this->ldapService->testConnection();
        return new JSONResponse([
            'success' => $result['success'],
            'groups'  => $result['groups'] ?? [],
        ]);
    }

    private function getDefault(string $key): string {
        return match ($key) {
            'ldap_port'              => '389',
            'ldap_tls'               => '0',
            'ldap_user_attr'         => 'sAMAccountName',
            'ldap_user_object_class' => 'user',
            'ldap_group_filter'      => 'group',
            'ldap_member_attr'       => 'member',
            'ldap_group_name_attr'   => 'cn',
            'ldap_membership_mode'   => 'memberof',
            'admin_ldap_group'       => 'ADMIN_NEXTCLOUD',
            'synology_smb_domain'    => 'WORKGROUP',
            'synology_api_port'      => '5000',
            'synology_api_ssl'       => '0',
            default                  => '',
        };
    }
}
