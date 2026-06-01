# Architecture technique

## Vue d'ensemble

`synoldap` est une application Nextcloud standard qui s'intègre via les APIs officielles du framework. Elle ne modifie pas le cœur de Nextcloud et ne nécessite pas de dépendances externes (pas de Composer, pas de curl).

```
┌─────────────────────────────────────────────────────────┐
│                    Nextcloud Core                        │
│  IUserManager  │  IGroupManager  │  GlobalStoragesService│
└───────┬────────┴────────┬────────┴──────────┬────────────┘
        │                 │                   │
┌───────▼─────────────────▼───────────────────▼────────────┐
│                      synoldap                             │
│                                                           │
│  LdapUserBackend ──► LdapService ──► Synology LDAP/AD   │
│                                                           │
│  UserLoggedInListener                                     │
│    └──► GroupSyncService ──► LdapService                 │
│              └──► SynologyApiService ──► DSM API         │
│              └──► StorageConfigService ──► files_external│
└───────────────────────────────────────────────────────────┘
```

---

## Composants

### Application bootstrap — `AppInfo/Application.php`

- Enregistre `UserLoggedInListener` sur l'événement `PostLoginEvent`
- Enregistre `LdapUserBackend` via `IUserManager::registerBackend()` dans `boot()` (pas `register()`, pour respecter le cycle de vie NC)

### Backend d'authentification — `UserBackend/LdapUserBackend.php`

Implémente les interfaces NC :
- `ICheckPasswordBackend` : délègue à `LdapService::authenticate()`
- `IGetDisplayNameBackend` : délègue à `LdapService::getUserDisplayName()`
- `ICountUsersBackend` : délègue à `LdapService::getAllUserUids()`

NC appelle `checkPassword()` à chaque tentative de connexion. Si l'uid retourné est valide, NC provisionne automatiquement le compte (création à la première connexion, mise à jour du displayName).

`deleteUser()` retourne toujours `false` — backend en lecture seule, la source de vérité reste l'AD Synology.

### Service LDAP — `Service/LdapService.php`

Deux types de connexions :
1. **Connexion service** (`connect()`) : bind avec le compte de service configuré — utilisé pour les recherches (getUserInfo, getAllUserUids, getUserGroups)
2. **Connexion brute** (`connectRaw()`) : sans bind initial — utilisé uniquement pour tester les credentials utilisateur dans `authenticate()`

Normalisation du login (v2.0.2) :
- `stripDomainPrefix()` : retire le préfixe `DOMAIN\` avant toute recherche (`sAMAccountName` ne contient pas le domaine dans l'AD)
- `buildUserSearchFilter()` : si le login contient `@`, le filtre inclut aussi `userPrincipalName` pour les logins UPN

> **Note PHP** : `ldap_get_entries()` et `ldap_get_attributes()` retournent toujours les noms d'attributs en **minuscules**. Tous les accès aux attributs LDAP utilisent donc `strtolower($attrName)` comme clé.

Flux `authenticate()` :
```
authenticate(login, password)
  ├─ guard: empty($password) → null  (protection bind anonyme)
  ├─ getUserInfo($login)
  │    ├─ stripDomainPrefix(login) → retire DOMAIN\
  │    ├─ buildUserSearchFilter() → filtre sAMAccountName ou UPN
  │    └─ retourne dn, uid (via strtolower(attr)), displayName
  ├─ connectRaw() → nouvelle connexion LDAP
  ├─ ldap_bind($conn, $dn, $password) → true/false
  ├─ ldap_unbind($conn) dans finally
  └─ retourne $uid ou null
```

Le compte de service n'est jamais exposé dans le code de validation des credentials.

### Listener de connexion — `Listener/UserLoggedInListener.php`

S'exécute après chaque connexion réussie, quel que soit le backend d'authentification. Délègue à `GroupSyncService::syncUser()`.

### Service de synchronisation — `Service/GroupSyncService.php`

`syncUser(IUser $user)` :
1. Récupère les groupes AD de l'utilisateur via LDAP
2. Synchronise les membres des groupes NC manuels
3. Gère la promotion/révocation admin (groupe admin configuré + garde-fou dernier admin)
4. Pour les entrées auto :
   - **Mode `name`** : `syncNameEntry()` → pour chaque groupe AD, crée un montage `rootShare/groupName`
   - **Mode `acl`** : `syncAclEntry()` → récupère les ACL via `SynologyApiService`, fait l'intersection avec les groupes AD de l'utilisateur, crée les montages correspondants

### Service API DSM — `Service/SynologyApiService.php`

Interroge l'API REST Synology (SYNO.*) en HTTP/HTTPS via `file_get_contents` + stream context (pas de curl).

Flux `discoverAclMappings(shareName)` :
```
discoverAclMappings($share)
  ├─ cache hit → retourne résultat mis en cache (TTL 1h)
  ├─ login() → SYNO.API.Auth → sid
  ├─ SYNO.FileStation.List → liste des sous-dossiers + real_path
  │    (ex: /volume1/Externe/Compta)
  ├─ pour chaque sous-dossier:
  │    getFolderGroups($realPath, $sid)
  │      └─ SYNO.Core.ACL → ACEs filtrées: type=group, read=true, deny=false
  ├─ logout($sid)
  └─ mise en cache + retour: ['Compta' => ['Responsable', 'Compta'], ...]
