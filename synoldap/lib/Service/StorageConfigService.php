<?php
declare(strict_types=1);

namespace OCA\SynoLDAP\Service;

use OCP\IConfig;
use OCP\IGroupManager;
use Psr\Log\LoggerInterface;

class StorageConfigService {
    private const APP_ID = 'synoldap';

    public function __construct(
        private IConfig $config,
        private IGroupManager $groupManager,
        private LoggerInterface $logger,
    ) {}

    private function getStoragesService(): ?\OCA\Files_External\Service\GlobalStoragesService {
        if (!class_exists(\OCA\Files_External\Service\GlobalStoragesService::class)) {
            return null;
        }

        try {
            return \OC::$server->get(\OCA\Files_External\Service\GlobalStoragesService::class);
        } catch (\Throwable) {
            return null;
        }
    }

    private function getBackendService(): ?\OCA\Files_External\Service\BackendService {
        if (!class_exists(\OCA\Files_External\Service\BackendService::class)) {
            return null;
        }

        try {
            return \OC::$server->get(\OCA\Files_External\Service\BackendService::class);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Crée ou met à jour les montages SMB pour chaque correspondance groupe → partage.
     */
    public function applyMounts(array $mappings): array {
        $storagesService = $this->getStoragesService();
        $backendService  = $this->getBackendService();

        if (!$storagesService || !$backendService) {
            return [['status' => 'error', 'message' => "L'application Files_External n'est pas activée dans Nextcloud."]];
        }

        $backend     = $backendService->getBackend('smb');
        $authMech    = $backendService->getAuthMechanism('password::global');

        if (!$backend) {
            return [['status' => 'error', 'message' => "Backend SMB non disponible. Vérifiez que l'extension PHP smbclient est installée."]];
        }

        if (!$authMech) {
            return [['status' => 'error', 'message' => "Mécanisme d'authentification 'password::global' non disponible."]];
        }

        $synoHost   = $this->config->getAppValue(self::APP_ID, 'synology_host', '');
        $synoUser   = $this->config->getAppValue(self::APP_ID, 'synology_smb_user', '');
        $synoPass   = $this->config->getAppValue(self::APP_ID, 'synology_smb_password', '');
        $synoDomain = $this->config->getAppValue(self::APP_ID, 'synology_smb_domain', 'WORKGROUP');

        if (empty($synoHost)) {
            return [['status' => 'error', 'message' => "Hôte Synology non configuré."]];
        }

        $results          = [];
        $existingStorages = $storagesService->getStorages();

        foreach ($mappings as $mapping) {
            $ldapGroup  = trim($mapping['ldap_group'] ?? '');
            $ncGroup    = trim($mapping['nc_group'] ?? $ldapGroup);
            $share      = trim($mapping['storage_share'] ?? '');
            $subfolder  = trim($mapping['storage_subfolder'] ?? '');
            $mountPoint = trim($mapping['mount_point'] ?? $ncGroup);

            if (empty($share) || empty($ncGroup)) {
                continue;
            }

            // S'assure que le groupe Nextcloud existe
            if (!$this->groupManager->groupExists($ncGroup)) {
                $this->groupManager->createGroup($ncGroup);
                $this->logger->info("[SynoLDAP] Groupe Nextcloud créé lors du montage: {$ncGroup}");
            }

            $mountPoint = '/' . ltrim($mountPoint, '/');

            try {
                $existingMount = $this->findExistingMount($existingStorages, $ncGroup, $mountPoint);

                $backendOptions = [
                    'host'   => $synoHost,
                    'share'  => $share,
                    'root'   => $subfolder,
                    'domain' => $synoDomain,
                ];
                $authOptions = [
                    'user'     => $synoUser,
                    'password' => $synoPass,
                ];

                if ($existingMount !== null) {
                    $existingMount->setBackendOptions($backendOptions);
                    $existingMount->setAuthOptions($authOptions);
                    $storagesService->updateStorage($existingMount);
                    $action = 'mis à jour';
                } else {
                    $storageConfig = new \OCA\Files_External\Lib\StorageConfig();
                    $storageConfig->setMountPoint(ltrim($mountPoint, '/'));
                    $storageConfig->setBackend($backend);
                    $storageConfig->setAuthMechanism($authMech);
                    $storageConfig->setBackendOptions($backendOptions);
                    $storageConfig->setAuthOptions($authOptions);
                    $storageConfig->setApplicableGroups([$ncGroup]);
                    $storagesService->addStorage($storageConfig);
                    $action = 'créé';
                }

                $results[] = [
                    'status'  => 'ok',
                    'group'   => $ncGroup,
                    'mount'   => $mountPoint,
                    'share'   => $share,
                    'message' => "Montage {$action} : {$mountPoint} → //{$synoHost}/{$share}",
                ];
            } catch (\Throwable $e) {
                $this->logger->error("[SynoLDAP] Erreur montage {$ncGroup}: " . $e->getMessage());
                $results[] = [
                    'status'  => 'error',
                    'group'   => $ncGroup,
                    'message' => $e->getMessage(),
                ];
            }
        }

        return $results;
    }

    private function findExistingMount(array $storages, string $groupName, string $mountPoint): mixed {
        foreach ($storages as $storage) {
            $groups = $storage->getApplicableGroups();
            if (
                in_array($groupName, $groups, true) &&
                '/' . ltrim($storage->getMountPoint(), '/') === $mountPoint
            ) {
                return $storage;
            }
        }
        return null;
    }
}
