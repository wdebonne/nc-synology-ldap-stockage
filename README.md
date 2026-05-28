# nc-synology-ldap-stockage

**Application Nextcloud** pour l'intégration automatique de l'Active Directory Synology avec la gestion des groupes et du stockage externe SMB.

[![Nextcloud](https://img.shields.io/badge/Nextcloud-25--30-0082c9?logo=nextcloud)](https://nextcloud.com)
[![PHP](https://img.shields.io/badge/PHP-8.0%2B-777bb4?logo=php)](https://php.net)
[![Licence](https://img.shields.io/badge/Licence-AGPL--3.0-green)](LICENSE)
[![Version](https://img.shields.io/badge/Version-1.0.0-blue)](CHANGELOG.md)

---

## Fonctionnalités

- **Synchronisation automatique** des groupes Active Directory Synology → groupes Nextcloud à chaque connexion utilisateur
- **Promotion automatique** en administrateur Nextcloud via un groupe AD configurable (ex: `ADMIN_NEXTCLOUD`)
- **Montage SMB automatique** du stockage externe Synology par groupe (ex: groupe `Compta` → partage `\\Synology\Compta`)
- **Interface d'administration** visuelle, intuitive, entièrement gérable par les administrateurs
- **Synchronisation en masse** de tous les utilisateurs en un clic
- Compatible **Synology Directory Service** (AD interne basé sur Samba)

## Comment ça marche

```
Connexion utilisateur LDAP
         │
         ▼
  Requête memberOf AD
         │
    ┌────┴─────────────────────────────┐
    │ Groupe ADMIN_NEXTCLOUD ?         │──► Promu Admin Nextcloud
    │ Groupe Compta ?                  │──► Groupe NC Compta → Dossier SMB /Compta
    │ Groupe RH ?                      │──► Groupe NC RH → Dossier SMB /RH
    └──────────────────────────────────┘
```

L'utilisateur voit **uniquement** les dossiers correspondant à ses groupes AD, montés automatiquement via le stockage externe Nextcloud.

## Prérequis

| Composant | Version minimale |
|-----------|-----------------|
| Nextcloud | 25 |
| PHP | 8.0 |
| Extension PHP | `ldap`, `smbclient` |
| App NC | `user_ldap` (activée) |
| App NC | `files_external` (activée) |

## Installation rapide

```bash
# Cloner le dépôt
git clone https://github.com/wdebonne/nc-synology-ldap-stockage.git

# Copier l'app dans Nextcloud
sudo cp -r nc-synology-ldap-stockage/synoldap /var/www/nextcloud/apps/

# Activer l'app
sudo -u www-data php /var/www/nextcloud/occ app:enable synoldap
```

Ou utiliser le script fourni :

```bash
sudo bash synoldap/install.sh /var/www/nextcloud
```

Voir le [guide d'installation complet](docs/INSTALLATION.md).

## Configuration

Une fois installée, rendez-vous dans **Nextcloud → Paramètres → Administration → Synology LDAP**.

![Interface d'administration SynoLDAP](docs/img/screenshot-admin.png)

Voir le [guide de configuration](docs/CONFIGURATION.md).

## Documentation

| Document | Description |
|----------|-------------|
| [Installation](docs/INSTALLATION.md) | Guide d'installation détaillé |
| [Configuration](docs/CONFIGURATION.md) | Configuration LDAP, SMB et groupes |
| [API](docs/API.md) | Référence API REST admin |
| [Dépannage](docs/TROUBLESHOOTING.md) | Résolution des problèmes courants |
| [Architecture](docs/ARCHITECTURE.md) | Structure technique du projet |

## Exemple de configuration rapide

**Synology AD :**
- Hôte : `192.168.1.100`
- Port : `389`
- Bind DN : `CN=svc-nextcloud,CN=Users,DC=mondomaine,DC=local`
- Base DN users : `CN=Users,DC=mondomaine,DC=local`
- Mode : `Active Directory (memberOf)`

**Correspondance groupe → stockage :**

| Groupe AD | Groupe NC | Partage SMB | Résultat |
|-----------|-----------|-------------|---------|
| `Compta` | `Compta` | `Compta` | `/Compta` visible par les membres |
| `RH` | `RH` | `RH` | `/RH` visible par les membres |
| `ADMIN_NEXTCLOUD` | *(admin)* | *(aucun)* | Droits admin NC |

## Licence

Ce projet est distribué sous licence [AGPL-3.0](LICENSE).

## Contribution

Les contributions sont les bienvenues ! Voir [CONTRIBUTING.md](CONTRIBUTING.md).

## Auteur

**wdebonne** — [wdebonne@hotmail.com](mailto:wdebonne@hotmail.com)