```

Le cache utilise `ICacheFactory::createLocal()` (APCu en production, fichier en fallback), clé = `synoldap_acl_` + md5(shareName).

### Service de montage — `Service/StorageConfigService.php`

Wrapping de l'API `files_external` de Nextcloud (`GlobalStoragesService`).

`ensureGroupMount(groupName, rootShare, subfolder, mountPrefix)` :
- Vérifie si un montage identique existe déjà (pour éviter les doublons)
- Construit le point de montage : `prefix/subfolder` ou `prefix/groupName` selon le mode
- Crée ou met à jour le `StorageConfig` avec le backend `smb`, les credentials configurés, et l'applicable_group = `$groupName`

`applyMounts(mappings)` :
- Pour les entrées manuelles : crée directement le montage
- Pour `auto_mode = 'name'` : les montages sont créés dynamiquement à la connexion dans `GroupSyncService`
- Pour `auto_mode = 'acl'` : idem, via `SynologyApiService`

### Contrôleur admin — `Controller/AdminController.php`

API REST JSON pour l'interface d'administration. Toutes les routes requièrent `#[AdminRequired]`.

`saveConfig()` : les mots de passe vides dans la requête ne remplacent pas les mots de passe existants.
`getConfig()` : les mots de passe sont masqués (`''`) dans la réponse.

---

## Flux de données complet

```
Utilisateur → POST /login (login, password)
  │
  ▼
NC appelle LdapUserBackend::checkPassword(login, password)
  │
  ▼
LdapService::authenticate(login, password)
  ├─ Connexion service → ldap_search → trouve DN de l'utilisateur
  ├─ Connexion brute → ldap_bind(DN, password)
  └─ Retourne uid (string) ou null
  │
  ├─ null → NC essaie les autres backends → échec → 401
  └─ uid → NC crée le compte si nouveau (displayName depuis getUserInfo)
           → PostLoginEvent
             │
             ▼
           UserLoggedInListener → GroupSyncService::syncUser($user)
             ├─ LdapService::getUserGroups($uid) → [Responsable, Compta]
             ├─ Sync groupes NC manuels
             ├─ Admin check
             └─ Entrées auto (ACL)
                  ├─ SynologyApiService::discoverAclMappings('Externe')
                  │    → {Compta: [Responsable, Compta], RH: [RH, DGS]}
                  ├─ Intersection: userGroups ∩ folderGroups
                  │    Responsable ∈ [Responsable, Compta] → Compta ✓
                  └─ StorageConfigService::ensureGroupMount('Compta', 'Externe', 'Compta', 'NAS')
                       → montage SMB //NAS/Externe/Compta → /NAS/Compta pour le groupe Compta
```

---

## Dépendances et contraintes

### Pas de Composer

Aucune dépendance externe. Les appels API DSM utilisent `file_get_contents` avec stream context. Les interfaces NC sont résolues par l'injecteur de dépendances d'OCP.

### cURL pour les appels API DSM

Les appels à l'API REST Synology utilisent cURL (depuis la v2.0.1). `file_get_contents` a été remplacé pour une meilleure gestion des erreurs réseau et la compatibilité avec les environnements qui n'ont pas `allow_url_fopen` activé.

### Injecteur de dépendances NC

Tous les services sont instanciés par le container NC (`OCP\AppFramework\App`). Les constructeurs déclarent leurs dépendances avec des type hints — NC les résout automatiquement.

### Rétrocompatibilité

`auto_mode = true` (v1.0) est équivalent à `auto_mode = 'name'` (v2.0) — les configurations existantes fonctionnent sans migration.

---

## Fichiers clés

| Fichier | Rôle |
|---------|------|
| `appinfo/info.xml` | Métadonnées, version, dépendances NC |
| `appinfo/routes.php` | Déclaration des routes REST |
| `lib/AppInfo/Application.php` | Bootstrap : enregistrement backend + listener |
| `lib/UserBackend/LdapUserBackend.php` | Backend d'authentification NC |
| `lib/Listener/UserLoggedInListener.php` | Hook PostLoginEvent |
| `lib/Service/LdapService.php` | Connexion LDAP, auth, groupes, énumération |
| `lib/Service/SynologyApiService.php` | API REST DSM, découverte ACL, cache |
| `lib/Service/GroupSyncService.php` | Sync groupes NC + montages à la connexion |
| `lib/Service/StorageConfigService.php` | Création/MAJ des montages Files_External |
| `lib/Controller/AdminController.php` | API REST admin |
| `lib/Settings/AdminSection.php` | Entrée menu admin NC |
| `lib/Settings/AdminSettings.php` | Chargement template admin |
| `templates/admin.php` | Interface d'administration HTML |
| `js/admin.js` | Logique frontend (config, ACL preview, mappings) |
| `css/admin.css` | Styles du panel admin |
