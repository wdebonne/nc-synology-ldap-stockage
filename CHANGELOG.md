# Changelog

Toutes les modifications notables de ce projet sont documentées dans ce fichier.

Le format suit [Keep a Changelog](https://keepachangelog.com/fr/1.0.0/),
et ce projet respecte le [Versionnage Sémantique](https://semver.org/lang/fr/).

---

## [3.2.1] — 2026-06-04

### Fix — UserLdapBridgeService : écriture directe dans oc_appconfig

La v3.2.0 utilisait `\OCA\User_LDAP\Configuration::saveConfiguration()` pour écrire
la config user_ldap. Cette classe utilise `\OCP\Server::get()` en interne avec des
dépendances NC 33 qui ne s'injectent pas correctement depuis un service externe →
`sync()` retournait false silencieusement.

**Fix** : réécriture complète en `IConfig::setAppValue()` directs — plus simple,
pas de dépendance aux classes internes de user_ldap, 100% prévisible.

Paramètres écrits dans `oc_appconfig[user_ldap]` (préfixe vide = premier serveur) :
- Serveur : `ldap_host`, `ldap_port`, `ldap_tls`, `ldap_turn_off_cert_check`
- Auth : `ldap_agent_name`, `ldap_agent_password` (base64-encoded comme user_ldap)
- DNs : `ldap_base`, `ldap_base_users`, `ldap_base_groups`
- Filtres : `ldap_userlist_filter`, `ldap_login_filter`, `ldap_login_filter_username`
- UID : `ldap_expert_username_attr` (sAMAccountName)
- Groupes : `ldap_group_filter`, `ldap_group_member_assoc_attribute`, `ldap_group_display_name`
- UUID : `ldap_uuid_user_attribute` (objectGUID)
- Activation : `ldap_configuration_active = 1`

---

## [3.2.0] — 2026-06-04

### Architecture définitive — user_ldap intégré via bridge automatique

#### Constat final

Après 35+ versions de correctifs, les bugs (401 DAV, "Se souvenir de moi", sessions) persitaient
car ils sont intrinsèques à tout backend custom face aux internals de NC 33 (DAV_AUTHENTICATED,
session regeneration, dirty table reads). user_ldap n'a jamais ces problèmes car il a été testé
et mis à jour spécifiquement pour NC 33.

#### Solution : UserLdapBridgeService

**`synoldap/lib/Service/UserLdapBridgeService.php`** — nouveau service qui configure
user_ldap automatiquement depuis les paramètres LDAP de synoldap :

- Utilise `\OCA\User_LDAP\Configuration::saveConfiguration()` → user_ldap gère
  le chiffrement du mot de passe (base64), la sérialisation, l'écriture en DB
- Mappe les propriétés synoldap → user_ldap : host, port, TLS, bind DN/password,
  base DN utilisateurs et groupes, filtres, attribut UID (sAMAccountName)
- Enregistre le préfixe dans `configuration_prefixes` (NC 33)
- Idempotent : `IConfig::setAppValue()` n'écrit en DB que si la valeur a changé

#### Fonctionnement

1. Admin configure les paramètres LDAP dans synoldap (une seule interface)
2. Lors de **chaque Save**, synoldap synchronise sa config vers user_ldap
3. Lors de **chaque boot NC**, synoldap vérifie et re-synchronise si besoin
4. user_ldap se charge de TOUT ce qui touche l'authentification :
   - `checkPassword()` (remember me, re-validation token)
   - DAV_AUTHENTICATED (géré nativement)
   - Sessions NC 33 (éprouvé)
5. synoldap intercepte `PostLoginEvent` pour `getBackendClassName() === 'LDAP'`
   et gère la sync des groupes AD → NC + montages SMB

#### Nouveaux endpoints admin

- `GET /admin/user-ldap-status` — état de la synchronisation user_ldap
- `POST /admin/sync-user-ldap` — force la re-synchronisation

#### Requis

user_ldap doit être installé dans NC (inclus par défaut dans NC AIO).
synoldap détecte automatiquement sa présence via `class_exists('\OCA\User_LDAP\Configuration')`.

---

## [3.1.1] — 2026-06-04

### Ajouté — Interface admin : test SMB + toggles activer/désactiver par section

#### Bouton "Tester la connexion SMB"

Nouveau bouton dans la section "Connexion Synology" qui effectue deux vérifications successives :
1. **Connectivité réseau** (port 445) — vérifie si le Synology est joignable depuis le serveur NC
2. **Authentification SMB** via `icewind/smb` (bundlé avec NC's `files_external`) — liste les partages SMB accessibles avec les credentials configurés

Endpoint : `POST /admin/test-smb` → `AdminController::testSmb()`

#### Toggles activer/désactiver par section

Chaque bloc de l'interface admin dispose désormais d'un switch ON/OFF dans son entête :
- Section **LDAP** (🔌)
- Section **Synology SMB + API DSM** (🗄️)
- Section **Promotion Admin** (👑)
- Section **Correspondances Groupes ↔ Stockage** (🗂️)

Comportement :
- L'état est sauvegardé dans `localStorage` → persistant entre les rechargements
- Section désactivée : contenu grisé + barré + tous les inputs non cliquables
- Le clic sur le switch ne déclenche pas le collapse/expand de la section (events séparés)

---

## [3.1.0] — 2026-06-04

### Refactoring architecture — Table de mapping persistante (pattern user_ldap)

#### Pourquoi user_ldap est stable avec NC 33

La vraie raison pour laquelle user_ldap n'a jamais les problèmes que nous avons eus :
**`ldap_user_mapping`** — une table DB dédiée. Quand `userExists(uid)` est appelé, user_ldap
consulte d'abord cette table. Si l'utilisateur est trouvé → `true` immédiatement, aucun
appel LDAP, aucun cache à expirer, aucune dépendance à Redis/APCu.

Nos versions 2.x utilisaient `oc_preferences[synoldap][known]` comme substitut, mais
cette table fait partie des tables trackées par NC 33 pour les dirty reads → problèmes de
transaction → incohérence de session → `DAV_AUTHENTICATED` corrompu → 401.

#### Ce qui change en 3.1.0

**Migration `Version3001Date20260603`** : crée la table `oc_synoldap_users` (uid, dn, verified_at).
Équivalent direct de `oc_ldap_user_mapping` de user_ldap. **Non trackée par NC 33** pour
les dirty reads.

**`LdapUserBackend`** — réécriture complète sur les patterns user_ldap :
- `implementsActions()` : bitmask manuel `CHECK_PASSWORD | GET_HOME | GET_DISPLAYNAME | COUNT_USERS`
  (comme `User_LDAP::implementsActions()`) — plus fiable que les interfaces OCP en NC 33
- `checkPassword()` : après auth LDAP réussie, appelle `upsertMapping(uid, dn)` — équivalent
  de `cacheUserExists()` + `User::markLogin()` de user_ldap
- `userExists()` : 1. cache distribué → 2. `oc_synoldap_users` (DB, permanent) → 3. LDAP
  (premier login uniquement). Après le premier login, **JAMAIS d'appel LDAP** pour cet utilisateur
- `getHome()` : déclaré via `implementsActions(GET_HOME)` — NC initialise correctement le home storage

**`LdapService::getUserDn()`** : nouvelle méthode pour stocker le DN LDAP dans le mapping.

**`UserLoggedInListener`** : redevient `getBackendClassName() === 'SynoLDAP'` (notre backend
est de nouveau enregistré), suppression de toute manipulation de session.

**`Application::boot()`** : `userManager->registerBackend($backend)` de retour.

#### Procédure de déploiement

```bash
# 1. Déployer les fichiers
sudo docker exec -u www-data nextcloud-aio-nextcloud php /var/www/html/occ upgrade
# → Crée la table oc_synoldap_users via la migration

# 2. Redémarrer pour vider l'OPcache
sudo docker restart nextcloud-aio-nextcloud

# 3. Se reconnecter — le premier login peuple oc_synoldap_users
#    Les logins suivants utilisent la table → aucun LDAP → aucun problème de session
```

---

## [3.0.0] — 2026-06-03

### Refactoring majeur — synoldap devient un companion app pour user_ldap

#### Constat

Malgré 35 versions correctives successives, les bugs persistaient :
- "Cannot authenticate over ajax calls" → `DAV_AUTHENTICATED` hérité d'une session précédente
- Dirty table reads NC 33 → writes DB dans la transaction d'auth
- Gestion de session, CSRF, `known=1`, `ensureUserRow()`... autant de problèmes propres à
  l'implémentation d'un backend utilisateur custom qui lutte contre les internals de NC 33.

La cause profonde : **réimplémenter un backend utilisateur NC complet est trop complexe**.
NC 33 a des exigences très strictes (transactions DB, session management, DAV auth) que
user_ldap respecte nativement après des années de développement.

#### Nouvelle architecture

synoldap ne gère **plus** l'authentification. user_ldap reste installé et configuré pour
l'Active Directory Synology — il fonctionne parfaitement.

synoldap devient un **companion app** qui ajoute les fonctionnalités Synology-spécifiques :

| Qui fait quoi | Avant (v2.x) | Après (v3.0) |
|---|---|---|
| Authentification LDAP | LdapUserBackend (custom) | **user_ldap** (officiel) |
| Session / DAV_AUTHENTICATED | Notre code (bugué) | **user_ldap** (stable) |
| home directory, userExists | Notre code | **user_ldap** |
| Sync groupes AD → NC | Notre code | **Notre code** (inchangé) |
| Montages SMB | Notre code | **Notre code** (inchangé) |
| API Synology ACL | Notre code | **Notre code** (inchangé) |

#### Changements techniques

**`Application.php`** : suppression de `$userManager->registerBackend($backend)`. Plus aucun
backend utilisateur enregistré — uniquement le listener PostLoginEvent.

**`UserLoggedInListener`** : filtre `getBackendClassName() === 'LDAP'` (user_ldap retourne
'LDAP' depuis `getBackendName()`). Les comptes NC natifs (Database) sont toujours ignorés.
Suppression de `ISession`, `IDBConnection`, DAV_AUTHENTICATED manipulation — tout cela est
géré correctement par user_ldap.

**`LdapUserBackend.php`** : gardé dans les sources mais plus enregistré. Peut être réactivé
si user_ldap est absent.

#### Prérequis

user_ldap doit être installé et configuré :
- Hôte LDAP : IP du Synology
- Filtre utilisateur : `objectClass=user`
- Attribut login : `sAMAccountName`
- Groupes : optionnel (synoldap les gère via sa propre connexion LDAP)

---

## [2.0.35] — 2026-06-03

### Correction définitive — "Cannot authenticate over ajax calls" (PROPFIND 401)

#### Cause racine confirmée par le code source NC 33

```
Auth.php:31  public const DAV_AUTHENTICATED = 'AUTHENTICATED_TO_DAV_BACKEND';
Auth.php:85  $this->session->set(self::DAV_AUTHENTICATED, uid);  // posé après auth DAV réussie
Auth.php:163 $forcedLogout = false;
Auth.php:169 $forcedLogout = true;  // si DAV_AUTHENTICATED ≠ uid courant
Auth.php:176 if ($forcedLogout) { $this->userSession->logout(); }
```

**Scénario exact :**
1. Un premier utilisateur (ex. admin) fait un PROPFIND réussi → `DAV_AUTHENTICATED = 'admin'`
2. e.berthy (utilisateur LDAP) se connecte → NC 33 régénère l'ID de session mais **copie** toutes les données de l'ancienne session, dont `DAV_AUTHENTICATED = 'admin'`
3. PROPFIND d'e.berthy : Auth.php line 169 détecte `'admin' ≠ 'e.berthy'` → `forcedLogout = true`
4. NC déconnecte e.berthy en cours de traitement du PROPFIND
5. `parent::check()` (Basic Auth) échoue → requête AJAX → `throw NotAuthenticated('Cannot authenticate over ajax calls')` → **401**

Les utilisateurs natifs NC (admin) ne sont PAS affectés car notre `UserLoggedInListener` ne s'exécute que pour `getBackendClassName() === 'SynoLDAP'`. L'administrateur natif utilise également une auth par token d'app pour le client desktop, qui contourne le check de session DAV.

**Fix** : `UserLoggedInListener` supprime `DAV_AUTHENTICATED` de la session dès le `PostLoginEvent`. La prochaine fois que NC's Auth.php s'exécute (PROPFIND), `is_null(DAV_AUTHENTICATED) = true` → condition 1 valide → auth réussit.

---

## [2.0.34] — 2026-06-03

### Diagnostic confirmé — Cause réelle du 401

```
"app":"webdav","message":"Cannot authenticate over ajax calls"
Auth.php:205 — Sabre\DAV\Exception\NotAuthenticated
```

Le 401 ne vient PAS de synoldap mais de NC 33 qui bloque les requêtes DAV AJAX depuis le
navigateur quand soit (a) le token CSRF est invalide, soit (b) la 2FA est requise mais non
complétée pour la session courante.

**Fix `StorageConfigService::doMount()`** : n'appelle `updateStorage()` que si la
configuration a réellement changé. Sans ce garde, chaque login déclenchait une écriture
dans `oc_external_storages` → mise à jour du cache `oc_mounts` → dirty table reads NC 33
→ `SetupManager::setupForUser()` rate partiellement dans le même processus PHP → peut
contribuer à l'invalidation du token CSRF (session state incohérent).

---

## [2.0.33] — 2026-06-03

### Fix définitif — NC AIO PostgreSQL : `oc_users.backend` inexistant

#### Root cause confirmée par le log NC

```
[SynoLDAP] ensureUserRow(e.dupont):
SQLSTATE[42703]: column "backend" does not exist
```

**NC AIO utilise PostgreSQL.** En PostgreSQL + NC 33, `oc_users` n'a **pas** de colonne
`backend`. Toutes les tentatives `ensureUserRow()` (v2.0.29 à 2.0.32) échouaient silencieusement.
NC 33 + PostgreSQL détermine le backend en **itérant tous les backends** via `userExists()`.

#### Vraie cause des 401

`known=1` n'était **pas garanti** dans `checkPassword()`. NC re-valide le token "Se souvenir
de moi" toutes les 300s en appelant `checkPassword()`. Si le cache Redis avait expiré et
LDAP était lent, `checkPassword()` retournait `false` → token invalidé → 401.

En 2.0.32, `known=1` n'était posé que dans le listener PostLoginEvent — les re-checks de
token (qui appellent `checkPassword()` sans re-déclencher PostLoginEvent) ne le posaient pas.

#### Fix 2.0.33

**`checkPassword()`** : pose `known=1` après TOUTE auth réussie (login ET re-check token).
Avec Redis (3600s) + `known=1` en oc_preferences, `userExists()` ne touche plus jamais LDAP.

**`UserLoggedInListener`** : suppression de `ensureUserRow()` + `IDBConnection` (incompatibles
PostgreSQL). Filtre : `getBackendClassName() === 'SynoLDAP'`.

---

## [2.0.32] — 2026-06-03

### Refactoring NC 33 — Isolation des transactions DB + diagnostic utilisateur

#### Diagnostic : utilisateur correctement provisionné mais PROPFIND 401

`occ user:info e.dupont` confirme : backend=SynoLDAP, enabled=true, storage OK.
`oc_users.backend` est correct — la cause racine 401 est ailleurs.

#### Root cause NC 33 : "dirty table reads"

Le log `occ upgrade` révèle `"version": "33.0.3.2"`. NC 33 impose une isolation
stricte des transactions DB. Les erreurs "dirty table reads" montrent que NC 33 bloque
les lectures/écritures sur certaines tables (`oc_appconfig`, `oc_users`) quand elles
sont impliquées dans une transaction active. Notre code dans `checkPassword()` qui appelait
`setUserValue()`, `setAppValue()` et `ensureUserRow()` échouait silencieusement (try/catch).
Conséquence : `known=1` n'était jamais posé → `userExists()` tombait sur LDAP à chaque
validation de session → si LDAP est momentanément lent → 401.

#### Fix : déplacement complet des ops DB vers PostLoginEvent (hors transaction)

**`LdapUserBackend::checkPassword()`** :
- Suppression de toutes les opérations DB (setUserValue, setAppValue, ensureUserRow)
- Uniquement : cache Redis + authentification LDAP + cache Redis
- Compatible NC 33 (aucune écriture DB dans le contexte de transaction d'auth)

**`UserLoggedInListener`** :
- Filtre remplacé : `known=1` → `getBackendClassName() === 'SynoLDAP'`
  (plus fiable : fonctionne même si known=1 n'a pas encore été posé)
- Injection de `IDBConnection` pour `ensureUserRow()`
- Séquence : known=1 → ensureUserRow() → syncUser() (tout hors transaction NC)

---

## [2.0.31] — 2026-06-03

### Fix — ensureUserRow() sur les cache hits credentials

`ensureUserRow()` n'était appelé que lors d'une authentification LDAP fraîche. Si les
credentials étaient déjà en cache Redis (session précédente, TTL 3600s), `checkPassword()`
retournait le UID en cache sans jamais appeler `ensureUserRow()`. Résultat : `oc_users.backend`
pouvait rester incorrect entre les déploiements.

Fix : `ensureUserRow()` est maintenant appelé même sur un cache hit (Redis ou app config),
garantissant que `oc_users.backend` est toujours correct quelle que soit la source du UID.

---

## [2.0.30] — 2026-06-03

### Correction — Bug critique dans ensureUserRow() (QueryBuilder réutilisé)

Console F12 confirme la cause racine :
```
PROPFIND /remote.php/dav/files/e.dupont/ 401 (Unauthorized)
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
