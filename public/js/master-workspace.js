(() => {
    const root = document.querySelector('[data-master-workspace]');
    if (!root) return;
    root.querySelector('[data-master-navigation]')?.addEventListener('change', event => { if (event.target.value) location.href = event.target.value; });
    root.addEventListener('invalid', event => {
        const pane = event.target.closest('[data-master-doc-pane]');
        if (!pane?.hidden) return;
        root.querySelector(`[data-master-doc-tab="${pane.dataset.masterDocPane}"]`)?.click();
    }, true);
    const healthBuilder = root.querySelector('.master-builder');
    if (healthBuilder) {
        const panes = [...healthBuilder.children], labels = ['Kategori','Pertanyaan','Properti'];
        const steps = document.createElement('nav'); steps.className = 'master-builder-mobile-steps'; steps.setAttribute('aria-label','Langkah builder kesehatan');
        const mobileBuilder = matchMedia('(max-width:767px)');
        const showStep = number => { if (!mobileBuilder.matches) return; panes.forEach((pane,index) => pane.hidden = index !== number); steps.querySelectorAll('button').forEach((button,index) => button.setAttribute('aria-pressed',String(index===number))); };
        labels.forEach((label,index) => { const button = document.createElement('button'); button.type='button'; button.className='secondary'; button.textContent=label; button.addEventListener('click',()=>showStep(index)); steps.append(button); });
        healthBuilder.before(steps); showStep(0);
        mobileBuilder.addEventListener('change', () => { if (mobileBuilder.matches) showStep(0); else panes.forEach(pane=>pane.hidden=false); });
        healthBuilder.addEventListener('click', event => { if (event.target.closest('[data-master-category]')) showStep(1); if (event.target.closest('[data-inspect-question]')) showStep(2); });
        const category = root.querySelector('[data-master-category]')?.dataset.masterCategory;
        root.querySelectorAll('[data-master-question]').forEach(row => row.hidden = row.dataset.category !== category);
    }
    const dirtyForms = new Set();
    const markDirty = event => {
        const form = event.target.form || event.target.closest('form');
        if (form && !form.matches('.filters') && !form.closest('.master-form-drawer')) dirtyForms.add(form);
    };
    root.addEventListener('input',markDirty);root.addEventListener('change',markDirty);
    addEventListener('beforeunload',event => { if (dirtyForms.size || dirty) { event.preventDefault(); event.returnValue=''; } });
    root.addEventListener('submit',event => {
        const form=event.target;
        if (form.matches('.filters') || form.closest('.master-form-drawer')) return;
        const otherDirty=[...dirtyForms].filter(other=>other!==form);
        if (otherDirty.length && /\/terbit$/.test(new URL(form.action).pathname)) { event.preventDefault(); alert('Simpan perubahan draf terlebih dahulu sebelum menerbitkan.'); return; }
        if (otherDirty.length && !confirm('Perubahan formulir lain belum disimpan. Lanjutkan menyimpan formulir ini dan tinggalkan perubahan lainnya?')) { event.preventDefault(); return; }
        dirtyForms.clear();
    });
    const guide = root.querySelector('.master-guide');
    root.querySelector('[data-master-guide]')?.addEventListener('click', () => guide.showModal());
    root.querySelector('[data-master-close]')?.addEventListener('click', () => guide.close());
    const formDrawer = root.querySelector('.master-form-drawer');
    let dirty = false;
    const closeForm = () => { if (!dirty || confirm('Perubahan belum disimpan. Tutup form?')) { dirty = false; formDrawer.close(); } };
    root.querySelector('[data-master-form-close]')?.addEventListener('click', closeForm);
    formDrawer?.addEventListener('cancel', event => { event.preventDefault(); closeForm(); });
    formDrawer?.addEventListener('input', () => dirty = true);
    root.addEventListener('click', async event => {
        const link = event.target.closest('a[data-master-form]');
        if (link && !event.metaKey && !event.ctrlKey && !event.shiftKey) {
            event.preventDefault();
            try {
                const response = await fetch(link.href, {headers:{'Accept':'text/html'}});
                if (!response.ok) throw new Error('Form tidak dapat dimuat.');
                const page = new DOMParser().parseFromString(await response.text(), 'text/html');
                const form = page.querySelector('[data-master-workspace] form:not([method=get])');
                if (!form) throw new Error('Form tidak tersedia.');
                root.querySelector('[data-master-form-title]').textContent = page.querySelector('h1')?.textContent || 'Data Master';
                root.querySelector('[data-master-form-content]').replaceChildren(form);
                form.querySelectorAll('a').forEach(anchor => { if (anchor.textContent.trim() === 'Batal') anchor.addEventListener('click', event => { event.preventDefault(); closeForm(); }); });
                dirty = false; formDrawer.showModal();
            } catch { location.href = link.href; }
            return;
        }
    });
    formDrawer?.addEventListener('submit', async event => {
        event.preventDefault(); const form = event.target;
        const submit = form.querySelector('button[type=submit],button:not([type])');
        if (submit) submit.disabled = true;
        try {
            const response = await fetch(form.action, {method:'POST', body:new FormData(form), headers:{'Accept':'application/json','X-Requested-With':'XMLHttpRequest'}});
            form.querySelector('[data-master-errors]')?.remove();
            if (response.status === 422) {
                const data = await response.json();
                const errors = document.createElement('div'); errors.className = 'errors'; errors.dataset.masterErrors = ''; errors.setAttribute('role','alert');
                errors.textContent = Object.values(data.errors || {}).flat().join(' '); form.prepend(errors); errors.scrollIntoView({block:'nearest'}); return;
            }
            if (!response.ok) throw new Error('Penyimpanan gagal. Coba kembali.');
            dirty = false; location.reload();
        } catch (error) { const message = document.createElement('p'); message.setAttribute('role','alert'); message.textContent = error.message; form.prepend(message); }
        finally { if (submit) submit.disabled = false; }
    });
    root.addEventListener('click', event => {
        const docTab = event.target.closest('[data-master-doc-tab]');
        if (docTab) {
            root.querySelectorAll('[data-master-doc-tab]').forEach(tab => tab.setAttribute('aria-selected',String(tab === docTab)));
            root.querySelectorAll('[data-master-doc-pane]').forEach(pane => pane.hidden = pane.dataset.masterDocPane !== docTab.dataset.masterDocTab);
        }
        const move = event.target.closest('[data-workflow-move]');
        if (move) {
            const row = move.closest('[data-workflow-row]');
            if (move.dataset.workflowMove === 'up' && row.previousElementSibling) row.previousElementSibling.before(row);
            else if (move.dataset.workflowMove === 'down' && row.nextElementSibling) row.nextElementSibling.after(row);
            move.dispatchEvent(new Event('change',{bubbles:true}));
        }
        const category = event.target.closest('[data-master-category]');
        if (category) {
            root.querySelectorAll('[data-master-question]').forEach(row => row.hidden = row.dataset.category !== category.dataset.masterCategory);
        }
        const question = event.target.closest('[data-inspect-question]');
        if (question) {
            const data = JSON.parse(question.dataset.inspectQuestion);
            const inspector = root.querySelector('[data-master-inspector]');
            inspector.replaceChildren();
            for (const [label, value] of Object.entries(data)) {
                const heading = document.createElement('h3'); heading.textContent = label;
                const text = document.createElement('p'); text.textContent = String(value ?? '—');
                inspector.append(heading, text);
            }
            const editor = document.getElementById('master-question-editor');
            if (editor && question.dataset.questionConfig) {
                const config = JSON.parse(question.dataset.questionConfig);
                const section = document.createElement('section');
                const field = (name,label,value,type='text',choices=null) => {
                    const wrap=document.createElement('div'), caption=document.createElement('label');
                    caption.textContent=label; const control=document.createElement(choices?'select':type==='textarea'?'textarea':'input');
                    control.name=name;control.id=`question-inspector-${name}`;control.setAttribute('form',editor.id);caption.htmlFor=control.id;
                    if(choices)for(const [key,label] of Object.entries(choices))control.append(new Option(label,key));
                    else if(type!=='textarea')control.type=type;
                    control.value=value??'';wrap.append(caption,control);section.append(wrap);return control;
                };
                field('question_id','',config.id,'hidden').parentElement.hidden=true;
                field('code','Kode',config.code).readOnly=true;
                field('question','Teks Pertanyaan',config.question,'textarea').required=true;
                field('category','Kategori',config.category).required=true;
                field('answer_type','Jenis Jawaban',config.answer_type,'select',{'YES_NO_UNKNOWN':'Ya / Tidak / Tidak tahu','YES_NO_UNKNOWN_NOT_APPLICABLE':'Ya / Tidak / Tidak tahu / Tidak relevan','SINGLE_SELECT':'Pilihan tunggal','MULTI_SELECT':'Pilihan jamak','NUMBER':'Angka','DATE':'Tanggal','SHORT_TEXT_CONDITIONAL':'Teks singkat bersyarat'});
                field('helper_text','Bantuan untuk CS',config.helper_text,'textarea');
                for(const [name,label] of Object.entries({is_required:'Wajib dijawab',restricted:'Akses terbatas',is_active:'Aktif'}))field(name,label,Number(config[name]||0),'select',{'1':'Ya','0':'Tidak'});
                field('sequence','Urutan Katalog',config.sequence,'number');
                const options=config.options?JSON.parse(config.options):{};
                field('guided_options','Pilihan Jawaban (satu per baris)',Object.values(options||{}).join('\n'),'textarea');
                const clarification=config.clarification_rule?JSON.parse(config.clarification_rule):{},followup=config.followup_rule?JSON.parse(config.followup_rule):{};
                field('guided_clarification','Klarifikasi Saat Jawaban',clarification?.when?.[0]||'','select',{'':'Tanpa pemicu','YA':'Ya','TIDAK':'Tidak','TIDAK_TAHU':'Tidak tahu'});
                field('guided_followup','Rekomendasi',followup?.outcome||'','select',{'':'Tanpa rekomendasi','NEEDS_CLARIFICATION':'Perlu klarifikasi','REFER_TO_MCU':'Rujuk MCU','MEDICAL_REVIEW_REQUIRED':'Tinjauan medis'});
                field('guided_followup_when','Rekomendasi Saat Jawaban',followup?.when?.[0]||'YA','select',{'YA':'Ya','TIDAK':'Tidak','TIDAK_TAHU':'Tidak tahu'});
                const save=document.createElement('button');save.type='submit';save.setAttribute('form',editor.id);save.textContent='Simpan Pertanyaan';section.append(save);inspector.prepend(section);
            }
        }
        const add = event.target.closest('[data-condition-add]');
        if (add) {
            const builder = add.closest('[data-condition-builder]');
            const row = builder.querySelector('[data-condition-row]').cloneNode(true);
            const index = Number(builder.dataset.nextIndex || builder.querySelectorAll('[data-condition-row]').length);
            row.querySelectorAll('[name]').forEach(control => { control.name = control.name.replace(/\[conditions\]\[\d+\]/, `[conditions][${index}]`); if (control.tagName === 'INPUT') control.value = ''; });
            builder.dataset.nextIndex = index + 1;
            row.querySelectorAll('.master-condition-entity').forEach(element=>element.remove());
            builder.querySelector('[data-condition-list]').append(row);
            document.dispatchEvent(new Event('master:rows-added'));
        }
        const remove = event.target.closest('[data-condition-remove]');
        if (remove && remove.closest('[data-condition-builder]').querySelectorAll('[data-condition-row]').length > 1) remove.closest('[data-condition-row]').remove();
    });
    root.querySelector('[data-workflow-draft]')?.addEventListener('submit', () => {
        root.querySelectorAll('[data-workflow-row]').forEach((row,index) => row.querySelectorAll('[name]').forEach(control => control.name = control.name.replace(/stages\[\d+\]/, `stages[${index}]`)));
    });
    const conditionScopes={country_id:'COUNTRY',vacancy_id:'VACANCY',sponsor_id:'SPONSOR',job_order_id:'JOB_ORDER'};
    const conditionRequests=new WeakMap();
    const loadConditionEntities = async (row,search='') => {
        const field=row.querySelector('[data-condition-field]'),input=row.querySelector('[data-condition-value]');
        if(!field||!input)return;
        const scope=conditionScopes[field.value];
        const previous=row.querySelector('.master-condition-entity');
        if(!scope){previous?.remove();input.hidden=false;input.type='text';return;}
        let wrapper=previous;
        if(!wrapper||wrapper.dataset.scope!==scope){
            previous?.remove();wrapper=document.createElement('div');wrapper.className='master-condition-entity';wrapper.dataset.scope=scope;
            const searchInput=document.createElement('input');searchInput.type='search';searchInput.placeholder='Cari nama acuan…';searchInput.setAttribute('aria-label','Cari nilai kondisi');searchInput.dataset.conditionEntitySearch='';
            const select=document.createElement('select');select.name=input.name.replace('[value]','[values][]');select.multiple=true;select.setAttribute('aria-label','Nilai acuan kondisi');
            wrapper.append(searchInput,select);input.after(wrapper);
            wrapper.dataset.selected=input.value;input.value='';
        }
        input.hidden=true;input.type='hidden';
        const url=new URL(document.querySelector('[data-scope-search]').dataset.url,location.origin);url.searchParams.set('scope',scope);url.searchParams.set('q',search);
        const select=wrapper.querySelector('select');
        const selected=[...select.selectedOptions].map(option=>option.value);
        for(const id of selected.length?selected:(wrapper.dataset.selected||'').split(',').filter(Boolean))url.searchParams.append('selected[]',id);
        conditionRequests.get(row)?.abort();const pending=new AbortController();conditionRequests.set(row,pending);
        wrapper.querySelectorAll('[role=alert]').forEach(element=>element.remove());
        try{
            const response=await fetch(url,{signal:pending.signal,headers:{'Accept':'application/json'}});if(!response.ok)throw Error('Acuan gagal dimuat. Ketik ulang pencarian.');
            const data=await response.json();if(field.value!==Object.keys(conditionScopes).find(key=>conditionScopes[key]===scope)||!wrapper.isConnected)return;
            const values=selected.length?selected:(wrapper.dataset.selected||'').split(',');
            select.replaceChildren(...data.items.map(item=>new Option(item.name,String(item.id),false,values.includes(String(item.id)))));
            wrapper.dataset.selected='';
        }catch(error){if(error.name==='AbortError')return;const message=document.createElement('small');message.setAttribute('role','alert');message.textContent=error.message;wrapper.append(message);}
    };
    const initializeConditionEntities=()=>root.querySelectorAll('[data-condition-row]').forEach(row=>{if(conditionScopes[row.querySelector('[data-condition-field]')?.value]){if(!row.querySelector('.master-condition-entity'))loadConditionEntities(row);}else{const input=row.querySelector('[data-condition-value]');if(input){input.hidden=false;input.type='text';}}});
    root.addEventListener('change',event=>{if(event.target.matches('[data-condition-field]')){const row=event.target.closest('[data-condition-row]');row.querySelector('[data-condition-value]').value='';loadConditionEntities(row);}});
    let conditionTimer;root.addEventListener('input',event=>{if(event.target.matches('[data-condition-entity-search]')){clearTimeout(conditionTimer);conditionTimer=setTimeout(()=>loadConditionEntities(event.target.closest('[data-condition-row]'),event.target.value),300);}});
    document.addEventListener('master:rows-added',initializeConditionEntities);initializeConditionEntities();
    const updateConditions = () => root.querySelectorAll('[data-requirement-row]').forEach(row => {
        const builder = row.querySelector('[data-condition-builder]');
        if (!builder) return;
        const active = row.querySelector('[name$="[required_level]"]').value === 'CONDITIONAL';
        builder.hidden = !active;
        builder.querySelectorAll('input,select,button').forEach(control => control.disabled = !active);
    });
    root.addEventListener('change', updateConditions);
    document.addEventListener('master:rows-added', updateConditions);
    updateConditions();
    root.querySelectorAll('.table-card table').forEach(table => {
        const labels = [...table.querySelectorAll('thead th')].map(heading => heading.textContent);
        table.querySelectorAll('tbody tr').forEach(row => [...row.children].forEach((cell,index) => { if (!cell.colSpan || cell.colSpan === 1) cell.dataset.label = labels[index] || ''; }));
    });
    const filterForm = root.querySelector('form.filters');
    if (filterForm) {
        const anchor = document.createElement('span'); filterForm.before(anchor);
        const toggle = document.createElement('button'); toggle.type = 'button'; toggle.className = 'secondary master-filter-toggle';
        toggle.textContent = 'Sembunyikan Filter'; toggle.setAttribute('aria-expanded','true'); anchor.before(toggle);
        const sheet = document.createElement('dialog'); sheet.className = 'master-filter-sheet'; sheet.setAttribute('aria-label','Filter Data');
        const close = document.createElement('button'); close.type = 'button'; close.className = 'secondary'; close.textContent = 'Tutup Filter'; close.addEventListener('click', () => sheet.close()); sheet.append(close); root.append(sheet);
        const mobile = matchMedia('(max-width:767px)');
        const layout = () => {
            filterForm.hidden = false;
            if (mobile.matches) { sheet.append(filterForm); toggle.textContent = 'Filter Data'; toggle.setAttribute('aria-expanded', String(sheet.open)); }
            else { sheet.close(); anchor.after(filterForm); toggle.textContent = 'Sembunyikan Filter'; toggle.setAttribute('aria-expanded','true'); }
        };
        mobile.addEventListener('change',layout); layout();
        sheet.addEventListener('close', () => toggle.setAttribute('aria-expanded','false'));
        toggle.addEventListener('click', () => {
            if (mobile.matches) { sheet.showModal(); toggle.setAttribute('aria-expanded','true'); }
            else { filterForm.hidden = !filterForm.hidden; toggle.textContent = filterForm.hidden ? 'Tampilkan Filter' : 'Sembunyikan Filter'; toggle.setAttribute('aria-expanded',String(!filterForm.hidden)); }
        });
    }
    root.querySelectorAll('label').forEach((label, index) => {
        if (label.htmlFor || label.querySelector('input,select,textarea')) return;
        const control = label.nextElementSibling;
        if (!control?.matches('input,select,textarea')) return;
        control.id ||= `master-control-${index}`;
        label.htmlFor = control.id;
    });
    root.querySelector('[data-question-search]')?.addEventListener('input', event => {
        const term = event.target.value.trim().toLocaleLowerCase('id');
        root.querySelectorAll('[data-master-question]').forEach(row => row.hidden = !row.textContent.toLocaleLowerCase('id').includes(term));
    });
})();
