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
                    <span class="synoldap-hint">Compte utilisé pour requêter l'AD. Ex : CN=svc-nextcloud,CN=Users,DC=mondomaine,DC=local</span>
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
                    <span class="synoldap-hint">L'OU ou CN où se trouvent les comptes utilisateurs</span>
                </div>
                <div class="synoldap-field synoldap-field-wide">
                    <label for="ldap_group_base_dn">Base DN — Groupes</label>
                    <input type="text" id="ldap_group_base_dn" name="ldap_group_base_dn"
                           placeholder="CN=Users,DC=domain,DC=local" />
                    <span class="synoldap-hint">L'OU ou CN où se trouvent les groupes</span>
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

    <!-- Section 2 : Stockage Synology -->
    <div class="synoldap-card">
        <div class="synoldap-card-header" data-toggle="storage-section">
            <span class="synoldap-card-icon">🗄️</span>
            <h3>2. Connexion SMB vers le Synology</h3>
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
                    <span class="synoldap-hint">Compte service sur le Synology avec accès aux partages</span>
                </div>
                <div class="synoldap-field">
                    <label for="synology_smb_password">Mot de passe SMB</label>
                    <input type="password" id="synology_smb_password" name="synology_smb_password"
                           placeholder="(inchangé si vide)" autocomplete="new-password" />
                </div>
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
                        Si un membre quitte le groupe AD, il perdra ses droits admin (sauf s'il est le dernier admin).
                    </span>
                </div>
            </div>
            <div class="synoldap-info-box">
                ℹ️ La promotion/révocation est effectuée à chaque connexion de l'utilisateur.
                Pour appliquer immédiatement à tous les utilisateurs existants, utilisez <strong>Synchroniser maintenant</strong>.
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
                Le dossier est monté automatiquement dans Nextcloud pour les membres du groupe correspondant.
            </p>

            <div class="synoldap-table-wrap">
                <table class="synoldap-table" id="mappings-table">
                    <thead>
                        <tr>
                            <th>Groupe AD (LDAP)</th>
                            <th>Groupe Nextcloud</th>
                            <th>Partage SMB</th>
                            <th>Sous-dossier <span class="synoldap-optional">(optionnel)</span></th>
                            <th>Point de montage</th>
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

            <div class="synoldap-info-box">
                ℹ️ Après avoir sauvegardé, cliquez sur <strong>Appliquer les montages</strong> pour créer
                les stockages externes dans Nextcloud. L'application <em>Files_External</em> doit être activée.
            </div>
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
