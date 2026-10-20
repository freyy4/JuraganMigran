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
    const scope = document.querySelector('[data-cp-layout-scope]');
    const layoutFeedback = document.querySelector('[data-cp-layout-feedback]');
    let version = 0;
    const apply = () => fields.forEach(input => root.querySelectorAll(`[data-cp-show="${input.dataset.cpField}"]`).forEach(field => field.hidden = !input.checked));
    const loadLayout = async (initial = false) => {
        try {
            const data = await request(root.dataset.cpLayoutUrl + (initial ? '' : '?scope=' + scope.value));
            fields.forEach(input => input.checked = data.fields.includes(input.dataset.cpField));
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
            const data = await request(root.dataset.cpLayoutUrl, 'PUT', {scope:scope.value,version,fields:fields.filter(input => input.checked).map(input => input.dataset.cpField)});
            version = data.version; layoutFeedback.textContent = 'Tata letak disimpan.';
        } catch (error) { layoutFeedback.textContent = error.message; }
        finally { event.target.disabled = false; }
    });
    loadLayout(true);
    document.querySelectorAll('.cp-menu button').forEach(button => button.addEventListener('click', () => button.closest('details').open = false));
    const boxes = [...root.querySelectorAll('[data-cp-select]')];
    let allFiltered = false;
    const selectionText = document.querySelector('[data-cp-selection]');
    const updateSelection = () => {
        pendingKey = null;
        selectionText.textContent = allFiltered ? 'Semua lead sesuai filter akan diproses.' : `${boxes.filter(box => box.checked).length} lead dipilih`;
    };
    boxes.forEach(box => box.addEventListener('change', () => { allFiltered = false; updateSelection(); }));
    document.querySelector('[data-cp-select-page]').addEventListener('change', event => { allFiltered = false; boxes.forEach(box => box.checked = event.target.checked); updateSelection(); });
    document.querySelector('[data-cp-select-filter]').addEventListener('click', () => { allFiltered = true; boxes.forEach(box => box.checked = true); updateSelection(); });
    document.querySelector('[data-cp-clear]').addEventListener('click', () => { allFiltered = false; boxes.forEach(box => box.checked = false); document.querySelector('[data-cp-select-page]').checked = false; updateSelection(); });
    const form = document.querySelector('[data-cp-bulk-form]');
    const feedback = document.querySelector('[data-cp-bulk-feedback]');
    const action = form.elements.action;
    const updateAction = () => form.querySelectorAll('[data-cp-payload]').forEach(section => section.hidden = section.dataset.cpPayload !== action.value);
    action.addEventListener('change', updateAction); updateAction();
    let pendingKey = null;
    form.addEventListener('input', () => pendingKey = null);
    form.addEventListener('submit', async event => {
        event.preventDefault();
        const ids = boxes.filter(box => box.checked).map(box => Number(box.dataset.cpSelect));
        if (!allFiltered && (!ids.length || ids.length > 100)) { feedback.textContent = 'Pilih 1–100 lead atau gunakan semua hasil filter.'; return; }
        const submit = form.querySelector('button[type=submit],button:not([type])'); submit.disabled = true;
        pendingKey ||= crypto.randomUUID();
        const payload = Object.fromEntries(new FormData(form));
        payload.remove = !!form.elements.remove.checked;
        payload.contacts = !!form.elements.contacts?.checked;
        if (payload.due_at) payload.due_at = new Date(payload.due_at).toISOString();
        try {
            const job = await request(root.dataset.cpBulkUrl, 'POST', {action:action.value,idempotency_key:pendingKey,selection:allFiltered?'filtered':'ids',ids,filters:JSON.parse(root.dataset.cpFilters),payload});
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
