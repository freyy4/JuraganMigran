(() => {
    document.querySelectorAll('[data-cc-open]').forEach(button => button.addEventListener('click', () => document.getElementById(button.dataset.ccOpen)?.showModal()));
    document.querySelectorAll('[data-cc-close]').forEach(button => button.addEventListener('click', () => button.closest('dialog').close()));
    document.querySelectorAll('[data-cc-inspector]').forEach(button => button.addEventListener('click', () => document.querySelector('.cc-inspector')?.classList.toggle('is-open')));
    document.querySelectorAll('[data-cc-coach]').forEach(coach => {
        const key = `jm-crm-${document.body.dataset.uiUser}-${coach.dataset.ccCoach}`;
        try { if (localStorage.getItem(key)) coach.hidden = true; } catch {}
        coach.querySelector('[data-cc-dismiss-coach]')?.addEventListener('click', () => { coach.hidden = true; try { localStorage.setItem(key, 'dismissed'); } catch {} });
    });
    const composer = document.querySelector('[data-cc-composer]');
    if (composer) {
        const mode = composer.elements.mode;
        const template = composer.elements.template_id;
        const render = () => {
            composer.querySelector('[data-cc-template]').hidden = mode.value !== 'template';
            composer.querySelector('[data-cc-text]').hidden = mode.value === 'template';
            const attachment = composer.querySelector('[data-cc-attachment]');
            if (attachment) { attachment.hidden = mode.value !== 'text'; attachment.querySelector('input').disabled = mode.value !== 'text'; }
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
        mode.addEventListener('change', render); template.addEventListener('change', render); render();
        composer.querySelector('[data-cc-quick]').addEventListener('change', event => { if (mode.value === 'text' && event.target.value) composer.elements.text.value = event.target.value; });
    }
    const draftKey = composer?.dataset.provider === 'QR_WEB_SESSION' ? `jm-crm-draft-${document.body.dataset.uiUser}-${composer.dataset.conversationId}` : null;
    const clearDraft = message => {
        if (!draftKey || !['SENT','DELIVERED','READ'].includes(message.status)) return;
        try {
            const draft = JSON.parse(sessionStorage.getItem(draftKey) || 'null');
            if (draft?.clientKey === message.client_key && draft.text === composer.elements.text.value) {composer.elements.text.value='';sessionStorage.removeItem(draftKey);}
        } catch {}
    };
    if (draftKey) {
        try {const draft=JSON.parse(sessionStorage.getItem(draftKey)||'null');if(draft?.text&&!composer.elements.text.value)composer.elements.text.value=draft.text;}catch{}
        const preserve=()=>{if(composer.elements.mode.value!=='text')return;try{sessionStorage.setItem(draftKey,JSON.stringify({text:composer.elements.text.value,clientKey:composer.elements.client_key.value}));}catch{}};
        composer.elements.text.addEventListener('input',preserve);composer.addEventListener('submit',preserve);
    }
    const messages = document.querySelector('.cc-messages'); if (messages && !new URLSearchParams(location.search).has('before')) messages.scrollTop = messages.scrollHeight;
    const inbox = document.querySelector('[data-cc-inbox]');
    if (inbox && composer && !new URLSearchParams(location.search).has('before')) {
        const status = document.querySelector('[data-cc-live-status]');
        let inFlight = false;
        setInterval(async () => {
            if (document.hidden || inFlight) return;
            inFlight = true;
            try {
                const response = await fetch(inbox.dataset.pollUrl, {headers: {'Accept':'application/json'}});
                if (!response.ok) throw new Error('Muat ulang untuk memeriksa akses percakapan.');
                const data = await response.json();
                if (data.selected && Number(composer.elements.version.value) !== data.selected.version) {
                    composer.querySelector('button').disabled = true;
                    status.textContent = 'Owner atau status berubah. Muat ulang sebelum membalas.';
                    return;
                }
                const nearBottom = messages.scrollHeight - messages.scrollTop - messages.clientHeight < 100;
                for (const message of data.messages || []) {
                    clearDraft(message);
                    const old = messages.querySelector(`[data-message-id="${message.id}"]`);
                    const caption = new Date((message.provider_timestamp || message.created_at).replace(' ', 'T') + ((message.provider_timestamp || message.created_at).includes('Z') ? '' : 'Z')).toLocaleString('id-ID', {timeZone:'Asia/Jakarta',hour:'2-digit',minute:'2-digit',day:'numeric',month:'short'}) + ' · ' + message.status + (message.error_code ? ' · ' + message.error_code : '');
                    if (old) { old.querySelector('small').textContent = caption; continue; }
                    const item = document.createElement('article'); item.dataset.messageId = message.id; item.className = 'cc-message';
                    item.classList.toggle('outbound', message.direction === 'OUTBOUND'); item.classList.toggle('internal', message.direction === 'INTERNAL');
                    if (message.direction === 'INTERNAL') { const label = document.createElement('strong'); label.textContent = 'Catatan internal · tidak dikirim'; item.append(label); }
                    const text = document.createElement('p'); text.textContent = message.text; const small = document.createElement('small'); small.textContent = caption; item.append(text, small); messages.append(item);
                }
                if (nearBottom) messages.scrollTop = messages.scrollHeight;
                status.textContent = 'Tersinkron · ' + new Date().toLocaleTimeString('id-ID', {hour:'2-digit', minute:'2-digit'});
            } catch (error) { status.textContent = error.message || 'Koneksi terputus; mencoba lagi.'; }
            finally { inFlight = false; }
        }, 10000);
    }
    const board = document.querySelector('[data-cc-board]');
    if (board) {
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
                feedback.textContent = 'Tahap berhasil diperbarui.';
                location.reload();
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
            if (!card || saving) { event.preventDefault(); return; }
            event.dataTransfer.setData('text/plain', card.dataset.id); event.dataTransfer.effectAllowed = 'move';
        });
        board.addEventListener('dragend', () => { card = null; board.querySelectorAll('.dragover').forEach(column => column.classList.remove('dragover')); });
        board.querySelectorAll('[data-stage]').forEach(column => {
            column.addEventListener('dragover', event => {
                if (!card || saving) return;
                event.preventDefault(); column.classList.add('dragover');
                const rect = board.getBoundingClientRect();
                if (event.clientX < rect.left + 70) board.scrollLeft -= 20;
                if (event.clientX > rect.right - 70) board.scrollLeft += 20;
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
