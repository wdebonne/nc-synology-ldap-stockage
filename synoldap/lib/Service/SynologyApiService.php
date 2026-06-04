<?php
declare(strict_types=1);

namespace OCA\SynoLDAP\Service;

use OCP\ICacheFactory;
use OCP\IConfig;
use Psr\Log\LoggerInterface;

class SynologyApiService {
    private const APP_ID  = 'synoldap';
    private const CACHE_TTL = 3600; // secondes

    /**
     * Jeton CSRF (SynoToken) obtenu au login, requis par les API privilégiées
     * SYNO.Core.* qui refusent le SID passé en paramètre GET (erreur 403).
     */
    private ?string $synoToken = null;

    public function __construct(
        private IConfig $config,
        private ICacheFactory $cacheFactory,
        private LoggerInterface $logger,
    ) {}

    // ─── Config helpers ───────────────────────────────────────────────────────

    private function getApiBase(): string {
        $host = $this->config->getAppValue(self::APP_ID, 'synology_host', '');
        $port = $this->config->getAppValue(self::APP_ID, 'synology_api_port', '5000');
        $ssl  = $this->config->getAppValue(self::APP_ID, 'synology_api_ssl', '0') === '1';
        $scheme = $ssl ? 'https' : 'http';
        return "{$scheme}://{$host}:{$port}/webapi";
    }

    private function getApiUser(): string {
        return $this->config->getAppValue(self::APP_ID, 'synology_api_user', '');
    }

    private function getApiPassword(): string {
        return $this->config->getAppValue(self::APP_ID, 'synology_api_password', '');
    }

    // ─── HTTP ─────────────────────────────────────────────────────────────────

    /**
     * Appel générique à l'API Synology DSM.
     *
     * SYNO.Core.* : exigent un POST avec SynoToken dans le corps, le SID en cookie
     * et X-SYNO-TOKEN en en-tête. Les API standard (SYNO.FileStation.*, SYNO.API.Auth)
     * fonctionnent en GET avec _sid en paramètre.
     */
    private function apiGet(string $api, int $version, string $method, array $params = [], ?string $sid = null): array {
        $base      = $this->getApiBase();
        $isCoreApi = str_starts_with($api, 'SYNO.Core.');

        $baseParams = ['api' => $api, 'version' => $version, 'method' => $method];
        if ($sid !== null && !$isCoreApi) {
            $baseParams['_sid'] = $sid;
        }
        $allParams = array_merge($baseParams, $params);

        if (!function_exists('curl_init')) {
            throw new \RuntimeException("L'extension PHP curl est requise mais non disponible");
        }

        $url  = $base . '/entry.cgi';
        $ch   = curl_init();
        $opts = [
            CURLOPT_URL            => $isCoreApi ? $url : ($url . '?' . http_build_query($allParams)),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_FOLLOWLOCATION => false,
        ];

        if ($isCoreApi && $sid !== null) {
            // SYNO.Core.* : POST + SynoToken dans le corps + cookie + en-tête
            $postFields = $allParams;
            if ($this->synoToken !== null) {
                $postFields['SynoToken'] = $this->synoToken;
            }
            $opts[CURLOPT_POST]       = true;
            $opts[CURLOPT_POSTFIELDS] = http_build_query($postFields);
            $opts[CURLOPT_COOKIE]     = 'id=' . $sid;
            $headers = ['Content-Type: application/x-www-form-urlencoded'];
            if ($this->synoToken !== null) {
                $headers[] = 'X-SYNO-TOKEN: ' . $this->synoToken;
            }
            $opts[CURLOPT_HTTPHEADER] = $headers;
        } elseif ($sid !== null) {
            // API standard : cookie + en-tête en bonus pour compatibilité
            $opts[CURLOPT_COOKIE] = 'id=' . $sid;
            if ($this->synoToken !== null) {
                $opts[CURLOPT_HTTPHEADER] = ['X-SYNO-TOKEN: ' . $this->synoToken];
            }
        }

        curl_setopt_array($ch, $opts);

        $raw    = curl_exec($ch);
        $errno  = curl_errno($ch);
        $errmsg = curl_error($ch);
        curl_close($ch);

        if ($raw === false || $errno !== CURLE_OK) {
            throw new \RuntimeException("Impossible de joindre l'API Synology DSM ({$base}) : {$errmsg} (curl #{$errno})");
        }

        $data = json_decode($raw, true);
        if (!is_array($data)) {
            throw new \RuntimeException('Réponse API DSM invalide (non-JSON)');
        }

        return $data;
    }

