# Guide de contribution

Merci de votre intérêt pour ce projet !

## Signaler un bug

1. Vérifiez que le bug n'est pas déjà signalé dans les [Issues](https://github.com/wdebonne/nc-synology-ldap-stockage/issues)
2. Ouvrez une nouvelle issue avec :
   - La version de Nextcloud (`occ status`)
   - La version de PHP (`php -v`)
   - Le message d'erreur complet (logs NC : `data/nextcloud.log | grep SynoLDAP`)
   - Les étapes pour reproduire
   - La version DSM du Synology si le problème concerne les ACL

## Proposer une fonctionnalité

Ouvrez une issue avec le label `enhancement` en décrivant :
- Le cas d'usage
- Le comportement attendu
- Toute alternative envisagée

## Soumettre du code

1. **Fork** le dépôt
2. Créez une branche : `git checkout -b feature/ma-fonctionnalite`
3. Respectez les conventions :
   - PHP PSR-12
   - Namespaces `OCA\SynoLDAP\...`
   - Pas de dépendances supplémentaires (pas de composer requis)
   - Aucun commentaire redondant — le code doit être auto-documenté
4. Testez sur une instance Nextcloud réelle avec un Synology (ou un émulateur LDAP)
5. Ouvrez une **Pull Request** vers `main`

## Structure du projet

```
synoldap/                   ← Dossier de l'app Nextcloud (à copier dans apps/)
├── appinfo/
│   ├── info.xml            ← Métadonnées de l'app (version, dépendances)
│   └── routes.php          ← Déclaration des routes API REST
├── lib/
│   ├── AppInfo/
│   │   └── Application.php ← Bootstrap : enregistrement du backend, listener
│   ├── Controller/
│   │   └── AdminController.php  ← API REST admin (config, test, sync, ACL)
│   ├── Listener/
│   │   └── UserLoggedInListener.php  ← Hook PostLoginEvent
│   ├── Service/
│   │   ├── LdapService.php          ← Connexion LDAP, auth, groupes, énumération
│   │   ├── GroupSyncService.php     ← Sync groupes NC + montages à la connexion
│   │   ├── StorageConfigService.php ← Création/MAJ des montages Files_External
│   │   └── SynologyApiService.php   ← API REST DSM (ACL Synology, cache)
│   ├── UserBackend/
│   │   └── LdapUserBackend.php     ← Backend auth NC (remplace user_ldap)
│   └── Settings/
│       ├── AdminSection.php        ← Entrée dans le menu admin NC
│       └── AdminSettings.php       ← Chargement du template admin
├── templates/
│   └── admin.php           ← Interface d'administration HTML
├── js/
│   └── admin.js            ← Logique frontend (config, ACL preview, mappings)
├── css/
│   └── admin.css           ← Styles du panel admin
└── img/
    └── app.svg             ← Icône de l'app
docs/                       ← Documentation
├── INSTALLATION.md
├── CONFIGURATION.md
├── API.md
├── TROUBLESHOOTING.md
└── ARCHITECTURE.md
```

## Flux de données

```
login()
  └─► LdapUserBackend.checkPassword()
        └─► LdapService.authenticate()        # bind utilisateur
              └─► retourne uid ou false
  └─► NC provisionne l'utilisateur si nouveau
  └─► PostLoginEvent
        └─► GroupSyncService.syncUser()
              ├─► LdapService.getUserGroups()   # groupes AD
              ├─► Mappings manuels              # nc_group ← ldap_group
              └─► Entrées auto
                    ├─► mode 'name' : ensureGroupMount(group, share, group)
                    └─► mode 'acl'  : SynologyApiService.discoverAclMappings()
                                        └─► StorageConfigService.ensureGroupMount()
```

## Environnement de développement

```bash
# Nextcloud de test avec Docker
docker run -d -p 8080:80 nextcloud:27

# Activer les apps requises
occ app:enable files_external

# Copier l'app en dev (lien symbolique)
ln -s /chemin/vers/synoldap /var/www/html/apps/synoldap
occ app:enable synoldap

# Voir les logs en temps réel
tail -f data/nextcloud.log | grep SynoLDAP

# Tester l'authentification manuellement
occ user:info <username>
```

### Simuler un AD Synology en local

Pour développer sans Synology physique, un serveur Samba avec le schéma AD suffit :

```bash
# OpenLDAP avec schéma AD minimal (pour les tests de base)
docker run -d --name ldap \
  -e LDAP_DOMAIN=test.local \
  -e LDAP_ADMIN_PASSWORD=admin \
  -p 389:389 \
  osixia/openldap:latest
```

## Licence

En soumettant du code, vous acceptez que votre contribution soit distribuée sous licence [AGPL-3.0](LICENSE).
