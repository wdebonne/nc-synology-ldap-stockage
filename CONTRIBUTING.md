# Guide de contribution

Merci de votre intérêt pour ce projet ! Voici comment contribuer.

## Signaler un bug

1. Vérifiez que le bug n'est pas déjà signalé dans les [Issues](https://github.com/wdebonne/nc-synology-ldap-stockage/issues)
2. Ouvrez une nouvelle issue avec :
   - La version de Nextcloud
   - La version de PHP
   - Le message d'erreur complet (logs NC : `data/nextcloud.log`)
   - Les étapes pour reproduire

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
4. Testez sur une instance Nextcloud réelle
5. Ouvrez une **Pull Request** vers `main`

## Structure du projet

```
synoldap/                   ← Dossier de l'app Nextcloud (à copier dans apps/)
├── appinfo/                ← Métadonnées et routes
├── lib/
│   ├── AppInfo/            ← Bootstrap
│   ├── Controller/         ← API REST
│   ├── Listener/           ← Événements NC
│   ├── Service/            ← Logique métier
│   └── Settings/           ← Panneau admin NC
├── templates/              ← HTML admin
├── js/                     ← JavaScript frontend
├── css/                    ← Styles
└── img/                    ← Icônes
docs/                       ← Documentation
```

## Environnement de développement

```bash
# Nextcloud de test avec Docker
docker run -d -p 8080:80 nextcloud:27

# Activer les apps requises
occ app:enable user_ldap
occ app:enable files_external

# Copier l'app en dev (avec lien symbolique)
ln -s /chemin/vers/synoldap /var/www/html/apps/synoldap
occ app:enable synoldap

# Voir les logs en temps réel
tail -f data/nextcloud.log | grep SynoLDAP
```

## Licence

En soumettant du code, vous acceptez que votre contribution soit distribuée sous licence [AGPL-3.0](LICENSE).