    // ─── Auth ─────────────────────────────────────────────────────────────────

    private function login(): string {
        $user = $this->getApiUser();
        $pass = $this->getApiPassword();

        if (empty($user)) {
            throw new \RuntimeException("Utilisateur API DSM non configuré dans la section SMB/API.");
        }

        $res = $this->apiGet('SYNO.API.Auth', 6, 'login', [
            'account'           => $user,
            'passwd'            => $pass,
            'session'           => 'synoldap',
            'format'            => 'sid',
            'enable_syno_token' => 'yes',
        ]);

        if (empty($res['success']) || empty($res['data']['sid'])) {
            $code = $res['error']['code'] ?? '?';
            // Codes courants : 400=info invalide, 401=interdit, 403=2FA requis
            $msg = match((int) $code) {
                400 => "Compte ou mot de passe invalide (code 400)",
                401 => "Accès refusé — vérifier les droits du compte (code 401)",
                403 => "Double authentification requise (code 403)",
                default => "Échec authentification DSM (code {$code})",
            };
            throw new \RuntimeException($msg);
        }

        // Mémoriser le SynoToken pour les appels privilégiés (SYNO.Core.*).
        // DSM peut retourner le jeton sous 'synotoken' (lowercase) ou 'SynoToken'.
        $this->synoToken = $res['data']['synotoken'] ?? $res['data']['SynoToken'] ?? null;
        if ($this->synoToken === null) {
            $this->logger->warning('[SynoLDAP] SynoToken absent de la réponse login — les API SYNO.Core.* pourraient retourner 403');
        }

        return $res['data']['sid'];
    }

    private function logout(string $sid): void {
        try {
            $this->apiGet('SYNO.API.Auth', 6, 'logout', ['session' => 'synoldap'], $sid);
        } catch (\Throwable) {
            // Échec de logout non bloquant
        }
        $this->synoToken = null;
    }

    // ─── Public API ──────────────────────────────────────────────────────────

