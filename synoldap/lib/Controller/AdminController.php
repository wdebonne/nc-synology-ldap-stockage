<?php
declare(strict_types=1);

namespace OCA\SynoLDAP\Controller;

use OCA\SynoLDAP\Service\GroupSyncService;
use OCA\SynoLDAP\Service\LdapService;
use OCA\SynoLDAP\Service\StorageConfigService;
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
    ];

    public function __construct(
        IRequest $request,
        private IConfig $config,
        private LdapService $ldapService,
        private GroupSyncService $groupSyncService,
        private StorageConfigService $storageConfigService,
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
        // Passwords sont toujours masqués en lecture
        $data['ldap_bind_password']     = '';
        $data['synology_smb_password']  = '';

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

        // Mots de passe : sauvegarder uniquement si fournis
        if (!empty($params['ldap_bind_password'])) {
            $this->config->setAppValue(self::APP_ID, 'ldap_bind_password', $params['ldap_bind_password']);
        }
        if (!empty($params['synology_smb_password'])) {
            $this->config->setAppValue(self::APP_ID, 'synology_smb_password', $params['synology_smb_password']);
        }

        // Correspondances de groupes
        if (array_key_exists('group_mappings', $params)) {
            $mappings = is_string($params['group_mappings'])
                ? json_decode($params['group_mappings'], true)
                : $params['group_mappings'];
            $this->config->setAppValue(self::APP_ID, 'group_mappings', json_encode($mappings ?? []));
        }

        return new JSONResponse(['success' => true, 'message' => 'Configuration sauvegardée avec succès.']);
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
    public function syncAll(): JSONResponse {
        $results = $this->groupSyncService->syncAllUsers();
        $message = "Synchronisation terminée : {$results['synced']} utilisateur(s) synchronisé(s)";
        if ($results['skipped'] > 0) {
            $message .= ", {$results['skipped']} ignoré(s) (non présents dans Nextcloud)";
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
            'ldap_port'             => '389',
            'ldap_tls'              => '0',
            'ldap_user_attr'        => 'sAMAccountName',
            'ldap_user_object_class'=> 'user',
            'ldap_group_filter'     => 'group',
            'ldap_member_attr'      => 'member',
            'ldap_group_name_attr'  => 'cn',
            'ldap_membership_mode'  => 'memberof',
            'admin_ldap_group'      => 'ADMIN_NEXTCLOUD',
            'synology_smb_domain'   => 'WORKGROUP',
            default                 => '',
        };
    }
}
