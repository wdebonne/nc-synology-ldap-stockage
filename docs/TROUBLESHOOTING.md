# Dépannage

## Commandes utiles

```bash
# Logs SynoLDAP en temps réel
tail -f /var/www/nextcloud/data/nextcloud.log | grep SynoLDAP

# Vérifier le statut d'un utilisateur
sudo -u www-data php /var/www/nextcloud/occ user:info <uid>

# Lister les montages externes
sudo -u www-data php /var/www/nextcloud/occ files_external:list

# Forcer une resynchronisation
sudo -u www-data php /var/www/nextcloud/occ user:resetpassword --no-interaction <uid>
```

---

## Problèmes d'authentification

### L'utilisateur ne peut pas se connecter

1. **Vérifier que l'app est active** : `occ app:list | grep synoldap`
2. **Tester la connexion LDAP** dans Administration → SynoLDAP → bouton "Tester la connexion LDAP"
3. **Formats de login acceptés** (depuis la v2.0.2) :
   - `jdupont` — sAMAccountName (recommandé)
   - `CORP\jdupont` — préfixe domaine Windows (retiré automatiquement)
   - `jean.dupont@corp.local` — format UPN (recherche aussi dans `userPrincipalName`)
4. **Vérifier le compte** : le compte doit être actif sur le Synology (non désactivé dans l'AD)
5. **Activer les logs debug** et vérifier :
   ```bash
   tail -f data/nextcloud.log | grep -E "SynoLDAP|checkPassword|authenticate"
   ```
   Les messages `[SynoLDAP] Utilisateur introuvable` vs `Mot de passe incorrect` permettent de distinguer les deux causes d'échec.

### Lire les logs pour comprendre l'échec exact

Depuis la v2.0.2, l'erreur LDAP réelle est journalisée. Après une tentative de connexion :

```bash
tail -50 /var/www/nextcloud/data/nextcloud.log | grep SynoLDAP
```

Messages courants et leur signification :

| Message | Cause | Solution |
|---------|-------|----------|
| `Utilisateur introuvable dans l'AD` | sAMAccountName absent du Base DN configuré | Élargir le Base DN (ex : `DC=pavilly,DC=int` au lieu de `CN=Users,DC=...`) |
| `Bind échoué … Invalid credentials` | Mot de passe incorrect dans l'AD | Vérifier le mot de passe côté AD |
| `Bind échoué … Constraint violation` | Politique de compte AD (compte verrouillé, expiration) | Vérifier le compte dans la console AD |
| `Bind échoué … Can't contact LDAP server` | Serveur LDAP injoignable lors du bind utilisateur | Vérifier le réseau / firewall |

> **Cas fréquent** : les utilisateurs sont dans une OU personnalisée (`OU=Mairie,DC=...`) mais le Base DN pointe vers `CN=Users,DC=...`. Le compte de service trouve les utilisateurs (qui apparaissent dans la liste) mais le bind utilisateur échoue car `getUserInfo()` ne les retrouve pas avec un Base DN trop restrictif. Mettre le Base DN à la racine du domaine (`DC=pavilly,DC=int`) résout le problème.

### "Cannot bind to LDAP server"

- Vérifier que le Synology est joignable sur le port configuré (`389` ou `636`)
- Vérifier que le Bind DN et le mot de passe sont corrects
- Pour LDAPS, vérifier que le certificat est valide ou utiliser LDAP non chiffré sur un réseau interne sécurisé

### L'utilisateur existe dans l'AD mais pas dans Nextcloud

Le compte Nextcloud est créé automatiquement à la **première connexion**. Si l'utilisateur n'a jamais tenté de se connecter, il n'apparaît pas dans la liste NC — c'est normal.

### Mot de passe vide refusé

C'est intentionnel — une protection contre le bind LDAP anonyme. Vérifier que l'utilisateur saisit bien son mot de passe.

---

## Problèmes de synchronisation des groupes

### Les groupes AD ne sont pas synchronisés

1. La synchronisation a lieu **à chaque connexion** — demander à l'utilisateur de se déconnecter puis reconnecter
2. Vérifier que le Base DN groupes est correct
3. Vérifier le mode LDAP : `active_directory` (memberOf) ou `posix` (memberUid/posixGroup)
4. Pour le mode Active Directory, vérifier que `memberOf` est renseigné sur les objets utilisateurs du Synology

### Le groupe admin ne fonctionne pas

- Vérifier le nom exact du groupe dans le champ "Groupe admin" (sensible à la casse)
- Le dernier administrateur NC ne peut pas être révoqué automatiquement (garde-fou)

---

## Problèmes de montages SMB

### Les dossiers n'apparaissent pas dans Nextcloud

1. Vérifier que `files_external` est activée : `occ app:list | grep files_external`
2. Vérifier les credentials SMB dans la configuration
3. Vérifier que l'utilisateur est bien membre des groupes attendus (voir la synchronisation des groupes)
4. Pour le mode ACL : vérifier que l'API DSM est configurée et testée avec succès

### Erreur de montage SMB

```bash
# Tester la connexion SMB manuellement
smbclient //192.168.1.10/Externe -U svc_smb
```

Vérifier que :
- Le partage existe sur le Synology
- Le compte SMB a accès au partage
- Le firewall autorise le port 445 (SMB) depuis le serveur Nextcloud

### Le mode ACL ne crée pas les bons montages

1. Cliquer **Prévisualiser les ACL** dans l'interface admin pour voir ce que l'API DSM retourne
2. Vérifier que les groupes AD visibles dans la prévisualisation correspondent aux groupes de l'utilisateur
3. Vider le cache ACL si les droits ont été modifiés récemment sur le Synology : **Vider le cache ACL** ou `POST /admin/clear-acl-cache`
4. Vérifier les logs pour les erreurs d'appel API DSM

---

## Problèmes avec l'API DSM

### "API DSM non configurée"

Renseigner le port, l'utilisateur et le mot de passe DSM dans la section "Connexion Synology".

### "Échec de l'authentification DSM : error_code=403"

- Vérifier les credentials DSM (utilisateur + mot de passe)
- Vérifier que le compte est dans le groupe `administrators` du Synology
- Vérifier que l'API est accessible : `curl http://192.168.1.10:5000/webapi/query.cgi?api=SYNO.API.Info&version=1&method=query`

### "Timeout" ou connexion refusée

- Vérifier que le port DSM est correct (5000 HTTP, 5001 HTTPS)
- Vérifier que le firewall autorise la connexion depuis le serveur Nextcloud vers le Synology sur ce port
- Si HTTPS est activé avec un certificat auto-signé : c'est supporté (la vérification du certificat est désactivée pour l'API DSM interne)

