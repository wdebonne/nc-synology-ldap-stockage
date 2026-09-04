<?php
/** @var \OCP\IL10N $l */
/** @var array $_ */
script('synoldap', 'admin');
style('synoldap', 'admin');
?>

<div id="synoldap-admin">
    <div class="synoldap-header">
        <img src="<?= \OC::$server->getURLGenerator()->imagePath('synoldap', 'app.svg') ?>" alt="" class="synoldap-logo" />
        <div>
            <h2>Synology LDAP Manager</h2>
            <p>Gestion automatique des groupes Active Directory Synology et du stockage externe</p>
        </div>
    </div>

    <div id="synoldap-status-bar" class="synoldap-status" style="display:none"></div>

    <!-- Section 1 : LDAP -->
    <div class="synoldap-card" id="card-ldap">
        <div class="synoldap-card-header" data-toggle="ldap-section">
            <span class="synoldap-card-icon">🔌</span>
            <h3>1. Connexion LDAP / Active Directory</h3>
            <label class="synoldap-section-toggle" title="Activer / Désactiver cette section" onclick="event.stopPropagation()">
                <input type="checkbox" class="section-enable-cb" data-section="ldap" checked />
                <span class="synoldap-toggle-track"><span class="synoldap-toggle-thumb"></span></span>
            </label>
            <span class="synoldap-toggle-icon">▼</span>
        </div>
        <div class="synoldap-card-body" id="ldap-section">
            <div class="synoldap-form-grid">
                <div class="synoldap-field">
                    <label for="ldap_host">Serveur LDAP (IP ou nom d'hôte)</label>
                    <input type="text" id="ldap_host" name="ldap_host" placeholder="192.168.1.100" />
                </div>
                <div class="synoldap-field synoldap-field-small">
                    <label for="ldap_port">Port</label>
                    <input type="number" id="ldap_port" name="ldap_port" value="389" min="1" max="65535" />
                </div>
                <div class="synoldap-field synoldap-field-checkbox">
                    <label>
                        <input type="checkbox" id="ldap_tls" name="ldap_tls" value="1" />
                        Utiliser LDAPS (SSL/TLS)
                    </label>
                </div>
            </div>
            <div class="synoldap-form-grid">
                <div class="synoldap-field synoldap-field-wide">
                    <label for="ldap_bind_dn">Compte de service (Bind DN)</label>
                    <input type="text" id="ldap_bind_dn" name="ldap_bind_dn"
                           placeholder="CN=nextcloud,CN=Users,DC=domain,DC=local" />
                    <span class="synoldap-hint">Ex : CN=svc-nextcloud,CN=Users,DC=mondomaine,DC=local</span>
                </div>
                <div class="synoldap-field">
                    <label for="ldap_bind_password">Mot de passe du compte</label>
                    <input type="password" id="ldap_bind_password" name="ldap_bind_password"
                           placeholder="(inchangé si vide)" autocomplete="new-password" />
                </div>
            </div>
            <div class="synoldap-actions">
                <button id="btn-detect-ad" class="synoldap-btn synoldap-btn-secondary">
                    🧭 Détecter le domaine automatiquement
                </button>
                <span id="detect-ad-result" class="synoldap-inline-result"></span>
            </div>
            <p class="synoldap-hint">
                Lit le RootDSE du contrôleur de domaine et remplit les base DN ci-dessous
                (ex : <code>DC=pavilly,DC=int</code>). Renseignez d'abord l'hôte et, si possible,
                le compte de service.
            </p>

            <hr class="synoldap-separator" />
            <div class="synoldap-form-grid">
                <div class="synoldap-field synoldap-field-wide">
                    <label for="ldap_user_base_dn">Base DN — Utilisateurs</label>
                    <input type="text" id="ldap_user_base_dn" name="ldap_user_base_dn"
                           placeholder="CN=Users,DC=domain,DC=local" />
                </div>
                <div class="synoldap-field synoldap-field-wide">
                    <label for="ldap_group_base_dn">Base DN — Groupes</label>
                    <input type="text" id="ldap_group_base_dn" name="ldap_group_base_dn"
                           placeholder="CN=Users,DC=domain,DC=local" />
                </div>
            </div>
            <div class="synoldap-form-grid">
                <div class="synoldap-field">
                    <label for="ldap_membership_mode">Mode de détection des groupes</label>
                    <select id="ldap_membership_mode" name="ldap_membership_mode">
                        <option value="memberof">Active Directory (attribut memberOf) — recommandé Synology AD</option>
                        <option value="posix">POSIX / OpenLDAP (attribut memberUid)</option>
                    </select>
                </div>
                <div class="synoldap-field">
                    <label for="ldap_user_attr">Attribut UID utilisateur</label>
                    <input type="text" id="ldap_user_attr" name="ldap_user_attr"
                           placeholder="sAMAccountName" />
                    <span class="synoldap-hint">AD : sAMAccountName | LDAP : uid</span>
                </div>
            </div>

            <div class="synoldap-actions">
                <button id="btn-test-ldap" class="synoldap-btn synoldap-btn-secondary">
                    🔍 Tester la connexion LDAP
                </button>
                <span id="ldap-test-result" class="synoldap-inline-result"></span>
            </div>

            <div id="ldap-groups-preview" class="synoldap-groups-preview" style="display:none">
                <strong>Groupes détectés :</strong>
                <div id="ldap-groups-list" class="synoldap-tag-list"></div>
            </div>
        </div>
    </div>

    <!-- Section 2 : Stockage Synology (SMB + API DSM) -->
    <div class="synoldap-card" id="card-storage">
        <div class="synoldap-card-header" data-toggle="storage-section">
            <span class="synoldap-card-icon">🗄️</span>
            <h3>2. Connexion Synology (SMB + API DSM)</h3>
            <label class="synoldap-section-toggle" title="Activer / Désactiver cette section" onclick="event.stopPropagation()">
                <input type="checkbox" class="section-enable-cb" data-section="storage" checked />
                <span class="synoldap-toggle-track"><span class="synoldap-toggle-thumb"></span></span>
            </label>
            <span class="synoldap-toggle-icon">▼</span>
        </div>
        <div class="synoldap-card-body" id="storage-section">

            <div class="synoldap-form-grid">
                <div class="synoldap-field">
                    <label for="synology_host">Hôte du Synology (IP ou nom)</label>
                    <input type="text" id="synology_host" name="synology_host"
                           placeholder="192.168.1.50" />
                </div>
                <div class="synoldap-field">
                    <label for="storage_backend">Transport par défaut</label>
                    <select id="storage_backend" name="storage_backend">
                        <option value="smb">SMB — compte de service (droits pilotés par Nextcloud)</option>
                        <option value="smb_user">SMB — identifiants de l'utilisateur (ACL du NAS) ★</option>
                        <option value="local">NFS / local — partage déjà monté dans le conteneur</option>
                    </select>
                    <span class="synoldap-hint">Modifiable ligne par ligne (colonne Type)</span>
                </div>
                <div class="synoldap-field synoldap-field-checkbox">
                    <label>
                        <input type="checkbox" id="mount_enable_sharing" name="mount_enable_sharing" value="1" />
                        Autoriser le partage Nextcloud des fichiers montés
                    </label>
                    <span class="synoldap-hint">Nécessaire pour partager un document du NAS entre services</span>
                </div>
            </div>

            <!-- Transport local (NFS) -->
            <div id="local-block">
                <p class="synoldap-section-label">Montage NFS (transport « local »)</p>
                <div class="synoldap-form-grid">
                    <div class="synoldap-field synoldap-field-wide">
                        <label for="local_root">Racine locale dans le conteneur Nextcloud</label>
                        <input type="text" id="local_root" name="local_root" placeholder="/mnt/nas" />
                        <span class="synoldap-hint">
                            Chemin où le NAS est monté en NFSv4 côté hôte puis exposé au conteneur.
                            Le partage <code>User</code> sera lu dans <code>/mnt/nas/User</code>.
                            Sur Nextcloud AIO : variable <code>NEXTCLOUD_MOUNT=/mnt/</code> sur le mastercontainer.
                        </span>
                    </div>
                </div>
                <div class="synoldap-actions">
                    <button id="btn-test-local" class="synoldap-btn synoldap-btn-secondary">
                        📂 Vérifier le chemin local
                    </button>
                    <span id="local-test-result" class="synoldap-inline-result"></span>
                </div>
                <div id="local-folders" class="synoldap-groups-preview" style="display:none">
                    <strong>Dossiers visibles :</strong>
                    <div id="local-folders-list" class="synoldap-tag-list"></div>
                </div>
                <hr class="synoldap-separator" />
            </div>

            <p class="synoldap-section-label">Accès SMB (montage des dossiers)</p>
            <div class="synoldap-form-grid">
                <div class="synoldap-field">
                    <label for="synology_smb_domain">Domaine / Workgroup</label>
                    <input type="text" id="synology_smb_domain" name="synology_smb_domain"
                           placeholder="WORKGROUP" />
                </div>
                <div class="synoldap-field">
                    <label for="smb_user_auth">Identifiants utilisateur — conservation</label>
                    <select id="smb_user_auth" name="smb_user_auth">
                        <option value="session">En session — aucun mot de passe stocké</option>
                        <option value="stored">En base (chiffré) — compatible mobile et tâches de fond</option>
                    </select>
                    <span class="synoldap-hint">
                        Utilisé par le transport « SMB — identifiants de l'utilisateur » :
                        chaque utilisateur accède au NAS avec son compte AD, le Synology applique
                        ses ACL Windows à tous les niveaux. Pensez à activer côté DSM
                        « Cacher les sous-dossiers et fichiers des utilisateurs sans autorisations ».
                    </span>
                </div>
            </div>
            <div class="synoldap-form-grid">
                <div class="synoldap-field">
                    <label for="synology_smb_user">Utilisateur SMB</label>
                    <input type="text" id="synology_smb_user" name="synology_smb_user"
                           placeholder="nextcloud-service" />
                    <span class="synoldap-hint">Compte service avec accès aux partages SMB</span>
                </div>
                <div class="synoldap-field">
                    <label for="synology_smb_password">Mot de passe SMB</label>
                    <input type="password" id="synology_smb_password" name="synology_smb_password"
                           placeholder="(inchangé si vide)" autocomplete="new-password" />
                </div>
            </div>

            <div class="synoldap-actions">
                <input type="text" id="smb-test-share"
                       placeholder="Partage ou chemin (ex. NextCloud/Compta)"
                       style="max-width:240px" />
                <button id="btn-test-smb" class="synoldap-btn synoldap-btn-secondary">
                    🖥️ Tester la connexion SMB
                </button>
                <span id="smb-test-result" class="synoldap-inline-result"></span>
            </div>
            <p class="synoldap-hint" style="margin-top:4px">
                Laissez le champ vide pour vérifier les partages déjà déclarés dans les
                correspondances de groupes ci-dessous, ou saisissez un partage
                (« NextCloud ») voire un sous-dossier (« NextCloud/Compta ») pour tester
                directement son accès. ⚠️ « Compta » seul n'est pas un partage mais un
                sous-dossier de « NextCloud ».
            </p>

            <hr class="synoldap-separator" />
            <p class="synoldap-section-label">
                API DSM
                <span class="synoldap-hint" style="font-weight:normal">
                    — nécessaire pour lire les ACL Synology (mode automatique par ACL)
                </span>
            </p>
            <div class="synoldap-form-grid">
                <div class="synoldap-field synoldap-field-small">
                    <label for="synology_api_port">Port DSM</label>
                    <input type="number" id="synology_api_port" name="synology_api_port"
                           value="5000" min="1" max="65535" />
                    <span class="synoldap-hint">HTTP : 5000 / HTTPS : 5001</span>
                </div>
                <div class="synoldap-field synoldap-field-checkbox">
                    <label>
                        <input type="checkbox" id="synology_api_ssl" name="synology_api_ssl" value="1" />
                        HTTPS (certificat auto-signé accepté)
                    </label>
                </div>
            </div>
            <div class="synoldap-form-grid">
                <div class="synoldap-field">
                    <label for="synology_api_user">Utilisateur DSM (admin)</label>
                    <input type="text" id="synology_api_user" name="synology_api_user"
                           placeholder="admin" />
                    <span class="synoldap-hint">Compte avec droits admin pour lire les ACL via l'API DSM</span>
                </div>
                <div class="synoldap-field">
                    <label for="synology_api_password">Mot de passe DSM</label>
                    <input type="password" id="synology_api_password" name="synology_api_password"
                           placeholder="(inchangé si vide)" autocomplete="new-password" />
                </div>
            </div>

            <div class="synoldap-actions">
                <button id="btn-test-dsm-api" class="synoldap-btn synoldap-btn-secondary">
                    🔑 Tester l'API DSM
                </button>
                <button id="btn-list-shares" class="synoldap-btn synoldap-btn-secondary">
                    📚 Lister les partages du NAS
                </button>
                <span id="dsm-api-test-result" class="synoldap-inline-result"></span>
            </div>
            <div id="shares-preview" class="synoldap-groups-preview" style="display:none">
                <strong>Partages disponibles :</strong>
                <div id="shares-list" class="synoldap-tag-list"></div>
            </div>
        </div>
    </div>

    <!-- Section 3 : Groupe Admin -->
    <div class="synoldap-card" id="card-admin-group">
        <div class="synoldap-card-header" data-toggle="admin-section">
            <span class="synoldap-card-icon">👑</span>
            <h3>3. Promotion automatique Administrateur</h3>
            <label class="synoldap-section-toggle" title="Activer / Désactiver cette section" onclick="event.stopPropagation()">
                <input type="checkbox" class="section-enable-cb" data-section="admin-group" checked />
                <span class="synoldap-toggle-track"><span class="synoldap-toggle-thumb"></span></span>
            </label>
            <span class="synoldap-toggle-icon">▼</span>
        </div>
        <div class="synoldap-card-body" id="admin-section">
            <div class="synoldap-form-grid">
                <div class="synoldap-field synoldap-field-wide">
                    <label for="admin_ldap_group">Groupe AD → Admin Nextcloud</label>
                    <input type="text" id="admin_ldap_group" name="admin_ldap_group"
                           placeholder="ADMIN_NEXTCLOUD" />
                    <span class="synoldap-hint">
                        Les membres de ce groupe AD seront automatiquement administrateurs Nextcloud à la connexion.
                    </span>
                </div>
            </div>
        </div>
    </div>

    <!-- Section 4 : Correspondances Groupes / Stockage -->
    <div class="synoldap-card" id="card-mappings">
        <div class="synoldap-card-header" data-toggle="mappings-section">
            <span class="synoldap-card-icon">🗂️</span>
            <h3>4. Correspondances Groupes ↔ Stockage</h3>
            <label class="synoldap-section-toggle" title="Activer / Désactiver cette section" onclick="event.stopPropagation()">
                <input type="checkbox" class="section-enable-cb" data-section="mappings" checked />
                <span class="synoldap-toggle-track"><span class="synoldap-toggle-thumb"></span></span>
            </label>
            <span class="synoldap-toggle-icon">▼</span>
        </div>
        <div class="synoldap-card-body" id="mappings-section">
            <p class="synoldap-desc">
                Définissez ici quels groupes AD ont accès à quels partages Synology.
                En mode <strong>ACL auto</strong>, les droits sont lus directement depuis Synology — identique à ce
                que l'utilisateur voit en montant le NAS sur son PC Windows.
            </p>

            <div class="synoldap-table-wrap">
                <table class="synoldap-table" id="mappings-table">
                    <thead>
                        <tr>
                            <th class="col-auto" title="Manuel, auto par nom, auto par ACL Synology, ou commun à tous">Mode</th>
                            <th title="Transport : SMB ou NFS/local">Type</th>
                            <th class="col-manual">Groupe AD (LDAP)</th>
                            <th class="col-manual">Groupe NC <span class="synoldap-optional">ou</span> Utilisateur NC</th>
                            <th>Partage</th>
                            <th class="col-manual">Sous-dossier <span class="synoldap-optional">(opt.)</span></th>
                            <th class="col-manual">Point de montage</th>
                            <th class="col-auto-extra">Préfixe NC <span class="synoldap-optional">(opt.)</span></th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody id="mappings-body">
                        <!-- Rempli dynamiquement par JS -->
                    </tbody>
                </table>
            </div>

            <datalist id="synoldap-shares"></datalist>

            <button id="btn-add-mapping" class="synoldap-btn synoldap-btn-ghost">
                ＋ Ajouter une correspondance
            </button>

            <div class="synoldap-info-box" id="acl-info-box">
                ℹ️ <strong>Mode ACL :</strong> les droits sont lus depuis l'API DSM Synology (SYNO.Core.ACL).
                Les montages sont créés automatiquement à la connexion de chaque utilisateur selon ses groupes AD.
                Un <strong>préfixe</strong> (ex. <code>NAS</code>) reproduit la même arborescence que le lecteur réseau Windows :
                l'utilisateur voit <em>/NAS/Compta/2026</em> dans Nextcloud comme sur son PC.
                <br>
                <strong>Exemple type — partage <code>User</code> :</strong> une ligne <strong>Auto ACL</strong>
                sur le partage <code>User</code> avec le préfixe <code>User</code> — les membres de
                <code>Compta</code> ne voient que <em>/User/Compta</em> — plus une ligne
                <strong>Commun</strong> pour le dossier accessible à tous les services.
                <br>
                <button id="btn-clear-acl-cache" class="synoldap-btn-link" style="margin-top:6px">
                    🗑️ Vider le cache ACL (forcer la relecture des droits Synology)
                </button>
            </div>
        </div>
    </div>

    <!-- Aperçu ACL -->
    <div id="acl-preview-card" class="synoldap-card" style="display:none">
        <div class="synoldap-card-header" data-toggle="acl-preview-section">
            <span class="synoldap-card-icon">🔍</span>
            <h3>Aperçu des ACL découvertes</h3>
            <span class="synoldap-toggle-icon">▼</span>
        </div>
        <div class="synoldap-card-body" id="acl-preview-section">
            <p class="synoldap-desc">
                Droits lus depuis Synology. Chaque ligne = un sous-dossier du partage et les groupes AD qui y ont accès en lecture.
            </p>
            <div id="acl-preview-content"></div>
        </div>
    </div>

    <!-- Actions globales -->
    <div class="synoldap-card synoldap-actions-card">
        <div class="synoldap-card-body synoldap-actions-row">
            <button id="btn-save" class="synoldap-btn synoldap-btn-primary">
                💾 Sauvegarder la configuration
            </button>
            <button id="btn-apply-storage" class="synoldap-btn synoldap-btn-secondary">
                🔗 Appliquer les montages stockage
            </button>
            <button id="btn-preview-acl" class="synoldap-btn synoldap-btn-secondary">
                🔍 Prévisualiser les ACL
            </button>
            <button id="btn-debug-acl" class="synoldap-btn synoldap-btn-ghost" title="Affiche la réponse brute de l'API DSM pour diagnostic">
                🛠️ Diagnostic ACL brut
            </button>
            <button id="btn-sync-all" class="synoldap-btn synoldap-btn-warning">
                🔄 Synchroniser tous les utilisateurs maintenant
            </button>
            <button id="btn-check-duplicates" class="synoldap-btn synoldap-btn-secondary">
                🔎 Détecter les groupes dupliqués
            </button>
        </div>
    </div>

    <!-- Purge des groupes dupliqués -->
    <div id="duplicates-card" class="synoldap-card" style="display:none">
        <div class="synoldap-card-header" data-toggle="duplicates-section">
            <span class="synoldap-card-icon">🧹</span>
            <h3>Groupes dupliqués détectés</h3>
            <span class="synoldap-toggle-icon">▼</span>
        </div>
        <div class="synoldap-card-body" id="duplicates-section">
            <p class="synoldap-desc">
                Les doublons ci-dessous ont le même nom. La purge conserve le groupe avec le plus de membres
                et y fusionne les membres des autres avant de les supprimer.
            </p>
            <div id="duplicates-content"></div>
            <div class="synoldap-actions" style="margin-top:16px">
                <button id="btn-purge-duplicates" class="synoldap-btn synoldap-btn-warning">
                    🗑️ Purger les doublons (fusionner + supprimer)
                </button>
                <span id="purge-result" class="synoldap-inline-result"></span>
            </div>
        </div>
    </div>

    <!-- Log de résultats -->
    <div id="synoldap-log" class="synoldap-log" style="display:none">
        <div class="synoldap-log-header">
            Journal des opérations
            <button id="btn-clear-log" class="synoldap-btn-link">Effacer</button>
        </div>
        <div id="synoldap-log-content"></div>
    </div>
</div>
