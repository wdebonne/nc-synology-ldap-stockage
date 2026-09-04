# Guide d'installation

## Prérequis

| Composant | Version minimale |
|-----------|-----------------|
| Nextcloud | 25 (testé jusqu'à 34 — Hub 26) |
| PHP | 8.1 avec les extensions `ldap` et `curl` |
| Extension PHP | `smbclient` — uniquement pour le transport SMB |
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
# Doit afficher : synoldap: 2.0.10
```

---

## Installation en conteneur (Docker / Portainer / AIO)

### 1. Copier l'app dans le conteneur

Le nom du conteneur applicatif est `nextcloud-aio-nextcloud` avec Nextcloud AIO,
`nextcloud` ou `nextcloud-app` avec une image officielle.

```bash
docker cp ./synoldap nextcloud-aio-nextcloud:/var/www/html/custom_apps/synoldap
docker exec -u root nextcloud-aio-nextcloud chown -R www-data:www-data /var/www/html/custom_apps/synoldap
docker exec -u www-data nextcloud-aio-nextcloud php occ app:enable files_external
docker exec -u www-data nextcloud-aio-nextcloud php occ app:enable synoldap
```

> Avec AIO, `custom_apps` est un volume persistant : l'app survit aux mises à jour du conteneur.
> Vérifier après chaque montée de version de Nextcloud que `max-version` (dans
> `synoldap/appinfo/info.xml`) couvre la nouvelle version — l'app déclare 25 → 34.

---

## Monter le NAS en NFSv4 (transport « local »)

Ce mode évite complètement SMB : le partage Synology est monté **sur l'hôte Docker**,
puis exposé au conteneur Nextcloud. C'est le plus rapide et le plus simple à maintenir.

### 1. Côté Synology (DSM)

1. **Panneau de configuration → Services de fichiers → NFS** : activer NFS, cocher **NFSv4.1**.
2. **Dossier partagé → `User` → Modifier → Autorisations NFS → Créer** :
   - Nom d'hôte ou IP : l'adresse de l'hôte Docker (ex. `192.168.1.20`)
   - Privilège : **Lecture/écriture**
   - Squash : **Aucun mappage** (ou `Mapper tous les utilisateurs sur admin` si l'accès est refusé)
   - Cocher **Autoriser les connexions à partir de ports non privilégiés** et
     **Autoriser l'accès des utilisateurs aux sous-dossiers montés**
3. Noter le **chemin de montage** affiché en bas de la fenêtre (ex. `/volume1/User`).

### 2. Côté hôte Docker

```bash
sudo apt install nfs-common
sudo mkdir -p /mnt/nas/User

# Test manuel
sudo mount -t nfs4 192.168.1.254:/volume1/User /mnt/nas/User
ls /mnt/nas/User
```

Rendre le montage permanent dans `/etc/fstab` :

```fstab
192.168.1.254:/volume1/User  /mnt/nas/User  nfs4  vers=4.1,rw,hard,timeo=600,retrans=2,noatime,_netdev  0  0
```

```bash
sudo mount -a
```

### 3. Exposer le montage au conteneur

**Nextcloud AIO** — ajouter la variable `NEXTCLOUD_MOUNT` au *mastercontainer* puis le recréer :

```yaml
# docker-compose du mastercontainer (Portainer → Stack)
environment:
  - NEXTCLOUD_MOUNT=/mnt/
```

AIO monte alors `/mnt/` de l'hôte dans le conteneur applicatif au même chemin :
le partage est visible dans Nextcloud sous `/mnt/nas/User`.

**Image Nextcloud officielle** — ajouter un volume au conteneur applicatif :

```yaml
volumes:
  - /mnt/nas:/mnt/nas
```

### 4. Vérifier depuis le conteneur

```bash
docker exec -u www-data nextcloud-aio-nextcloud ls -l /mnt/nas/User
```

Puis, dans **Administration → Synology LDAP → section 2**, choisir le transport
`NFS / local`, saisir la racine locale (`/mnt/nas`) et cliquer **« Vérifier le chemin
local »** : l'app contrôle l'existence, les droits de lecture/écriture pour `www-data`
(uid 33) et liste les dossiers trouvés.

### 5. Droits d'accès

Le conteneur Nextcloud écrit avec l'uid **33** (`www-data`). Trois possibilités :

| Situation | Solution |
|-----------|----------|
| Squash « Aucun mappage » et dossiers appartenant à un utilisateur DSM | Donner les droits R/W au groupe `users` sur le partage, ou ajouter une ACL pour l'utilisateur de service |
| Accès refusé malgré les ACL | Passer le squash NFS sur `Mapper tous les utilisateurs sur admin` |
| Lecture seule voulue | Laisser le privilège NFS en **Lecture seule** |

> Les droits **fins** (qui voit quoi) restent pilotés par les ACL Synology lues via l'API DSM :
> l'app crée un montage par groupe AD. Le montage NFS, lui, doit simplement être accessible
> au conteneur.

**Passer un montage SMB existant en NFS** : renseigner la racine locale, basculer le
*Transport par défaut* (ou la colonne **Type** de la ligne) sur `NFS`, puis cliquer
**« Appliquer les montages stockage »** — les montages existants sont convertis sur place,
sans changement de point de montage ni perte de partages Nextcloud.

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
