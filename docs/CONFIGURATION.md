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

Cliquer **Tester la connexion LDAP** pour valider et voir les groupes détectés.

> Le compte de service doit avoir uniquement les droits de lecture sur l'annuaire AD. Ne jamais utiliser l'administrateur de domaine.

---

## Section 2 — Connexion Synology (SMB + API DSM)

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

Cliquer **Tester l'API DSM** pour valider la connexion à l'API Synology.

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
