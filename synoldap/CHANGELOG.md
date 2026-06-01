# Changelog

## [2.0.10] - 2026-06-01

### Corrections
- **Connexions LDAP multiples → session détruite** : chaque méthode du service LDAP ouvrait et fermait sa propre connexion TCP au compte de service. Lors d'un login, 4-5 connexions s'ouvraient en rafale ; le Synology LDAP rejetait la suivante → `userExists()` échouait silencieusement → Nextcloud invalidait la session → boucle login. Corrigé par mise en cache de la connexion dans `$serviceConn` (propriété de classe), réutilisée pour toute la durée de la requête PHP. Fermée proprement par `__destruct()`.
- **`user_ldap` en conflit avec `synoldap`** : `user_ldap` (official Nextcloud LDAP) interceptait l'authentification des utilisateurs synoldap et changeait leur backend en session, causant l'invalidation au GET suivant. Solution : désactiver `user_ldap` lorsque `synoldap` gère l'authentification LDAP.
- **`userExists()` silencieux en cas d'échec** : les exceptions de connexion LDAP étaient avalées sans log, rendant le diagnostic impossible. Ajout de `warning` sur `userExists()` introuvable et sur `ldap_search` échoué.

---

## [2.0.7] - 2026-06-01

### Correction critique
- **Session détruite immédiatement après login** : chaque méthode LDAP (`getUserInfo`, `getUserGroups`, `getAllUserUids`, `isKnownLdapGroup`) ouvrait et fermait sa propre connexion au compte de service. Lors d'un login, 4-5 connexions s'ouvraient en rafale → le Synology LDAP rejetait la suivante → `userExists()` retournait `false` → Nextcloud loggait "Found one account that was removed from its backend" → session invalidée → redirection vers /login. Corrigé : la connexion du compte de service est maintenant mise en cache dans `$serviceConn` (propriété de classe) et réutilisée pour toute la durée de la requête PHP. Fermée proprement dans `__destruct()`.

---

## [2.0.4] - 2026-06-01

### Ajouté
- **Synchronisation directe des groupes AD → NC** : tous les groupes AD de l'utilisateur sont maintenant automatiquement reflétés comme groupes Nextcloud (même nom), sans configuration de mapping nécessaire. Les groupes couverts par un mapping manuel restent gérés par ce mapping. Un groupe NC est retiré à l'utilisateur si le groupe AD correspondant existe dans l'annuaire mais que l'utilisateur n'en fait plus partie.
- **`LdapService::isKnownLdapGroup()`** : vérifie si un groupe NC correspond à un groupe AD réel (évite de retirer des utilisateurs de groupes NC purement locaux ayant le même nom qu'un groupe AD).

---

## [2.0.3] - 2026-06-01

### Corrections
- **Nom complet et email absents** : `ldap_get_attributes()` renvoie les attributs avec la casse du serveur (Synology AD : `displayName`, `givenName`, `sAMAccountName`). Le code cherchait en minuscules → toutes les valeurs étaient null → fallback sur l'identifiant. Corrigé : normalisation immédiate de toutes les clés en minuscules (`strtolower`) après `ldap_get_attributes()`.
- **Logs de profil** : `syncProfile()` journalise maintenant les mises à jour de displayName et d'email (niveau `info`) pour confirmer la synchronisation, ainsi qu'un avertissement si l'utilisateur est introuvable dans l'AD.

---

## [2.0.2] - 2026-06-01

### Corrections
- **Zéro utilisateurs listés** : PHP retourne les noms d'attributs LDAP en minuscules (`ldap_get_entries`, `ldap_get_attributes`). L'accès via le nom configuré (`sAMAccountName`) retournait toujours `null` → aucun utilisateur n'apparaissait dans la liste, le partage ou le panneau admin. Corrigé avec `strtolower()` dans `getAllUserUids()`, `getUserInfo()` et `getGroupsViaSearch()`.
- **Login `DOMAIN\username`** : le préfixe domaine Windows est maintenant retiré avant la recherche LDAP (`sAMAccountName` ne contient pas le domaine dans l'AD). Un utilisateur peut désormais se connecter avec `CORP\jdupont` ou `jdupont`.
- **Login UPN** (`user@domain.com`) : la recherche inclut maintenant aussi `userPrincipalName` lorsque le login contient `@`.
- **Erreur de bind masquée** : le `@` sur `ldap_bind()` dans `authenticate()` supprimait le message d'erreur LDAP réel. Supprimé — l'erreur exacte est maintenant journalisée au niveau `warning` (ex : "Invalid credentials", "Constraint violation").
- **Nom complet et email non synchronisés** : le displayName et l'email de l'AD n'étaient jamais poussés vers le compte Nextcloud. Corrigé via `GroupSyncService::syncProfile()` appelée à chaque connexion.

---

## [2.0.1] - 2026-05-29

### Corrections
- Connexion LDAPS (port 636) avec certificat auto-signé Synology : ajout de `LDAP_OPT_X_TLS_REQUIRE_CERT = NEVER` avant chaque `ldap_connect()` dans `LdapService` (méthodes `connect()` et `connectRaw()`). Nécessaire car le Synology Directory Server exige une connexion chiffrée et utilise un certificat auto-signé rejeté par défaut par PHP.

---

## [2.0.0] - 2026-05-28

### Nouveautés
- Backend d'authentification intégré — connexion avec les identifiants Windows (sAMAccountName + mot de passe AD), sans app `user_ldap` externe
- Provisionnement automatique des comptes Nextcloud à la première connexion
- Synchronisation automatique des groupes AD → groupes Nextcloud à chaque connexion
- Promotion automatique administrateur via groupe AD configurable
- Trois modes de montage SMB : manuel, auto par nom de groupe, auto par ACL Synology
- Mode ACL : lecture des droits réels via l'API DSM (`SYNO.Core.ACL`) — chaque utilisateur voit exactement les dossiers autorisés par ses groupes AD
- Préfixe de montage pour reproduire l'arborescence Windows dans Nextcloud (`/NAS/Compta`, etc.)
- Interface d'administration avec aperçu ACL en temps réel et journal d'activité

### Corrections
- Remplacement de `file_get_contents` par cURL pour les appels à l'API DSM Synology
- Gestion des erreurs de connexion DSM et vérification de `allow_url_fopen`
- Correction des types de paramètres dans `LdapUserBackend` (`userExists`, `getUsers`, `getDisplayName`, `deleteUser`)
- Ajout de `hasUserListings()` pour la liste d'utilisateurs dans le backend
