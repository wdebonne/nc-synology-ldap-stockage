# Changelog

Toutes les modifications notables de ce projet sont documentées dans ce fichier.

Le format suit [Keep a Changelog](https://keepachangelog.com/fr/1.0.0/),
et ce projet respecte le [Versionnage Sémantique](https://semver.org/lang/fr/).

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
