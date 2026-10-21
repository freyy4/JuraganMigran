(() => {
    document.querySelectorAll('[data-cc-open]').forEach(button => button.addEventListener('click', () => document.getElementById(button.dataset.ccOpen)?.showModal()));
    document.querySelectorAll('[data-cc-close]').forEach(button => button.addEventListener('click', () => button.closest('dialog').close()));
    const timeline = document.querySelector('[data-cc-timeline]');
    let activityFilter = 'all';
    const filterTimeline = () => timeline?.querySelectorAll('[data-cc-activity-day]').forEach(day => {
        const rows = [...day.querySelectorAll('[data-cc-activity]')];
        const matching = rows.filter(row => activityFilter === 'all' || row.dataset.ccActivity === activityFilter);
        rows.forEach(row => row.hidden = !matching.includes(row) || (!day.dataset.expanded && matching.indexOf(row) >= 3));
        day.hidden = matching.length === 0;
        const expand = day.querySelector('[data-cc-activity-expand]');
        expand.hidden = matching.length <= 3;
        expand.textContent = day.dataset.expanded ? 'Ringkas aktivitas' : `Lihat ${matching.length - 3} aktivitas lainnya`;
    });
    document.querySelectorAll('[data-cc-activity-filter]').forEach(button => button.addEventListener('click', () => {
        activityFilter = button.dataset.ccActivityFilter;
        document.querySelectorAll('[data-cc-activity-filter]').forEach(item => item.setAttribute('aria-pressed', String(item === button)));
        timeline.querySelectorAll('[data-cc-activity-day]').forEach(day => delete day.dataset.expanded);
        filterTimeline();
    }));
    timeline?.querySelectorAll('[data-cc-activity-expand]').forEach(button => button.addEventListener('click', () => {
        const day = button.closest('[data-cc-activity-day]');
        if (day.dataset.expanded) delete day.dataset.expanded; else day.dataset.expanded = 'true';
        filterTimeline();
    }));
    const workspace = document.querySelector('.cc-inbox');
    const inspector = workspace?.querySelector('.cc-inspector');
    const inspectorKey = `jm-crm-${document.body.dataset.uiUser}-inspector-collapsed`;
    const inspectorDesktop = matchMedia('(min-width:1200px)');
    const syncInspector = () => {
        const open = inspectorDesktop.matches ? !workspace?.classList.contains('rail-collapsed') : inspector?.classList.contains('is-open');
        document.querySelectorAll('[data-cc-inspector]').forEach(button => button.setAttribute('aria-expanded', String(!!open)));
        if (inspector) {
            inspector.inert = !open;
            if (open && !inspectorDesktop.matches) { inspector.setAttribute('role', 'dialog'); inspector.setAttribute('aria-modal', 'true'); }
            else { inspector.removeAttribute('role'); inspector.removeAttribute('aria-modal'); }
        }
    };
    try { if (localStorage.getItem(inspectorKey) === 'true') workspace?.classList.add('rail-collapsed'); } catch {}
    syncInspector(); inspectorDesktop.addEventListener('change', syncInspector);
    document.querySelectorAll('[data-cc-inspector]').forEach(button => button.addEventListener('click', () => {
        if (inspectorDesktop.matches) {
            workspace?.classList.toggle('rail-collapsed');
            try { localStorage.setItem(inspectorKey, String(workspace.classList.contains('rail-collapsed'))); } catch {}
        } else inspector?.classList.toggle('is-open');
        syncInspector();
        if (button.classList.contains('cc-inspector-close')) workspace?.querySelector('.cc-thread-head [data-cc-inspector]')?.focus();
        else if (!inspector?.inert) inspector?.querySelector('button,a,summary')?.focus();
    }));
    document.addEventListener('keydown', event => {
        if (event.key === 'Tab' && !inspectorDesktop.matches && inspector?.classList.contains('is-open')) {
            const controls = [...inspector.querySelectorAll('a[href],button,input,select,textarea,summary')].filter(control => !control.disabled && control.getClientRects().length);
            const first = controls[0], last = controls.at(-1);
            if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last?.focus(); }
            else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first?.focus(); }
        }
        if (event.key === 'Escape' && inspector?.classList.contains('is-open')) {
            inspector.classList.remove('is-open'); syncInspector(); workspace.querySelector('.cc-thread-head [data-cc-inspector]')?.focus();
        }
    });
    inspector?.querySelectorAll('.cc-panel').forEach((panel, index) => {
        if (index === 0 || !panel.querySelector(':scope > h3')) return;
        const heading = panel.querySelector(':scope > h3');
        const accordion = document.createElement('details'); accordion.className = panel.className;
        const summary = document.createElement('summary'); summary.append(heading); accordion.append(summary);
        while (panel.firstChild) accordion.append(panel.firstChild);
        panel.replaceWith(accordion);
    });
    const listFilter = document.querySelector('.cc-list-filter');
    if (listFilter) {
        listFilter.classList.add('is-enhanced');
        listFilter.querySelectorAll('select').forEach(select => select.addEventListener('change', () => listFilter.requestSubmit()));
        let searchTimer;
        listFilter.querySelector('input[name=search]')?.addEventListener('input', () => { clearTimeout(searchTimer); searchTimer = setTimeout(() => listFilter.requestSubmit(), 300); });
    }
    document.querySelectorAll('[data-cc-coach]').forEach(coach => {
        const key = `jm-crm-${document.body.dataset.uiUser}-${coach.dataset.ccCoach}`;
        try { if (localStorage.getItem(key)) coach.hidden = true; } catch {}
        coach.querySelector('[data-cc-dismiss-coach]')?.addEventListener('click', () => { coach.hidden = true; try { localStorage.setItem(key, 'dismissed'); } catch {} });
    });
    const composer = document.querySelector('[data-cc-composer]');
    if (composer) {
        const mode = composer.querySelector('[data-cc-mode-select]');
        const submittedMode = composer.elements.mode;
        const textArea = composer.elements.text;
        const grow = () => { textArea.style.height = 'auto'; textArea.style.height = Math.min(120, Math.max(48, textArea.scrollHeight)) + 'px'; };
        textArea.addEventListener('input', grow); grow();
        const template = composer.elements.template_id;
        let activeTab = mode.value === 'template' ? 'quick' : mode.value;
        composer.querySelectorAll('[data-cc-mode]').forEach(button => button.addEventListener('click', () => {
            activeTab = button.dataset.ccMode;
            if (activeTab === 'note') mode.value = 'note';
            else if (activeTab === 'quick' && !mode.querySelector('option[value=text]').disabled) mode.value = 'text';
            else if (activeTab === 'quick') mode.value = 'template';
            else mode.value = 'text';
            render();
            composer.querySelector('[data-cc-compose-menu]').open = false;
            textArea.focus();
        }));
        composer.querySelector('[data-cc-official]')?.addEventListener('click', () => { if (mode.querySelector('option[value=template]').disabled) return; mode.value = 'template'; activeTab = 'quick'; render(); });
        const render = () => {
            if (!mode.value || mode.selectedOptions[0]?.disabled) {
                mode.value = mode.querySelector('option:not([disabled])').value;
                activeTab = mode.value === 'template' ? 'quick' : mode.value;
            }
            submittedMode.value = mode.value;
            composer.querySelector('.cc-compose-footer button').textContent = mode.value === 'note' ? 'Simpan catatan' : 'Kirim';
            composer.querySelectorAll('[data-cc-mode]').forEach(button => { button.setAttribute('aria-pressed', String(button.dataset.ccMode === activeTab)); button.disabled = ['text','attachment'].includes(button.dataset.ccMode) && mode.querySelector('option[value=text]').disabled; });
            const quick = composer.querySelector('[data-cc-quick-panel]');
            if (quick) quick.hidden = activeTab !== 'quick';
            composer.querySelector('[data-cc-template]').hidden = mode.value !== 'template';
            composer.querySelector('[data-cc-text]').hidden = mode.value === 'template';
            composer.classList.toggle('is-internal', activeTab === 'note');
            textArea.placeholder = activeTab === 'note' ? 'Catatan internal · hanya terlihat di CRM…' : 'Ketik pesan…';
            const attachment = composer.querySelector('[data-cc-attachment]');
            if (attachment) { attachment.hidden = mode.value !== 'text' || activeTab !== 'attachment'; attachment.querySelector('input').disabled = mode.value !== 'text' || activeTab !== 'attachment'; }
            const variables = composer.querySelector('[data-cc-variables]');
            variables.replaceChildren();
            const selected = template.selectedOptions[0];
            let body = '';
            try { body = JSON.parse(selected?.dataset.components || '[]').find(c => c.type === 'BODY')?.text || ''; } catch {}
            composer.querySelector('[data-cc-template-preview]').textContent = body;
            const count = new Set([...body.matchAll(/\{\{(\d+)\}\}/g)].map(m => m[1])).size;
            for (let i = 1; i <= count; i++) {
                const label = document.createElement('label'); label.textContent = `Variabel ${i}`;
                const input = document.createElement('input'); input.name = 'variables[]'; input.required = mode.value === 'template'; input.maxLength = 500;
                label.append(input); variables.append(label);
            }
        };
        const composeMenu = composer.querySelector('[data-cc-compose-menu]');
        document.addEventListener('click', event => { if (!composeMenu.contains(event.target)) composeMenu.open = false; });
        composeMenu.addEventListener('keydown', event => { if (event.key === 'Escape') { composeMenu.open = false; composeMenu.querySelector('summary').focus(); } });
        composer.addEventListener('submit', () => { submittedMode.value = mode.value; });
        mode.addEventListener('change', () => { activeTab = mode.value === 'template' ? 'quick' : mode.value; render(); }); template.addEventListener('change', render); render();
        composer.querySelector('[data-cc-quick]').addEventListener('change', event => { if (mode.value === 'text' && event.target.value) composer.elements.text.value = event.target.value; });
    }
    const draftKey = composer ? `jm-crm-draft-${document.body.dataset.uiUser}-${composer.dataset.conversationId}` : null;
    const clearDraft = message => {
        if (!draftKey || !['SENT','DELIVERED','READ'].includes(message.status)) return;
        try {
            const draft = JSON.parse(sessionStorage.getItem(draftKey) || 'null');
            if (draft?.clientKey === message.client_key && draft.text === composer.elements.text.value) {composer.elements.text.value='';sessionStorage.removeItem(draftKey);}
        } catch {}
    };
    if (draftKey) {
        try {const draft=JSON.parse(sessionStorage.getItem(draftKey)||'null');if(draft?.text&&!composer.elements.text.value){composer.elements.text.value=draft.text;if(draft.clientKey)composer.elements.client_key.value=draft.clientKey;if(draft.mode){const select=composer.querySelector('[data-cc-mode-select]');select.value=draft.mode;select.dispatchEvent(new Event('change'));}}}catch{}
        const preserve=()=>{try{sessionStorage.setItem(draftKey,JSON.stringify({text:composer.elements.text.value,clientKey:composer.elements.client_key.value,mode:composer.elements.mode.value}));}catch{}};
        composer.elements.text.addEventListener('input',preserve);composer.addEventListener('change',preserve);composer.addEventListener('submit',preserve);composer.elements.text.dispatchEvent(new Event('input'));
    }
    const renderMessageCaption = (element, message) => {
        const timestamp = message.provider_timestamp || message.created_at;
        const time = new Date(timestamp.replace(' ', 'T') + (timestamp.includes('Z') ? '' : 'Z'))
            .toLocaleString('id-ID', {timeZone:'Asia/Jakarta',hour:'2-digit',minute:'2-digit',day:'numeric',month:'short'});
        element.textContent = time;
        if (message.direction === 'OUTBOUND') {
            const [icon, label] = {
                SENT: ['✓', 'Terkirim'], DELIVERED: ['✓✓', 'Diterima'], READ: ['✓✓', 'Dibaca'],
                FAILED: ['!', 'Gagal dikirim'], SENDING: ['◷', 'Sedang dikirim']
            }[message.status] || ['◷', 'Dalam antrean'];
            const receipt = document.createElement('span');
            receipt.className = 'cc-receipt'; receipt.dataset.status = message.status;
            receipt.classList.toggle('is-read', message.status === 'READ');
            receipt.classList.toggle('is-failed', message.status === 'FAILED');
            receipt.setAttribute('role', 'img'); receipt.setAttribute('aria-label', label); receipt.title = label;
            receipt.textContent = icon; element.append(' ', receipt);
        } else if (message.direction === 'INTERNAL') element.append(' · Tersimpan');
        if (message.error_code) element.append(' · ' + message.error_code);
    };
    const messages = document.querySelector('.cc-messages'); if (messages && !new URLSearchParams(location.search).has('before')) messages.scrollTop = messages.scrollHeight;
    const inbox = document.querySelector('[data-cc-inbox]');
    const notice = document.querySelector('[data-cc-notice]');
    let noticeTimer;
    const notify = (text, temporary = false) => {
        clearTimeout(noticeTimer);
        if (notice) {
            notice.textContent = text; notice.hidden = !text;
            if (temporary) noticeTimer = setTimeout(() => { notice.hidden = true; }, 4000);
        }
    };
    let sending = false;
    let canReply = true;
    let refreshConversation = async () => {};
    const sendButton = composer?.querySelector('.cc-compose-footer button');
    const syncSendButton = () => { if (sendButton) sendButton.disabled = sending || !canReply; };
    if (composer) composer.addEventListener('submit', async event => {
        event.preventDefault();
        if (sending || !canReply) return;
        sending = true; syncSendButton();
        const submittedText = composer.elements.text.value;
        const submittedKey = composer.elements.client_key.value;
        try {
            const response = await fetch(composer.action, {
                method: 'POST', body: new FormData(composer),
                headers: {'Accept': 'application/json'}, signal: AbortSignal.timeout(30000)
            });
            const data = await response.json().catch(() => ({}));
            if (!response.ok) {
                if ([403,404].includes(response.status)) canReply = false;
                notify(response.status === 409
                    ? 'Owner atau status percakapan berubah. Draft tetap tersimpan; periksa data terbaru sebelum mengirim kembali.'
                    : Object.values(data.errors || {}).flat().join(' ') || data.message || 'Pesan belum terkirim. Periksa akses atau koneksi Anda. Draft tetap tersimpan.');
                if ([403,404,409].includes(response.status)) await refreshConversation();
                return;
            }
            if (composer.elements.client_key.value === submittedKey) {
                composer.elements.client_key.value = crypto.randomUUID();
                if (composer.elements.text.value === submittedText) {
                    composer.elements.text.value = '';
                    composer.elements.attachment.value = '';
                    try { sessionStorage.removeItem(draftKey); } catch {}
                    composer.elements.text.dispatchEvent(new Event('input'));
                }
            }
            notify(data.status === 'SAVED' ? 'Catatan internal disimpan.' : 'Pesan masuk antrean.', true);
            await refreshConversation();
        } catch {
            notify('Pengiriman belum dapat dipastikan. Draft tetap tersimpan. Periksa koneksi sebelum mencoba kembali.');
        } finally { sending = false; syncSendButton(); }
    });
    if (inbox && composer) {
        const status = document.querySelector('[data-cc-live-status]');
        let inFlight = false;
        refreshConversation = async () => {
            if (inFlight) return;
            inFlight = true;
            try {
                const response = await fetch(inbox.dataset.pollUrl, {headers: {'Accept':'application/json'}, cache: 'no-store', signal: AbortSignal.timeout(15000)});
                if ([401,403,404].includes(response.status)) {
                    canReply = false; syncSendButton();
                    notify('Akses percakapan berubah atau sesi berakhir. Draft tetap tersimpan. Buka kembali inbox untuk melanjutkan.');
                    return;
                }
                if (!response.ok) throw new Error('Koneksi terputus; pembaruan akan dicoba lagi.');
                const data = await response.json();
                if (data.selected) {
                    if (Number(composer.elements.version.value) !== data.selected.version) {
                        notify('Owner atau status percakapan diperbarui. Draft tetap tersimpan; periksa perubahan sebelum mengirim.');
                    }
                    document.querySelectorAll('.cc-thread input[name=version]').forEach(input => input.value = data.selected.version);
                    canReply = data.selected.can_reply === true;
                    syncSendButton();
                    const owner = document.querySelector('[data-cc-owner]');
                    if (owner) owner.textContent = data.selected.owner_name || 'Belum ditugaskan';
                    const state = document.querySelector('[data-cc-conversation-status]');
                    if (state) state.textContent = data.selected.status;
                    const closeStatus = document.querySelector('.cc-thread-menu input[name=status]');
                    if (closeStatus) {
                        closeStatus.value = data.selected.status === 'OPEN' ? 'CLOSED' : 'OPEN';
                        closeStatus.closest('details').querySelector('summary').textContent = data.selected.status === 'OPEN' ? 'Tutup percakapan' : 'Buka kembali percakapan';
                    }
                    const windowOpen = composer.dataset.provider === 'QR_WEB_SESSION' || new Date((data.selected.service_window_expires_at || '').replace(' ', 'T') + 'Z') > new Date();
                    composer.querySelector('[data-cc-mode-select] option[value=text]').disabled = !windowOpen;
                    if (!canReply) notify('Percakapan ditutup atau Anda bukan owner aktif. Draft tetap tersimpan.');
                }
                if (new URLSearchParams(location.search).has('before')) return;
                const nearBottom = messages.scrollHeight - messages.scrollTop - messages.clientHeight < 100;
                for (const message of data.messages || []) {
                    clearDraft(message);
                    const old = messages.querySelector(`[data-message-id="${message.id}"]`);
                    if (old) { renderMessageCaption(old.querySelector('small'), message); continue; }
                    const item = document.createElement('article'); item.dataset.messageId = message.id; item.className = 'cc-message';
                    item.classList.toggle('outbound', message.direction === 'OUTBOUND'); item.classList.toggle('internal', message.direction === 'INTERNAL');
                    if (message.direction === 'INTERNAL') { const label = document.createElement('strong'); label.textContent = 'Catatan internal · tidak dikirim'; item.append(label); }
                    const text = document.createElement('p'); text.textContent = message.text; const small = document.createElement('small'); renderMessageCaption(small, message); item.append(text, small); if (message.direction === 'INBOUND' && ['image','document'].includes(message.message_type)) {
                        const link = document.createElement('a');
                        link.href = composer.action.replace(/\/send$/, `/messages/${Number(message.id)}/attachment`);
                        link.textContent = 'Unduh lampiran (pemeriksaan keamanan)'; item.append(link);
                    }
                    messages.append(item);
                }
                if (nearBottom) messages.scrollTop = messages.scrollHeight;
                status.textContent = 'Tersinkron · ' + new Date().toLocaleTimeString('id-ID', {hour:'2-digit', minute:'2-digit'});
            } catch (error) { status.textContent = error.message || 'Koneksi terputus; mencoba lagi.'; }
            finally { inFlight = false; }
        };
        setInterval(() => { if (!document.hidden && !sending) refreshConversation(); }, 5000);
        document.addEventListener('visibilitychange', () => { if (!document.hidden && !sending) refreshConversation(); });
        refreshConversation();
    }
    const board = document.querySelector('[data-cc-board]');
    if (board) {
        let pan = null;
        board.addEventListener('pointerdown', event => {
            if (event.button !== 0 || event.pointerType === 'touch' || event.target.closest('.cc-lead-card,a,button,input,select,textarea,summary,dialog')) return;
            pan = {id:event.pointerId,x:event.clientX,scroll:board.scrollLeft,active:false};
        });
        board.addEventListener('pointermove', event => {
            if (!pan || pan.id !== event.pointerId) return;
            if (!pan.active && Math.abs(event.clientX - pan.x) < 5) return;
            pan.active = true; board.setPointerCapture(event.pointerId); board.classList.add('is-panning');
            event.preventDefault(); board.scrollLeft = pan.scroll - (event.clientX - pan.x);
        });
        const stopPan = () => { pan = null; board.classList.remove('is-panning'); };
        board.addEventListener('pointerup', stopPan);
        board.addEventListener('pointercancel', stopPan);
        board.addEventListener('lostpointercapture', stopPan);
        window.addEventListener('pointerup', stopPan);
        const select = document.createElement('select'); select.className = 'cc-mobile-stages'; select.setAttribute('aria-label', 'Tahap pipeline mobile');
        board.querySelectorAll('[data-stage]').forEach((column, index) => { const option = new Option(column.querySelector('h2').textContent, column.dataset.stage); select.append(option); column.classList.toggle('mobile-active', index === 0); });
        const style = document.createElement('style'); style.textContent = '.cc-mobile-stages{display:none}@media(max-width:767px){.cc-mobile-stages{display:block;width:100%;min-height:44px;margin:12px 0}}'; document.head.append(style);
        select.addEventListener('change', () => board.querySelectorAll('[data-stage]').forEach(column => column.classList.toggle('mobile-active', column.dataset.stage === select.value))); board.before(select);
        let card = null;
        let saving = false;
        const feedback = document.querySelector('[data-cc-feedback]');
        const move = async (moving, column) => {
            if (saving || !column || moving.closest('[data-stage]') === column) return;
            let reason = null;
            if (column.dataset.stage === 'LOST') {
                const dialog = document.getElementById('cc-lost'); dialog.returnValue = ''; dialog.showModal();
                const value = await new Promise(resolve => dialog.addEventListener('close', () => resolve(dialog.returnValue), {once:true}));
                if (value !== 'save') return;
                reason = dialog.querySelector('select').value;
            }
            saving = true;
            const original = moving.parentElement;
            const next = moving.nextSibling;
            const source = original.closest('[data-stage]');
            const counts = [source, column].map(item => item.querySelector('header b'));
            const oldCounts = counts.map(item => item.textContent);
            moving.classList.add('cp-moving');
            column.querySelector('.cc-column-cards').prepend(moving);
            counts[0].textContent = Math.max(0, Number(oldCounts[0]) - 1);
            counts[1].textContent = Number(oldCounts[1]) + 1;
            feedback.className = ''; feedback.textContent = 'Menyimpan tahap…';
            try {
                const response = await fetch(moving.dataset.url, {method:'POST',headers:{'Content-Type':'application/json','Accept':'application/json','X-CSRF-TOKEN':board.dataset.token},body:JSON.stringify({stage:column.dataset.stage,version:Number(moving.dataset.version),reason})});
                const result = await response.json();
                if (!response.ok) throw new Error(response.status === 409 ? 'Lead telah diubah pengguna lain. Muat ulang sebelum mencoba lagi.' : Object.values(result.errors || {}).flat()[0] || result.message || 'Tahap gagal diperbarui.');
                moving.dataset.version = result.version;
                const activity = moving.querySelector('[data-cp-show=activity]');
                if (activity) activity.textContent = 'Diperbarui baru saja';
                feedback.textContent = 'Tahap berhasil diperbarui.';
                const targets = JSON.parse(board.dataset.transitions || '{}')[result.stage] || [];
                const fallback = moving.querySelector('[data-cp-move]');
                if (fallback) {
                    fallback.replaceChildren(new Option('Pilih tahap…', ''));
                    targets.forEach(stage => fallback.add(new Option(board.querySelector(`[data-stage="${stage}"] h2`)?.textContent || stage, stage)));
                    fallback.closest('details').hidden = !targets.length;
                }
                moving.draggable = result.stage !== 'CONVERTED';
                moving.classList.add('cc-drop-success');
                setTimeout(() => moving.classList.remove('cc-drop-success'), 180);
                board.querySelectorAll('[data-stage]').forEach(stage => {
                    const empty = stage.querySelector('.cc-column-empty');
                    if (empty) empty.hidden = !!stage.querySelector('[data-id]');
                });
            } catch (error) {
                original.insertBefore(moving, next);
                counts.forEach((item, index) => item.textContent = oldCounts[index]);
                feedback.className = 'errors'; feedback.textContent = error.message;
            } finally { moving.classList.remove('cp-moving'); saving = false; }
        };
        board.addEventListener('change', async event => {
            const select = event.target.closest('[data-cp-move]');
            if (!select || !select.value) return;
            await move(select.closest('[data-id]'), board.querySelector(`[data-stage="${select.value}"]`));
            select.value = '';
        });
        board.addEventListener('dragstart', event => {
            card = event.target.closest('[draggable="true"]');
            if (!card || saving || event.target.closest('a,button,input,select,textarea,summary')) { event.preventDefault(); return; }
            card.classList.add('is-dragging');
            event.dataTransfer.setData('text/plain', card.dataset.id); event.dataTransfer.effectAllowed = 'move';
        });
        board.addEventListener('dragend', () => { board.querySelectorAll('.is-dragging').forEach(item => item.classList.remove('is-dragging')); card = null; board.querySelectorAll('.dragover').forEach(column => column.classList.remove('dragover')); });
        board.querySelectorAll('[data-stage]').forEach(column => {
            column.addEventListener('dragover', event => {
                if (!card || saving) return;
                event.preventDefault(); column.classList.add('dragover');
                const rect = board.getBoundingClientRect();
                if (event.clientX < rect.left + 56) board.scrollLeft -= 20;
                if (event.clientX > rect.right - 56) board.scrollLeft += 20;
            });
            column.addEventListener('dragleave', event => { if (!column.contains(event.relatedTarget)) column.classList.remove('dragover'); });
            column.addEventListener('drop', async event => {
                event.preventDefault(); column.classList.remove('dragover');
                const moving = card; card = null;
                if (moving) await move(moving, column);
            });
        });
    }
})();
(() => {
    const button = document.querySelector('[data-cc-meta-connect]');
    if (!button) return;
    const status = document.querySelector('[data-cc-meta-status]');
    button.addEventListener('click', async () => {
        button.disabled = true;
        let listener;
        try {
            const request = async (url, body) => {
                const response = await fetch(url, {method: 'POST', headers: {'Content-Type':'application/json', 'Accept':'application/json', 'X-CSRF-TOKEN':button.dataset.token},body:JSON.stringify(body)});
                const data = await response.json(); if (!response.ok) throw new Error(data.message || 'Koneksi gagal.'); return data;
            };
            status.textContent = 'Menyiapkan otorisasi Meta…';
            const setup = await request(button.dataset.start, {});
            if (!window.FB) await new Promise((resolve, reject) => { const script = document.createElement('script'); script.src = 'https://connect.facebook.net/en_US/sdk.js'; script.onload = resolve; script.onerror = reject; document.head.append(script); });
            window.FB.init({appId: setup.app_id, autoLogAppEvents: false, xfbml: false, version: setup.version});
            let result = null; let code = null; let saving = false;
            const finish = async () => {
                if (!result || !code || saving) return;
                saving = true; window.removeEventListener('message', listener);
                try {
                    await request(button.dataset.callback, {state: setup.state, code, waba_id: result.waba_id, phone_number_id: result.phone_number_id});
                    location.reload();
                } catch (error) { status.textContent = error.message; button.disabled = false; }
            };
            listener = event => {
                let origin; try { origin = new URL(event.origin); } catch { return; }
                if (origin.protocol !== 'https:' || !(origin.hostname === 'facebook.com' || origin.hostname.endsWith('.facebook.com'))) return;
                let data; try { data = typeof event.data === 'string' ? JSON.parse(event.data) : event.data; } catch { return; }
                if (data?.type !== 'WA_EMBEDDED_SIGNUP') return;
                if (data.event === 'FINISH') { result = data.data; finish(); }
                else if (data.event === 'CANCEL' || data.event === 'ERROR') { status.textContent = 'Koneksi dibatalkan. Anda dapat mencoba lagi.'; button.disabled = false; window.removeEventListener('message', listener); }
            };
            window.addEventListener('message', listener);
            status.textContent = 'Selesaikan pemilihan akun dan nomor pada jendela Meta.';
            window.FB.login(response => { code = response.authResponse?.code; if (!code) { status.textContent = 'Otorisasi dibatalkan.'; button.disabled = false; window.removeEventListener('message', listener); } else finish(); }, {config_id: setup.config_id, response_type:'code', override_default_response_type:true, extras:{setup:{}, sessionInfoVersion:'3'}});
            setTimeout(() => { window.removeEventListener('message', listener); button.disabled = false; }, 600000);
        } catch (error) { status.textContent = error.message || 'SDK Meta tidak dapat dimuat.'; button.disabled = false; if (listener) window.removeEventListener('message', listener); }
    });
})();
