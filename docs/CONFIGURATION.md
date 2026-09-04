# Guide de configuration

Accéder à **Administration → SynoLDAP** dans l'interface Nextcloud.

---

## Section 1 — LDAP / Active Directory

| Champ | Description | Exemple |
|-------|-------------|---------|
| Hôte Synology | IP ou nom DNS du Synology | `192.168.1.10` |
| Port LDAP | 389 (LDAP) ou 636 (LDAPS) | `389` |
| Bind DN | DN du compte de service (lecture seule) | `CN=svc_nc,CN=Users,DC=corp,DC=local` |
| Mot de passe Bind | Mot de passe du compte de service | |
| Base DN utilisateurs | Base de recherche des utilisateurs | `CN=Users,DC=corp,DC=local` |
| Base DN groupes | Base de recherche des groupes | `CN=Users,DC=corp,DC=local` |
| Filtre utilisateurs | Filtre LDAP supplémentaire (optionnel) | `(department=NC)` |
| Mode LDAP | `active_directory` (memberOf) ou `posix` (memberUid) | `active_directory` |

### Détection automatique du domaine

Renseigner l'hôte (et de préférence le compte de service), puis cliquer
**« Détecter le domaine automatiquement »**. L'app lit le RootDSE du contrôleur de domaine
(`defaultNamingContext`) et remplit les base DN utilisateurs et groupes — déduites des
conteneurs réels quand le bind réussit, sinon `CN=Users,<domaine>` par défaut.
Sur le domaine `pavilly.int`, la détection retourne `DC=pavilly,DC=int`.

Cliquer **Tester la connexion LDAP** pour valider et voir les groupes détectés.

> Le compte de service doit avoir uniquement les droits de lecture sur l'annuaire AD. Ne jamais utiliser l'administrateur de domaine.

---

## Section 2 — Connexion Synology (SMB / NFS + API DSM)

### Transport

| Transport | Description |
|-----------|-------------|
| **SMB — compte de service** | Nextcloud se connecte au NAS en CIFS avec un compte unique. Les accès sont découpés par les montages par groupe créés par l'app. |
| **SMB — identifiants de l'utilisateur** | Un seul montage pour tout le monde : chaque utilisateur s'authentifie au NAS avec son compte AD et le Synology applique ses ACL Windows, à tous les niveaux. |
| **NFS / local** | Le partage est déjà monté sur l'hôte (NFSv4) et visible dans le conteneur. Nextcloud lit les fichiers en local. |

Le transport choisi ici est celui utilisé par défaut ; chaque ligne de correspondance peut le
surcharger avec la colonne **Type**.

### Transport SMB — identifiants de l'utilisateur

C'est le mode le plus proche du lecteur réseau Windows : **le NAS arbitre**, pas Nextcloud.
Un membre de `COMPTA` qui n'a pas de droit sur `COMPTA/MARCHE PUBLIC` ne le voit pas, à
n'importe quelle profondeur — y compris depuis les applications mobiles et tablettes.

Configuration type : une seule ligne **Commun**, type **SMB user**, partage `Users`,
point de montage `Users`. Aucune ligne ACL n'est nécessaire pour ce partage.

| Champ | Effet |
|-------|-------|
| Identifiants utilisateur — conservation : **En session** | Aucun mot de passe stocké côté serveur |
| Identifiants utilisateur — conservation : **En base (chiffré)** | Plus robuste pour les clients mobiles et les tâches de fond |

**Limites à connaître :**

| Situation | Conséquence |
|-----------|-------------|
| Connexion (web, mobile, bureau) avec le mot de passe AD | ✅ le montage fonctionne |
| Mot de passe AD modifié | ⚠️ le montage échoue jusqu'à la reconnexion du client |
| Connexion SSO/SAML sans mot de passe | ❌ pas de montage |
| Tâches de fond : aperçus, indexation plein texte, `files:scan`, liens de partage public | ❌ pas d'accès au montage |

> Côté DSM, activer sur le partage l'option **« Cacher les sous-dossiers et fichiers des
> utilisateurs sans autorisations »** : sinon les dossiers interdits restent visibles mais
> inaccessibles, comme sur un partage Windows sans énumération basée sur l'accès.

> L'option « Autoriser le partage Nextcloud des fichiers montés » a peu d'intérêt sur ce
> transport : un partage nécessite un accès au stockage hors session du propriétaire.

### Transport NFS / local

| Champ | Description | Exemple |
|-------|-------------|---------|
| Racine locale | Chemin, **vu depuis le conteneur Nextcloud**, sous lequel les partages sont montés | `/mnt/nas` |

Le partage `User` est alors lu dans `/mnt/nas/User`, et le sous-dossier `Compta` dans
`/mnt/nas/User/Compta`.

Cliquer **« Vérifier le chemin local »** : l'app contrôle que le chemin existe depuis le
conteneur, qu'il est lisible (et inscriptible) par `www-data` (uid 33), et liste les dossiers
qu'il contient. Voir la [procédure de montage NFS](INSTALLATION.md#monter-le-nas-en-nfsv4-transport--local-).

### Partage Nextcloud des fichiers montés

La case **« Autoriser le partage Nextcloud des fichiers montés »** active l'option
`enable_sharing` sur les montages créés : sans elle, les fichiers du NAS ne peuvent pas être
partagés depuis Nextcloud (lien public, partage à un autre utilisateur).

### Stockage SMB

| Champ | Description | Exemple |
|-------|-------------|---------|
| Hôte Synology | Réutilisé depuis la section LDAP | `192.168.1.10` |
| Utilisateur SMB | Compte de service pour les montages | `svc_smb` |
| Mot de passe SMB | Mot de passe du compte SMB | |

