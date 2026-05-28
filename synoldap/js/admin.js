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
        const opts = {
            method,
            headers: csrfHeaders(),
        };
        if (body !== null) {
            opts.body = JSON.stringify(body);
        }
        const res = await fetch(apiUrl(path), opts);
        if (!res.ok) {
            throw new Error('Erreur HTTP ' + res.status);
        }
        return res.json();
    }

    // ─── Status bar ──────────────────────────────────────────────────────────

    function showStatus(message, type = 'info') {
        const bar = document.getElementById('synoldap-status-bar');
        bar.className = 'synoldap-status synoldap-status-' + type;
        bar.textContent = message;
        bar.style.display = 'block';
        if (type === 'success') {
            setTimeout(() => { bar.style.display = 'none'; }, 5000);
        }
    }

    function showLog(message, type = 'info') {
        const log = document.getElementById('synoldap-log');
        const content = document.getElementById('synoldap-log-content');
        log.style.display = 'block';

        const line = document.createElement('div');
        line.className = 'synoldap-log-line synoldap-log-' + type;
        const ts = new Date().toLocaleTimeString();
        line.textContent = '[' + ts + '] ' + message;
        content.appendChild(line);
        content.scrollTop = content.scrollHeight;
    }

    // ─── Section toggle ──────────────────────────────────────────────────────

    function initSectionToggles() {
        document.querySelectorAll('.synoldap-card-header[data-toggle]').forEach(header => {
            header.addEventListener('click', () => {
                const targetId = header.dataset.toggle;
                const body = document.getElementById(targetId);
                const icon = header.querySelector('.synoldap-toggle-icon');
                const collapsed = body.style.display === 'none';
                body.style.display = collapsed ? '' : 'none';
                icon.textContent = collapsed ? '▼' : '▶';
            });
        });
    }

    // ─── Load config ─────────────────────────────────────────────────────────

    async function loadConfig() {
        try {
            const data = await apiFetch('/admin/config');

            const fields = [
                'ldap_host', 'ldap_port', 'ldap_bind_dn',
                'ldap_user_base_dn', 'ldap_group_base_dn',
                'ldap_membership_mode', 'ldap_user_attr',
                'admin_ldap_group',
                'synology_host', 'synology_smb_user', 'synology_smb_domain',
            ];

            fields.forEach(key => {
                const el = document.getElementById(key);
                if (!el) return;
                if (el.type === 'checkbox') {
                    el.checked = data[key] === '1' || data[key] === true;
                } else if (el.tagName === 'SELECT') {
                    el.value = data[key] || '';
                } else {
                    el.value = data[key] || '';
                }
            });

            const tlsEl = document.getElementById('ldap_tls');
            if (tlsEl) tlsEl.checked = data['ldap_tls'] === '1' || data['ldap_tls'] === true;

            renderMappings(data.group_mappings || []);
        } catch (e) {
            showStatus('Impossible de charger la configuration : ' + e.message, 'error');
        }
    }

    // ─── Mappings table ──────────────────────────────────────────────────────

    function getMappings() {
        const rows = document.querySelectorAll('#mappings-body tr[data-index]');
        const mappings = [];
        rows.forEach(row => {
            const inputs = row.querySelectorAll('input');
            mappings.push({
                ldap_group:        inputs[0].value.trim(),
                nc_group:          inputs[1].value.trim(),
                storage_share:     inputs[2].value.trim(),
                storage_subfolder: inputs[3].value.trim(),
                mount_point:       inputs[4].value.trim(),
            });
        });
        return mappings.filter(m => m.ldap_group);
    }

    function renderMappings(mappings) {
        const tbody = document.getElementById('mappings-body');
        tbody.innerHTML = '';
        mappings.forEach((m, i) => addMappingRow(m, i));
    }

    function addMappingRow(data = {}, index = Date.now()) {
        const tbody = document.getElementById('mappings-body');
        const existing = tbody.querySelectorAll('tr').length;
        const i = index !== undefined ? index : existing;

        const row = document.createElement('tr');
        row.dataset.index = i;

        const ldapGroup   = data.ldap_group || '';
        const ncGroup     = data.nc_group || '';
        const share       = data.storage_share || '';
        const subfolder   = data.storage_subfolder || '';
        const mountPoint  = data.mount_point || '';

        row.innerHTML = `
            <td><input type="text" class="synoldap-table-input" value="${esc(ldapGroup)}"
                placeholder="Compta" data-field="ldap_group" /></td>
            <td><input type="text" class="synoldap-table-input" value="${esc(ncGroup)}"
                placeholder="Compta (auto)" data-field="nc_group" /></td>
            <td><input type="text" class="synoldap-table-input" value="${esc(share)}"
                placeholder="Compta" data-field="storage_share" /></td>
            <td><input type="text" class="synoldap-table-input" value="${esc(subfolder)}"
                placeholder="" data-field="storage_subfolder" /></td>
            <td><input type="text" class="synoldap-table-input" value="${esc(mountPoint)}"
                placeholder="/Compta" data-field="mount_point" /></td>
            <td><button class="synoldap-btn-remove" title="Supprimer">✕</button></td>
        `;

        // Auto-fill nc_group from ldap_group if empty
        const ldapInput = row.querySelector('[data-field="ldap_group"]');
        const ncInput   = row.querySelector('[data-field="nc_group"]');
        const mpInput   = row.querySelector('[data-field="mount_point"]');

        ldapInput.addEventListener('input', () => {
            if (!ncInput.value) {
                ncInput.placeholder = ldapInput.value || 'Compta';
            }
            if (!mpInput.value) {
                mpInput.placeholder = '/' + (ldapInput.value || 'Compta');
            }
        });

        row.querySelector('.synoldap-btn-remove').addEventListener('click', () => {
            row.remove();
        });

        tbody.appendChild(row);
    }

    function esc(str) {
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/"/g, '&quot;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;');
    }

    // ─── Save config ─────────────────────────────────────────────────────────

    async function saveConfig() {
        const btn = document.getElementById('btn-save');
        btn.disabled = true;
        btn.textContent = '⏳ Sauvegarde…';

        const payload = {
            ldap_host:          document.getElementById('ldap_host').value,
            ldap_port:          document.getElementById('ldap_port').value,
            ldap_tls:           document.getElementById('ldap_tls').checked ? '1' : '0',
            ldap_bind_dn:       document.getElementById('ldap_bind_dn').value,
            ldap_bind_password: document.getElementById('ldap_bind_password').value,
            ldap_user_base_dn:  document.getElementById('ldap_user_base_dn').value,
            ldap_group_base_dn: document.getElementById('ldap_group_base_dn').value,
            ldap_membership_mode: document.getElementById('ldap_membership_mode').value,
            ldap_user_attr:     document.getElementById('ldap_user_attr').value,
            admin_ldap_group:   document.getElementById('admin_ldap_group').value,
            synology_host:        document.getElementById('synology_host').value,
            synology_smb_user:    document.getElementById('synology_smb_user').value,
            synology_smb_password:document.getElementById('synology_smb_password').value,
            synology_smb_domain:  document.getElementById('synology_smb_domain').value,
            group_mappings: getMappings(),
        };

        try {
            const res = await apiFetch('/admin/config', 'POST', payload);
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
        const btn = document.getElementById('btn-test-ldap');
        const result = document.getElementById('ldap-test-result');
        const preview = document.getElementById('ldap-groups-preview');
        const list = document.getElementById('ldap-groups-list');

        btn.disabled = true;
        btn.textContent = '⏳ Test en cours…';
        result.className = 'synoldap-inline-result';
        result.textContent = '';

        try {
            const res = await apiFetch('/admin/test-ldap', 'POST', {});

            if (res.success) {
                result.className = 'synoldap-inline-result synoldap-ok';
                result.textContent = '✓ ' + res.message;
                showLog('Test LDAP réussi : ' + res.message, 'success');

                if (res.groups && res.groups.length > 0) {
                    list.innerHTML = '';
                    res.groups.forEach(g => {
                        const tag = document.createElement('span');
                        tag.className = 'synoldap-tag';
                        tag.textContent = g;
                        tag.title = 'Cliquer pour utiliser ce groupe';
                        list.appendChild(tag);
                    });
                    preview.style.display = '';
                } else {
                    preview.style.display = 'none';
                }
            } else {
                result.className = 'synoldap-inline-result synoldap-err';
                result.textContent = '✗ ' + res.message;
                showLog('Test LDAP échoué : ' + res.message, 'error');
                preview.style.display = 'none';
            }
        } catch (e) {
            result.className = 'synoldap-inline-result synoldap-err';
            result.textContent = '✗ Erreur : ' + e.message;
            showLog('Test LDAP erreur : ' + e.message, 'error');
        } finally {
            btn.disabled = false;
            btn.textContent = '🔍 Tester la connexion';
        }
    }

    // ─── Sync all users ──────────────────────────────────────────────────────

    async function syncAll() {
        const btn = document.getElementById('btn-sync-all');
        btn.disabled = true;
        btn.textContent = '⏳ Synchronisation en cours…';

        showLog('Démarrage de la synchronisation de tous les utilisateurs…', 'info');

        try {
            const res = await apiFetch('/admin/sync-all', 'POST', {});
            const type = res.success ? 'success' : 'warning';
            showStatus(res.message, type);
            showLog(res.message, type);

            if (res.details && res.details.errors && res.details.errors.length > 0) {
                res.details.errors.forEach(err => showLog('  ✗ ' + err, 'error'));
            }
        } catch (e) {
            showStatus('Erreur synchronisation : ' + e.message, 'error');
            showLog('Erreur : ' + e.message, 'error');
        } finally {
            btn.disabled = false;
            btn.textContent = '🔄 Synchroniser tous les utilisateurs maintenant';
        }
    }

    // ─── Apply storage mounts ────────────────────────────────────────────────

    async function applyStorage() {
        const btn = document.getElementById('btn-apply-storage');
        btn.disabled = true;
        btn.textContent = '⏳ Application des montages…';

        showLog('Application des montages de stockage externe…', 'info');

        try {
            const res = await apiFetch('/admin/apply-storage', 'POST', {});

            if (res.results && res.results.length > 0) {
                res.results.forEach(r => {
                    const type = r.status === 'ok' ? 'success' : 'error';
                    showLog((r.status === 'ok' ? '✓ ' : '✗ ') + (r.message || r.group), type);
                });
            }

            const hasErrors = res.results && res.results.some(r => r.status === 'error');
            if (hasErrors) {
                showStatus('Montages appliqués avec des erreurs. Voir le journal.', 'warning');
            } else {
                showStatus('Tous les montages ont été appliqués avec succès !', 'success');
            }
        } catch (e) {
            showStatus('Erreur lors de l\'application des montages : ' + e.message, 'error');
            showLog('Erreur : ' + e.message, 'error');
        } finally {
            btn.disabled = false;
            btn.textContent = '🔗 Appliquer les montages stockage';
        }
    }

    // ─── Init ────────────────────────────────────────────────────────────────

    document.addEventListener('DOMContentLoaded', () => {
        initSectionToggles();
        loadConfig();

        document.getElementById('btn-test-ldap').addEventListener('click', testLdap);
        document.getElementById('btn-save').addEventListener('click', saveConfig);
        document.getElementById('btn-sync-all').addEventListener('click', syncAll);
        document.getElementById('btn-apply-storage').addEventListener('click', applyStorage);

        document.getElementById('btn-add-mapping').addEventListener('click', () => {
            addMappingRow({}, Date.now());
        });

        document.getElementById('btn-clear-log').addEventListener('click', () => {
            document.getElementById('synoldap-log-content').innerHTML = '';
            document.getElementById('synoldap-log').style.display = 'none';
        });

        // Clic sur un tag de groupe pour le copier dans une nouvelle ligne
        document.getElementById('ldap-groups-list').addEventListener('click', e => {
            if (e.target.classList.contains('synoldap-tag')) {
                addMappingRow({ ldap_group: e.target.textContent }, Date.now());
                document.getElementById('mappings-section').scrollIntoView({ behavior: 'smooth' });
            }
        });
    });

})();
