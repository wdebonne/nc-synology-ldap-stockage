# Changelog

## [2.0.2] - 2026-06-01

### Corrections
- **Zéro utilisateurs listés** : PHP retourne les noms d'attributs LDAP en minuscules (`ldap_get_entries`, `ldap_get_attributes`). L'accès via le nom configuré (`sAMAccountName`) retournait toujours `null` → aucun utilisateur n'apparaissait dans la liste, le partage ou le panneau admin. Corrigé avec `strtolower()` dans `getAllUserUids()`, `getUserInfo()` et `getGroupsViaSearch()`.
- **Login `DOMAIN\username`** : le préfixe domaine Windows est maintenant retiré avant la recherche LDAP (`sAMAccountName` ne contient pas le domaine dans l'AD). Un utilisateur peut désormais se connecter avec `CORP\jdupont` ou `jdupont`.
- **Login UPN** (`user@domain.com`) : la recherche inclut maintenant aussi `userPrincipalName` lorsque le login contient `@`.
- **Logs de diagnostic** : `authenticate()` logue maintenant clairement si l'utilisateur est introuvable dans l'AD ou si le mot de passe est incorrect (niveau `debug`).

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
