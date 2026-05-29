# Structure Active Directory — Kiriyama

## Principe général

| Objet | Rôle | Utilisé pour |
|-------|------|-------------|
| **OU** (Unité d'organisation) | Conteneur hiérarchique | Organisation, délégation, GPO |
| **Groupe de sécurité** | Regroupement d'utilisateurs | Droits d'accès (partages, apps) |
| **Utilisateur** | Compte d'accès | Authentification |

> Les GPO s'appliquent aux **OU**, pas directement aux groupes.  
> Les droits Synology/Nextcloud s'appliquent via les **groupes**.

---

## Arborescence recommandée

```
OU=Kiriyama                          ← racine organisation (filtre SynoLDAP)
│
├── OU=Mairie                        ← bâtiment / entité
│   │
│   ├── OU=Compta                    ← service
│   │   ├── Groupe: Compta           ← accès partage /NAS/Compta
│   │   └── Groupe: Responsable_Compta  ← accès partage /NAS/Compta (droits +)
│   │
│   ├── OU=RH                        ← service
│   │   ├── Groupe: RH               ← accès partage /NAS/RH
│   │   └── Groupe: Responsable_RH   ← accès partage /NAS/RH (droits +)
│   │
│   └── OU=Direction
│       └── Groupe: Direction        ← accès partages transverses
│
└── OU=Batiment2                     ← autre site / entité (si besoin)
    └── OU=ServiceX
        └── Groupe: ServiceX
```

---

## Utilisateurs — où les placer ?

Les utilisateurs peuvent être placés directement dans l'OU de leur service.  
Leur appartenance aux **groupes** détermine leurs droits.

```
OU=Compta
├── Groupe: Compta
├── Groupe: Responsable_Compta
├── User: jean.dupont          → membre de Compta
└── User: marie.martin         → membre de Compta + Responsable_Compta
```

Un utilisateur peut être membre de **plusieurs groupes** issus de **plusieurs OU**.

---

## GPO — 2 méthodes

### Méthode A — GPO par OU (structurelle)

Créer une GPO liée à une OU : tous les objets de l'OU héritent de la GPO.

```
GPO "Fond d'écran Compta"  →  liée à OU=Compta
    s'applique à tous les users dans OU=Compta
```

**Quand l'utiliser** : GPO identique pour tout un service.

---

### Méthode B — Filtrage de sécurité (recommandé pour les responsables)

GPO liée à une OU parente, mais filtrée pour n'appliquer qu'à un groupe précis.

```
GPO "Config Responsable_Compta"  →  liée à OU=Compta
    Filtrage de sécurité : Groupe Responsable_Compta uniquement
    → s'applique uniquement aux membres du groupe, peu importe leur OU
```

**Avantages** :
- Pas besoin de créer une OU dédiée juste pour les responsables
- Si un responsable change de service, il suffit de changer son groupe
- Maintenabilité meilleure sur le long terme

**Quand créer une sous-OU pour les responsables** : seulement si tu veux cibler leurs **postes de travail** (GPO machine) plutôt que leurs comptes utilisateur.

---

## Groupes imbriqués (nesting)

AD autorise les groupes dans les groupes. Exemple :

```
Groupe: Compta
    └── Groupe: Responsable_Compta  (membres inclus automatiquement)
```

→ Un membre de `Responsable_Compta` hérite aussi des droits de `Compta`.  
→ Utile pour éviter de mettre les responsables dans deux groupes manuellement.

---

## Configuration SynoLDAP

### Base DN des groupes

Configurer `ldap_group_base_dn` à la racine de l'organisation :

```
OU=Kiriyama,DC=mairie,DC=local
```

La recherche LDAP est récursive (scope `subtree`) : tous les groupes dans `Kiriyama`
et ses sous-OUs sont trouvés automatiquement.

**Effet** : les groupes système AD (`Domain Admins`, `DnsAdmins`, etc.) qui sont dans
`CN=Users,DC=...` n'appartiennent pas à `OU=Kiriyama` → ils sont ignorés.

### Correspondance groupes ↔ partages Synology

| Groupe AD | Partage Synology | Mode SynoLDAP |
|-----------|-----------------|---------------|
| `Compta` | `/NAS/Compta` | Auto-nom ou ACL |
| `Responsable_Compta` | `/NAS/Compta` | ACL (mêmes dossiers, droits RW vs RO) |
| `RH` | `/NAS/RH` | Auto-nom ou ACL |
| `Responsable_RH` | `/NAS/RH` | ACL |
| `Direction` | `/NAS/Direction` | Manuel |

---

## Résumé des décisions clés

| Question | Réponse |
|----------|---------|
| OU dédiée par service ? | **Oui** — pour l'organisation et les GPO par service |
| OU dédiée par rôle (Responsable) ? | **Non** — utiliser le filtrage de sécurité sur les GPO |
| Groupes dans les groupes ? | **Oui** — `Responsable_Compta` imbriqué dans `Compta` |
| Racine de filtrage SynoLDAP ? | `OU=Kiriyama,DC=...,DC=...` |
