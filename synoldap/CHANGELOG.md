# Changelog

## [3.4.2] - 2026-09-04

### Modifié

- Champ **Domaine / Workgroup** : exemple `PAVILLY` et explication de son rôle — c'est le nom
  de connexion Nextcloud qui est transmis au NAS, il doit donc être qualifié par le domaine
  NetBIOS pour que le transport « identifiants de l'utilisateur » fonctionne.
- Version incrémentée pour forcer la reprise des ressources statiques (JS/CSS) par Nextcloud
  et les navigateurs après la mise à jour.

---

## [3.4.1] - 2026-09-04

### Corrigé

- **Erreur 500 sur la page de réglages avec Nextcloud 34** : le gabarit d'administration
  appelait encore `\OC::$server->getURLGenerator()`, retiré du conteneur hérité dans les
  versions récentes de Nextcloud. Le rendu de la page échouait donc dès la balise `<img>`
  du logo, avant tout le reste du formulaire. Remplacé par la fonction de gabarit publique
  `image_path()`, échappée par `p()`.

---

## [3.4.0] - 2026-09-04

### Ajouté

- **Transport « SMB — identifiants de l'utilisateur »** : un seul montage pour tous, où chaque
  utilisateur s'authentifie au NAS avec son propre compte AD. Le Synology applique alors ses
  **ACL Windows à tous les niveaux** (et pas seulement au premier sous-dossier) : l'arborescence
  est identique à celle du lecteur réseau, sur le web comme sur mobile, tablette et PC distant.
- Option **Identifiants utilisateur — conservation** : *en session* (aucun mot de passe stocké)
  ou *en base* (chiffré, plus robuste pour les clients mobiles et les tâches de fond).
- Valeur **SMB user** dans la colonne *Type* des correspondances.

### Modifié

- Le mécanisme d'authentification est désormais comparé avant toute réécriture d'un montage :
  basculer une ligne du compte de service vers les identifiants utilisateur met le montage à
  jour sur place.

### Limites connues du transport « identifiants de l'utilisateur »

- Nécessite une connexion avec le mot de passe AD (pas de SSO sans mot de passe)
- Un changement de mot de passe AD casse le montage jusqu'à la reconnexion du client
- Les tâches de fond (aperçus, indexation, `files:scan`) et les liens de partage public n'ont
  pas accès au montage

---

## [3.3.0] - 2026-09-04

### Ajouté

- **Transport NFS / local** en plus de SMB : quand le NAS est déjà monté sur l'hôte en NFSv4
  (Docker, Portainer, Nextcloud AIO), les montages utilisent le backend `local` de
  `files_external` — plus rapide, sans compte SMB ni extension `smbclient`.
  Nouveau champ *Racine locale* (ex. `/mnt/nas`) : le partage `User` est lu dans `/mnt/nas/User`.
- **Transport par ligne** : colonne **Type** (Défaut / SMB / NFS) dans les correspondances.
- **Bouton « Vérifier le chemin local »** : contrôle depuis le conteneur que le chemin existe,
  qu'il est lisible/inscriptible par `www-data` (uid 33), et liste les dossiers trouvés —
  diagnostic des montages NFS et de la variable `NEXTCLOUD_MOUNT` (AIO).
- **Mode de correspondance « Commun »** : un dossier monté pour **tous** les utilisateurs
  (sans groupe ni utilisateur applicable), pour le dossier partagé entre tous les services.
- **Détection automatique du domaine AD** : lecture du RootDSE (`defaultNamingContext`) et
  remplissage des base DN utilisateurs/groupes. Avec le compte de service, les conteneurs réels
  sont déduits (suffixe DN commun) au lieu du `CN=Users` par défaut.
- **Liste des partages du NAS** via l'API DSM (`SYNO.FileStation.List/list_share`) :
  auto-complétion du champ *Partage* ; un clic sur un partage crée une ligne « Auto ACL »
  préconfigurée.
- Option **« Autoriser le partage Nextcloud des fichiers montés »** (`enable_sharing`),
  nécessaire pour partager un document du NAS entre services depuis Nextcloud.
- Nouvelles routes : `POST /admin/detect-ad`, `GET /admin/shares`, `POST /admin/test-local`.

### Modifié

- **Compatibilité Nextcloud 34** (Hub 26) : `max-version` passé de 33 à 34.
- `StorageConfigService` : transports SMB et local unifiés (`resolveBackend()`,
  `buildStorageOptions()`) ; un montage existant qui change de transport est **converti sur
  place** (backend et mécanisme d'authentification réappliqués), sans perdre son point de montage.
- Montages : options `enable_sharing`, `previews` et `filesystem_check_changes = 1` (relecture
  du dossier à chaque accès direct, indispensable quand les fichiers sont modifiés hors Nextcloud).
- Le garde anti-écriture inutile de la v2.0.34 (erreurs 401 sur NC 33) est conservé et étendu
  au backend et aux options de montage : rien n'est écrit dans `oc_external_storages` si la
  configuration n'a pas changé.
- `\OC::$server->get()` remplacé par `\OCP\Server::get()` (API publique) dans
  `StorageConfigService`.
- Les tests des nouvelles actions (détection AD, chemin local, partages) enregistrent la
  configuration au préalable : plus de test sur une configuration périmée.

### Corrigé

- Mode manuel : un champ *Groupe Nextcloud* laissé vide reprend correctement le nom du groupe AD
  lors de l'application des montages.

---

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