---

## Problèmes de montages NFS / local

### « … est introuvable depuis le conteneur Nextcloud »

Le chemin n'existe pas **dans le conteneur** — même s'il existe sur l'hôte.

```bash
# 1. Le montage NFS est-il actif sur l'hôte ?
mount | grep nfs
ls /mnt/nas/User

# 2. Est-il visible dans le conteneur ?
docker exec -u www-data nextcloud-aio-nextcloud ls -l /mnt/nas/User
```

- **Nextcloud AIO** : la variable `NEXTCLOUD_MOUNT=/mnt/` doit être posée sur le
  *mastercontainer*, qui doit ensuite être recréé (et le conteneur applicatif redémarré).
- **Image officielle** : ajouter `- /mnt/nas:/mnt/nas` dans les `volumes` du conteneur.
- Un montage NFS fait **après** le démarrage du conteneur n'est pas propagé : remonter le
  partage puis redémarrer le conteneur (ou monter avec la propagation `rshared`).

### « … existe mais n'est pas lisible par l'utilisateur du conteneur »

Nextcloud accède aux fichiers avec l'uid **33** (`www-data`).

- Vérifier le squash NFS côté DSM (*Dossier partagé → Modifier → Autorisations NFS*) :
  passer sur `Mapper tous les utilisateurs sur admin` si les ACL Windows bloquent l'uid 33.
- Vérifier le privilège NFS (Lecture seule / Lecture-écriture).
- Contrôler côté hôte : `ls -ln /mnt/nas/User` puis `sudo -u \#33 ls /mnt/nas/User`.

### « Backend « Local » indisponible »

Le backend local de `files_external` est désactivé :

```bash
sudo -u www-data php /var/www/nextcloud/occ config:system:get files_external_allow_local_storage_mount
# s'il retourne false :
sudo -u www-data php /var/www/nextcloud/occ config:system:delete files_external_allow_local_storage_mount
```

### Les fichiers modifiés depuis Windows n'apparaissent pas

Les montages créés par l'app posent `filesystem_check_changes = 1` (relecture à chaque accès
direct). Si un dossier reste périmé :

```bash
sudo -u www-data php /var/www/nextcloud/occ files:scan --path="/<user>/files/User/Compta"
```

---

## Problèmes de performances

### Connexion lente

- Le timeout LDAP est de 10s par défaut
- Le timeout API DSM est de 10s par défaut
- Si le Synology est lent à répondre, vérifier la connectivité réseau

### Cache ACL

Les données ACL sont mises en cache 1 heure (APCu ou Redis selon la configuration NC). Si les droits changent fréquemment, vider le cache manuellement après chaque modification.

---

## Collecte d'informations pour signaler un bug

```bash
# Version Nextcloud
sudo -u www-data php /var/www/nextcloud/occ status

# Version PHP
php -v

# Logs SynoLDAP (dernières 100 lignes)
tail -100 /var/www/nextcloud/data/nextcloud.log | grep SynoLDAP

# Montages externes configurés
sudo -u www-data php /var/www/nextcloud/occ files_external:list --all
```

Inclure ces informations lors de l'ouverture d'une [issue GitHub](https://github.com/wdebonne/nc-synology-ldap-stockage/issues).
