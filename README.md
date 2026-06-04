# nc-synology-ldap-stockage

**Application Nextcloud** pour l'intégration complète de l'Active Directory Synology : authentification, groupes, ACL et stockage externe SMB — sans app tierce.

[![Nextcloud](https://img.shields.io/badge/Nextcloud-25--33-0082c9?logo=nextcloud)](https://nextcloud.com)
[![PHP](https://img.shields.io/badge/PHP-8.1%2B-777bb4?logo=php)](https://php.net)
[![Licence](https://img.shields.io/badge/Licence-AGPL--3.0-green)](LICENSE)
[![Version](https://img.shields.io/badge/Version-3.2.2-blue)](CHANGELOG.md)

---

## Fonctionnalités

### Authentification (nouveau v2.0)
- **Backend d'authentification intégré** — les utilisateurs se connectent avec leurs identifiants Windows (sAMAccountName + mot de passe AD), sans app `user_ldap` externe
- **Provisionnement automatique** — le compte Nextcloud est créé à la première connexion, avec le nom complet récupéré depuis l'AD
- **Source unique de vérité** — désactiver un compte sur Synology = l'accès Nextcloud est immédiatement révoqué

### Gestion des groupes
- **Synchronisation automatique** des groupes AD → groupes Nextcloud à chaque connexion
- **Promotion admin automatique** via un groupe AD configurable (`ADMIN_NEXTCLOUD` par défaut)
- Support **Active Directory** (attribut `memberOf`) et **POSIX/OpenLDAP** (attribut `memberUid`)

### Stockage — trois modes
| Mode | Description |
|------|-------------|
| **Manuel** | Mapping explicite : groupe AD → partage SMB précis |
| **Auto par nom** | Sous-dossier = nom du groupe (ex : groupe `Compta` → `//NAS/Externe/Compta`) |
| **Auto par ACL** ★ | Lit les ACL Synology via l'API DSM → chaque utilisateur voit exactement les dossiers auxquels ses groupes AD donnent accès, identique à un lecteur réseau Windows |

### Arborescence identique Windows / Nextcloud
Avec le champ **Préfixe de montage** (ex : `NAS`), les dossiers apparaissent dans Nextcloud sous `/NAS/Compta/2026` — même chemin que le lecteur réseau sur le PC Windows.

---

## Comment ça marche

```
Utilisateur ouvre Nextcloud → saisit login Windows + mot de passe
         │
         ▼
  LdapUserBackend::checkPassword()
  Bind LDAP avec DN + mot de passe utilisateur
         │ Succès
         ▼
  Nextcloud crée le compte automatiquement (1ère connexion)
  Récupère le nom complet depuis l'AD
         │
         ▼
  PostLoginEvent → GroupSyncService
  ┌─────────────────────────────────────────────┐
  │ Groupe ADMIN_NEXTCLOUD ?  → Admin NC         │
  │ Mode ACL → lit les ACL sur l'API DSM        │
  │   Compta : [Responsable, Compta]            │
  │   RH     : [RH, DRH]                        │
  │                                             │
  │ Aurélie (Responsable) → /NAS/Compta         │
  │ Martin  (RH)          → /NAS/RH             │
  │ DGS (Compta + RH)     → /NAS/Compta + /NAS/RH│
  └─────────────────────────────────────────────┘
         │
         ▼
  Montages SMB créés via Files_External API
  Utilisateur voit ses dossiers — identique au lecteur réseau Windows
```

---

## Prérequis

| Composant | Version minimale |
|-----------|-----------------|
| Nextcloud | 25 |
| PHP | 8.1 |
| Extension PHP | `ldap`, `smbclient` |
| App NC | `files_external` (activée) |

> **Plus besoin de `user_ldap`** — l'authentification est intégrée depuis la v2.0.

---

## Installation rapide

```bash
# Cloner le dépôt
git clone https://github.com/wdebonne/nc-synology-ldap-stockage.git

# Copier l'app dans Nextcloud
sudo cp -r nc-synology-ldap-stockage/synoldap /var/www/nextcloud/apps/

# Activer l'app (files_external est une dépendance)
sudo -u www-data php /var/www/nextcloud/occ app:enable files_external
sudo -u www-data php /var/www/nextcloud/occ app:enable synoldap
```

Ou utiliser le script fourni :

```bash
sudo bash synoldap/install.sh /var/www/nextcloud
```

Voir le [guide d'installation complet](docs/INSTALLATION.md).

---

## Configuration en 3 étapes

### 1. LDAP / Active Directory
Renseigner l'hôte Synology, le compte de service (Bind DN), les Base DN.

### 2. Connexion Synology (SMB + API DSM)
- **SMB** : compte de service pour le montage des dossiers
- **API DSM** (port 5000) : compte admin pour la lecture des ACL

### 3. Correspondances groupes ↔ stockage
Créer une ligne **Auto ACL** avec le partage racine (ex : `Externe`) et le préfixe NC (ex : `NAS`).

Voir le [guide de configuration complet](docs/CONFIGURATION.md).

---

## Exemple concret

**Situation sur Synology :**
- Partage `Externe` avec sous-dossiers `Compta`, `RH`, `Direction`
- Groupe AD `Responsable` → droits R/W sur `Compta`
- Groupe AD `RH` → droits R/W sur `RH`

**Configuration synoldap :**
- Mode : Auto ACL
- Partage racine : `Externe`
- Préfixe NC : `NAS`

**Résultat dans Nextcloud :**

| Utilisateur | Groupes AD | Voit dans Nextcloud |
|-------------|------------|---------------------|
| Aurélie | `Responsable` | `/NAS/Compta/` |
| Martin | `RH` | `/NAS/RH/` |
| Sophie (DGS) | `Responsable` + `RH` | `/NAS/Compta/` et `/NAS/RH/` |

---

## Documentation

| Document | Description |
|----------|-------------|
| [Installation](docs/INSTALLATION.md) | Guide d'installation détaillé |
| [Configuration](docs/CONFIGURATION.md) | Configuration LDAP, SMB, API DSM, modes de montage |
| [API REST](docs/API.md) | Référence des endpoints d'administration |
| [Dépannage](docs/TROUBLESHOOTING.md) | Résolution des problèmes courants |
| [Architecture](docs/ARCHITECTURE.md) | Structure technique du projet |

---

## Licence

Ce projet est distribué sous licence [AGPL-3.0](LICENSE).

## Contribution

Les contributions sont les bienvenues ! Voir [CONTRIBUTING.md](CONTRIBUTING.md).

## Auteur

**wdebonne**
