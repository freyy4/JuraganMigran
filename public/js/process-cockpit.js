(() => {
    const root = document.getElementById('process-cockpit');
    if (!root) return;
    const form = root.querySelector('#process-filter-form');
    const search = form.querySelector('[name=search]');
    const storageKey = `jm-process:${root.dataset.user}`;
    let selected = new Map(), controller, generation = 0, timer, composing = false;
    let sessionPreferences = {};
    const preferences = () => { try { return { ...JSON.parse(localStorage.getItem(storageKey) || '{}'), ...sessionPreferences }; } catch { return sessionPreferences; } };
    const savePreference = change => { sessionPreferences = { ...preferences(), ...change }; try { localStorage.setItem(storageKey, JSON.stringify(sessionPreferences)); } catch {} };
    const applyPreferences = () => {
        const prefs = preferences();
        form.hidden = prefs.hideFilters === true;
        const filterToggle = root.querySelector('[data-filter-panel-toggle]');
        if (filterToggle) { filterToggle.textContent = form.hidden ? 'Tampilkan Filter' : 'Sembunyikan Filter'; filterToggle.setAttribute('aria-expanded', String(!form.hidden)); }
        const rail = root.querySelector('#process-priorities');
        if (rail) rail.hidden = prefs.hidePriorities === true;
        root.classList.toggle('process-rail-hidden', prefs.hidePriorities === true);
        root.querySelector('[data-priority-toggle]')?.setAttribute('aria-expanded', String(prefs.hidePriorities !== true));
        root.querySelectorAll('[data-column]').forEach(input => {
            input.checked = !prefs.hiddenColumns?.includes(input.dataset.column);
            root.querySelectorAll(`[data-col="${input.dataset.column}"]`).forEach(cell => { cell.hidden = !input.checked; });
        });
    };
    const syncSelection = () => {
        root.querySelectorAll('[data-select-row]').forEach(input => { input.checked = selected.has(input.value); });
        const toolbar = root.querySelector('.process-bulk-toolbar');
        if (toolbar) toolbar.hidden = selected.size === 0;
        const count = root.querySelector('[data-selected-count]');
        if (count) count.textContent = selected.size;
        const hidden = root.querySelector('[data-bulk-selection]');
        if (hidden) {
            hidden.replaceChildren();
            selected.forEach((row, id) => {
                for (const [name, value] of [[`applications[]`, id], [`from_stages[${id}]`, row.stage]]) {
                    const input = document.createElement('input'); input.type = 'hidden'; input.name = name; input.value = value ?? ''; hidden.append(input);
                }
            });
        }
        const all = root.querySelector('[data-select-page]');
        if (all) {
            const ids = new Set([...root.querySelectorAll('[data-select-row]')].map(input => input.value));
            all.checked = ids.size > 0 && selected.size === ids.size;
            all.indeterminate = selected.size > 0 && !all.checked;
        }
    };
    const stop = () => { clearTimeout(timer); controller?.abort(); generation++; };
    const queryUrl = () => {
        const url = new URL(form.action);
        new FormData(form).forEach((value, key) => { if (value !== '') url.searchParams.set(key, value); });
        const current = new URL(location.href);
        for (const key of ['per_page', 'sort']) { if (current.searchParams.has(key)) url.searchParams.set(key, current.searchParams.get(key)); }
        return url;
    };
    const load = async (url, updateHistory = true) => {
        stop(); const requestId = generation; controller = new AbortController();
        const results = root.querySelector('#process-results');
        const status = form.querySelector('[data-search-status]');
        results.setAttribute('aria-busy', 'true'); results.inert = true;
        status.textContent = 'Mencari…';
        try {
            const response = await fetch(url, { signal: controller.signal, credentials: 'same-origin', headers: { Accept: 'text/html', 'X-Requested-With': 'XMLHttpRequest' }, cache: 'no-store' });
            if (requestId !== generation) return;
            if (response.redirected && new URL(response.url).pathname !== url.pathname) { location.assign(response.url); return; }
            if (!response.ok) throw new Error('response');
            const html = new DOMParser().parseFromString(await response.text(), 'text/html');
            if (requestId !== generation) return;
            const nextRoot = html.getElementById('process-cockpit'), next = html.getElementById('process-results');
            if (!nextRoot || !next) throw new Error('markup');
            next.querySelectorAll('script').forEach(item => item.remove());
            results.replaceChildren(...next.childNodes);
            const nextForm = html.getElementById('process-filter-form');
            [...form.elements].forEach(input => {
                if (!input.name) return;
                const other = [...nextForm.elements].find(item => item.name === input.name);
                if (other) input.value = other.value;
            });
            const saveDialog = root.querySelector('#process-save-dialog'), nextDialog = html.getElementById('process-save-dialog');
            if (saveDialog && nextDialog) saveDialog.replaceChildren(...nextDialog.childNodes);
            root.dataset.mode = nextRoot.dataset.mode;
            selected.clear(); syncSelection(); applyPreferences();
            if (updateHistory) history.pushState(null, '', url);
            status.textContent = 'Antrean diperbarui.';
        } catch (error) {
            if (error.name !== 'AbortError' && requestId === generation) status.textContent = 'Antrean gagal diperbarui. Data sebelumnya tetap tersedia. Tekan Terapkan untuk mencoba lagi.';
        } finally { if (requestId === generation) { results.removeAttribute('aria-busy'); results.inert = false; } }
    };
    const openBulk = action => {
        const dialog = root.querySelector('#process-bulk-dialog');
        const select = root.querySelector('[data-bulk-action]');
        if (!dialog || !select || !selected.size) return;
        if (action) select.value = action;
        if (!select.value) { select.focus(); select.reportValidity(); return; }
        dialog.querySelectorAll('[data-action-fields]').forEach(group => {
            const active = group.dataset.actionFields.split(' ').includes(select.value); group.hidden = !active;
            group.querySelectorAll('input,select').forEach(input => { input.disabled = !active; input.required = active && !input.hasAttribute('data-optional'); });
        });
        dialog.querySelector('[name=confirmed]').checked = false;
        dialog.querySelector('[data-bulk-summary]').textContent = `${select.selectedOptions[0].textContent} untuk ${selected.size} lamaran: ${[...selected.values()].map(row => row.name).join(', ')}.`;
        dialog.showModal();
    };
    root.addEventListener('click', event => {
        const close = event.target.closest('[data-close-dialog]');
        if (close) { close.closest('dialog').close(); return; }
        if (event.target.closest('[data-filter-panel-toggle]')) { savePreference({ hideFilters: !form.hidden }); applyPreferences(); return; }
        if (event.target.closest('[data-filters-toggle]')) { const expanded = root.classList.toggle('process-filters-expanded'); root.querySelector('[data-filters-toggle]').setAttribute('aria-expanded', String(expanded)); return; }
        if (event.target.closest('[data-save-view]')) { root.querySelector('#process-save-dialog').showModal(); return; }
        if (event.target.closest('[data-priority-toggle]')) { savePreference({ hidePriorities: !preferences().hidePriorities }); applyPreferences(); return; }
        if (event.target.closest('[data-clear-selection]')) { selected.clear(); syncSelection(); return; }
        if (event.target.closest('[data-review-bulk]')) { openBulk(); return; }
        const quick = event.target.closest('[data-row-action]');
        if (quick) { selected.clear(); selected.set(quick.dataset.id, { stage: quick.dataset.stage, name: quick.dataset.name }); syncSelection(); openBulk(quick.dataset.rowAction); quick.closest('details')?.removeAttribute('open'); return; }
        const link = event.target.closest('a[href]');
        const eventName = link?.hasAttribute('data-stage-filter') ? 'PROCESS_STAGE_FILTER_CLICKED' : link?.hasAttribute('data-row-open') ? (link.closest('#process-priorities') ? 'PROCESS_PRIORITY_OPENED' : 'PROCESS_ROW_OPENED') : null;
        if (eventName) {
            const payload = { event: eventName, _token: root.dataset.csrf };
            if (link.dataset.applicationId) payload.application_id = link.dataset.applicationId;
            if (link.dataset.stageFilter) payload.stage_id = link.dataset.stageFilter;
            fetch(root.dataset.eventUrl, { method: 'POST', credentials: 'same-origin', keepalive: true, headers: { 'Content-Type': 'application/json', Accept: 'application/json' }, body: JSON.stringify(payload) }).catch(() => {});
        }
        if (!link || event.defaultPrevented || event.button !== 0 || event.ctrlKey || event.metaKey || event.altKey || event.shiftKey || link.target) return;
        const url = new URL(link.href);
        if (url.origin !== location.origin || url.pathname !== new URL(form.action).pathname) return;
        event.preventDefault();
        if (url.searchParams.has('mode')) savePreference({ mode: url.searchParams.get('mode') });
        load(url);
    });
    root.addEventListener('change', event => {
        const input = event.target;
        if (input.matches('[data-select-row]')) { if (input.checked) selected.set(input.value, { stage: input.dataset.stage, name: input.dataset.name }); else selected.delete(input.value); syncSelection(); }
        if (input.matches('[data-select-page]')) { root.querySelectorAll('[data-select-row]').forEach(row => { if (input.checked) selected.set(row.value, { stage: row.dataset.stage, name: row.dataset.name }); else selected.delete(row.value); }); syncSelection(); }
        if (input.matches('[data-column]')) { savePreference({ hiddenColumns: [...root.querySelectorAll('[data-column]')].filter(item => !item.checked).map(item => item.dataset.column) }); applyPreferences(); }
        if (input.matches('[data-page-size],[data-process-sort]')) { const url = new URL(location.href); url.searchParams.delete('page'); url.searchParams.set(input.matches('[data-page-size]') ? 'per_page' : 'sort', input.value); load(url); }
        if (input.matches('[data-saved-view]') && input.value) location.assign(input.value);
    });
    form.addEventListener('submit', event => { if (window.fetch && window.AbortController) { event.preventDefault(); load(queryUrl()); } });
    const schedule = () => { stop(); const results = root.querySelector('#process-results'); results.removeAttribute('aria-busy'); results.inert = false; if (!composing) timer = setTimeout(() => load(queryUrl()), 350); };
    search.addEventListener('compositionstart', () => { composing = true; stop(); });
    search.addEventListener('compositionend', () => { composing = false; schedule(); });
    search.addEventListener('input', schedule);
    window.addEventListener('popstate', () => load(new URL(location.href), false));
    const prefs = preferences();
    applyPreferences();
    if (!location.search && prefs.mode && ['summary', 'table', 'board', 'followups'].includes(prefs.mode) && prefs.mode !== root.dataset.mode) { const url = new URL(location.href); url.searchParams.set('mode', prefs.mode); load(url); }
})();
