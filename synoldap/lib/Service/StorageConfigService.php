<?php
declare(strict_types=1);

namespace OCA\SynoLDAP\Service;

use OCP\IConfig;
use OCP\IGroupManager;
use OCP\Server;
use Psr\Log\LoggerInterface;

/**
 * Création et mise à jour des montages « Stockage externe » Nextcloud.
 *
 * Deux transports vers le Synology :
 *  - SMB   : Nextcloud parle au NAS en CIFS (extension smbclient)
 *  - Local : le partage est déjà monté sur l'hôte (NFSv4) et exposé au conteneur
 *            Nextcloud ; on utilise le backend « local » de files_external sur
 *            /racine/partage/sous-dossier
 *
 * Quatre modes de correspondance :
 *  - manuel : groupe AD (ou utilisateur NC) → partage/sous-dossier précis
 *  - name   : sous-dossier = nom du groupe
 *  - acl    : sous-dossiers et groupes lus depuis les ACL Synology (API DSM)
 *  - all    : montage commun visible par tous les utilisateurs
 */
class StorageConfigService {
    private const APP_ID = 'synoldap';

    public const TYPE_SMB   = 'smb';
    public const TYPE_LOCAL = 'local';

    public function __construct(
        private IConfig $config,
        private IGroupManager $groupManager,
        private LoggerInterface $logger,
        private SynologyApiService $synoApiService,
    ) {}

    // ─── Helpers de configuration ────────────────────────────────────────────

    /** manual | name | acl | all */
    public static function modeOf(array $mapping): string {
        $auto = $mapping['auto_mode'] ?? false;
        if ($auto === true || $auto === 'name') {
            return 'name';
        }
        if ($auto === 'acl' || $auto === 'all') {
            return (string) $auto;
        }
        return 'manual';
    }

    /** smb | local — la valeur de la ligne, sinon le transport global. */
    public function typeOf(array $mapping): string {
        $type = trim((string) ($mapping['storage_type'] ?? ''));
        if ($type !== self::TYPE_SMB && $type !== self::TYPE_LOCAL) {
            $type = $this->config->getAppValue(self::APP_ID, 'storage_backend', self::TYPE_SMB);
        }
        return $type === self::TYPE_LOCAL ? self::TYPE_LOCAL : self::TYPE_SMB;
    }

    private function getLocalRoot(): string {
        return rtrim($this->config->getAppValue(self::APP_ID, 'local_root', ''), '/');
    }

    private function isSharingEnabled(): bool {
        return $this->config->getAppValue(self::APP_ID, 'mount_enable_sharing', '1') === '1';
    }

    private function getSmbCredentials(): array {
        return [
            'host'   => $this->config->getAppValue(self::APP_ID, 'synology_host', ''),
            'user'   => $this->config->getAppValue(self::APP_ID, 'synology_smb_user', ''),
            'pass'   => $this->config->getAppValue(self::APP_ID, 'synology_smb_password', ''),
            'domain' => $this->config->getAppValue(self::APP_ID, 'synology_smb_domain', 'WORKGROUP'),
        ];
    }

    // ─── Accès à files_external ──────────────────────────────────────────────

    private function getStoragesService(): ?\OCA\Files_External\Service\GlobalStoragesService {
        if (!class_exists(\OCA\Files_External\Service\GlobalStoragesService::class)) {
            return null;
        }
        try {
            return Server::get(\OCA\Files_External\Service\GlobalStoragesService::class);
        } catch (\Throwable) {
            return null;
        }
    }

