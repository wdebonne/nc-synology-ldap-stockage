# Référence API REST

Toutes les routes sont préfixées par `/apps/synoldap`. Les routes `admin/*` requièrent le rôle administrateur Nextcloud et une protection CSRF (`requesttoken` dans l'en-tête ou le corps de la requête).

---

## Configuration

### GET /admin/config

Retourne la configuration actuelle. Les mots de passe ne sont jamais retournés.

**Réponse 200**
```json
{
  "ldap_host": "192.168.1.10",
  "ldap_port": "389",
  "ldap_bind_dn": "CN=svc_nc,CN=Users,DC=corp,DC=local",
  "ldap_bind_password": "",
  "ldap_base_dn": "CN=Users,DC=corp,DC=local",
  "ldap_base_dn_groups": "CN=Users,DC=corp,DC=local",
  "ldap_filter": "",
  "ldap_mode": "active_directory",
  "smb_user": "svc_smb",
  "smb_password": "",
  "synology_api_port": "5000",
  "synology_api_ssl": "0",
  "synology_api_user": "api_nc",
  "synology_api_password": "",
  "admin_group": "ADMIN_NEXTCLOUD",
  "mappings": [
    {
      "auto_mode": "acl",
      "storage_share": "Externe",
      "mount_prefix": "NAS"
    }
  ]
}
```

---

### POST /admin/config

Sauvegarde la configuration. Les champs mot de passe vides sont ignorés (le mot de passe existant est conservé).

**Corps (JSON)**
```json
{
  "ldap_host": "192.168.1.10",
  "ldap_port": "389",
  "ldap_bind_dn": "CN=svc_nc,CN=Users,DC=corp,DC=local",
  "ldap_bind_password": "secret",
  "ldap_base_dn": "CN=Users,DC=corp,DC=local",
  "ldap_base_dn_groups": "CN=Users,DC=corp,DC=local",
  "ldap_filter": "",
  "ldap_mode": "active_directory",
  "smb_user": "svc_smb",
  "smb_password": "secret",
  "synology_api_port": "5000",
  "synology_api_ssl": "0",
  "synology_api_user": "api_nc",
  "synology_api_password": "secret",
  "admin_group": "ADMIN_NEXTCLOUD",
  "mappings": []
}
```

**Réponse 200**
```json
{ "success": true }
```

---

## Tests de connexion

### POST /admin/test-ldap

Teste la connexion LDAP avec les paramètres actuels. Retourne les groupes détectés.

**Réponse 200 — succès**
```json
{
  "success": true,
  "message": "Connexion LDAP réussie",
  "groups": ["Compta", "RH", "Direction", "ADMIN_NEXTCLOUD"]
}
```

**Réponse 200 — échec**
```json
{
  "success": false,
  "message": "Impossible de se connecter à 192.168.1.10:389 — Connection refused"
}
```

---

### POST /admin/test-dsm-api

Teste la connexion à l'API REST DSM Synology.

**Réponse 200 — succès**
```json
{
  "success": true,
  "message": "API DSM accessible — DSM 7.2-64570"
}
```

**Réponse 200 — échec**
```json
{
  "success": false,
  "message": "Échec de l'authentification DSM : [SynoAPI] error_code=403"
}
```

---

## Synchronisation

### POST /admin/sync-all

Synchronise les groupes et montages de tous les utilisateurs Nextcloud existants.

**Réponse 200**
```json
{
  "success": true,
  "synced": 12,
  "errors": 0
}
```

---

### POST /admin/apply-mounts

Applique les montages SMB pour tous les groupes configurés (sans synchronisation LDAP).

**Réponse 200**
```json
{
  "success": true,
  "message": "Montages appliqués"
}
```

---

## ACL Synology

### GET /admin/discover-acl?share={shareName}

Interroge l'API DSM pour découvrir les ACL Windows du partage. Les résultats sont mis en cache 1 heure.

**Paramètre** : `share` — nom du partage SMB racine (ex : `Externe`)

**Réponse 200 — succès**
```json
{
  "success": true,
  "mappings": {
    "Compta": ["Responsable", "Compta", "DGS"],
    "RH": ["RH", "DGS"],
    "Direction": ["DGS", "Direction"]
  }
}
```

**Réponse 200 — échec**
```json
{
  "success": false,
  "message": "API DSM non configurée"
}
```

---

### POST /admin/clear-acl-cache

Vide le cache ACL pour forcer la relecture des droits au prochain appel.

**Réponse 200**
```json
{
  "success": true,
  "message": "Cache ACL vidé"
}
```

---

## Codes d'erreur HTTP

| Code | Signification |
|------|---------------|
| 200 | Succès (vérifier le champ `success` dans la réponse) |
| 401 | Non authentifié |
| 403 | Droits insuffisants (admin requis) ou token CSRF invalide |
| 404 | Route inconnue |
| 500 | Erreur interne (voir `nextcloud.log`) |