Le compte SMB doit avoir accès en lecture aux partages racine. Les ACL par sous-dossier contrôlent les accès fins.

### API DSM (pour le mode ACL)

| Champ | Description | Exemple |
|-------|-------------|---------|
| Port DSM | Port de l'interface d'administration | `5000` (HTTP) ou `5001` (HTTPS) |
| HTTPS | Activer TLS (certificat auto-signé accepté) | |
| Utilisateur DSM | Compte admin DSM dédié | `api_nc` |
| Mot de passe DSM | Mot de passe du compte admin DSM | |

Cliquer **Tester l'API DSM** pour valider la connexion à l'API Synology, et
**Lister les partages du NAS** pour alimenter l'auto-complétion du champ *Partage*
(un clic sur un partage crée une ligne « Auto ACL » préconfigurée).

> Le compte DSM doit être dans le groupe `administrators` du Synology. Créer un compte dédié, ne pas utiliser `admin`.

---

## Section 3 — Groupe admin

Renseigner le nom du groupe AD dont les membres doivent recevoir les droits administrateur Nextcloud (ex : `ADMIN_NEXTCLOUD`). Laisser vide pour désactiver cette fonctionnalité.

---

## Section 4 — Correspondances groupes ↔ stockage

### Modes disponibles

| Mode | Cas d'usage |
|------|-------------|
| **Manuel** | Mapping explicite : groupe AD précis → partage SMB précis |
| **Auto par nom** | Le sous-dossier SMB porte le même nom que le groupe AD |
| **Auto par ACL ★** | Lit les ACL Synology — chaque utilisateur voit les dossiers autorisés par ses groupes |
| **Commun** | Un dossier monté pour tous les utilisateurs, sans condition de groupe |

La colonne **Type** (Défaut / SMB / SMB user / NFS) permet de mélanger les transports : par
exemple le partage principal en « SMB user » (ACL du NAS) et un dossier commun en NFS.

### Ligne manuelle

| Colonne | Description |
|---------|-------------|
| Groupe AD | Groupe LDAP source |
| Groupe NC | Groupe Nextcloud cible |
| Partage SMB | Nom du partage sur le Synology |
| Sous-dossier | Chemin relatif dans le partage (optionnel) |
| Point de montage | Nom affiché dans Nextcloud |

### Ligne Auto par nom

| Colonne | Description |
|---------|-------------|
| Partage racine | Nom du partage SMB racine (ex : `Externe`) |
| Préfixe NC | Préfixe pour le point de montage (ex : `NAS`) |

Exemple : groupe `Compta`, partage `Externe`, préfixe `NAS` → montage `/NAS/Compta`.

### Ligne Auto par ACL ★

| Colonne | Description |
|---------|-------------|
| Partage racine | Nom du partage SMB racine (ex : `Externe`) |
| Préfixe NC | Préfixe pour le point de montage (ex : `NAS`) |

L'application interroge l'API DSM pour lire les ACL Windows (NTFS-style) de chaque sous-dossier du partage. Pour chaque sous-dossier, les groupes AD qui ont un droit de lecture sont identifiés. À la connexion de l'utilisateur, les sous-dossiers correspondant à ses groupes AD sont montés.

Exemple :
- Synology : groupe `Responsable` a R/W sur `Externe/Compta`
- Aurélie est dans le groupe AD `Responsable`
- → Aurélie voit `/NAS/Compta` dans Nextcloud, même sans être dans le groupe `Compta`

### Ligne Commune

| Colonne | Description |
|---------|-------------|
| Partage | Nom du partage (ex : `User`) |
| Sous-dossier | Dossier à partager avec tout le monde (vide = racine du partage) |
| Point de montage | Chemin affiché dans Nextcloud (ex : `User/Commun`) |

Le montage est créé sans groupe ni utilisateur applicable : **tous** les utilisateurs
Nextcloud le voient. Exemple type pour le partage `User` du NAS :

| Ligne | Mode | Résultat |
|-------|------|----------|
| 1 | Auto ACL, partage `User`, préfixe `User` | `/User/Compta` visible seulement par les groupes AD cités dans l'ACL Synology |
| 2 | Commun, partage `User`, sous-dossier `Commun` | `/User/Commun` visible par tout le monde |

### Prévisualiser les ACL

Cliquer **Prévisualiser les ACL** pour afficher le mapping découvert (dossier → groupes AD avec accès) sans attendre la connexion d'un utilisateur.

Les données ACL sont mises en cache 1 heure. Cliquer **Vider le cache ACL** après toute modification de droits sur le Synology pour forcer la relecture immédiate.

---

## Exemple de configuration complète

**Contexte :** Synology à `192.168.1.10`, domaine `corp.local`, partage `Externe` avec sous-dossiers `Compta`, `RH`, `Direction`.

**Résultat attendu :** Aurélie (`Responsable`) → `/NAS/Compta`, Martin (`RH`) → `/NAS/RH`, Sophie DGS (`Responsable` + `RH`) → `/NAS/Compta` + `/NAS/RH`.

| Paramètre | Valeur |
|-----------|--------|
| Hôte | `192.168.1.10` |
| Bind DN | `CN=svc_nc,CN=Users,DC=corp,DC=local` |
| Base DN | `CN=Users,DC=corp,DC=local` |
| Mode | `active_directory` |
| Utilisateur SMB | `svc_smb` |
| Port DSM | `5000` |
| Utilisateur DSM | `api_nc` |
| Groupe admin | `ADMIN_NEXTCLOUD` |
| Correspondance | **Auto ACL**, partage `Externe`, préfixe `NAS` |