    public function testConnection(): array {
        try {
            $sid = $this->login();
            $this->logout($sid);
            $host = $this->config->getAppValue(self::APP_ID, 'synology_host', '');
            $port = $this->config->getAppValue(self::APP_ID, 'synology_api_port', '5000');
            return ['success' => true, 'message' => "Connexion API DSM réussie ({$host}:{$port})"];
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Découvre les mappings dossier → groupes AD à partir des ACL Synology.
     * Résultat mis en cache (TTL = 1 heure).
     *
     * @return array<string, list<string>>  ['Compta' => ['Responsable', 'Compta'], 'RH' => ['RH'], ...]
     */
    public function discoverAclMappings(string $shareName): array {
        $cacheKey = 'acl_' . md5($shareName);
        $cache    = $this->cacheFactory->createLocal(self::APP_ID);

        $cached = $cache->get($cacheKey);
        if (is_array($cached)) {
            return $cached;
        }

        $sid = $this->login();
        try {
            $mappings = $this->doDiscover($shareName, $sid);
        } finally {
            $this->logout($sid);
        }

        $cache->set($cacheKey, $mappings, self::CACHE_TTL);
        $this->logger->info("[SynoLDAP] ACL découvertes pour '{$shareName}' : " . count($mappings) . " dossier(s)");

        return $mappings;
    }

    /**
     * Vide le cache ACL (utile après une modification des droits sur Synology).
     */
    public function clearAclCache(): void {
        $this->cacheFactory->createLocal(self::APP_ID)->clear('acl_');
    }

    // ─── Discover logic ───────────────────────────────────────────────────────

    /**
     * @return array<string, list<string>>
     */
    private function doDiscover(string $shareName, string $sid): array {
        // 1. Lister les sous-dossiers du partage avec le chemin réel sur le NAS
        $listRes = $this->apiGet('SYNO.FileStation.List', 2, 'list', [
            'folder_path' => '/' . ltrim($shareName, '/'),
            'additional'  => json_encode(['real_path', 'perm']),
            'filetype'    => 'dir',
        ], $sid);

        if (empty($listRes['success'])) {
            $code = $listRes['error']['code'] ?? '?';
            throw new \RuntimeException("Impossible de lister le partage '{$shareName}' (code {$code})");
        }

        $files = $listRes['data']['files'] ?? [];
        if (empty($files)) {
            return [];
        }

        $mappings = [];

        foreach ($files as $item) {
            if (empty($item['isdir'])) {
                continue;
            }

            $folderName = $item['name'];
            $realPath   = $item['additional']['real_path'] ?? null;

            if (!$realPath) {
                $this->logger->warning("[SynoLDAP] Chemin réel introuvable pour '{$folderName}'");
                continue;
            }

            // 2. Lire les ACL du sous-dossier via SYNO.Core.ACL
            $groups = $this->getFolderGroups($realPath, $sid);

            if (!empty($groups)) {
                $mappings[$folderName] = $groups;
                $this->logger->debug("[SynoLDAP] '{$folderName}' → groupes: " . implode(', ', $groups));
            }
        }

        return $mappings;
    }

    /**
     * Retourne la liste des groupes ayant une ACE d'autorisation sur un dossier.
     *
     * Compatible DSM 6 (type='group', is_deny) et DSM 7 (tag='group', type='allow'/'deny').
     *
     * @return list<string>
     */
    private function getFolderGroups(string $realPath, string $sid): array {
        $aclRes = $this->apiGet('SYNO.Core.ACL', 1, 'get', ['path' => $realPath], $sid);

        if (empty($aclRes['success'])) {
            $code = $aclRes['error']['code'] ?? '?';
            $this->logger->warning("[SynoLDAP] ACL non disponible pour '{$realPath}' (code {$code})");
            return [];
        }

        $groups = [];
        foreach ($aclRes['data']['acl'] ?? [] as $ace) {
            $name = trim($ace['name'] ?? '');
            if ($name === '') {
                continue;
            }

            // DSM 6 : $ace['type'] = 'group' | 'user', $ace['is_deny'] = bool
            // DSM 7 : $ace['tag']  = 'group' | 'user', $ace['type'] = 'allow' | 'deny'
            $isGroup = ($ace['type'] ?? '') === 'group'
                    || ($ace['tag']  ?? '') === 'group';

            $isDeny  = !empty($ace['is_deny'])
                    || ($ace['type'] ?? '') === 'deny';

            if ($isGroup && !$isDeny) {
                $groups[] = $name;
            }
        }

        $this->logger->debug("[SynoLDAP] getFolderGroups '{$realPath}' → " . implode(', ', $groups) . " (" . count($aclRes['data']['acl'] ?? []) . " ACE(s) bruts)");

        return $groups;
    }

    /**
     * Retourne les données brutes ACL DSM pour un dossier — utilisé par le diagnostic admin.
     */
    public function getRawAcl(string $shareName): array {
        $sid = $this->login();
        try {
            // 1. Lister les sous-dossiers
            $listRes = $this->apiGet('SYNO.FileStation.List', 2, 'list', [
                'folder_path' => '/' . ltrim($shareName, '/'),
                'additional'  => json_encode(['real_path', 'perm']),
                'filetype'    => 'dir',
            ], $sid);

            $files = $listRes['data']['files'] ?? [];
            $result = [
                'list_success' => $listRes['success'] ?? false,
                'list_error'   => $listRes['error'] ?? null,
                'folders'      => [],
            ];

            // 2. Pour chaque sous-dossier, récupérer les ACL brutes
            foreach (array_slice($files, 0, 5) as $item) {
                if (empty($item['isdir'])) {
                    continue;
                }
                $realPath = $item['additional']['real_path'] ?? null;
                $entry = [
                    'name'      => $item['name'],
                    'real_path' => $realPath,
                    'acl_raw'   => null,
                ];
                if ($realPath) {
                    $aclRes = $this->apiGet('SYNO.Core.ACL', 1, 'get', ['path' => $realPath], $sid);
                    $entry['acl_raw'] = $aclRes;
                }
                $result['folders'][] = $entry;
            }

            return $result;
        } finally {
            $this->logout($sid);
        }
    }
}
