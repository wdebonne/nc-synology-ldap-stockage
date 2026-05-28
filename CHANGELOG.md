# Changelog

Toutes les modifications notables de ce projet sont documentées dans ce fichier.

Le format suit [Keep a Changelog](https://keepachangelog.com/fr/1.0.0/),
et ce projet respecte le [Versionnage Sémantique](https://semver.org/lang/fr/).

---

## [1.0.0] — 2026-05-28

### Ajouté
- Synchronisation automatique des groupes Active Directory Synology → groupes Nextcloud à chaque connexion utilisateur
- Promotion/révocation automatique du statut administrateur Nextcloud via groupe AD configurable (`ADMIN_NEXTCLOUD` par défaut)
- Montage automatique du stockage externe SMB par groupe via l'API `Files_External` de Nextcloud
- Interface d'administration visuelle avec 4 sections : LDAP, Stockage SMB, Groupe Admin, Correspondances
- Support du mode **Active Directory** (attribut `memberOf` sur l'objet utilisateur)
- Support du mode **POSIX/OpenLDAP** (attribut `memberUid` sur les groupes `posixGroup`)
- Bouton "Tester la connexion LDAP" avec affichage des groupes détectés
- Synchronisation en masse de tous les utilisateurs en un clic
- Application automatique des montages SMB pour tous les groupes configurés
- Clic sur un groupe détecté pour l'ajouter directement comme correspondance
- Journal des opérations en temps réel dans l'interface admin
- Script d'installation `install.sh` pour déploiement rapide
- Compatible Nextcloud 25 à 30, PHP 8.0+

### Sécurité
- Garde-fou : le dernier administrateur Nextcloud ne peut pas être révoqué automatiquement
- Les mots de passe (LDAP bind, SMB) ne sont jamais retournés par l'API GET
- Toutes les routes admin requièrent les droits administrateur (`@AdminRequired`)
- Échappement LDAP via `ldap_escape()` pour prévenir l'injection LDAP

---

## [Non publié]

### Prévu
- Synchronisation planifiée (cron) sans attendre la connexion
- Support de la recherche de groupes imbriqués (nested groups AD)
- Import/export de la configuration en JSON
- Notifications par email lors des promotions admin
- Support WebDAV et NFS en plus de SMB
- Interface multilingue (en, de, es)
