# Guide d'installation

## Prérequis

| Composant | Version minimale |
|-----------|-----------------|
| Nextcloud | 25 |
| PHP | 8.0 avec extensions `ldap`, `smbclient` |
| App NC | `files_external` (activée) |
| Synology DSM | 6.2+ (pour l'API ACL) |

> **`user_ldap` n'est plus nécessaire** depuis la v2.0 — l'authentification est intégrée dans `synoldap`.

---

## Installation rapide (script)

```bash
git clone https://github.com/wdebonne/nc-synology-ldap-stockage.git
sudo bash nc-synology-ldap-stockage/synoldap/install.sh /var/www/nextcloud
```

Le script active `files_external`, copie l'app, et active `synoldap`.

---

## Installation manuelle

### 1. Copier l'app

```bash
sudo cp -r nc-synology-ldap-stockage/synoldap /var/www/nextcloud/apps/
sudo chown -R www-data:www-data /var/www/nextcloud/apps/synoldap
```

### 2. Activer les dépendances

```bash
sudo -u www-data php /var/www/nextcloud/occ app:enable files_external
```

### 3. Activer l'app

```bash
sudo -u www-data php /var/www/nextcloud/occ app:enable synoldap
```

### 4. Vérifier

```bash
sudo -u www-data php /var/www/nextcloud/occ app:list | grep synoldap
# Doit afficher : synoldap: 2.0.0
```

---

## Installation en développement (lien symbolique)

```bash
ln -s /chemin/vers/repo/synoldap /var/www/nextcloud/apps/synoldap
sudo -u www-data php /var/www/nextcloud/occ app:enable synoldap
tail -f /var/www/nextcloud/data/nextcloud.log | grep SynoLDAP
```

---

## Mise à jour depuis la v1.0

1. Désactiver l'app `user_ldap` si elle était utilisée uniquement pour `synoldap` :
   ```bash
   sudo -u www-data php /var/www/nextcloud/occ app:disable user_ldap
   ```
2. Remplacer le dossier `synoldap/` par la nouvelle version.
3. Réactiver l'app :
   ```bash
   sudo -u www-data php /var/www/nextcloud/occ app:disable synoldap
   sudo -u www-data php /var/www/nextcloud/occ app:enable synoldap
   ```
4. Vider le cache Nextcloud :
   ```bash
   sudo -u www-data php /var/www/nextcloud/occ maintenance:repair
   ```

La configuration existante (LDAP, SMB, correspondances) est conservée. Les correspondances `auto_mode = true` (v1.0) sont automatiquement traitées comme `auto_mode = 'name'` (rétrocompatibilité).

---

## Vérification post-installation

```bash
# Tester l'authentification d'un utilisateur AD
sudo -u www-data php /var/www/nextcloud/occ user:info <sAMAccountName>

# Voir les logs en temps réel
tail -f /var/www/nextcloud/data/nextcloud.log | grep SynoLDAP
```

Accéder à **Administration → SynoLDAP** dans l'interface Nextcloud pour finaliser la configuration.

Voir le [guide de configuration](CONFIGURATION.md).
