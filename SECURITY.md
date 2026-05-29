# Politique de sécurité

## Versions supportées

| Version | Supportée |
|---------|-----------|
| 2.0.x   | ✅ Oui    |
| 1.0.x   | ⚠️ Correctifs critiques uniquement |

## Signaler une vulnérabilité

**Ne pas ouvrir d'issue publique pour une vulnérabilité de sécurité.**

Envoyez un email à : [wdebonne@gmail.com](mailto:wdebonne@gmail.com)

Incluez :
- La description de la vulnérabilité
- Les étapes pour la reproduire
- L'impact potentiel

Vous recevrez une réponse sous 48h (normalement) et un correctif sera publié dès que possible.

---

## Mesures de sécurité en place

### Authentification utilisateur
- **Mot de passe vide refusé** : `authenticate()` retourne immédiatement `null` si le mot de passe est vide, empêchant tout bind LDAP anonyme
- **Connexion LDAP dédiée** : le bind utilisateur utilise une connexion séparée — le compte de service n'est jamais exposé au code de validation des credentials
- **Backend en lecture seule** : `LdapUserBackend::deleteUser()` retourne toujours `false` — aucune suppression de compte possible depuis Nextcloud
- Désactiver un compte sur Synology révoque l'accès immédiatement (le bind LDAP échoue)

### Accès à l'API d'administration
- Toutes les routes admin requièrent le rôle administrateur Nextcloud (`#[AdminRequired]`)
- Protection CSRF via le token Nextcloud (`requesttoken`) sur toutes les requêtes POST
- Les mots de passe (LDAP bind, SMB, API DSM) ne sont **jamais** retournés par l'API GET config

### Injection LDAP
- Toutes les entrées utilisateur passent par `ldap_escape()` avant d'être utilisées dans des filtres LDAP
- Filtres LDAP construits avec des valeurs typées et escapées uniquement

### API DSM Synology
- Le mot de passe DSM est stocké chiffré dans la configuration Nextcloud
- Les sessions DSM sont fermées (`logout`) après chaque usage
- Timeout de 10 secondes sur toutes les requêtes HTTP vers l'API DSM
- L'option SSL désactive la vérification du certificat pour les certificats auto-signés — à utiliser uniquement sur un réseau interne de confiance

### Stockage SMB
- Le compte SMB de service ne doit avoir que les droits nécessaires (lecture minimum, écriture si besoin)
- Le mot de passe SMB n'est jamais retourné par l'API

### Autres protections
- **Dernier admin** : garde-fou empêchant la révocation automatique du dernier administrateur Nextcloud
- **Timeout LDAP** : connexion avec timeout 10s pour éviter les blocages en cas d'indisponibilité du serveur
- Comptes désactivés dans l'AD (bit `userAccountControl`) exclus de l'énumération

---

## Bonnes pratiques de déploiement

- Utilisez un **compte de service dédié** (droits lecture seule) pour le bind LDAP, jamais l'administrateur AD
- Activez **LDAPS** (port 636) si le serveur LDAP est sur un réseau non sécurisé
- Le **compte API DSM** devrait être un compte admin dédié (pas le compte `admin` principal)
- Le compte SMB doit avoir uniquement les droits de lecture sur les partages racine — les ACL par sous-dossier gèrent les accès utilisateurs
- Vérifiez régulièrement les logs Nextcloud (`data/nextcloud.log`) pour détecter des anomalies
- Videz le cache ACL (`POST /admin/clear-acl-cache`) après toute modification de droits sur Synology pour que les changements soient effectifs immédiatement
