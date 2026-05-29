# Synology LDAP Manager

Application Nextcloud custom pour intégrer l'Active Directory Synology sans dépendance externe.

## Fonctionnalités

- **Authentification AD** — connexion avec les identifiants Windows (`sAMAccountName` + mot de passe AD)
- **Provisionnement automatique** — le compte Nextcloud est créé à la première connexion
- **Synchronisation des groupes** — les groupes AD sont reflétés dans Nextcloud à chaque connexion
- **Promotion admin** — un groupe AD configurable donne les droits administrateur Nextcloud
- **Montage SMB en 3 modes** :
  - Manuel — groupe AD → dossier Nextcloud défini manuellement
  - Auto par nom — le nom du groupe AD correspond au nom du partage SMB
  - Auto par ACL Synology — lit les droits réels via l'API DSM, chaque utilisateur voit exactement ce qu'il voit sur son PC Windows

## Prérequis

- Nextcloud 25–33
- PHP 8.1+ avec extension `ldap`
- Synology NAS avec Directory Server (Active Directory) actif
- Compte de service AD avec accès LDAP en lecture
- LDAPS (port 636) recommandé — le Synology Directory Server exige une connexion chiffrée

## Installation

```bash
cp -r synoldap/ /var/www/html/custom_apps/
sudo -E -u www-data php occ app:enable synoldap
```

## Configuration

### 1. Connexion LDAP / Active Directory

| Champ | Valeur |
|-------|--------|
| Serveur LDAP | IP du NAS Synology |
| Port | `636` (LDAPS) |
| LDAPS | Activé |
| Compte de service (Bind DN) | `CN=nextcloud,CN=Users,DC=mondomaine,DC=int` |
| Base DN Utilisateurs | `CN=Users,DC=mondomaine,DC=int` |
| Base DN Groupes | `CN=Users,DC=mondomaine,DC=int` |
| Mode détection groupes | Active Directory (attribut memberOf) |
| Attribut UID | `sAMAccountName` |

> **Certificat auto-signé** : le Synology Directory Server utilise un certificat auto-signé. L'application gère automatiquement cette situation via `LDAP_OPT_X_TLS_REQUIRE_CERT`. Aucune configuration supplémentaire n'est requise.

### 2. API DSM Synology (mode ACL uniquement)

Nécessaire uniquement pour le mode **Auto ACL**. Renseigne l'URL du DSM et un compte administrateur.

### 3. Correspondances groupes / partages

Ajoute une ligne par partage SMB à monter. Choisis le mode :
- **Manuel** : groupe AD + chemin SMB définis explicitement
- **Auto** : le nom du groupe AD = nom du partage
- **Auto ACL** : l'app lit les ACL Synology et crée les montages automatiquement

## Déploiement sur Nextcloud AIO (Docker Unraid)

Après modification du code en local :

```bash
# Depuis Unraid terminal
docker cp "/chemin/local/synoldap" nextcloud-aio-nextcloud:/var/www/html/custom_apps/
```

Les fichiers dans `/var/www/html/custom_apps/` sont dans un **volume Docker nommé persistant** — ils survivent aux redémarrages du conteneur.

## Structure

```
synoldap/
├── appinfo/
│   ├── info.xml          # Métadonnées de l'app
│   └── routes.php        # Routes API
├── lib/
│   ├── Controller/       # AdminController (API REST admin)
│   ├── Listener/         # UserLoggedInListener (sync post-login)
│   ├── Service/
│   │   ├── LdapService.php          # Connexion LDAP / authentification / groupes
│   │   ├── GroupSyncService.php     # Synchronisation groupes AD → NC
│   │   ├── StorageConfigService.php # Création des montages SMB
│   │   └── SynologyApiService.php   # API DSM (ACL, sessions)
│   ├── Settings/         # Section admin Nextcloud
│   └── UserBackend/      # LdapUserBackend (ICheckPasswordBackend)
├── templates/
│   └── admin.php         # Interface d'administration
├── js/
│   └── admin.js          # Logique frontend admin
└── css/
    └── admin.css         # Styles interface admin
```

## Licence

AGPL-3.0
