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
    <div class="synoldap-card">
        <div class="synoldap-card-header" data-toggle="ldap-section">
            <span class="synoldap-card-icon">🔌</span>
            <h3>1. Connexion LDAP / Active Directory</h3>
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
                    🔍 Tester la connexion
                </button>
                <span id="ldap-test-result" class="synoldap-inline-result"></span>
            </div>

            <div id="ldap-groups-preview" class="synoldap-groups-preview" style="display:none">
                <strong>Groupes détectés :</strong>
                <div id="ldap-groups-list" class="synoldap-tag-list"></div>
            </div>
        </div>
    </div>

    <!-- Section 2 : Stockage Synology + API DSM -->
    <div class="synoldap-card">
        <div class="synoldap-card-header" data-toggle="storage-section">
            <span class="synoldap-card-icon">🗄️</span>
            <h3>2. Connexion Synology (SMB + API DSM)</h3>
            <span class="synoldap-toggle-icon">▼</span>
        </div>
        <div class="synoldap-card-body" id="storage-section">

            <p class="synoldap-section-label">Accès SMB (montage des dossiers)</p>
            <div class="synoldap-form-grid">
                <div class="synoldap-field">
                    <label for="synology_host">Hôte du Synology (IP ou nom)</label>
                    <input type="text" id="synology_host" name="synology_host"
                           placeholder="192.168.1.50" />
                </div>
                <div class="synoldap-field">
                    <label for="synology_smb_domain">Domaine / Workgroup</label>
                    <input type="text" id="synology_smb_domain" name="synology_smb_domain"
                           placeholder="WORKGROUP" />
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
                <span id="dsm-api-test-result" class="synoldap-inline-result"></span>
            </div>
        </div>
    </div>

    <!-- Section 3 : Groupe Admin -->
    <div class="synoldap-card">
        <div class="synoldap-card-header" data-toggle="admin-section">
            <span class="synoldap-card-icon">👑</span>
            <h3>3. Promotion automatique Administrateur</h3>
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
    <div class="synoldap-card">
        <div class="synoldap-card-header" data-toggle="mappings-section">
            <span class="synoldap-card-icon">🗂️</span>
            <h3>4. Correspondances Groupes ↔ Stockage</h3>
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
                            <th class="col-auto" title="Mode : manuel, auto par nom, ou auto par ACL Synology">Mode</th>
                            <th class="col-manual">Groupe AD (LDAP)</th>
                            <th class="col-manual">Groupe Nextcloud</th>
                            <th>Partage SMB</th>
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

            <button id="btn-add-mapping" class="synoldap-btn synoldap-btn-ghost">
                ＋ Ajouter une correspondance
            </button>

            <div class="synoldap-info-box" id="acl-info-box">
                ℹ️ <strong>Mode ACL :</strong> les droits sont lus depuis l'API DSM Synology (SYNO.Core.ACL).
                Les montages sont créés automatiquement à la connexion de chaque utilisateur selon ses groupes AD.
                Un <strong>préfixe</strong> (ex. <code>NAS</code>) reproduit la même arborescence que le lecteur réseau Windows :
                l'utilisateur voit <em>/NAS/Compta/2026</em> dans Nextcloud comme sur son PC.
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
            <button id="btn-sync-all" class="synoldap-btn synoldap-btn-warning">
                🔄 Synchroniser tous les utilisateurs maintenant
            </button>
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