    private function getBackendService(): ?\OCA\Files_External\Service\BackendService {
        if (!class_exists(\OCA\Files_External\Service\BackendService::class)) {
            return null;
        }
        try {
            return Server::get(\OCA\Files_External\Service\BackendService::class);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Backend et mécanisme d'authentification pour un transport donné.
     *
     * @return array{0: object, 1: object}
     */
    private function resolveBackend($backendService, string $type): array {
        if ($type === self::TYPE_LOCAL) {
            $backend  = $backendService->getBackend('local');
            $authMech = $backendService->getAuthMechanism('null::null');
            if (!$backend) {
                throw new \RuntimeException(
                    'Backend « Local » indisponible : vérifiez que '
                    . "'files_external_allow_local_storage_mount' n'est pas à false dans config.php."
                );
            }
            if (!$authMech) {
                throw new \RuntimeException("Mécanisme d'authentification « null::null » non disponible.");
            }
            return [$backend, $authMech];
        }

        $backend  = $backendService->getBackend('smb');
        $authMech = $backendService->getAuthMechanism('password::global');
        if (!$backend) {
            throw new \RuntimeException(
                "Backend SMB non disponible. Vérifiez que l'extension PHP smbclient est installée."
            );
        }
        if (!$authMech) {
            throw new \RuntimeException("Mécanisme d'authentification 'password::global' non disponible.");
        }
        return [$backend, $authMech];
    }

    /**
     * Options de backend et cible lisible pour un transport donné.
     *
     * @return array{options: array, auth: array, target: string, missing: bool}
     */
    private function buildStorageOptions(string $type, string $share, string $subfolder): array {
        $share     = trim($share, '/');
        $subfolder = trim($subfolder, '/');

        if ($type === self::TYPE_LOCAL) {
            $root = $this->getLocalRoot();
            if ($root === '') {
                throw new \RuntimeException(
                    'Racine locale (NFS) non configurée dans la section « Connexion Synology ».'
                );
            }
            $path = $root
                . ($share !== '' ? '/' . $share : '')
                . ($subfolder !== '' ? '/' . $subfolder : '');

            return [
                'options' => ['datadir' => $path],
                'auth'    => [],
                'target'  => $path,
                'missing' => !@is_dir($path),
            ];
        }

        $creds = $this->getSmbCredentials();
        if (empty($creds['host'])) {
            throw new \RuntimeException('Hôte Synology non configuré.');
        }
        if ($share === '') {
            throw new \RuntimeException('Partage SMB non renseigné.');
        }

        return [
            'options' => [
                'host'   => $creds['host'],
                'share'  => $share,
                'root'   => $subfolder,
                'domain' => $creds['domain'],
            ],
            'auth' => [
                'user'     => $creds['user'],
                'password' => $creds['pass'],
            ],
            'target'  => "//{$creds['host']}/{$share}" . ($subfolder !== '' ? "/{$subfolder}" : ''),
            'missing' => false,
        ];
    }

    // ─── Application globale (bouton admin) ──────────────────────────────────

    /**
     * Crée ou met à jour les montages de toutes les correspondances configurées.
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

        $results          = [];
        $existingStorages = $storagesService->getStorages();

        foreach ($mappings as $mapping) {
            $mode      = self::modeOf($mapping);
            $type      = $this->typeOf($mapping);
            $rootShare = trim((string) ($mapping['storage_share'] ?? ''));
            $prefix    = trim((string) ($mapping['mount_prefix'] ?? ''), '/');

            if ($mode === 'all') {
                // ── Mode commun : un montage visible par tous les utilisateurs ─
                $sub        = trim((string) ($mapping['storage_subfolder'] ?? ''), '/');
                $mountPoint = trim((string) ($mapping['mount_point'] ?? ''), '/');
                if ($mountPoint === '') {
                    $mountPoint = $sub !== '' ? $sub : $rootShare;
                }
                if ($mountPoint === '') {
                    continue;
                }
                $results[] = $this->doMount(
                    $storagesService, $backendService, $existingStorages,
                    '', $type, $rootShare, $sub, $mountPoint, '', true
                );
                continue;
            }

            if ($mode === 'acl' || $mode === 'name') {
                // En local (NFS) un partage vide est admis pour le mode nom :
                // les dossiers sont cherchés directement sous la racine locale.
                if ($rootShare === '' && ($mode === 'acl' || $type === self::TYPE_SMB)) {
                    continue;
                }

                if ($mode === 'acl') {
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
                                    $storagesService, $backendService, $existingStorages,
                                    $groupName, $type, $rootShare, $folderName, $mountPoint
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
                            $storagesService, $backendService, $existingStorages,
                            $gid, $type, $rootShare, $gid, $mountPoint
                        );
                    }
                }
                continue;
            }

            // ── Mode manuel ────────────────────────────────────────────────────
            $ncGroup    = trim((string) ($mapping['nc_group'] ?? '')) ?: trim((string) ($mapping['ldap_group'] ?? ''));
            $ncUser     = trim((string) ($mapping['nc_user'] ?? ''));
            $share      = trim((string) ($mapping['storage_share'] ?? ''));
            $subfolder  = trim((string) ($mapping['storage_subfolder'] ?? ''));
            // Mount point : utilise le groupe/utilisateur comme fallback si vide
            $defaultName = $ncUser ?: $ncGroup;
            $mountPoint  = trim((string) ($mapping['mount_point'] ?? '')) ?: $defaultName;

            if (($share === '' && $type === self::TYPE_SMB) || ($ncGroup === '' && $ncUser === '')) {
                continue;
            }

            $results[] = $this->doMount(
                $storagesService, $backendService, $existingStorages,
                $ncGroup, $type, $share, $subfolder, $mountPoint, $ncUser
            );
        }

        return $results;
    }

    // ─── Application ciblée (à la connexion) ─────────────────────────────────

    /**
     * Crée le montage d'un groupe si absent — appelé à la connexion.
     *
     * @param string $subfolder   Sous-dossier (vide = nom du groupe)
     * @param string $mountPrefix Préfixe NC (ex. "NAS" → mount point = "NAS/subfolder")
     * @param string $type        smb | local (vide = transport global)
     */
    public function ensureGroupMount(
        string $groupName,
        string $rootShare,
        string $subfolder = '',
        string $mountPrefix = '',
        string $type = ''
    ): void {
        $storagesService = $this->getStoragesService();
        $backendService  = $this->getBackendService();
        if (!$storagesService || !$backendService) {
            return;
        }

        $sub        = $subfolder ?: $groupName;
        $mountPoint = $mountPrefix ? "{$mountPrefix}/{$sub}" : $sub;

        $this->doMount(
            $storagesService, $backendService, $storagesService->getStorages(),
            $groupName, $this->typeOf(['storage_type' => $type]), $rootShare, $sub, $mountPoint
        );
    }

    /**
     * Crée le montage commun (visible par tous les utilisateurs) d'une entrée « all ».
     */
    public function ensureCommonMount(
        string $rootShare,
        string $subfolder,
        string $mountPoint,
        string $type = ''
    ): void {
        $storagesService = $this->getStoragesService();
        $backendService  = $this->getBackendService();
        if (!$storagesService || !$backendService) {
            return;
        }
        if (trim($mountPoint, '/') === '') {
            return;
        }

        $this->doMount(
            $storagesService, $backendService, $storagesService->getStorages(),
            '', $this->typeOf(['storage_type' => $type]), $rootShare, $subfolder, $mountPoint, '', true
        );
    }

    // ─── Montage unitaire ────────────────────────────────────────────────────

    /**
     * @param string $ncGroup Groupe NC cible (vide si montage utilisateur ou commun)
     * @param string $ncUser  Si renseigné : montage par utilisateur au lieu de groupe
     * @param bool   $forAll  Montage global : aucun groupe ni utilisateur applicable
     */
    private function doMount(
        $storagesService,
        $backendService,
        array $existingStorages,
        string $ncGroup,
        string $type,
        string $share,
        string $subfolder,
        string $mountPoint,
        string $ncUser = '',
        bool $forAll = false
    ): array {
        $byUser = ($ncUser !== '');
        $label  = $forAll ? 'tous les utilisateurs' : ($byUser ? "user: {$ncUser}" : "groupe: {$ncGroup}");
        $target = $forAll ? '*' : ($byUser ? $ncUser : $ncGroup);

        $mountPoint = '/' . ltrim($mountPoint, '/');
        if ($mountPoint === '/') {
            return ['status' => 'error', 'group' => $target, 'message' => "Point de montage vide ({$label})."];
        }

        try {
            [$backend, $authMech] = $this->resolveBackend($backendService, $type);
            $storage              = $this->buildStorageOptions($type, $share, $subfolder);

            if (!$forAll && !$byUser) {
                if (!$this->groupManager->groupExists($ncGroup)) {
                    $this->groupManager->createGroup($ncGroup);
                    $this->logger->info("[SynoLDAP] Groupe Nextcloud créé lors du montage: {$ncGroup}");
                }
            }

            $backendOptions = $storage['options'];
            $authOptions    = $storage['auth'];
            $sharing        = $this->isSharingEnabled();

            $existingMount = $forAll
                ? $this->findExistingCommonMount($existingStorages, $mountPoint)
                : ($byUser
                    ? $this->findExistingMountForUser($existingStorages, $ncUser, $mountPoint)
                    : $this->findExistingMount($existingStorages, $ncGroup, $mountPoint));

            if ($existingMount !== null) {
                // NC 33 : n'écrire dans oc_external_storages que si la config a changé.
                // Sans ce garde, updateStorage() est appelé à chaque login → écrit dans
                // oc_mounts via le cache → dirty table reads → SetupManager::setupForUser()
                // rate partiellement → PROPFIND retourne 401 dans le même processus PHP.
                $existingOptions = $existingMount->getBackendOptions();
                $existingAuth    = $existingMount->getAuthOptions();
                $existingBackend = $existingMount->getBackend()?->getIdentifier();
                $existingSharing = $existingMount->getMountOptions()['enable_sharing'] ?? null;

                $changed = $existingOptions !== $backendOptions
                    || $existingAuth !== $authOptions
                    || $existingBackend !== $backend->getIdentifier()
                    || $existingSharing !== $sharing;

                if ($changed) {
                    // Backend et mécanisme sont réappliqués : un montage SMB existant
                    // bascule proprement en local (NFS) si le transport a changé.
                    $existingMount->setBackend($backend);
                    $existingMount->setAuthMechanism($authMech);
                    $existingMount->setBackendOptions($backendOptions);
                    $existingMount->setAuthOptions($authOptions);
                    $existingMount->setMountOptions($this->mountOptions($sharing));
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
                $storageConfig->setMountOptions($this->mountOptions($sharing));
                if ($forAll) {
                    // Ni utilisateur ni groupe applicable = visible par tout le monde
                    $storageConfig->setApplicableUsers([]);
                    $storageConfig->setApplicableGroups([]);
                } elseif ($byUser) {
                    $storageConfig->setApplicableUsers([$ncUser]);
                } else {
                    $storageConfig->setApplicableGroups([$ncGroup]);
                }
                $storagesService->addStorage($storageConfig);
                $action = 'créé';
            }

            if ($storage['missing']) {
                $this->logger->warning("[SynoLDAP] Chemin local absent : {$storage['target']}");
                return [
                    'status'  => 'warning',
                    'group'   => $target,
                    'mount'   => $mountPoint,
                    'share'   => $share,
                    'message' => "Montage {$action} : {$mountPoint} → {$storage['target']} ({$label}) — "
                        . "⚠ ce chemin n'existe pas dans le conteneur Nextcloud "
                        . '(montage NFS ou variable NEXTCLOUD_MOUNT manquante).',
                ];
            }

            return [
                'status'  => 'ok',
                'group'   => $target,
                'mount'   => $mountPoint,
                'share'   => $share,
                'message' => "Montage {$action} : {$mountPoint} → {$storage['target']} ({$label})",
            ];
        } catch (\Throwable $e) {
            $this->logger->error("[SynoLDAP] Erreur montage {$target}: " . $e->getMessage());
            return [
                'status'  => 'error',
                'group'   => $target,
                'mount'   => $mountPoint,
                'message' => "{$label} : " . $e->getMessage(),
            ];
        }
    }

    /**
     * Options appliquées à tous les montages créés par l'app.
     *
     * filesystem_check_changes = 1 (CHECK_ONCE) : le dossier est relu à chaque accès
     * direct, indispensable quand les fichiers sont modifiés hors Nextcloud (Windows).
     */
    private function mountOptions(bool $sharing): array {
        return [
            'enable_sharing'           => $sharing,
            'previews'                 => true,
            'filesystem_check_changes' => 1,
        ];
    }

    private function findExistingCommonMount(array $storages, string $mountPoint): mixed {
        foreach ($storages as $storage) {
            if ('/' . ltrim($storage->getMountPoint(), '/') !== $mountPoint) {
                continue;
            }
            if (empty($storage->getApplicableUsers()) && empty($storage->getApplicableGroups())) {
                return $storage;
            }
        }
        return null;
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

    // ─── Diagnostic du transport local (NFS) ─────────────────────────────────

    /**
     * Vérifie la racine locale et liste ce qu'elle contient — outil de diagnostic
     * pour valider qu'un partage NFS est bien visible depuis le conteneur.
     *
     * @return array{success: bool, message: string, path: string, folders: list<string>}
     */
    public function testLocalRoot(string $subPath = ''): array {
        $root = $this->getLocalRoot();
        if ($root === '') {
            return [
                'success' => false,
                'message' => 'Racine locale (NFS) non configurée.',
                'path'    => '',
                'folders' => [],
            ];
        }

        $path = $root . ($subPath !== '' ? '/' . trim($subPath, '/') : '');

        if (!@is_dir($path)) {
            return [
                'success' => false,
                'message' => "« {$path} » est introuvable depuis le conteneur Nextcloud. "
                    . "Vérifiez le montage NFS sur l'hôte et la variable NEXTCLOUD_MOUNT (AIO).",
                'path'    => $path,
                'folders' => [],
            ];
        }
        if (!@is_readable($path)) {
            return [
                'success' => false,
                'message' => "« {$path} » existe mais n'est pas lisible par l'utilisateur du conteneur (www-data, uid 33).",
                'path'    => $path,
                'folders' => [],
            ];
        }

        $folders  = $this->listSubFolders($path);
        $writable = @is_writable($path);

        return [
            'success' => true,
            'message' => "« {$path} » accessible — " . count($folders) . ' sous-dossier(s)'
                . ($writable ? ', écriture OK' : ', ⚠ lecture seule'),
            'path'    => $path,
            'folders' => $folders,
        ];
    }

    /** @return list<string> */
    private function listSubFolders(string $path): array {
        $entries = @scandir($path);
        if ($entries === false) {
            return [];
        }

        $folders = [];
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..' || str_starts_with($entry, '@') || str_starts_with($entry, '#')) {
                continue;
            }
            if (@is_dir($path . '/' . $entry)) {
                $folders[] = $entry;
            }
        }
        sort($folders, SORT_NATURAL | SORT_FLAG_CASE);

        return $folders;
    }
}
