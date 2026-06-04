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
        private SynologyApiService $synoApiService,
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

    private function getSmbCredentials(): array {
        return [
            'host'   => $this->config->getAppValue(self::APP_ID, 'synology_host', ''),
            'user'   => $this->config->getAppValue(self::APP_ID, 'synology_smb_user', ''),
            'pass'   => $this->config->getAppValue(self::APP_ID, 'synology_smb_password', ''),
            'domain' => $this->config->getAppValue(self::APP_ID, 'synology_smb_domain', 'WORKGROUP'),
        ];
    }

    /**
     * Crée ou met à jour les montages SMB pour toutes les correspondances configurées.
     *
     * Trois modes possibles :
     *  - Manuel         : mapping explicite groupe AD → partage/sous-dossier/point de montage
     *  - Auto par nom   : auto_mode='name' — sous-dossier = nom du groupe NC
     *  - Auto par ACL   : auto_mode='acl'  — ACL lues depuis l'API DSM Synology
     *
     * Le champ mount_prefix (ex. "NAS") préfixe tous les points de montage auto
     * pour reproduire la même arborescence qu'un disque réseau Windows.
     */
    public function applyMounts(array $mappings): array {
        $storagesService = $this->getStoragesService();
        $backendService  = $this->getBackendService();

        if (!$storagesService || !$backendService) {
            return [['status' => 'error', 'message' => "L'application Files_External n'est pas activée dans Nextcloud."]];
        }

        $backend  = $backendService->getBackend('smb');
        $authMech = $backendService->getAuthMechanism('password::global');

        if (!$backend) {
            return [['status' => 'error', 'message' => "Backend SMB non disponible. Vérifiez que l'extension PHP smbclient est installée."]];
        }
        if (!$authMech) {
            return [['status' => 'error', 'message' => "Mécanisme d'authentification 'password::global' non disponible."]];
        }

        $creds = $this->getSmbCredentials();
        if (empty($creds['host'])) {
            return [['status' => 'error', 'message' => "Hôte Synology non configuré."]];
        }

        $results          = [];
        $existingStorages = $storagesService->getStorages();

        foreach ($mappings as $mapping) {
            $autoMode = $mapping['auto_mode'] ?? false;

            if (!empty($autoMode)) {
                $rootShare = trim($mapping['storage_share'] ?? '');
                $prefix    = trim($mapping['mount_prefix'] ?? '');
                if (empty($rootShare)) {
                    continue;
                }

                if ($autoMode === 'acl') {
                    // ── Mode ACL : lire les droits depuis Synology ────────────
                    try {
                        $aclMappings = $this->synoApiService->discoverAclMappings($rootShare);
                        if (empty($aclMappings)) {
                            $results[] = ['status' => 'warning', 'message' => "Aucun ACL trouvé sur '{$rootShare}'"];
                            continue;
                        }
                        foreach ($aclMappings as $folderName => $groups) {
                            $mountPoint = $prefix ? "{$prefix}/{$folderName}" : $folderName;
                            foreach ($groups as $groupName) {
                                $results[] = $this->doMount(
                                    $storagesService, $backend, $authMech, $existingStorages,
                                    $groupName, $rootShare, $folderName, $mountPoint, $creds
                                );
                            }
                        }
                    } catch (\Throwable $e) {
                        $results[] = ['status' => 'error', 'message' => "Erreur ACL DSM : " . $e->getMessage()];
                    }
                } else {
                    // ── Mode nom : sous-dossier = nom du groupe NC ────────────
                    foreach ($this->groupManager->search('', -1, 0) as $group) {
                        $gid = $group->getGID();
                        if (in_array($gid, ['admin', 'disabled'], true)) {
                            continue;
                        }
                        $mountPoint = $prefix ? "{$prefix}/{$gid}" : $gid;
                        $results[] = $this->doMount(
                            $storagesService, $backend, $authMech, $existingStorages,
                            $gid, $rootShare, $gid, $mountPoint, $creds
                        );
                    }
                }
                continue;
            }

            // ── Mode manuel ────────────────────────────────────────────────────
            $ncGroup    = trim($mapping['nc_group']   ?? ($mapping['ldap_group'] ?? ''));
            $ncUser     = trim($mapping['nc_user']    ?? '');
            $share      = trim($mapping['storage_share'] ?? '');
            $subfolder  = trim($mapping['storage_subfolder'] ?? '');
            // Mount point : utilise le groupe/utilisateur comme fallback si vide
            $defaultName = $ncUser ?: $ncGroup;
            $mountPoint  = trim($mapping['mount_point'] ?? $defaultName);

            if (empty($share) || (empty($ncGroup) && empty($ncUser))) {
                continue;
            }

            $results[] = $this->doMount(
                $storagesService, $backend, $authMech, $existingStorages,
                $ncGroup, $share, $subfolder, $mountPoint, $creds, $ncUser
            );
        }

        return $results;
    }

    /**
     * Crée le montage d'un groupe si absent — appelé à la connexion.
     *
     * @param string $subfolder   Sous-dossier SMB (vide = nom du groupe)
     * @param string $mountPrefix Préfixe NC (ex. "NAS" → mount point = "NAS/subfolder")
     */
    public function ensureGroupMount(
        string $groupName,
        string $rootShare,
        string $subfolder = '',
        string $mountPrefix = ''
    ): void {
        $storagesService = $this->getStoragesService();
        $backendService  = $this->getBackendService();
        if (!$storagesService || !$backendService) {
            return;
        }

        $backend  = $backendService->getBackend('smb');
        $authMech = $backendService->getAuthMechanism('password::global');
        if (!$backend || !$authMech) {
            return;
        }

        $creds = $this->getSmbCredentials();
        if (empty($creds['host'])) {
            return;
        }

        $sub        = $subfolder ?: $groupName;
        $mountPoint = $mountPrefix ? "{$mountPrefix}/{$sub}" : $sub;

        $existingStorages = $storagesService->getStorages();
        $this->doMount(
            $storagesService, $backend, $authMech, $existingStorages,
            $groupName, $rootShare, $sub, $mountPoint, $creds
        );
    }

    private function doMount(
        $storagesService,
        $backend,
        $authMech,
        array $existingStorages,
        string $ncGroup,
        string $share,
        string $subfolder,
        string $mountPoint,
        array $creds,
        string $ncUser = ''   // si renseigné : montage par utilisateur au lieu de groupe
    ): array {
        $byUser = ($ncUser !== '');

        if (!$byUser) {
            if (!$this->groupManager->groupExists($ncGroup)) {
                $this->groupManager->createGroup($ncGroup);
                $this->logger->info("[SynoLDAP] Groupe Nextcloud créé lors du montage: {$ncGroup}");
            }
        }

        $mountPoint = '/' . ltrim($mountPoint, '/');

        try {
            $existingMount = $byUser
                ? $this->findExistingMountForUser($existingStorages, $ncUser, $mountPoint)
                : $this->findExistingMount($existingStorages, $ncGroup, $mountPoint);
            $backendOptions = [
                'host'   => $creds['host'],
                'share'  => $share,
                'root'   => $subfolder,
                'domain' => $creds['domain'],
            ];
            $authOptions = [
                'user'     => $creds['user'],
                'password' => $creds['pass'],
            ];

            if ($existingMount !== null) {
                // NC 33 : n'écrire dans oc_external_storages que si la config a changé.
                // Sans ce garde, updateStorage() est appelé à chaque login → écrit dans
                // oc_mounts via le cache → dirty table reads → SetupManager::setupForUser()
                // rate partiellement → PROPFIND retourne 401 dans le même processus PHP.
                $existingOptions = $existingMount->getBackendOptions();
                $existingAuth    = $existingMount->getAuthOptions();
                if ($existingOptions !== $backendOptions || $existingAuth !== $authOptions) {
                    $existingMount->setBackendOptions($backendOptions);
                    $existingMount->setAuthOptions($authOptions);
                    $storagesService->updateStorage($existingMount);
                    $action = 'mis à jour';
                } else {
                    $action = 'inchangé';
                }
            } else {
                $storageConfig = new \OCA\Files_External\Lib\StorageConfig();
                $storageConfig->setMountPoint(ltrim($mountPoint, '/'));
                $storageConfig->setBackend($backend);
                $storageConfig->setAuthMechanism($authMech);
                $storageConfig->setBackendOptions($backendOptions);
                $storageConfig->setAuthOptions($authOptions);
                if ($byUser) {
                    $storageConfig->setApplicableUsers([$ncUser]);
                } else {
                    $storageConfig->setApplicableGroups([$ncGroup]);
                }
                $storagesService->addStorage($storageConfig);
                $action = 'créé';
            }

            $path = "//{$creds['host']}/{$share}" . ($subfolder ? "/{$subfolder}" : '');
            $target = $byUser ? $ncUser : $ncGroup;
            return [
                'status'  => 'ok',
                'group'   => $target,
                'mount'   => $mountPoint,
                'share'   => $share,
                'message' => "Montage {$action} : {$mountPoint} → {$path} (" . ($byUser ? "user: {$ncUser}" : "groupe: {$ncGroup}") . ")",
            ];
        } catch (\Throwable $e) {
            $target = $byUser ? $ncUser : $ncGroup;
            $this->logger->error("[SynoLDAP] Erreur montage {$target}: " . $e->getMessage());
            return [
                'status'  => 'error',
                'group'   => $target,
                'message' => $e->getMessage(),
            ];
        }
    }

    private function findExistingMountForUser(array $storages, string $userName, string $mountPoint): mixed {
        foreach ($storages as $storage) {
            $users = $storage->getApplicableUsers();
            if (
                in_array($userName, $users, true) &&
                '/' . ltrim($storage->getMountPoint(), '/') === $mountPoint
            ) {
                return $storage;
            }
        }
        return null;
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
