(() => {
    const root = document.querySelector('[data-cp-user]');
    if (!root) return;
    const request = async (url, method = 'GET', body) => {
        const response = await fetch(url, {method, headers:{Accept:'application/json','Content-Type':'application/json','X-CSRF-TOKEN':root.dataset.cpToken}, ...(body ? {body:JSON.stringify(body)} : {})});
        const data = await response.json();
        if (!response.ok) throw new Error(Object.values(data.errors || {}).flat()[0] || data.message || 'Permintaan gagal.');
        return data;
    };
    const fields = [...document.querySelectorAll('[data-cp-field]')];
    const orderedFields = () => [...document.querySelectorAll('[data-cp-field]')];
    const scope = document.querySelector('[data-cp-layout-scope]');
    const layoutFeedback = document.querySelector('[data-cp-layout-feedback]');
    let version = 0;
    const apply = () => {
        orderedFields().forEach(input => {
            document.querySelectorAll(`[data-cp-show="${input.dataset.cpField}"],[data-cp-preview="${input.dataset.cpField}"]`).forEach(field => field.hidden = !input.checked);
            root.querySelectorAll('.cp-card-fields').forEach(container => {
                const field = container.querySelector(`[data-cp-show="${input.dataset.cpField}"]`);
                if (field) container.append(field);
            });
            const preview = document.querySelector('[data-cp-preview-fields]');
            const field = preview?.querySelector(`[data-cp-preview="${input.dataset.cpField}"]`);
            if (field) preview.append(field);
        });
    };
    let draggedField;
    fields.forEach(input => {
        const row = input.closest('.cp-field-row');
        row?.addEventListener('dragstart', event => { draggedField = row; event.stopPropagation(); });
        row?.addEventListener('dragover', event => event.preventDefault());
        row?.addEventListener('drop', event => { event.preventDefault(); if (draggedField && draggedField !== row) row.before(draggedField); draggedField = null; apply(); });
        row?.querySelector('[data-cp-field-up]')?.addEventListener('click', () => { const previous = row.previousElementSibling; if (previous?.matches('.cp-field-row')) previous.before(row); apply(); });
        row?.querySelector('[data-cp-field-down]')?.addEventListener('click', () => { const next = row.nextElementSibling; if (next?.matches('.cp-field-row')) next.after(row); apply(); });
    });
    const loadLayout = async (initial = false) => {
        try {
            const data = await request(root.dataset.cpLayoutUrl + (initial ? '' : '?scope=' + scope.value));
            fields.forEach(input => input.checked = data.fields.includes(input.dataset.cpField));
            data.fields.forEach(key => { const input = fields.find(item => item.dataset.cpField === key); const row = input?.closest('.cp-field-row'); if (row) row.parentElement.append(row); });
            apply();
            if (initial) {
                const personal = await request(root.dataset.cpLayoutUrl + '?scope=USER');
                version = personal.version;
            } else version = data.version;
        } catch (error) { layoutFeedback.textContent = error.message; }
    };
    fields.forEach(input => input.addEventListener('change', apply));
    scope.addEventListener('change', () => loadLayout());
    document.querySelector('[data-cp-save-layout]').addEventListener('click', async event => {
        event.target.disabled = true;
        try {
            const data = await request(root.dataset.cpLayoutUrl, 'PUT', {scope:scope.value,version,fields:orderedFields().filter(input => input.checked).map(input => input.dataset.cpField)});
            version = data.version; layoutFeedback.textContent = 'Tata letak disimpan.';
        } catch (error) { layoutFeedback.textContent = error.message; }
        finally { event.target.disabled = false; }
    });
    loadLayout(true);
    document.querySelectorAll('.cp-menu button,.cp-menu a').forEach(button => button.addEventListener('click', () => button.closest('details').open = false));
    document.querySelectorAll('.cp-menu').forEach(menu => {
        document.addEventListener('click', event => { if (!menu.contains(event.target)) menu.open = false; });
        menu.addEventListener('keydown', event => {
            if (event.key === 'Escape') { menu.open = false; menu.querySelector('summary').focus(); }
            if (['ArrowDown','ArrowUp','Home','End'].includes(event.key) && menu.open) {
                event.preventDefault();
                const items = [...menu.querySelectorAll('button,a')];
                const index = items.indexOf(document.activeElement);
                const next = event.key === 'Home' ? 0 : event.key === 'End' ? items.length - 1 : (index + (event.key === 'ArrowDown' ? 1 : -1) + items.length) % items.length;
                items[next]?.focus();
            }
        });
    });
    document.querySelectorAll('[data-cp-stage-id]').forEach(row => row.addEventListener('toggle', () => {
        if (row.open) document.querySelectorAll('[data-cp-stage-id]').forEach(other => { if (other !== row) other.open = false; });
    }));
    let draggedStage;
    document.querySelectorAll('[data-cp-stage-id]').forEach(row => {
        row.addEventListener('dragstart', event => { if (event.target.closest('input,select,button')) { event.preventDefault(); return; } draggedStage = row; });
        row.addEventListener('dragover', event => event.preventDefault());
        row.addEventListener('drop', event => { event.preventDefault(); if (draggedStage && draggedStage !== row) row.before(draggedStage); draggedStage = null; document.querySelectorAll('[data-cp-stage-id]').forEach((item,index) => item.querySelector('[name=display_order]').value = index); });
    });
    document.querySelector('[data-cp-save-stage-order]')?.addEventListener('click', async event => {
        const feedback = document.querySelector('[data-cp-stage-feedback]');
        try { await request(event.target.dataset.url, 'POST', {ids:[...document.querySelectorAll('[data-cp-stage-id]')].map(row=>Number(row.dataset.cpStageId))}); feedback.textContent = 'Urutan tahap disimpan. Muat ulang pipeline untuk melihat hasil.'; }
        catch (error) { feedback.textContent = error.message; }
    });
    const boxes = [...root.querySelectorAll('[data-cp-select]')];
    let allFiltered = false;
    const selectionText = document.querySelector('[data-cp-selection]');
    const updateSelection = () => {
        pendingKey = null;
        document.querySelector('.cp-bulk').hidden = !allFiltered && !boxes.some(box => box.checked);
        root.classList.toggle('is-selecting', allFiltered || boxes.some(box => box.checked));
        const pageSelected = boxes.length > 0 && boxes.every(box => box.checked);
        document.querySelector('[data-cp-select-page]').checked = pageSelected;
        document.querySelector('[data-cp-select-filter]').hidden = !pageSelected || allFiltered;
        boxes.forEach(box => box.closest('.cc-lead-card,tr')?.classList.toggle('is-selected', box.checked));
        selectionText.textContent = allFiltered ? 'Semua lead sesuai filter akan diproses.' : `${boxes.filter(box => box.checked).length} lead dipilih`;
    };
    boxes.forEach(box => box.addEventListener('change', () => { allFiltered = false; updateSelection(); }));
    document.querySelector('[data-cp-select-page]').addEventListener('change', event => { allFiltered = false; boxes.forEach(box => box.checked = event.target.checked); updateSelection(); });
    document.querySelector('[data-cp-select-filter]').addEventListener('click', () => { allFiltered = true; boxes.forEach(box => box.checked = true); updateSelection(); });
    document.querySelector('[data-cp-clear]').addEventListener('click', () => { allFiltered = false; boxes.forEach(box => box.checked = false); document.querySelector('[data-cp-select-page]').checked = false; updateSelection(); });
    const form = document.querySelector('[data-cp-bulk-form]');
    const feedback = document.querySelector('[data-cp-bulk-feedback]');
    const action = form.elements.action;
    const updateAction = () => {
        form.querySelectorAll('[data-cp-payload]').forEach(section => section.hidden = section.dataset.cpPayload !== action.value);
        const summary = document.querySelector('[data-cp-export-summary]');
        if (summary) summary.textContent = `Pilihan saat ini: ${allFiltered ? 'semua hasil filter' : boxes.filter(box => box.checked).length + ' lead'}. Filter tahap dan akses Anda tetap diterapkan pada server.`;
    };
    document.querySelector('[data-cp-select-start]')?.addEventListener('click', () => { root.classList.add('is-selecting'); boxes[0]?.focus(); });
    document.querySelector('[data-cp-export]')?.addEventListener('click', () => { action.value = 'export'; form.elements.export_scope.value = 'filtered'; updateAction(); document.getElementById('cp-bulk-action').showModal(); });
    let previewSequence = 0;
    const previewExport = async () => {
        if (action.value !== 'export') return;
        const summary = document.querySelector('[data-cp-export-summary]');
        const scope = form.elements.export_scope.value;
        const filters = JSON.parse(root.dataset.cpFilters);
        if (scope === 'stage') filters.stage = form.elements.export_stage.value;
        const ids = boxes.filter(box=>scope === 'page' || box.checked).map(box=>Number(box.dataset.cpSelect));
        const selection = ['filtered','stage'].includes(scope) || (scope === 'selection' && allFiltered) ? 'filtered' : 'ids';
        const sequence = ++previewSequence;
        summary.textContent = 'Menghitung lead dalam cakupan akses…';
        try { const data = await request(summary.dataset.previewUrl, 'POST', {selection, ids, filters}); if (sequence === previewSequence) summary.textContent = `${data.count} lead · ${data.columns.join(', ')} · ${data.destination}`; }
        catch (error) { if (sequence === previewSequence) summary.textContent = error.message; }
    };
    form.elements.export_scope.addEventListener('change', previewExport);
    form.elements.export_stage.addEventListener('change', previewExport);
    document.querySelector('[data-cp-export]')?.addEventListener('click', previewExport);
    document.querySelector('[data-cc-open="cp-bulk-action"]')?.addEventListener('click', () => { updateAction(); previewExport(); });
    action.addEventListener('change', () => { updateAction(); previewExport(); }); updateAction();
    let pendingKey = null;
    form.addEventListener('input', () => pendingKey = null);
    form.addEventListener('submit', async event => {
        event.preventDefault();
        let ids = boxes.filter(box => box.checked).map(box => Number(box.dataset.cpSelect));
        const filters = JSON.parse(root.dataset.cpFilters);
        let filtered = allFiltered;
        if (action.value === 'export') {
            const scope = form.elements.export_scope.value;
            if (scope === 'page') { filtered = false; ids = boxes.map(box => Number(box.dataset.cpSelect)); }
            if (scope === 'filtered' || scope === 'stage') filtered = true;
            if (scope === 'stage') filters.stage = form.elements.export_stage.value;
        }
        if (!filtered && (!ids.length || ids.length > 100)) { feedback.textContent = 'Pilih 1–100 lead atau gunakan semua hasil filter.'; return; }
        const submit = form.querySelector('button[type=submit],button:not([type])'); submit.disabled = true;
        pendingKey ||= crypto.randomUUID();
        const payload = Object.fromEntries(new FormData(form));
        payload.remove = !!form.elements.remove.checked;
        payload.contacts = !!form.elements.contacts?.checked;
        if (payload.due_at) payload.due_at = new Date(payload.due_at).toISOString();
        try {
            const job = await request(root.dataset.cpBulkUrl, 'POST', {action:action.value,idempotency_key:pendingKey,selection:filtered?'filtered':'ids',ids,filters,payload});
            feedback.textContent = 'Operasi masuk antrean. Menunggu pemrosesan…';
            const poll = async () => {
                try {
                    const status = await request(root.dataset.cpJobUrl.replace('00000000-0000-0000-0000-000000000000',job.id));
                    feedback.textContent = `${status.status}: ${status.success_count} berhasil, ${status.failure_count} gagal dari ${status.total_count}.`;
                    if (status.failures?.length) feedback.textContent += ' Lead gagal: ' + status.failures.map(item => '#' + item.lead_id).join(', ');
                    if (status.download_url) { const link = document.createElement('a'); link.href = status.download_url; link.textContent = ' Unduh ekspor'; feedback.append(link); }
                    if (['DONE','PARTIAL','FAILED'].includes(status.status)) { submit.disabled = false; pendingKey = null; return; }
                    setTimeout(poll,5000);
                } catch (error) { feedback.textContent = error.message; submit.disabled = false; }
            };
            setTimeout(poll,1500);
        } catch (error) { feedback.textContent = error.message; submit.disabled = false; }
    });
})();
