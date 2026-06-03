# Changelog

Toutes les modifications notables de ce projet sont documentées dans ce fichier.

Le format suit [Keep a Changelog](https://keepachangelog.com/fr/1.0.0/),
et ce projet respecte le [Versionnage Sémantique](https://semver.org/lang/fr/).

---

## [2.0.30] — 2026-06-03

### Correction — Bug critique dans ensureUserRow() (QueryBuilder réutilisé)

Console F12 confirme la cause racine :
```
PROPFIND /remote.php/dav/files/e.berthy/ 401 (Unauthorized)
```
L'utilisateur est connecté (dashboard visible) mais le DAV retourne 401. La session NC
fonctionne pour les pages HTML mais le middleware DAV de NC fait une vérification plus
stricte de `oc_users.backend` et échoue si ce champ pointe vers un backend non enregistré.

**Bug dans 2.0.29** : `ensureUserRow()` créait trois QueryBuilders séparés mais utilisait
`$qb->createNamedParameter()` du premier (SELECT) dans les suivants (INSERT/UPDATE).
Les named parameters appartiennent à leur QueryBuilder — les mélanger produit un SQL
invalide qui échoue silencieusement (capturé par le try/catch). Résultat : `oc_users.backend`
n'était **jamais** mis à jour malgré le code correct en apparence.

**Fix** : chaque opération SQL utilise maintenant son propre QueryBuilder avec ses propres
named parameters. Le curseur est correctement fermé après le SELECT.

**Note importante pour NC AIO** : après toute mise à jour de fichiers PHP, il faut vider
l'OPcache en redémarrant le container NC :
```
docker restart nextcloud-aio-nextcloud
```
Sans ce redémarrage, l'ancien code compilé continue à tourner et les corrections n'ont
aucun effet.

---

## [2.0.29] — 2026-06-03

### Correction critique — NC AIO : 401 partages, "Se souvenir de moi", création de fichiers

#### Cause racine identifiée avec NC AIO + Redis

NC 30+ (utilisé dans NC AIO) lit `oc_users.backend` pour déterminer quel backend PHP gère un utilisateur avant d'appeler `IUserManager::get()`. Si ce champ contient `OCA\User_LDAP\User_Proxy` (valeur laissée par user_ldap qui était peut-être actif ou testé avant synoldap), NC tente de charger user_ldap — non enregistré — ne trouve pas le backend et retourne `null` depuis `get()`. Résultat : **401 sur tous les appels API** (partages, sessions, création de fichiers), même si la session a été créée correctement.

Ce mécanisme n'existait pas dans les versions NC < 30 : NC itérait toujours tous les backends. En NC 30, l'optimisation par backend stocké en DB rend le champ `oc_users.backend` critique.

#### Fix : `ensureUserRow()` dans `checkPassword()`

Après chaque authentification LDAP réussie, `ensureUserRow(uid)` :
- Si aucune entrée `oc_users` n'existe → la crée avec `backend = 'OCA\SynoLDAP\UserBackend\LdapUserBackend'`
- Si une entrée existe avec un backend incorrect (ex. `OCA\User_LDAP\User_Proxy`) → la met à jour

Après cette correction, NC route correctement toutes les requêtes vers synoldap, résolvant d'un coup :
- **401 sur les partages** : NC trouve maintenant le bon backend pour valider la session
- **"Se souvenir de moi"** : le token est validé par le bon backend
- **Création de fichiers/dossiers** : la session est stable, le home est initialisé correctement

`IDBConnection` est injecté dans `LdapUserBackend` pour les opérations DB directes.

---

## [2.0.28] — 2026-06-03

### Refactoring backend — Parité fonctionnelle complète avec user_ldap

Audit ligne par ligne de `user_ldap::checkPassword()` et `user_ldap::User::markLogin()` révèle trois causes racines distinctes des bugs persistants.

#### Cause 1 — Cache credential APCu (par processus PHP) → "Se souvenir de moi" rompu

`ICacheFactory::createDistributed()` utilise APCu si Redis/Memcache n'est pas configuré. APCu est **par processus PHP** : chaque worker FPM a son propre cache. Le cache de 3600s posé par `checkPassword()` dans le processus A n'existe pas dans le processus B qui traite le re-check "Se souvenir de moi" (toutes les 300s) → LDAP appelé → si lent → token invalidé.

**Fix** : double cache credentials :
- Cache distribué (rapide, même processus)
- **App config NC** `cr_<hash>` (DB-backed, persistant, partagé entre tous les processus PHP) → stocke `uid|timestamp_expiry`. user_ldap n'en a pas besoin car son LDAP est toujours rapide ; synoldap peut être sur un NAS distant.

#### Cause 2 — `known=1` retiré de `checkPassword()` en 2.0.27 (régression)

En 2.0.27, `known=1` avait été retiré de `checkPassword()` pour "ne pas bloquer l'auto-provisionnement". L'auto-provisionnement (`oc_accounts`) est en réalité **lazily créé** par NC indépendamment de `userExists()` — retirer `known=1` ne servait à rien et introduisait une fragilité : premier appel à `userExists()` sans `known=1` → LDAP obligatoire → si LDAP lent → session invalide.

**Fix** : `checkPassword()` pose à nouveau `known=1` immédiatement après auth réussie, exactement comme `user_ldap::markLogin()` + `user_ldap::cacheUserExists()`.

#### Cause 3 — `getHome()` appelait `userExists()` de manière non optimisée

`getHome()` appelait `userExists()` qui pouvait appeler LDAP pour des utilisateurs dont le cache distribué était vide (nouveau processus PHP). Sur des instances sans Redis, cela forçait un aller-retour LDAP à chaque initialisation du home storage.

**Fix** : `getHome()` vérifie d'abord le cache distribué, puis `oc_preferences[known]`, et n'appelle `userExists()` (LDAP) que pour les utilisateurs complètement inconnus.

#### Nettoyage — `deleteUser()` supprime les données synoldap

`deleteUser()` supprime maintenant `known=1` et vide le cache distribué, évitant qu'un utilisateur supprimé de NC reste reconnu par le backend.

---

## [2.0.27] — 2026-06-03

### Correction critique — Impossible de créer des fichiers/dossiers avec les utilisateurs LDAP

Audit de la création de session utilisateur entre `user_ldap` et `synoldap` révèle deux bugs architecturaux distincts.

#### Bug 1 — Provisionnement NC jamais déclenché (cause principale)

- **Cause** : `checkPassword()` posait `known=1` dans `oc_preferences` ET remplissait le cache `exists_` **avant** que Nextcloud ait eu le temps d'auto-provisionner l'utilisateur. Lors du premier login, NC appelle `userExists()` immédiatement après `checkPassword()` pour créer l'entrée `oc_users` et `oc_accounts`. Si `exists_` est déjà en cache, `userExists()` retourne `true` instantanément — NC croit l'utilisateur déjà entièrement provisionné → **ne crée jamais `oc_users` ni `oc_accounts`** → home directory jamais initialisé correctement → impossible de créer des fichiers ou dossiers.
- **Fix** : `checkPassword()` ne pose plus `known=1` ni le cache `exists_`. Seul `userExists()` les pose, **après** confirmation LDAP. Cela laisse NC appeler LDAP via `userExists()` au premier login → confirmation → provisionnement complet → **puis** `known=1` est posé pour les appels suivants.

#### Bug 2 — `getHome()` non implémentée (cause secondaire)

- **Cause** : `user_ldap` déclare explicitement `Backend::GET_HOME` et implémente `getHome()`. NC utilise cette méthode pour initialiser le stockage home lors de la création du compte. Sans `IGetHomeBackend`, NC peut utiliser un chemin par défaut incorrect ou ignorer l'initialisation du stockage.
- **Fix** : Implémentation de `IGetHomeBackend` avec `getHome()` qui retourne `{datadirectory}/{uid}`. Même comportement que `user_ldap` sans attribut home LDAP configuré.

#### Résumé des changements dans `LdapUserBackend`

| | Avant | Après |
|---|---|---|
| `checkPassword()` | Pose `known=1` + `exists_` | Pose uniquement le cache credentials |
| `userExists()` | Peut retourner true avant provisionnement | Retourne true seulement APRÈS LDAP confirme + `known=1` |
| `getHome()` | Non implémentée | Implémentée via `IGetHomeBackend` |
| Provisionnement NC | Souvent ignoré (cache prématuré) | Toujours déclenché au premier login |

---

## [2.0.26] — 2026-06-03

### Correction — "Se souvenir de moi" impossible + aucun fichier en session

Audit complet du flow NC "remember me" et du flow de synchro groupes :

#### Bug 1 — Connexion impossible avec "Se souvenir de moi"

- **Cause** : NC re-valide le token "Se souvenir de moi" toutes les **300 secondes** en appelant `checkPassword($login, $password)` avec les credentials stockés dans le token. Le cache distribué de `checkPassword()` avait un TTL de **360 secondes** — trop proche de l'intervalle NC. Entre deux re-checks, le cache pouvait expirer, forçant un appel LDAP. Si LDAP répondait lentement à ce moment précis, `checkPassword()` retournait `false` → NC invalidait le token → **déconnexion silencieuse**.
- **Fix** : TTL du cache `checkPassword()` porté à **3600 secondes (1 heure)**. LDAP n'est désormais consulté qu'une fois par heure lors des re-validations de token, identique au comportement du cache de connexion `user_ldap`. Entre ces consultations, NC trouve toujours le résultat en cache → token préservé → "Se souvenir de moi" fonctionne.

#### Bug 2 — Aucun fichier disponible en session

- **Cause** : Si LDAP était lent ou indisponible au moment du `PostLoginEvent`, `getUserGroups()` levait une exception → `syncUser()` s'arrêtait immédiatement → **aucun groupe NC créé, aucun montage SMB créé** → l'utilisateur voyait un Nextcloud vide. Pour un premier login, ce vide était permanent jusqu'à une reconnexion avec LDAP disponible.
- **Fix** : `syncUser()` stocke les groupes dans `oc_preferences` (`synoldap/last_groups`) après chaque synchronisation LDAP réussie. Si LDAP échoue au login suivant, les groupes du dernier sync réussi sont utilisés comme fallback → les montages SMB existants restent visibles → l'utilisateur conserve ses fichiers.

---

## [2.0.25] — 2026-06-02

### Correction — Admin natif perd son groupe / utilisateur sans fichiers après login

Audit complet de user_ldap révèle deux causes racines distinctes :

#### Bug 1 — L'admin natif NC perd son appartenance au groupe admin lors des mises à jour

- **Cause** : `PostLoginEvent` se déclenche pour **tous** les utilisateurs, y compris les comptes NC natifs (ex. `admin`). `syncUser(admin)` appelle `getUserGroups('admin')` → l'admin n'existe pas en LDAP → retourne `[]` → `syncAdminStatus(admin, [])` : `shouldBeAdmin = false`, `isCurrentlyAdmin = true` → **retire admin du groupe admin** si d'autres admins existent.
- **user_ldap** évite ce problème car `Group_Proxy` ne connaît que les groupes LDAP : pour un utilisateur natif, il retourne `[]` sans toucher aux groupes NC existants.
- **Fix** : `UserLoggedInListener` vérifie désormais `oc_preferences[synoldap/known] = 1` avant de traiter l'événement. Seuls les utilisateurs authentifiés par le backend SynoLDAP (ayant cette préférence posée par `checkPassword()`) sont synchronisés.

#### Bug 2 — Utilisateur n'a aucun fichier/dossier partagé après login

- **Cause** : `getGroupsViaMemberOf()` retournait `[]` **silencieusement** quand `ldap_search` échoue (erreur réseau, timeout). `syncGroupMemberships(user, [])` interprétait ce `[]` comme "l'utilisateur n'a plus aucun groupe" → retirait l'utilisateur de **tous ses groupes** mappés manuellement → les montages SMB disparaissaient → aucun fichier visible.
- **Fix** : `getGroupsViaMemberOf()` lève une `RuntimeException` quand `ldap_search` échoue avec `errno != 0` (erreur réelle). `syncUser()` attrape cette exception et **abandonne la synchronisation sans toucher aux groupes existants** — identique au comportement user_ldap.

#### Fix complémentaire

- `userExists()` pose aussi `known=1` dans `oc_preferences` quand LDAP confirme l'existence (pas seulement `checkPassword()`), ce qui couvre les sessions créées avant la v2.0.24.

---

## [2.0.24] — 2026-06-02

### Refactoring majeur — userExists() basé sur oc_preferences (même base que user_ldap)

- **Diagnostic** : les versions 2.0.22 et 2.0.23 n'ont pas résolu le 401 car `userExists()` appelait encore LDAP dès que le cache distribué expirait (~5 min). `IUserManager::get()` appelle `userExists()` à **chaque requête** pour charger l'objet session — toute lenteur LDAP à ce moment invalide la session.
- **Root cause de user_ldap** : `User_LDAP::userExists()` consulte d'abord `ldap_user_mapping` (table DB privée), jamais le LDAP pour les utilisateurs connus. La table est peuplée une seule fois lors de la première authentification.
- **Fix** : `userExists()` réplique exactement cette logique avec `oc_preferences` comme table de mapping :
  1. Cache distribué (burst, < 5 min)
  2. `IConfig::getUserValue($uid, 'synoldap', 'known')` — persistant, aucun LDAP pour tout utilisateur déjà connecté (équivalent de `ldap_user_mapping`)
  3. LDAP — uniquement pour un utilisateur jamais vu par NC (première connexion)
- **`checkPassword()`** : appelle `$config->setUserValue($uid, 'synoldap', 'known', '1')` après chaque authentification réussie — alimente le mapping persistant.
- `IDBConnection` retiré du constructeur, `IConfig` injecté à la place.
- `IConfig::getUserValue` ne dépend d'aucune schema migration et fonctionne sur toutes les versions NC supportées.

---

## [2.0.23] — 2026-06-02

### Correction critique — Session invalide / 401 sur les partages (approche définitive)

- **Cause profonde** : `userExists()` était appelé par `IUserManager::get()` à chaque requête pour valider la session. Dès que le cache distribué expirait (~5 min), un appel LDAP synchrone était effectué. En cas de lenteur ou d'indisponibilité du LDAP, l'appel retournait `false` → Nextcloud ne trouvait plus l'utilisateur dans aucun backend → session invalidée → **401 sur toutes les requêtes API**, dont les appels de la page Partages.
- **Fix** : `userExists()` suit désormais exactement le même ordre que `user_ldap` :
  1. Cache distribué (< 5 min)
  2. **Base de données NC** (`oc_users`) — aucun LDAP pour les utilisateurs déjà provisionnés
  3. LDAP — uniquement pour les nouveaux utilisateurs jamais vus par NC
- **`LdapService::getUserInfo()`** : distingue maintenant "LDAP indisponible" (lève `RuntimeException`) de "utilisateur introuvable dans l'AD" (retourne `null`) via `ldap_errno()`.
- **`LdapService::userExists()`** : laisse les exceptions LDAP se propager afin que `LdapUserBackend::userExists()` puisse les attraper et utiliser le fallback DB.
- **Injection de `IDBConnection`** dans `LdapUserBackend` pour la requête `oc_users`.

---

## [2.0.22] — 2026-06-02

### Correction critique — Session invalide lors de l'accès aux partages (401)

- **Cause** : `isUserEnabled()` appelait `userExists()` à chaque requête API pour vérifier l'état du compte côté AD. `userExists()` capture ses propres exceptions et retourne `false` silencieusement. Résultat : dès que le cache distribué expirait (~5 min), un appel LDAP était effectué ; en cas de lenteur ou d'indisponibilité momentanée, `userExists()` retournait `false` sans lever d'exception → le `catch (\Throwable)` de `isUserEnabled()` n'était jamais atteint → Nextcloud considérait l'utilisateur désactivé → réponse 401 sur toutes les requêtes API, notamment la page "Partages".
- **Fix** : `isUserEnabled()` retourne désormais `(bool) $queryDatabaseValue()` — la valeur stockée en base NC — identique au comportement de `user_ldap`. La révocation AD reste effective : un compte désactivé côté Synology échoue au prochain `checkPassword()` → plus de nouvelle session.

---

## [2.0.10] — 2026-06-01

### Corrections critiques

- **Boucle login (POST ok → GET dashboard → 303 login)** : diagnostiquée par ajout de logs `warning` sur toutes les méthodes du backend. La cause était double : (1) le Synology LDAP rejetait les connexions rapides en rafale (4-5 connexions par login) → `userExists()` échouait silencieusement → session invalidée. Corrigé par mise en cache de `$serviceConn`. (2) L'app `user_ldap` (officielle Nextcloud) interceptait l'auth avant `synoldap` et changeait le backend de session ; solution : désactiver `user_ldap` quand `synoldap` gère le LDAP.
- **Logging insuffisant** : les échecs de `userExists()` et de `ldap_search` étaient silencieux. Ajout de `warning` pour rendre le diagnostic possible sans activer le niveau debug.

---

## [2.0.7] — 2026-06-01

### Correction critique — Session invalide immédiatement après login

- **Cause** : chaque opération LDAP (`getUserInfo`, `getUserGroups`, `getAllUserUids`) ouvrait une nouvelle connexion TCP au Synology LDAP et la fermait via `ldap_unbind()`. Un login ouvrait 4-5 connexions en rafale. Le Synology Directory Server rejetait la suivante → `userExists()` retournait `false` → Nextcloud loggait `"Found one account that was removed from its backend"` → la session était invalidée → redirection vers `/login` (POST login → 303 dashboard → 303 login).
- **Fix** : connexion du compte de service mise en cache dans `LdapService::$serviceConn` (propriété d'instance). Comme `LdapService` est un singleton dans le container NC, une seule connexion TCP est ouverte par requête HTTP et réutilisée par toutes les opérations. Fermée proprement dans `__destruct()`.

---

## [2.0.4] — 2026-06-01

### Ajouté

#### Synchronisation automatique des groupes AD → groupes Nextcloud
- **Sync directe sans mapping** : tous les groupes AD de l'utilisateur sont maintenant automatiquement créés comme groupes Nextcloud (même nom) et l'utilisateur y est ajouté — sans aucune configuration de mapping requise. Avant cette version, les groupes LDAP n'étaient appliqués à NC que si un mapping explicite était configuré.
- **Retrait automatique** : si l'utilisateur n'est plus membre d'un groupe AD, il est retiré du groupe NC correspondant à la prochaine synchronisation — uniquement si ce groupe NC correspond à un vrai groupe AD (contrôle via `isKnownLdapGroup()`).
- **`LdapService::isKnownLdapGroup(string $groupName)`** : nouvelle méthode qui vérifie l'existence d'un groupe dans l'annuaire LDAP par son `cn`. Protège les groupes NC locaux (même nom qu'un groupe AD) d'une suppression involontaire.

---

## [2.0.3] — 2026-06-01

### Corrigé

#### Nom complet et email non synchronisés malgré `syncProfile()`
- **`ldap_get_attributes()` ne normalise pas la casse** : contrairement à `ldap_get_entries()` (documenté lowercase), `ldap_get_attributes()` renvoie les attributs avec la casse du serveur. Le Synology AD renvoie `displayName`, `givenName`, `sAMAccountName` (majuscules internes). Le code cherchait `displayname`, `givenname` → null → fallback sur l'identifiant → condition `displayName !== uid` bloquait la mise à jour NC. Corrigé par normalisation via `foreach`/`strtolower` immédiatement après `ldap_get_attributes()`.
- **Logs de synchronisation de profil** : `syncProfile()` journalise maintenant chaque mise à jour de displayName et email (niveau `info`) et un avertissement si l'utilisateur est introuvable dans l'AD, pour faciliter le diagnostic.

---

## [2.0.2] — 2026-06-01

### Corrigé

#### Authentification et liste d'utilisateurs LDAP
- **Bug critique — zéro utilisateur listé** : PHP retourne les noms d'attributs LDAP en minuscules (`ldap_get_entries()`, `ldap_get_attributes()`). Dans `getAllUserUids()`, l'accès à `$entries[$i]['sAMAccountName']` était toujours `null` → la liste d'utilisateurs, le partage de fichiers et le panneau admin ne montraient aucun utilisateur LDAP. Corrigé avec `strtolower($userNameAttr)` dans `getAllUserUids()`, `getUserInfo()` et `getGroupsViaSearch()`.
- **Login avec préfixe domaine Windows** (`DOMAIN\username`) : `sAMAccountName` ne contient pas le préfixe domaine dans l'AD. Le login `CORP\jdupont` échouait avec "utilisateur introuvable". Corrigé par la méthode privée `LdapService::stripDomainPrefix()`.
- **Login au format UPN** (`utilisateur@domaine.com`) : la recherche inclut maintenant aussi `userPrincipalName` quand le login contient `@`. Géré par `LdapService::buildUserSearchFilter()`.
- **Erreur de bind masquée** : le `@` sur `ldap_bind()` supprimait l'erreur LDAP réelle. Supprimé — l'erreur exacte est maintenant journalisée (`warning`) pour pouvoir diagnostiquer les échecs d'authentification.
- **Nom complet et email non synchronisés** : `displayName` et `mail` récupérés depuis l'AD n'étaient jamais poussés vers le profil Nextcloud. `GroupSyncService::syncProfile()` est maintenant appelée à chaque connexion pour maintenir le profil NC à jour.

---

## [2.0.0] — 2026-05-28

### Ajouté

#### Authentification intégrée (nouveau)
- **`LdapUserBackend`** : backend d'authentification Nextcloud natif — les utilisateurs AD Synology se connectent avec leurs identifiants Windows (sAMAccountName + mot de passe) sans app `user_ldap` externe
- **Provisionnement automatique** : création du compte Nextcloud à la première connexion avec récupération du nom complet (displayName/cn/prénom+nom) depuis l'AD
- **`LdapService::authenticate()`** : bind LDAP séparé avec les credentials utilisateur (connexion dédiée, jamais via le compte de service)
- **`LdapService::getUserInfo()`** : récupère DN, uid, displayName et email d'un utilisateur
- **`LdapService::userExists()`** : vérifie l'existence dans l'AD (utilisé par NC pour la liste utilisateurs et le partage)
- Désactiver un compte sur Synology révoque immédiatement l'accès à Nextcloud (le bind LDAP échoue)

#### Mode ACL Synology (nouveau)
- **`SynologyApiService`** : interroge l'API REST DSM Synology (`SYNO.Core.ACL`) pour lire les droits réels sur chaque sous-dossier
- **`SynologyApiService::discoverAclMappings()`** : retourne un mapping `dossier → [groupes AD avec accès lecture]`, mis en cache 1 heure
- **`SynologyApiService::clearAclCache()`** : vide le cache pour forcer la relecture immédiate
- Fonctionne avec les ACL Windows (NTFS-style) du Synology — exemple : groupe `Responsable` a des droits sur `Compta` → les membres de `Responsable` voient `/Compta` dans NC
- Authentification API DSM configurable : hôte (réutilisé), port (défaut 5000), utilisateur admin, mot de passe, option HTTPS

#### Arborescence identique Windows / Nextcloud (nouveau)
- **Champ `mount_prefix`** dans les correspondances auto : préfixe tous les points de montage
- Exemple : `mount_prefix = NAS` → les dossiers apparaissent sous `/NAS/Compta/2026` dans Nextcloud — même chemin que le lecteur réseau Windows
- Transparent pour l'utilisateur entre Nextcloud et le disque SMB monté sur le PC

#### Interface d'administration — améliorations
- **Nouvelle section API DSM** dans la carte "Connexion Synology" : port, SSL, utilisateur, mot de passe, bouton "Tester l'API DSM"
- **Nouveau mode de ligne** : sélecteur `Manuel / Auto nom / Auto ACL ★` (remplace la case à cocher)
- **Champ `Préfixe NC`** dans les lignes auto pour l'arborescence Windows
- **Bouton "Prévisualiser les ACL"** : affiche en temps réel dossiers et groupes découverts sur Synology
- **Bouton "Vider le cache ACL"** : force la relecture des droits sans attendre expiration du cache
- Ligne **Auto nom** : fond bleu clair ; ligne **Auto ACL** : fond vert clair pour distinguer visuellement les modes
- Nouveau journal : logs distincts par type (success/error/warning/info)

#### Nouvelles routes API
- `POST /apps/synoldap/admin/test-dsm-api` — teste la connexion à l'API DSM
- `GET  /apps/synoldap/admin/discover-acl?share=Externe` — prévisualise les ACL d'un partage
- `POST /apps/synoldap/admin/clear-acl-cache` — vide le cache ACL

### Modifié
- **`StorageConfigService`** : refactorisé avec méthode `doMount()` privée partagée ; supporte `auto_mode = 'acl' | 'name' | false` ; nouveau paramètre `mount_prefix`
- **`GroupSyncService`** : injecte `StorageConfigService` et `SynologyApiService` ; gère les entrées auto par ACL (`syncAclEntry`) et par nom (`syncNameEntry`) ; crée les montages à la connexion en temps réel
- **`LdapService::getAllUserUids()`** : accepte maintenant `$search`, `$limit`, `$offset` pour la pagination (utilisé par le backend NC)
- **`AdminController`** : injecte `SynologyApiService` ; gère les nouvelles clés de config API DSM ; nouveaux endpoints
- **`LdapUserBackend`** enregistré dans `Application::boot()` via `IUserManager::registerBackend()`

### Supprimé
- Dépendance à l'app `user_ldap` : plus nécessaire depuis la v2.0

### Sécurité
- Le mot de passe vide est explicitement refusé dans `authenticate()` (protection contre le bind LDAP anonyme)
- Connexion LDAP distincte pour l'authentification utilisateur (pas de réutilisation du compte de service)
- Mot de passe API DSM masqué dans les réponses GET config
- L'API DSM est appelée avec timeout 10s ; les erreurs de connexion sont loguées et non propagées à l'utilisateur

---

## [1.0.0] — 2026-05-28

### Ajouté
- Synchronisation automatique des groupes Active Directory Synology → groupes Nextcloud à chaque connexion utilisateur
- Promotion/révocation automatique du statut administrateur Nextcloud via groupe AD configurable (`ADMIN_NEXTCLOUD` par défaut)
- Montage automatique du stockage externe SMB par groupe via l'API `Files_External` de Nextcloud
- Interface d'administration visuelle avec 4 sections : LDAP, Stockage SMB, Groupe Admin, Correspondances
- Support du mode **Active Directory** (attribut `memberOf` sur l'objet utilisateur)
- Support du mode **POSIX/OpenLDAP** (attribut `memberUid` sur les groupes `posixGroup`)
- Bouton "Tester la connexion LDAP" avec affichage des groupes détectés
- Synchronisation en masse de tous les utilisateurs en un clic
- Application automatique des montages SMB pour tous les groupes configurés
- Clic sur un groupe détecté pour l'ajouter directement comme correspondance
- Journal des opérations en temps réel dans l'interface admin
- Script d'installation `install.sh` pour déploiement rapide
- Compatible Nextcloud 25 à 30, PHP 8.0+

### Sécurité
- Garde-fou : le dernier administrateur Nextcloud ne peut pas être révoqué automatiquement
- Les mots de passe (LDAP bind, SMB) ne sont jamais retournés par l'API GET
- Toutes les routes admin requièrent les droits administrateur (`@AdminRequired`)
- Échappement LDAP via `ldap_escape()` pour prévenir l'injection LDAP

---

## [Non publié]

### Prévu
- Synchronisation planifiée (cron) sans attendre la connexion utilisateur
- Support des groupes imbriqués AD (nested groups)
- Import/export de la configuration en JSON
- Notifications par email lors des promotions admin
- Support WebDAV et NFS en plus de SMB
- Interface multilingue (en, de, es)
- Tableau de bord avec statistiques (nb utilisateurs sync, montages actifs, dernière sync)
