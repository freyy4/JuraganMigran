(() => {
    const configNode = document.getElementById('meta-connect-config');
    if (!configNode) return;
    const config = JSON.parse(configNode.textContent), wizard = document.querySelector('[data-mc-wizard]'), content = wizard.querySelector('[data-mc-content]'), feedback = wizard.querySelector('[data-mc-feedback]'), status = document.querySelector('[data-mc-status]');
    const labels = {CONNECTED:'Terhubung',TEST_REQUIRED:'Perlu uji pesan',DISCONNECTED:'Diputuskan',REAUTH_REQUIRED:'Hubungkan ulang',DEGRADED:'Perlu diperiksa',FAILED:'Koneksi gagal'};
    let session = null, selection = {}, channel = null, timer = null, popup = null, authorizationUrl = null, testRequest = null, revision = 0;
    const element = (tag, text, className) => { const node = document.createElement(tag); if (text != null) node.textContent = text; if (className) node.className = className; return node; };
    const request = async (path, body) => {
        const response = await fetch(config.base + path, {method:body === undefined ? 'GET':'POST', headers:{Accept:'application/json','Content-Type':'application/json','X-CSRF-TOKEN':config.token}, ...(body === undefined ? {} : {body:JSON.stringify(body)})});
        let result; try { result = await response.json(); } catch { throw Error('Koneksi belum dapat diproses. Muat ulang halaman dan coba lagi.'); }
        if (!response.ok) { const error = Error((Object.values(result.errors || {}).flat()[0] || result.message || 'Permintaan belum berhasil.') + (result.code ? ' (' + result.code + ')' : '')); error.code = result.code; throw error; }
        return result;
    };
    const button = (text, action, secondary = false) => { const node = element('button', text, secondary ? 'secondary':''); node.type = 'button'; node.addEventListener('click', async () => { node.disabled = true; feedback.textContent = ''; try { await action(); } catch (error) { feedback.textContent = error.message; status.textContent = error.message; } finally { node.disabled = false; } }); return node; };
    const field = (label, name, value = '', type = 'text') => { const wrap = element('label', label), input = element('input'); input.name = name; input.type = type; input.value = value; input.required = true; wrap.append(input); return wrap; };
    function page(step, title) {
        clearInterval(timer); revision++; wizard.hidden = false; const heading = element('h3', title); heading.tabIndex = -1; content.replaceChildren(heading); feedback.textContent = ''; heading.focus({preventScroll:true}); wizard.scrollIntoView({block:'start'});
        wizard.querySelectorAll('.mc-stepper li').forEach((node,index) => { if (index === step) node.setAttribute('aria-current','step'); else node.removeAttribute('aria-current'); });
    }
    const updateChannel = value => { channel = value; const index = config.channels.findIndex(row => row.id === value.id); if (index < 0) config.channels.push(value); else config.channels[index] = value; renderChannels(); };
    const formatDate = value => value ? new Date(value.replace(' ','T') + (/[Z+]/.test(value) ? '' : 'Z')).toLocaleString('id-ID',{timeZone:'Asia/Jakarta'}) + ' WIB' : 'Belum tersedia';
    function renderChannels() {
        const list = document.querySelector('[data-mc-channels]'); list.replaceChildren();
        if (!config.channels.length) { list.append(element('p','Belum terhubung. Hubungkan nomor WhatsApp Business resmi untuk mulai menerima pesan.')); return; }
        for (const row of config.channels) {
            const card = element('article',null,'mc-channel'); card.append(element('h3',row.name),element('p',row.display_phone_number),element('span',labels[row.status] || 'Perlu diperiksa','mc-state' + (row.status === 'CONNECTED' ? ' connected':'')));
            const details = element('dl');
            for (const [label,value] of (config.compact ? [['Tim',row.team_id ? 'Tim CS '+row.team_id:'Antrean umum'],['Terakhir diperiksa',formatDate(row.last_health_check_at)]] : [['Nama terverifikasi',row.verified_name || 'Belum tersedia'],['Kualitas',row.quality_rating || 'Belum tersedia'],['Webhook',row.webhook_ready ? 'Aktif':'Belum aktif'],['Tim',row.team_id ? 'Tim CS '+row.team_id:'Antrean umum'],['Routing',row.routing_method === 'ROUND_ROBIN' ? 'Round Robin':row.routing_method === 'LEAST_ACTIVE' ? 'Least Active':'Manual Queue'],['Terakhir diperiksa',formatDate(row.last_health_check_at)]])) { const item = element('div'); item.append(element('dt',label),element('dd',value)); details.append(item); }
            card.append(details); const actions = element('div',null,'actions');
            if (config.test) {
                actions.append(button('Periksa koneksi',async () => { const result = await request('/'+row.id+'/health',{}); updateChannel(result.channel); status.textContent = 'Status koneksi diperbarui.'; },true));
                if (row.is_active && row.webhook_ready) actions.append(button('Test Pesan',async () => { channel = row; await tests(); },true));
            }
            if (config.manage) actions.append(button(row.connection_method === 'MANUAL_LEGACY' ? 'Upgrade ke Meta Connect':'Hubungkan ulang',() => start(row.id),true));
            if (config.manage && row.status === 'CONNECTED') actions.append(button('Tim & routing',() => {channel = row; routing();},true));
            if (config.disconnect && row.is_active) actions.append(button('Putuskan',async () => {
                const dialog = document.getElementById('mc-disconnect'); dialog.returnValue = ''; dialog.showModal();
                const answer = await new Promise(resolve => dialog.addEventListener('close',() => resolve(dialog.returnValue),{once:true}));
                if (answer !== 'disconnect') return;
                const result = await request('/'+row.id+'/disconnect',{confirm:true}); updateChannel(result.channel); wizard.hidden = true; clearInterval(timer); status.textContent = 'Channel diputuskan. Riwayat chat tetap tersimpan.';
            },true));
            card.append(actions); list.append(card);
        }
    }
    async function start(channelId = null) {
        popup = window.open('about:blank','jm-meta-connect','width=650,height=760');
        status.textContent = 'Memeriksa kesiapan koneksi Meta…';
        try {
            const preflight = await request('/meta/preflight'); if (!preflight.ready) throw Error(preflight.message + ' (' + preflight.code + ')');
            const result = await request('/meta/connect/start',{channelId}); session = result.sessionId; selection = {}; channel = null; authorizationUrl = result.authorizationUrl;
            page(0,'Login menggunakan akun Meta Admin');
            content.append(element('p','Selesaikan login pada jendela Meta, lalu kembali ke halaman ini.'));
            if (resumeButton) {resumeButton.hidden=false;resumeButton.onclick=()=>resume(session).catch(error=>status.textContent=error.message);}
            if (popup) popup.location.href = authorizationUrl;
            else content.append(element('p','Popup diblokir browser. Buka login di tab baru untuk melanjutkan.'));
            const link = element('a','Buka login Meta di tab baru'); link.href = authorizationUrl; link.target = '_blank'; link.rel = 'noopener noreferrer'; content.append(link);
            content.append(button('Saya sudah login — lanjutkan',() => resume(session),true));
            timer = setInterval(async () => { try { const state = await request('/meta/connect/'+session); if (state.status !== 'AUTH_PENDING' && state.status !== 'EXCHANGING') await resume(session); } catch(error) { clearInterval(timer); feedback.textContent = error.message; } },3000);
        } catch(error) { if (popup) popup.close(); throw error; }
    }
    async function resume(id) {
        const state = await request('/meta/connect/'+id); session = id; selection = state.selection || {};
        if (state.channel) { channel = state.channel; updateChannel(channel); if (config.test && channel.is_active) return tests(); return done(); }
        if (['AUTH_PENDING','EXCHANGING'].includes(state.status)) { status.textContent = 'Selesaikan login Meta terlebih dahulu.'; return; }
        if (['FAILED','PERMISSION_REQUIRED','REAUTH_REQUIRED','DISCONNECTED'].includes(state.status)) { clearInterval(timer); throw Error(state.message || 'Hubungkan kembali untuk memperbarui sesi Meta.'); }
        if (selection.wabaId && selection.phoneNumberId) {
            const result = await request('/meta/connect/'+session+'/phones?'+new URLSearchParams({businessId:selection.businessId,wabaId:selection.wabaId}));
            const phone = result.items.find(item => String(item.id) === selection.phoneNumberId);
            if (phone) return confirmPhone(phone);
            return phones();
        }
        await businesses();
    }
    async function choices(step,title,path,onSelect,describe,emptyMessage) {
        page(step,title); const current = revision; content.append(element('p','Memuat pilihan dari Meta…'));
        const result = await request(path); if (current !== revision) return;
        content.lastChild.remove();
        if (!result.items.length) {
            content.append(element('p',emptyMessage)); const link = element('a','Buka pengaturan Meta ↗'); link.href = 'https://business.facebook.com/settings/'; link.target = '_blank'; link.rel = 'noopener noreferrer'; content.append(link);
        }
        const options = element('div',null,'mc-options'); options.dataset.mcOptions = '';
        result.items.forEach(item => { const node = button(item.name || item.display_phone_number || item.verified_name,() => onSelect(item),true); node.append(element('small',describe(item))); options.append(node); }); content.append(options);
        content.append(button('Muat ulang',() => choices(step,title,path,onSelect,describe,emptyMessage),true));
        if (step > 1) content.append(button('Kembali',step === 2 ? businesses:wabas,true));
    }
    const masked = id => '•••• ' + String(id).slice(-4);
    const businesses = () => choices(1,'Pilih bisnis yang akan dihubungkan','/meta/connect/'+session+'/businesses',async item => { selection = {businessId:item.id}; await request('/meta/connect/'+session+'/select',selection); await wabas(); },item => 'Business ID: '+masked(item.id),'Kami belum menemukan bisnis yang dapat diakses. Pastikan akun Meta Anda memiliki akses ke bisnis Juragan Migran.');
    const wabas = () => choices(2,'Pilih akun WhatsApp Business','/meta/connect/'+session+'/wabas?businessId='+encodeURIComponent(selection.businessId),async item => { selection.wabaId = item.id; delete selection.phoneNumberId; await phones(); },item => 'Akun: '+masked(item.id),'Akun WhatsApp Business belum tersedia. Siapkan akun di Meta, lalu kembali dan muat ulang.');
    const phones = () => choices(3,'Pilih nomor WhatsApp','/meta/connect/'+session+'/phones?'+new URLSearchParams({businessId:selection.businessId,wabaId:selection.wabaId}),async item => { selection.phoneNumberId = String(item.id); await request('/meta/connect/'+session+'/select',selection); confirmPhone(item); },item => (item.verified_name || 'Nama belum tersedia')+' · Kualitas: '+(item.quality_rating || 'Belum tersedia')+' · '+(item.code_verification_status === 'VERIFIED' ? 'Terverifikasi':'Perlu verifikasi'),'Belum ada nomor. Tambahkan atau verifikasi nomor melalui Meta, lalu muat ulang.');
    function confirmPhone(phone) {
        page(3,'Konfirmasi nomor dan hubungkan webhook'); content.append(element('p',phone.display_phone_number+' · '+(phone.verified_name || 'WhatsApp Business')));
        const form = element('form'); form.append(field('Nama channel','channelName',phone.verified_name || 'JM - CS Utama')); form.elements.channelName.maxLength = 100;
        if (phone.code_verification_status !== 'VERIFIED') { content.append(element('p','Nomor belum terverifikasi. Selesaikan verifikasi di Meta, lalu muat ulang.')); content.append(button('Muat ulang nomor',phones,true)); return; }
        if (phone.platform_type !== 'CLOUD_API') {
            content.append(element('p','Nomor perlu diregistrasikan. Masukkan PIN verifikasi dua langkah; PIN tidak disimpan.'));
            const register = element('form'); register.append(field('PIN enam digit','pin','','password')); register.elements.pin.pattern = '[0-9]{6}'; register.elements.pin.maxLength = 6; register.elements.pin.inputMode = 'numeric'; register.elements.pin.autocomplete = 'new-password';
            const send = element('button','Registrasikan nomor'); send.type = 'submit'; register.append(send); register.addEventListener('submit',async event => { event.preventDefault(); send.disabled = true; try { await request('/meta/connect/'+session+'/register',{...selection,pin:register.elements.pin.value}); register.reset(); await phones(); } catch(error) { feedback.textContent = error.message; } finally { send.disabled = false; } }); content.append(register); return;
        }
        const confirm = element('label'); const check = element('input'); check.type = 'checkbox'; confirm.append(check,document.createTextNode('Jika nomor berbeda dari channel lama, buat channel baru dan simpan riwayat lama.')); form.append(confirm);
        const send = element('button','Periksa izin & hubungkan'); send.type = 'submit'; form.append(send);
        form.addEventListener('submit',async event => { event.preventDefault(); send.disabled = true; feedback.textContent = 'Memeriksa izin dan penerimaan pesan…'; try { const result = await request('/meta/connect/'+session+'/finalize',{...selection,channelName:form.elements.channelName.value,confirmNewChannel:check.checked}); updateChannel(result.channel); if(config.test) await tests(); else done(); } catch(error) { feedback.textContent = error.message; } finally { send.disabled = false; } }); content.append(form,button('Pilih nomor lain',phones,true));
    }
    async function tests() {
        page(4,'Uji pesan masuk dan keluar'); const id = channel.id, current = revision;
        content.append(element('p','Kirim pesan TEST JM dari nomor uji Anda ke '+channel.display_phone_number+'. Setelah diterima, balasan uji dapat dikirim dalam window layanan.'));
        const summary = element('div',null,'mc-test-summary'); content.append(summary);
        const form = element('form'); form.append(field('Nomor penerima uji','recipient','','tel'));
        const templateLabel = element('label','Template resmi (jika window layanan belum terbuka)'), template = element('select'); template.name = 'templateId'; template.append(new Option('Tanpa template — gunakan window layanan','')); templateLabel.append(template); form.append(templateLabel);
        const variables = element('div'); form.append(variables); let templates = [];
        template.addEventListener('change',() => {variables.replaceChildren();const selected = templates.find(row => String(row.id) === template.value); let components = [];try{components = JSON.parse(selected?.components || '[]');}catch{}const body = components.find(row => row.type === 'BODY')?.text || '';variables.append(element('p',body)); const count = new Set([...body.matchAll(/\{\{(\d+)\}\}/g)].map(match => match[1])).size;for(let i=1;i<=count;i++)variables.append(field('Variabel '+i,'variable'+i));testRequest = null;});
        const consent = element('label'), check = element('input'); check.type = 'checkbox'; check.required = true; consent.append(check,document.createTextNode('Saya berwenang mengirim pesan ke nomor uji ini.')); form.append(consent);
        const send = element('button','Kirim Pesan Test'); send.type = 'submit'; form.append(send); content.append(form);
        form.addEventListener('input',() => testRequest = null);
        form.addEventListener('submit',async event => {event.preventDefault();send.disabled = true;testRequest ||= crypto.randomUUID();try{await request('/'+id+'/test-message',{requestId:testRequest,recipient:form.elements.recipient.value,consent:check.checked,templateId:template.value || null,variables:[...variables.querySelectorAll('input')].map(input => input.value)});testRequest = null;await refresh();feedback.textContent = 'Pesan uji dikirim. Status penerimaan akan diperbarui dari webhook.';}catch(error){feedback.textContent = error.message;}finally{send.disabled = false;}});
        const next = button('Lanjut ke tim CS',routing); next.hidden = !config.manage; next.disabled = true; content.append(next);
        async function refresh() {
            const result = await request('/'+id+'/tests'); if (revision !== current || channel.id !== id) return;
            updateChannel(result.channel); templates = result.templates;
            const old = template.value; template.replaceChildren(new Option('Tanpa template — gunakan window layanan','')); templates.forEach(row => template.append(new Option(row.name+' · '+row.language,row.id))); template.value = old;
            summary.replaceChildren(element('p',channel.inbound_test_at ? '✓ Pesan masuk diterima CRM':'Menunggu pesan TEST JM masuk…'),element('p',channel.outbound_test_at ? '✓ Pesan keluar diterima API Meta':'Pesan keluar belum berhasil'));
            for(const test of result.tests) summary.append(element('p',test.recipient_masked+' · '+test.status+(test.error_code ? ' · '+test.error_code:'')));
            next.disabled = !(channel.inbound_test_at && channel.outbound_test_at);
        }
        await refresh(); timer = setInterval(() => {if (!document.hidden) refresh().catch(error => feedback.textContent = error.message);},5000);
    }
    function routing() {
        page(5,'Pilih tim CS dan aturan pembagian'); const form = element('form'); form.append(field('Nama channel','name',channel.name));
        const teamLabel = element('label','Tim penerima'), team = element('select'); team.name = 'team_id'; team.append(new Option('Antrean umum — semua CS eligible','')); config.teams.forEach(row => team.append(new Option(row.name+' · '+row.members+' CS aktif',row.id))); team.value = channel.team_id || ''; teamLabel.append(team); form.append(teamLabel);
        const routeLabel = element('label','Metode pembagian'), method = element('select'); method.name = 'routing_method'; [['ROUND_ROBIN','Round Robin'],['LEAST_ACTIVE','Percakapan aktif paling sedikit'],['MANUAL','Antrean manual']].forEach(([value,label]) => method.append(new Option(label,value))); method.value = channel.routing_method; routeLabel.append(method); form.append(routeLabel);
        for(const [key,label] of [['prefer_existing_owner','Utamakan CS yang sudah menangani lead'],['strict_owner','Hanya owner yang dapat membalas']]){const wrap=element('label',label),input=element('input');input.type='checkbox';input.name=key;input.checked=!!channel[key];wrap.prepend(input);form.append(wrap);}
        let hours = {};try{hours = JSON.parse(channel.business_hours || '{}');}catch{}
        form.append(field('Jam mulai layanan (WIB, Senin–Jumat)','hours_start',hours?.start || '','time'),field('Jam selesai layanan (WIB)','hours_end',hours?.end || '','time'));form.elements.hours_start.required=false;form.elements.hours_end.required=false;
        const send = element('button','Simpan & aktifkan channel');send.type='submit';form.append(send);
        form.addEventListener('submit',async event => {event.preventDefault();send.disabled=true;try{const data=Object.fromEntries(new FormData(form));data.team_id=data.team_id || null;data.prefer_existing_owner=form.elements.prefer_existing_owner.checked;data.strict_owner=form.elements.strict_owner.checked;const result=await request('/'+channel.id+'/routing',data);updateChannel(result.channel);done();}catch(error){feedback.textContent=error.message;}finally{send.disabled=false;}});content.append(form);
    }
    function done(){page(5,channel.status === 'CONNECTED' ? 'WhatsApp berhasil terhubung':'Koneksi tersimpan — lanjutkan uji pesan');content.append(element('p',channel.name+' · '+channel.display_phone_number));if(channel.status==='CONNECTED'){content.append(element('p','Pesan baru dapat ditangani tim CS sesuai aturan routing.'));const link=element('a','Buka Inbox CRM');link.href=config.inbox;content.append(link);}else content.append(element('p','Pengguna dengan izin uji koneksi perlu menyelesaikan uji pesan masuk dan keluar.'));}
    document.querySelector('[data-mc-start]')?.addEventListener('click',async event => {event.currentTarget.disabled=true;try{await start();}catch(error){status.textContent=error.message;}finally{event.target.disabled=false;}});
    const resumeButton=document.querySelector('[data-mc-resume]');if(resumeButton&&config.resume){resumeButton.hidden=false;resumeButton.onclick=()=>resume(config.resume).catch(error=>status.textContent=error.message);}
    document.querySelector('[data-mc-pause]').onclick=()=>{wizard.hidden=true;clearInterval(timer);if(resumeButton&&session){resumeButton.hidden=false;resumeButton.onclick=()=>resume(session).catch(error=>status.textContent=error.message);}status.textContent='Progres tersimpan. Anda dapat melanjutkan dari sesi yang sama selama belum kedaluwarsa.';};
    document.querySelector('[data-mc-guide]').onclick=()=>{const help=document.querySelector('[data-mc-help]');help.hidden=false;help.focus();};document.querySelector('[data-mc-guide-close]').onclick=()=>document.querySelector('[data-mc-help]').hidden=true;
    window.addEventListener('message',event=>{if(event.origin!==location.origin||event.source!==popup||event.data?.type!=='JM_META_CALLBACK'||event.data.sessionId!==session)return;resume(session).catch(error=>status.textContent=error.message);});
    renderChannels();const incoming=new URLSearchParams(location.search).get('meta_session');if(incoming&&config.manage)resume(incoming).catch(error=>status.textContent=error.message);
})();
