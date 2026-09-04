/**
 * SynoLDAP Admin Panel
 */
(function () {
    'use strict';

    const APP_ID = 'synoldap';

    // ─── Helpers ──────────────────────────────────────────────────────────────

    function apiUrl(path) {
        return OC.generateUrl('/apps/' + APP_ID + path);
    }

    function csrfHeaders() {
        return {
            'requesttoken': OC.requestToken,
            'Content-Type': 'application/json',
        };
    }

    async function apiFetch(path, method = 'GET', body = null) {
        const opts = { method, headers: csrfHeaders() };
        if (body !== null) opts.body = JSON.stringify(body);
        const res = await fetch(apiUrl(path), opts);
        if (!res.ok) throw new Error('Erreur HTTP ' + res.status);
        return res.json();
    }

    function esc(str) {
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/"/g, '&quot;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;');
    }

    // ─── Status / Log ────────────────────────────────────────────────────────

    function showStatus(message, type = 'info') {
        const bar = document.getElementById('synoldap-status-bar');
        bar.className = 'synoldap-status synoldap-status-' + type;
        bar.textContent = message;
        bar.style.display = 'block';
        if (type === 'success') setTimeout(() => { bar.style.display = 'none'; }, 5000);
    }

    function showLog(message, type = 'info') {
        const log     = document.getElementById('synoldap-log');
        const content = document.getElementById('synoldap-log-content');
        log.style.display = 'block';
        const line = document.createElement('div');
        line.className = 'synoldap-log-line synoldap-log-' + type;
        line.textContent = '[' + new Date().toLocaleTimeString() + '] ' + message;
        content.appendChild(line);
        content.scrollTop = content.scrollHeight;
    }

    // ─── Section collapse toggles ────────────────────────────────────────────

    function initSectionToggles() {
        document.querySelectorAll('.synoldap-card-header[data-toggle]').forEach(header => {
            header.addEventListener('click', () => {
                const body = document.getElementById(header.dataset.toggle);
                const icon = header.querySelector('.synoldap-toggle-icon');
                const collapsed = body.style.display === 'none';
                body.style.display = collapsed ? '' : 'none';
                icon.textContent = collapsed ? '▼' : '▶';
            });
        });
    }

    // ─── Section enable / disable toggles ────────────────────────────────────

    const LS_KEY = 'synoldap_sections_enabled';

    function getSectionStates() {
        try {
            return JSON.parse(localStorage.getItem(LS_KEY) || '{}');
        } catch { return {}; }
    }

    function saveSectionState(section, enabled) {
        const states = getSectionStates();
        states[section] = enabled;
        localStorage.setItem(LS_KEY, JSON.stringify(states));
    }

    function applySectionState(cb) {
        const card    = document.getElementById('card-' + cb.dataset.section);
        const enabled = cb.checked;
        if (!card) return;
        card.classList.toggle('section-disabled', !enabled);
        // Désactiver/réactiver tous les inputs du body pour empêcher l'édition
        card.querySelectorAll('.synoldap-card-body input, .synoldap-card-body select, .synoldap-card-body button').forEach(el => {
            el.disabled = !enabled;
        });
    }

    function initSectionEnableToggles() {
        const states = getSectionStates();
        document.querySelectorAll('.section-enable-cb').forEach(cb => {
            const section = cb.dataset.section;
            // Restaurer l'état sauvegardé (si inexistant → activé par défaut)
            const enabled = states[section] !== undefined ? states[section] : true;
            cb.checked = enabled;
            applySectionState(cb);

            cb.addEventListener('change', () => {
                saveSectionState(section, cb.checked);
                applySectionState(cb);
                showStatus(
                    'Section "' + cb.closest('.synoldap-card-header').querySelector('h3').textContent.trim() +
                    '" ' + (cb.checked ? 'activée.' : 'désactivée.'),
                    cb.checked ? 'success' : 'warning'
                );
            });
        });
    }

    // ─── Load config ─────────────────────────────────────────────────────────

    /** Masque le bloc NFS ou le bloc SMB selon le transport par défaut. */
    function refreshBackendBlocks() {
        const isLocal = document.getElementById('storage_backend').value === 'local';
        document.getElementById('local-block').style.display = isLocal ? '' : 'none';
    }

    async function loadConfig() {
        try {
            const data = await apiFetch('/admin/config');

            [
                'ldap_host', 'ldap_port', 'ldap_bind_dn',
                'ldap_user_base_dn', 'ldap_group_base_dn',
                'ldap_membership_mode', 'ldap_user_attr',
                'admin_ldap_group',
                'synology_host', 'storage_backend', 'local_root', 'smb_user_auth',
                'synology_smb_user', 'synology_smb_domain',
                'synology_api_port', 'synology_api_user',
            ].forEach(key => {
                const el = document.getElementById(key);
                if (!el) return;
                el.tagName === 'SELECT' ? (el.value = data[key] || '') : (el.value = data[key] || '');
            });

            document.getElementById('ldap_tls').checked        = data['ldap_tls'] === '1';
            document.getElementById('synology_api_ssl').checked = data['synology_api_ssl'] === '1';
            document.getElementById('mount_enable_sharing').checked = data['mount_enable_sharing'] !== '0';

            refreshBackendBlocks();
            renderMappings(data.group_mappings || []);
        } catch (e) {
            showStatus('Impossible de charger la configuration : ' + e.message, 'error');
        }
    }

    // ─── Mappings table ──────────────────────────────────────────────────────

    /**
     * Sérialise toutes les lignes du tableau en JSON.
     * Formats :
     *   Manuel  → { auto_mode: false, ldap_group, nc_group, storage_share, storage_subfolder, mount_point }
     *   Auto    → { auto_mode: 'name'|'acl', storage_share, mount_prefix }
     */
    function getMappings() {
        const mappings = [];
        document.querySelectorAll('#mappings-body tr[data-index]').forEach(row => {
            const mode = row.dataset.mode; // 'manual' | 'name' | 'acl' | 'all'
            const type = val(row, 'storage_type'); // '' = transport global

            if (mode === 'manual') {
                const ldapGroup = val(row, 'ldap_group');
                const ncUser    = val(row, 'nc_user');
                const ncGroup   = val(row, 'nc_group');
                // Au moins ldap_group OU nc_user doit être renseigné
                if (!ldapGroup && !ncUser) return;
                mappings.push({
                    auto_mode:         false,
                    storage_type:      type,
                    ldap_group:        ldapGroup,
                    nc_group:          ncGroup,
                    nc_user:           ncUser,
                    storage_share:     val(row, 'storage_share'),
                    storage_subfolder: val(row, 'storage_subfolder'),
                    mount_point:       val(row, 'mount_point'),
                });
            } else if (mode === 'all') {
                const share = val(row, 'storage_share');
                const sub   = val(row, 'storage_subfolder');
                const mount = val(row, 'mount_point');
                if (!share && !sub && !mount) return;
                mappings.push({
                    auto_mode:         'all',
                    storage_type:      type,
                    storage_share:     share,
                    storage_subfolder: sub,
                    mount_point:       mount,
                });
            } else {
                const share = val(row, 'storage_share');
                if (!share) return;
                mappings.push({
                    auto_mode:    mode,           // 'name' ou 'acl'
                    storage_type: type,
                    storage_share: share,
                    mount_prefix:  val(row, 'mount_prefix'),
                });
            }
        });
        return mappings;
    }

    function val(row, field) {
        return (row.querySelector('[data-field="' + field + '"]')?.value || '').trim();
    }

    function renderMappings(mappings) {
        document.getElementById('mappings-body').innerHTML = '';
        mappings.forEach((m, i) => addMappingRow(m, i));
    }

    // ── HTML builders ─────────────────────────────────────────────────────────

    function option(value, label, current) {
        return `<option value="${esc(value)}" ${current === value ? 'selected' : ''}>${esc(label)}</option>`;
    }

    function buildModeCell(mode) {
        return `
            <td class="mode-cell">
                <select class="mode-select synoldap-table-input mode-select-input" title="Mode du montage">
                    ${option('manual', 'Manuel', mode)}
                    ${option('name',   'Auto nom', mode)}
                    ${option('acl',    'Auto ACL ★', mode)}
                    ${option('all',    'Commun (tous)', mode)}
                </select>
            </td>`;
    }

    function buildTypeCell(type) {
        return `
            <td class="type-cell">
                <select class="synoldap-table-input" data-field="storage_type"
                        title="Transport utilisé pour ce montage">
                    ${option('',         'Défaut', type)}
                    ${option('smb',      'SMB', type)}
                    ${option('smb_user', 'SMB user', type)}
                    ${option('local',    'NFS', type)}
                </select>
            </td>`;
    }

    function shareInput(value, placeholder, extraClass) {
        return `<input type="text" class="synoldap-table-input ${extraClass || ''}"
                    value="${esc(value || '')}" placeholder="${esc(placeholder)}"
                    data-field="storage_share" list="synoldap-shares" />`;
    }

    function buildAllCells(data) {
        return `
            <td colspan="2" class="auto-share-cell">
                <span class="auto-hint">👥 Visible par tous les utilisateurs</span>
            </td>
            <td>${shareInput(data.storage_share, 'User')}</td>
            <td><input type="text" class="synoldap-table-input" value="${esc(data.storage_subfolder || '')}"
                placeholder="(racine du partage)" data-field="storage_subfolder" /></td>
            <td><input type="text" class="synoldap-table-input" value="${esc(data.mount_point || '')}"
                placeholder="/User" data-field="mount_point" /></td>
            <td></td>`;
    }

    function bodyCells(mode, data) {
        if (mode === 'manual') return buildManualCells(data);
        if (mode === 'all')    return buildAllCells(data);
        return buildAutoCells(data, mode);
    }

    function buildManualCells(data) {
        // Détecter si c'est un montage par utilisateur
        const isUser = !!(data.nc_user && !data.nc_group);
        return `
            <td><input type="text" class="synoldap-table-input" value="${esc(data.ldap_group || '')}"
                placeholder="Compta" data-field="ldap_group" /></td>
            <td class="nc-target-cell">
                <div class="nc-target-toggle">
                    <label class="nc-target-label">
                        <input type="radio" name="nc_target_${Date.now()}_type" value="group"
                            class="nc-type-radio" ${isUser ? '' : 'checked'} />
                        Groupe
                    </label>
                    <label class="nc-target-label">
                        <input type="radio" name="nc_target_${Date.now()}_type" value="user"
                            class="nc-type-radio" ${isUser ? 'checked' : ''} />
                        Utilisateur
                    </label>
                </div>
                <input type="text" class="synoldap-table-input nc-group-input ${isUser ? 'hidden' : ''}"
                    value="${esc(data.nc_group || '')}" placeholder="(= Groupe AD)" data-field="nc_group" />
                <input type="text" class="synoldap-table-input nc-user-input ${isUser ? '' : 'hidden'}"
                    value="${esc(data.nc_user || '')}" placeholder="uid.utilisateur" data-field="nc_user" />
            </td>
            <td>${shareInput(data.storage_share, 'NextCloud')}</td>
            <td><input type="text" class="synoldap-table-input" value="${esc(data.storage_subfolder || '')}"
                placeholder="Compta" data-field="storage_subfolder" /></td>
            <td><input type="text" class="synoldap-table-input" value="${esc(data.mount_point || '')}"
                placeholder="/NAS/Compta" data-field="mount_point" /></td>
            <td></td>`;
    }

    function buildAutoCells(data, mode) {
        const isAcl  = mode === 'acl';
        const hintShare  = isAcl ? 'Partage racine (ex : Externe)' : 'Partage racine (ex : Externe)';
        const hintPrefix = 'Préfixe NC (ex : NAS → /NAS/Compta)';
        return `
            <td colspan="5" class="auto-share-cell">
                <div class="auto-row-content">
                    <span class="auto-share-label">Partage :</span>
                    ${shareInput(data.storage_share, hintShare, 'auto-share-input')}
                    ${isAcl
                        ? '<span class="auto-hint">→ ACL lues depuis Synology DSM à chaque connexion</span>'
                        : '<span class="auto-hint">→ sous-dossier = nom du groupe NC</span>'
                    }
                </div>
            </td>
            <td>
                <input type="text" class="synoldap-table-input" style="width:90px"
                    value="${esc(data.mount_prefix || '')}" placeholder="NAS"
                    data-field="mount_prefix"
                    title="${esc(hintPrefix)}" />
            </td>`;
    }

    function buildDeleteCell() {
        return `<td><button class="synoldap-btn-remove" title="Supprimer">✕</button></td>`;
    }

    function rowMode(data) {
        if (!data.auto_mode || data.auto_mode === false) return 'manual';
        if (data.auto_mode === true || data.auto_mode === 'name') return 'name';
        if (data.auto_mode === 'all') return 'all';
        return 'acl';
    }

    function applyRowClasses(row, mode) {
        row.classList.toggle('is-auto', mode === 'name' || mode === 'acl');
        row.classList.toggle('is-acl',  mode === 'acl');
        row.classList.toggle('is-all',  mode === 'all');
    }

    function addMappingRow(data = {}, index = Date.now()) {
        const tbody = document.getElementById('mappings-body');
        const mode  = rowMode(data);

        const row = document.createElement('tr');
        row.dataset.index = index;
        row.dataset.mode  = mode;
        applyRowClasses(row, mode);

        row.innerHTML =
            buildModeCell(mode) +
            buildTypeCell(data.storage_type || '') +
            bodyCells(mode, data) +
            buildDeleteCell();

        bindRowEvents(row);
        tbody.appendChild(row);
    }

    function rebuildRow(row, newMode) {
        // On conserve ce qui reste pertinent d'un mode à l'autre
        const data = {
            storage_type:      val(row, 'storage_type'),
            storage_share:     val(row, 'storage_share'),
            storage_subfolder: val(row, 'storage_subfolder'),
            mount_point:       val(row, 'mount_point'),
            mount_prefix:      val(row, 'mount_prefix'),
        };
        const idx = row.dataset.index;

        row.dataset.mode = newMode;
        applyRowClasses(row, newMode);

        row.innerHTML =
            buildModeCell(newMode) +
            buildTypeCell(data.storage_type) +
            bodyCells(newMode, data) +
            buildDeleteCell();

        row.dataset.index = idx;
        bindRowEvents(row);
    }

    function bindRowEvents(row) {
        row.querySelector('.synoldap-btn-remove').addEventListener('click', () => row.remove());

        row.querySelector('.mode-select').addEventListener('change', function () {
            rebuildRow(row, this.value);
        });

        // Auto-fill nc_group depuis ldap_group (mode manuel)
        const ldapIn = row.querySelector('[data-field="ldap_group"]');
        const ncIn   = row.querySelector('[data-field="nc_group"]');
        const mpIn   = row.querySelector('[data-field="mount_point"]');
        if (ldapIn && ncIn && mpIn) {
            ldapIn.addEventListener('input', () => {
                if (!ncIn.value) ncIn.placeholder = ldapIn.value || 'Compta';
                if (!mpIn.value) mpIn.placeholder = '/' + (ldapIn.value || 'Compta');
            });
        }

        // Toggle Groupe ↔ Utilisateur NC
        row.querySelectorAll('.nc-type-radio').forEach(radio => {
            radio.addEventListener('change', function () {
                const isUser = this.value === 'user';
                const groupIn = row.querySelector('.nc-group-input');
                const userIn  = row.querySelector('.nc-user-input');
                if (groupIn) groupIn.classList.toggle('hidden', isUser);
                if (userIn)  userIn.classList.toggle('hidden', !isUser);
            });
        });
    }

    // ─── Save config ─────────────────────────────────────────────────────────

    function collectPayload() {
        return {
            ldap_host:            document.getElementById('ldap_host').value,
            ldap_port:            document.getElementById('ldap_port').value,
            ldap_tls:             document.getElementById('ldap_tls').checked ? '1' : '0',
            ldap_bind_dn:         document.getElementById('ldap_bind_dn').value,
            ldap_bind_password:   document.getElementById('ldap_bind_password').value,
            ldap_user_base_dn:    document.getElementById('ldap_user_base_dn').value,
            ldap_group_base_dn:   document.getElementById('ldap_group_base_dn').value,
            ldap_membership_mode: document.getElementById('ldap_membership_mode').value,
            ldap_user_attr:       document.getElementById('ldap_user_attr').value,
            admin_ldap_group:     document.getElementById('admin_ldap_group').value,
            synology_host:        document.getElementById('synology_host').value,
            storage_backend:      document.getElementById('storage_backend').value,
            local_root:           document.getElementById('local_root').value,
            smb_user_auth:        document.getElementById('smb_user_auth').value,
            mount_enable_sharing: document.getElementById('mount_enable_sharing').checked ? '1' : '0',
            synology_smb_user:    document.getElementById('synology_smb_user').value,
            synology_smb_password:document.getElementById('synology_smb_password').value,
            synology_smb_domain:  document.getElementById('synology_smb_domain').value,
            synology_api_port:    document.getElementById('synology_api_port').value,
            synology_api_ssl:     document.getElementById('synology_api_ssl').checked ? '1' : '0',
            synology_api_user:    document.getElementById('synology_api_user').value,
            synology_api_password:document.getElementById('synology_api_password').value,
            group_mappings:       getMappings(),
        };
    }

    /**
     * Enregistre la configuration sans retour visuel.
     * Les tests serveur travaillent sur les valeurs enregistrées : on sauvegarde
     * silencieusement avant chaque test pour éviter de tester une ancienne config.
     */
    async function persistConfig() {
        return apiFetch('/admin/config', 'POST', collectPayload());
    }

    async function saveConfig() {
        const btn = document.getElementById('btn-save');
        btn.disabled = true;
        btn.textContent = '⏳ Sauvegarde…';

        try {
            const res = await apiFetch('/admin/config', 'POST', collectPayload());
            showStatus(res.message || 'Configuration sauvegardée.', 'success');
            showLog(res.message, 'success');
        } catch (e) {
            showStatus('Erreur lors de la sauvegarde : ' + e.message, 'error');
            showLog('Erreur sauvegarde : ' + e.message, 'error');
        } finally {
            btn.disabled = false;
            btn.textContent = '💾 Sauvegarder la configuration';
        }
    }

    // ─── Test LDAP ───────────────────────────────────────────────────────────

    async function testLdap() {
        const btn    = document.getElementById('btn-test-ldap');
        const result = document.getElementById('ldap-test-result');
        btn.disabled = true;
        btn.textContent = '⏳ Test…';
        result.textContent = '';

        try {
            const res = await apiFetch('/admin/test-ldap', 'POST', {});
            result.className = 'synoldap-inline-result ' + (res.success ? 'synoldap-ok' : 'synoldap-err');
            result.textContent = (res.success ? '✓ ' : '✗ ') + res.message;
            showLog('Test LDAP : ' + res.message, res.success ? 'success' : 'error');

            const preview = document.getElementById('ldap-groups-preview');
            const list    = document.getElementById('ldap-groups-list');
            if (res.success && res.groups && res.groups.length > 0) {
                list.innerHTML = '';
                res.groups.forEach(g => {
                    const tag = document.createElement('span');
                    tag.className = 'synoldap-tag';
                    tag.textContent = g;
                    tag.title = 'Cliquer pour créer une ligne de correspondance';
                    list.appendChild(tag);
                });
                preview.style.display = '';
            } else {
                preview.style.display = 'none';
            }
        } catch (e) {
            result.className = 'synoldap-inline-result synoldap-err';
            result.textContent = '✗ ' + e.message;
        } finally {
            btn.disabled = false;
            btn.textContent = '🔍 Tester la connexion';
        }
    }

    // ─── Test SMB ────────────────────────────────────────────────────────────

    async function testSmb() {
        const btn    = document.getElementById('btn-test-smb');
        const result = document.getElementById('smb-test-result');
        btn.disabled = true;
        btn.textContent = '⏳ Test…';
        result.textContent = '';

        try {
            const share = (document.getElementById('smb-test-share')?.value || '').trim();
            const res = await apiFetch('/admin/test-smb', 'POST', { share });
            result.className = 'synoldap-inline-result ' + (res.success ? 'synoldap-ok' : 'synoldap-err');
            result.textContent = (res.success ? '✓ ' : '✗ ') + res.message;
            showLog('Test SMB : ' + res.message, res.success ? 'success' : 'error');

            if (res.success && res.shares && res.shares.length > 0) {
                showLog('  Partages disponibles : ' + res.shares.join(', '), 'info');
            }
        } catch (e) {
            result.className = 'synoldap-inline-result synoldap-err';
            result.textContent = '✗ ' + e.message;
        } finally {
            btn.disabled = false;
            btn.textContent = '🖥️ Tester la connexion SMB';
        }
    }

    // ─── Test API DSM ────────────────────────────────────────────────────────

    async function testDsmApi() {
        const btn    = document.getElementById('btn-test-dsm-api');
        const result = document.getElementById('dsm-api-test-result');
        btn.disabled = true;
        btn.textContent = '⏳ Test…';
        result.textContent = '';

        try {
            const res = await apiFetch('/admin/test-dsm-api', 'POST', {});
            result.className = 'synoldap-inline-result ' + (res.success ? 'synoldap-ok' : 'synoldap-err');
            result.textContent = (res.success ? '✓ ' : '✗ ') + res.message;
            showLog('Test API DSM : ' + res.message, res.success ? 'success' : 'error');
        } catch (e) {
            result.className = 'synoldap-inline-result synoldap-err';
            result.textContent = '✗ ' + e.message;
        } finally {
            btn.disabled = false;
            btn.textContent = '🔑 Tester l\'API DSM';
        }
    }

    // ─── Preview ACL ─────────────────────────────────────────────────────────

    async function previewAcl() {
        const btn = document.getElementById('btn-preview-acl');
        btn.disabled = true;
        btn.textContent = '⏳ Lecture des ACL…';

        // Trouver le partage racine des lignes ACL configurées
        const aclRows = [...document.querySelectorAll('#mappings-body tr[data-mode="acl"]')];
        if (aclRows.length === 0) {
            showStatus('Aucune ligne en mode "Auto ACL" dans les correspondances.', 'warning');
            btn.disabled = false;
            btn.textContent = '🔍 Prévisualiser les ACL';
            return;
        }

        const share = (aclRows[0].querySelector('[data-field="storage_share"]')?.value || '').trim();
        if (!share) {
            showStatus('Veuillez saisir un partage racine dans la ligne Auto ACL.', 'warning');
            btn.disabled = false;
            btn.textContent = '🔍 Prévisualiser les ACL';
            return;
        }

        showLog('Lecture des ACL sur "' + share + '"…', 'info');

        try {
            const res = await fetch(apiUrl('/admin/discover-acl') + '?share=' + encodeURIComponent(share), {
                headers: csrfHeaders(),
            });
            const data = await res.json();

            if (!data.success) {
                showStatus('Erreur ACL : ' + data.message, 'error');
                showLog('Erreur ACL : ' + data.message, 'error');
            } else {
                renderAclPreview(share, data.mappings || {});
                showLog('ACL découvertes sur "' + share + '" : ' + Object.keys(data.mappings || {}).length + ' dossier(s)', 'success');
            }
        } catch (e) {
            showStatus('Erreur lors de la lecture des ACL : ' + e.message, 'error');
        } finally {
            btn.disabled = false;
            btn.textContent = '🔍 Prévisualiser les ACL';
        }
    }

    function renderAclPreview(share, mappings) {
        const card    = document.getElementById('acl-preview-card');
        const content = document.getElementById('acl-preview-content');
        card.style.display = '';

        const entries = Object.entries(mappings);
        if (entries.length === 0) {
            content.innerHTML = '<p class="synoldap-desc">Aucun dossier avec ACL de groupe trouvé dans "' + esc(share) + '".</p>';
        } else {
            const rows = entries.map(([folder, groups]) => `
                <tr>
                    <td><strong>${esc(folder)}</strong></td>
                    <td>${groups.map(g => `<span class="synoldap-tag">${esc(g)}</span>`).join(' ')}</td>
                </tr>`).join('');
            content.innerHTML = `
                <p class="synoldap-desc">Partage : <strong>${esc(share)}</strong></p>
                <table class="synoldap-table">
                    <thead><tr><th>Sous-dossier</th><th>Groupes AD avec accès lecture</th></tr></thead>
                    <tbody>${rows}</tbody>
                </table>`;
        }

        card.scrollIntoView({ behavior: 'smooth' });
    }

    // ─── Clear ACL cache ─────────────────────────────────────────────────────

    async function clearAclCache() {
        try {
            const res = await apiFetch('/admin/clear-acl-cache', 'POST', {});
            showStatus(res.message, 'success');
            showLog(res.message, 'success');
            // Masquer l'aperçu périmé
            document.getElementById('acl-preview-card').style.display = 'none';
        } catch (e) {
            showStatus('Erreur : ' + e.message, 'error');
        }
    }

    // ─── Debug ACL brut ──────────────────────────────────────────────────────

    async function debugAcl() {
        const btn = document.getElementById('btn-debug-acl');
        btn.disabled = true;
        btn.textContent = '⏳ Diagnostic…';

        const aclRows = [...document.querySelectorAll('#mappings-body tr[data-mode="acl"]')];
        if (aclRows.length === 0) {
            showStatus('Aucune ligne en mode "Auto ACL" dans les correspondances.', 'warning');
            btn.disabled = false;
            btn.textContent = '🛠️ Diagnostic ACL brut';
            return;
        }

        const share = (aclRows[0].querySelector('[data-field="storage_share"]')?.value || '').trim();
        if (!share) {
            showStatus('Veuillez saisir un partage racine dans la ligne Auto ACL.', 'warning');
            btn.disabled = false;
            btn.textContent = '🛠️ Diagnostic ACL brut';
            return;
        }

        showLog('Diagnostic ACL brut sur "' + share + '"…', 'info');

        try {
            const res = await fetch(apiUrl('/admin/debug-acl') + '?share=' + encodeURIComponent(share), {
                headers: csrfHeaders(),
            });
            const data = await res.json();

            const card    = document.getElementById('acl-preview-card');
            const content = document.getElementById('acl-preview-content');
            card.style.display = '';

            if (!data.success) {
                content.innerHTML = '<p class="synoldap-desc" style="color:red">Erreur : ' + esc(data.message) + '</p>';
                showLog('Erreur diagnostic : ' + data.message, 'error');
            } else {
                const debug = data.debug || {};
                let html = '<p class="synoldap-desc">Diagnostic brut DSM pour <strong>' + esc(share) + '</strong></p>';
                html += '<p>Listage OK : ' + (debug.list_success ? '✅' : '❌') + '</p>';
                if (debug.list_error) {
                    html += '<p style="color:red">Erreur listage : <code>' + esc(JSON.stringify(debug.list_error)) + '</code></p>';
                }
                (debug.folders || []).forEach(f => {
                    html += '<details style="margin:8px 0"><summary><strong>' + esc(f.name) + '</strong> — real_path: <code>' + esc(f.real_path || '(absent)') + '</code></summary>';
                    html += '<pre style="font-size:11px;overflow:auto;max-height:200px;background:#f5f5f5;padding:8px">' + esc(JSON.stringify(f.acl_raw, null, 2)) + '</pre>';
                    html += '</details>';
                });
                content.innerHTML = html;
                showLog('Diagnostic ACL brut affiché pour "' + share + '"', 'info');
            }

            card.scrollIntoView({ behavior: 'smooth' });
        } catch (e) {
            showStatus('Erreur diagnostic : ' + e.message, 'error');
        } finally {
            btn.disabled = false;
            btn.textContent = '🛠️ Diagnostic ACL brut';
        }
    }

    // ─── Sync all users ──────────────────────────────────────────────────────

    async function syncAll() {
        const btn = document.getElementById('btn-sync-all');
        btn.disabled = true;
        btn.textContent = '⏳ Synchronisation…';
        showLog('Synchronisation de tous les utilisateurs…', 'info');

        try {
            const res = await apiFetch('/admin/sync-all', 'POST', {});
            showStatus(res.message, res.success ? 'success' : 'warning');
            showLog(res.message, res.success ? 'success' : 'warning');
            if (res.details?.errors?.length) {
                res.details.errors.forEach(e => showLog('  ✗ ' + e, 'error'));
            }
        } catch (e) {
            showStatus('Erreur : ' + e.message, 'error');
        } finally {
            btn.disabled = false;
            btn.textContent = '🔄 Synchroniser tous les utilisateurs maintenant';
        }
    }

    // ─── Apply storage ────────────────────────────────────────────────────────

    async function applyStorage() {
        const btn = document.getElementById('btn-apply-storage');
        btn.disabled = true;
        btn.textContent = '⏳ Application…';
        showLog('Application des montages de stockage externe…', 'info');

        try {
            const res = await apiFetch('/admin/apply-storage', 'POST', {});
            const results = res.results || [];

            if (results.length === 0) {
                showLog('⚠️ Aucun montage à appliquer — vérifiez que des correspondances de groupes sont configurées et sauvegardées ci-dessous.', 'warning');
                showStatus('Aucun montage à appliquer.', 'warning');
                return;
            }

            results.forEach(r => {
                const icon = r.status === 'ok' ? '✓' : r.status === 'warning' ? '⚠️' : '✗';
                const type = r.status === 'ok' ? 'success' : r.status === 'warning' ? 'warning' : 'error';
                showLog(icon + ' ' + (r.message || r.group), type);
            });

            const hasErr  = results.some(r => r.status === 'error');
            const hasWarn = results.some(r => r.status === 'warning');
            showStatus(
                hasErr  ? 'Montages appliqués avec des erreurs.' :
                hasWarn ? 'Montages appliqués avec des avertissements.' :
                          `Montages appliqués ! (${results.length} traité(s))`,
                hasErr ? 'error' : hasWarn ? 'warning' : 'success'
            );
        } catch (e) {
            showStatus('Erreur : ' + e.message, 'error');
        } finally {
            btn.disabled = false;
            btn.textContent = '🔗 Appliquer les montages stockage';
        }
    }

    // ─── Duplicate groups ────────────────────────────────────────────────────

    async function checkDuplicateGroups() {
        const btn = document.getElementById('btn-check-duplicates');
        btn.disabled = true;
        btn.textContent = '⏳ Analyse…';

        try {
            const res = await apiFetch('/admin/duplicate-groups');
            const card = document.getElementById('duplicates-card');
            const content = document.getElementById('duplicates-content');

            if (!res.success) {
                showStatus('Erreur : ' + res.message, 'error');
                return;
            }

            showLog(res.message, res.to_delete > 0 ? 'warning' : 'success');

            if (res.to_delete === 0) {
                showStatus('✓ Aucun groupe dupliqué détecté.', 'success');
                card.style.display = 'none';
                return;
            }

            // Afficher le tableau des doublons
            const rows = res.duplicates.map(d => {
                const groupRows = d.groups.map((g, i) => {
                    const ldapBadge = g.ldap ? ' <span class="synoldap-badge-ldap">LDAP</span>' : '';
                    return `
                    <tr class="${i === 0 ? 'dup-keep' : 'dup-delete'}">
                        <td>${i === 0 ? '✓ Garder' : (g.ldap ? '⚠️ LDAP' : '✗ Supprimer')}</td>
                        <td><strong>${esc(g.displayName)}</strong>${ldapBadge}</td>
                        <td><code>${esc(g.gid)}</code></td>
                        <td>${g.members} membre(s)</td>
                    </tr>`;
                }).join('');
                return `<tr class="dup-separator"><td colspan="4"><strong>${esc(d.displayName)}</strong></td></tr>${groupRows}`;
            }).join('');

            content.innerHTML = `
                <table class="synoldap-table synoldap-dup-table">
                    <thead><tr><th>Action</th><th>Nom du groupe</th><th>GID</th><th>Membres</th></tr></thead>
                    <tbody>${rows}</tbody>
                </table>
                <p class="synoldap-desc" style="margin-top:8px">
                    <strong>${res.to_delete}</strong> groupe(s) seront traités. Les membres des doublons
                    base de données sont fusionnés dans le groupe conservé puis supprimés.
                    Un doublon <strong>LDAP</strong> ne peut pas être supprimé depuis Nextcloud :
                    retirez-le côté Active Directory.
                </p>`;

            card.style.display = '';
            card.scrollIntoView({ behavior: 'smooth' });
        } catch (e) {
            showStatus('Erreur : ' + e.message, 'error');
        } finally {
            btn.disabled = false;
            btn.textContent = '🔎 Détecter les groupes dupliqués';
        }
    }

    async function purgeDuplicateGroups() {
        const btn = document.getElementById('btn-purge-duplicates');
        const result = document.getElementById('purge-result');
        btn.disabled = true;
        btn.textContent = '⏳ Purge en cours…';
        result.textContent = '';

        try {
            const res = await apiFetch('/admin/purge-duplicate-groups', 'POST', {});
            result.className = 'synoldap-inline-result ' + (res.success ? 'synoldap-ok' : 'synoldap-err');
            result.textContent = (res.success ? '✓ ' : '⚠️ ') + res.message;
            showLog('Purge groupes : ' + res.message, res.success ? 'success' : 'warning');
            if (res.errors && res.errors.length > 0) {
                res.errors.forEach(e => showLog('  ⚠️ ' + e, 'warning'));
            }
            if (res.success && (res.deleted > 0 || res.skipped > 0)) {
                // Re-analyser pour vérifier l'état des doublons
                setTimeout(checkDuplicateGroups, 1500);
            }
        } catch (e) {
            result.className = 'synoldap-inline-result synoldap-err';
            result.textContent = '✗ ' + e.message;
        } finally {
            btn.disabled = false;
            btn.textContent = '🗑️ Purger les doublons (fusionner + supprimer)';
        }
    }

    // ─── Détection du domaine AD ─────────────────────────────────────────────

    async function detectAd() {
        const btn    = document.getElementById('btn-detect-ad');
        const result = document.getElementById('detect-ad-result');
        btn.disabled = true;
        btn.textContent = '⏳ Détection…';
        result.textContent = '';

        try {
            await persistConfig();
            const res = await apiFetch('/admin/detect-ad', 'POST', {});

            if (!res.success) {
                result.className = 'synoldap-inline-result synoldap-err';
                result.textContent = '✗ ' + res.message;
                showLog('Détection AD : ' + res.message, 'error');
                return;
            }

            ['ldap_user_base_dn', 'ldap_group_base_dn', 'ldap_user_attr', 'ldap_membership_mode']
                .forEach(key => {
                    if (res[key]) document.getElementById(key).value = res[key];
                });

            const detail = 'Domaine ' + res.domain + ' (' + res.base_dn + ')'
                + (res.authenticated ? '' : ' — lecture anonyme : vérifiez les base DN proposées');
            result.className = 'synoldap-inline-result synoldap-ok';
            result.textContent = '✓ ' + detail;
            showLog('Détection AD : ' + detail, 'success');
            showStatus('Domaine détecté — pensez à sauvegarder la configuration.', 'success');
        } catch (e) {
            result.className = 'synoldap-inline-result synoldap-err';
            result.textContent = '✗ ' + e.message;
        } finally {
            btn.disabled = false;
            btn.textContent = '🧭 Détecter le domaine automatiquement';
        }
    }

    // ─── Transport local (NFS) ───────────────────────────────────────────────

    async function testLocal() {
        const btn    = document.getElementById('btn-test-local');
        const result = document.getElementById('local-test-result');
        btn.disabled = true;
        btn.textContent = '⏳ Vérification…';
        result.textContent = '';

        try {
            await persistConfig();
            const res = await apiFetch('/admin/test-local', 'POST', {});
            result.className = 'synoldap-inline-result ' + (res.success ? 'synoldap-ok' : 'synoldap-err');
            result.textContent = (res.success ? '✓ ' : '✗ ') + res.message;
            showLog('Chemin local : ' + res.message, res.success ? 'success' : 'error');

            const box  = document.getElementById('local-folders');
            const list = document.getElementById('local-folders-list');
            list.innerHTML = '';
            if (res.success && res.folders && res.folders.length) {
                res.folders.forEach(folder => {
                    const tag = document.createElement('span');
                    tag.className = 'synoldap-tag';
                    tag.textContent = folder;
                    list.appendChild(tag);
                });
                box.style.display = '';
            } else {
                box.style.display = 'none';
            }
        } catch (e) {
            result.className = 'synoldap-inline-result synoldap-err';
            result.textContent = '✗ ' + e.message;
        } finally {
            btn.disabled = false;
            btn.textContent = '📂 Vérifier le chemin local';
        }
    }

    // ─── Liste des partages du NAS ───────────────────────────────────────────

    async function listShares() {
        const btn    = document.getElementById('btn-list-shares');
        const result = document.getElementById('dsm-api-test-result');
        btn.disabled = true;
        btn.textContent = '⏳ Lecture…';

        try {
            await persistConfig();
            const res = await apiFetch('/admin/shares');

            if (!res.success) {
                result.className = 'synoldap-inline-result synoldap-err';
                result.textContent = '✗ ' + res.message;
                showLog('Partages : ' + res.message, 'error');
                return;
            }

            const datalist = document.getElementById('synoldap-shares');
            const list     = document.getElementById('shares-list');
            datalist.innerHTML = '';
            list.innerHTML = '';

            res.shares.forEach(share => {
                const opt = document.createElement('option');
                opt.value = share.name;
                datalist.appendChild(opt);

                const tag = document.createElement('span');
                tag.className = 'synoldap-tag';
                tag.textContent = share.name;
                tag.dataset.share = share.name;
                tag.title = (share.real_path || share.path || '')
                    + ' — cliquer pour créer une ligne « Auto ACL » sur ce partage';
                list.appendChild(tag);
            });

            document.getElementById('shares-preview').style.display = res.shares.length ? '' : 'none';
            result.className = 'synoldap-inline-result synoldap-ok';
            result.textContent = '✓ ' + res.shares.length + ' partage(s)';
            showLog(res.shares.length + ' partage(s) trouvé(s) sur le NAS', 'success');
        } catch (e) {
            result.className = 'synoldap-inline-result synoldap-err';
            result.textContent = '✗ ' + e.message;
        } finally {
            btn.disabled = false;
            btn.textContent = '📚 Lister les partages du NAS';
        }
    }

    // ─── Init ─────────────────────────────────────────────────────────────────

    document.addEventListener('DOMContentLoaded', () => {
        initSectionToggles();
        initSectionEnableToggles();
        loadConfig();

        document.getElementById('storage_backend').addEventListener('change', refreshBackendBlocks);
        document.getElementById('btn-detect-ad').addEventListener('click', detectAd);
        document.getElementById('btn-test-local').addEventListener('click', testLocal);
        document.getElementById('btn-list-shares').addEventListener('click', listShares);
        document.getElementById('btn-test-ldap').addEventListener('click', testLdap);
        document.getElementById('btn-test-smb').addEventListener('click', testSmb);
        document.getElementById('btn-test-dsm-api').addEventListener('click', testDsmApi);
        document.getElementById('btn-save').addEventListener('click', saveConfig);
        document.getElementById('btn-sync-all').addEventListener('click', syncAll);
        document.getElementById('btn-apply-storage').addEventListener('click', applyStorage);
        document.getElementById('btn-preview-acl').addEventListener('click', previewAcl);
        document.getElementById('btn-debug-acl').addEventListener('click', debugAcl);
        document.getElementById('btn-clear-acl-cache').addEventListener('click', clearAclCache);

        document.getElementById('btn-add-mapping').addEventListener('click', () => {
            addMappingRow({}, Date.now());
        });

        document.getElementById('btn-check-duplicates').addEventListener('click', checkDuplicateGroups);
        document.getElementById('btn-purge-duplicates').addEventListener('click', purgeDuplicateGroups);

        document.getElementById('btn-clear-log').addEventListener('click', () => {
            document.getElementById('synoldap-log-content').innerHTML = '';
            document.getElementById('synoldap-log').style.display = 'none';
        });

        // Clic sur un tag LDAP → nouvelle ligne manuelle pré-remplie
        document.getElementById('ldap-groups-list').addEventListener('click', e => {
            if (e.target.classList.contains('synoldap-tag')) {
                addMappingRow({ ldap_group: e.target.textContent }, Date.now());
                document.getElementById('mappings-section').scrollIntoView({ behavior: 'smooth' });
            }
        });

        // Clic sur un partage → nouvelle ligne « Auto ACL » sur ce partage
        document.getElementById('shares-list').addEventListener('click', e => {
            if (e.target.classList.contains('synoldap-tag')) {
                const share = e.target.dataset.share || e.target.textContent;
                addMappingRow({ auto_mode: 'acl', storage_share: share, mount_prefix: share }, Date.now());
                document.getElementById('mappings-section').scrollIntoView({ behavior: 'smooth' });
            }
        });
    });

})();
