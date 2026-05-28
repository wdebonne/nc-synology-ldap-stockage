# Politique de sécurité

## Versions supportées

| Version | Supportée |
|---------|-----------|
| 1.0.x   | ✅ Oui    |

## Signaler une vulnérabilité

**Ne pas ouvrir d'issue publique pour une vulnérabilité de sécurité.**

Envoyez un email à : [wdebonne@hotmail.com](mailto:wdebonne@hotmail.com)

Incluez :
- La description de la vulnérabilité
- Les étapes pour la reproduire
- L'impact potentiel

Vous recevrez une réponse sous 48h et un correctif sera publié dès que possible.

## Mesures de sécurité en place

- **Injection LDAP** : toutes les entrées utilisateur passent par `ldap_escape()` avant d'être utilisées dans des filtres LDAP
- **Authentification** : toutes les routes API nécessitent le rôle administrateur Nextcloud (`@AdminRequired`)
- **CSRF** : protection via le token Nextcloud (`requesttoken`) sur toutes les requêtes POST
- **Mots de passe** : les mots de passe LDAP bind et SMB ne sont jamais retournés par l'API (masqués)
- **Dernier admin** : garde-fou empêchant la révocation automatique du dernier administrateur
- **Timeout LDAP** : connexion LDAP avec timeout de 10 secondes pour éviter les blocages

## Bonnes pratiques de déploiement

- Utilisez un **compte de service dédié** (read-only) pour le bind LDAP, jamais l'administrateur AD
- Activez **LDAPS** (port 636) si le serveur LDAP est sur un réseau non sécurisé
- Le compte SMB doit avoir uniquement les droits de **lecture** sur les partages, sauf si l'écriture est nécessaire
- Vérifiez régulièrement les logs Nextcloud pour détecter des anomalies de synchronisation
