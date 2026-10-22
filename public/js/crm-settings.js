(() => {
    const root = document.querySelector('[data-settings-root]');
    if (!root) return;
    const tabs = [...root.querySelectorAll('[data-sc-tab]')];
    const csrf = root.querySelector('input[name=_token]')?.value;
    const feedback = root.querySelector('[data-sc-feedback]');
    const preference = 'jm-crm-settings-' + document.body.dataset.uiUser;
    const remember = (key, value) => { try { localStorage.setItem(preference + '-' + key, value); } catch {} };
    const recalled = key => { try { return localStorage.getItem(preference + '-' + key); } catch { return null; } };
    const showFeedback = (node, text, error = false) => {
        if (!node) return;
        node.textContent = text; node.hidden = !text;
        node.classList.toggle('sc-error', error); node.classList.toggle('sc-success', !error);
    };
    function activate(tab, history = true) {
        if (!tabs.some(item => item.dataset.scTab === tab)) tab = 'channel';
        root.dataset.activeTab = tab;
        remember('tab', tab);
        tabs.forEach(item => { const active = item.dataset.scTab === tab; item.setAttribute('aria-selected', String(active)); item.tabIndex = active ? 0 : -1; });
        root.querySelectorAll('[data-sc-section]').forEach(section => { section.hidden = tab === 'channel' ? section.dataset.scSection === 'automation' : section.dataset.scSection !== tab; });
        root.querySelectorAll('[data-sc-help-item]').forEach(item => item.setAttribute('aria-current', String(item.dataset.scHelpItem === tab)));
        if (history) { const url = new URL(location.href); url.searchParams.set('tab', tab); window.history.replaceState(null, '', url); }
    }
    root.addEventListener('click', event => {
        const tab = event.target.closest('[data-sc-tab]');
        const link = event.target.closest('[data-sc-switch]');
        if (tab || link) { event.preventDefault(); activate(tab?.dataset.scTab || link.dataset.scSwitch); if (link && !matchMedia('(min-width:1280px)').matches) root.classList.remove('sc-help-open'); }
        const open = event.target.closest('[data-sc-open]');
        if (open) document.getElementById(open.dataset.scOpen)?.showModal();
        if (event.target.closest('[data-sc-close]')) event.target.closest('dialog')?.close();
        const variable = event.target.closest('[data-sc-variable]');
        if (variable) {
            const text = root.querySelector('[data-sc-quick-builder] textarea');
            text.setRangeText('{{' + variable.dataset.scVariable + '}}', text.selectionStart, text.selectionEnd, 'end');
            text.dispatchEvent(new Event('input', {bubbles:true})); text.focus();
        }
    });
    root.querySelector('.sc-tabs').addEventListener('keydown', event => {
        if (!['ArrowLeft','ArrowRight','Home','End'].includes(event.key)) return;
        event.preventDefault(); const index = tabs.indexOf(document.activeElement);
        const next = event.key === 'Home' ? 0 : event.key === 'End' ? tabs.length - 1 : (index + (event.key === 'ArrowRight' ? 1 : -1) + tabs.length) % tabs.length;
        activate(tabs[next].dataset.scTab); tabs[next].focus();
    });
    addEventListener('popstate', () => activate(new URLSearchParams(location.search).get('tab') || 'channel', false));
    activate(new URLSearchParams(location.search).has('tab') ? root.dataset.initialTab : recalled('tab') || root.dataset.initialTab, false);
    const coach = root.querySelector('[data-sc-coach]');
    coach.hidden = recalled('coach') === 'seen';
    if (!coach.hidden) remember('coach', 'seen');
    root.querySelector('[data-sc-dismiss]').onclick = () => { coach.hidden = true; try { localStorage.setItem(preference + '-coach', 'seen'); } catch {} };
    const desktopHelp = matchMedia('(min-width:1280px)');
    root.classList.toggle('sc-help-collapsed', recalled('help-collapsed') === 'true');
    const syncHelp = () => root.querySelectorAll('[data-sc-help-toggle]').forEach(button => button.setAttribute('aria-expanded', String(desktopHelp.matches ? !root.classList.contains('sc-help-collapsed') : root.classList.contains('sc-help-open'))));
    root.querySelectorAll('[data-sc-help-toggle]').forEach(button => button.addEventListener('click', () => { root.classList.toggle(desktopHelp.matches ? 'sc-help-collapsed' : 'sc-help-open'); if (desktopHelp.matches) remember('help-collapsed', String(root.classList.contains('sc-help-collapsed'))); syncHelp(); }));
    desktopHelp.addEventListener('change', syncHelp); syncHelp();
    root.addEventListener('keydown', event => { if (event.key === 'Escape') { root.classList.remove('sc-help-open'); syncHelp(); } });
    root.addEventListener('input', event => {
        if (event.target.matches('[data-sc-quick-builder] textarea')) {
            const values = {nama:'Siti',program:'Program Jepang',negara:'Jepang',nama_cs:'Tim CS'};
            root.querySelector('[data-sc-reply-preview]').textContent = event.target.value.replace(/\{\{\s*(\w+)\s*\}\}/g, (match, key) => values[key] || match) || 'Isi snippet untuk melihat pratinjau.';
        }
    });
    root.addEventListener('submit', async event => {
        const form = event.target.closest('[data-sc-save]');
        if (!form) return;
        event.preventDefault(); if (form.dataset.saving) return;
        const button = form.querySelector('button:not([type=button])');
        const status = form.querySelector('[data-sc-form-feedback]');
        form.dataset.saving = 'true'; button.disabled = true; form.setAttribute('aria-busy', 'true');
        showFeedback(status, 'Menyimpan…');
        try {
            const response = await fetch(form.action, {method:'POST',body:new FormData(form),headers:{Accept:'application/json'},signal:AbortSignal.timeout(30000)});
            if (!response.ok) {
                const result = await response.json().catch(() => ({}));
                throw Error(Object.values(result.errors || {}).flat().join(' ') || result.message || 'Pengaturan belum tersimpan. Coba kembali.');
            }
            const html = new DOMParser().parseFromString(await response.text(), 'text/html');
            if (!html.querySelector('[data-settings-root]')) throw Error('Sesi berakhir. Muat ulang halaman untuk melanjutkan.');
            const section = form.closest('[data-sc-section]');
            const sectionKey = section?.dataset.scSection || (form.closest('dialog[id^=sc-stage]') ? 'pipeline' : null);
            if (sectionKey) {
                const replacement = html.querySelector(`[data-sc-section="${sectionKey}"]`);
                const current = root.querySelector(`[data-sc-section="${sectionKey}"]`);
                if (replacement && current) current.replaceWith(replacement);
            }
            if (form.closest('dialog[id^=sc-stage]')) {
                form.closest('dialog').close();
                root.querySelectorAll('dialog[id^=sc-stage]').forEach(dialog => dialog.remove());
                html.querySelectorAll('dialog[id^=sc-stage]').forEach(dialog => root.append(dialog));
            }
            activate(root.dataset.activeTab, false);
            const message = html.querySelector('.notice')?.textContent.trim() || 'Pengaturan tersimpan.';
            showFeedback(status, message); showFeedback(feedback, message);
        } catch (error) { showFeedback(status, error.message || 'Koneksi terputus. Perubahan belum dapat dipastikan.', true); }
        finally { delete form.dataset.saving; button.disabled = false; form.removeAttribute('aria-busy'); }
    });
    const routing = root.querySelector('[data-sc-routing]');
    const details = root.querySelector('[data-sc-routing-details]');
    const channels = JSON.parse(document.getElementById('sc-channel-config').textContent);
    if (routing && details) {
        const channelSelect = routing.elements.channel_id;
        const selected = () => channels.find(row => String(row.id) === channelSelect.value);
        const syncFallbackOptions = () => {
            const team = details.elements.team_id.value;
            for (const option of details.elements.fallback_user_id.options) option.disabled = !!team && !!option.value && option.dataset.team !== team;
        };
        details.elements.team_id.addEventListener('change', () => {
            syncFallbackOptions();
            if (details.elements.fallback_user_id.selectedOptions[0]?.disabled) details.elements.fallback_user_id.value = '';
        });
        const syncRoutingState = () => {
            const row = selected();
            const text = root.querySelector('[data-sc-routing-state]');
            text.textContent = (row.routing_enabled === false || row.routing_enabled === 0) ? 'Penugasan otomatis dijeda; pesan tetap masuk ke Inbox.' : row.routing_method === 'MANUAL' ? 'Antrean manual — supervisor menentukan owner.' : 'Penugasan otomatis aktif.';
        };
        const populate = () => {
            const row = selected(); if (!row) return;
            routing.querySelectorAll('[name=routing_method]').forEach(input => input.checked = input.value === row.routing_method);
            details.elements.team_id.value = row.team_id || '';
            details.elements.routing_enabled.checked = row.routing_enabled !== false && row.routing_enabled !== 0;
            details.elements.fallback_user_id.value = row.fallback_user_id || '';
            syncFallbackOptions(); syncRoutingState();
            details.elements.prefer_existing_owner.checked = !!row.prefer_existing_owner;
            details.elements.strict_owner.checked = !!row.strict_owner;
            let hours = {}; try { hours = JSON.parse(row.business_hours || '{}') || {}; } catch {}
            details.elements.hours_start.value = hours.start || ''; details.elements.hours_end.value = hours.end || '';
        };
        channelSelect.addEventListener('change', populate); populate();
        const save = async event => {
            event.preventDefault(); const form = event.currentTarget;
            if (!details.reportValidity() || !routing.reportValidity() || routing.dataset.saving) return;
            const row = selected(), status = form.querySelector('[data-sc-form-feedback]') || routing.querySelector('[data-sc-routing-feedback]');
            const method = routing.querySelector('[name=routing_method]:checked').value;
            const payload = {routing_enabled:details.elements.routing_enabled.checked,fallback_user_id:details.elements.fallback_user_id.value ? Number(details.elements.fallback_user_id.value) : null,name:row.name,team_id:details.elements.team_id.value || null,routing_method:method,prefer_existing_owner:details.elements.prefer_existing_owner.checked,strict_owner:details.elements.strict_owner.checked,hours_start:details.elements.hours_start.value,hours_end:details.elements.hours_end.value};
            routing.dataset.saving = 'true'; const button = form.querySelector('button:not([type=button])'); button.disabled = true;
            showFeedback(status, 'Menyimpan routing…');
            try {
                const response = await fetch(row.routing_url, {method:'POST',headers:{Accept:'application/json','Content-Type':'application/json','X-CSRF-TOKEN':csrf},body:JSON.stringify(payload),signal:AbortSignal.timeout(30000)});
                const result = await response.json();
                if (!response.ok) throw Error(Object.values(result.errors || {}).flat().join(' ') || result.message || 'Routing belum tersimpan.');
                Object.assign(row, payload); row.business_hours = payload.hours_start ? JSON.stringify({start:payload.hours_start,end:payload.hours_end}) : null;
                syncRoutingState();
                showFeedback(status, 'Tim dan routing tersimpan. Owner existing tetap mengikuti kebijakan penugasan.');
                showFeedback(feedback, 'Routing ' + row.name + ' diperbarui.');
            } catch (error) { showFeedback(status, error.message || 'Koneksi terputus; periksa pengaturan sebelum mencoba lagi.', true); }
            finally { delete routing.dataset.saving; button.disabled = false; }
        };
        routing.addEventListener('submit', save); details.addEventListener('submit', save);
    }
    let dragged = null;
    root.addEventListener('dragstart', event => { dragged = event.target.closest('[data-sc-stage-id]'); dragged?.classList.add('is-dragging'); });
    root.addEventListener('dragover', event => { if (dragged && event.target.closest('[data-sc-stage-id]')) event.preventDefault(); });
    root.addEventListener('drop', event => { const target = event.target.closest('[data-sc-stage-id]'); if (dragged && target && target !== dragged) { event.preventDefault(); const rect = target.getBoundingClientRect(); if (event.clientX > rect.left + rect.width / 2) target.after(dragged); else target.before(dragged); } });
    root.addEventListener('dragend', () => { dragged?.classList.remove('is-dragging'); dragged = null; });
    root.addEventListener('click', async event => {
        const button = event.target.closest('[data-sc-save-order]'); if (!button) return;
        const status = root.querySelector('[data-sc-order-feedback]'); button.disabled = true;
        try {
            const response = await fetch(button.dataset.url, {method:'POST',headers:{Accept:'application/json','Content-Type':'application/json','X-CSRF-TOKEN':csrf},body:JSON.stringify({ids:[...root.querySelectorAll('[data-sc-stage-id]')].map(stage => Number(stage.dataset.scStageId))})});
            const result = await response.json(); if (!response.ok) throw Error(Object.values(result.errors || {}).flat().join(' ') || result.message || 'Urutan belum tersimpan.');
            showFeedback(status, 'Urutan tahap disimpan.');
        } catch (error) { showFeedback(status, error.message, true); } finally { button.disabled = false; }
    });
})();
