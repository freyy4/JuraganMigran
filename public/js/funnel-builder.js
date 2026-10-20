import {createFunnelEngine} from './funnel-editor-engine.js?v=grapesjs0238-2';
import {createFunnelStudio} from './funnel-builder-studio.js?v=20261017-buttons';
import {BuilderModel, PROTECTED_FIELDS, assertStructure} from './funnel-builder-model.js?v=20261017-tracking-api';

const source = document.getElementById('schema');
if (source) {
    const form = document.getElementById('builder-form');
    const originalSchema=JSON.parse(document.getElementById('builder-original-schema').textContent);
    let initialSchema, initialSchemaError=false;
    try{initialSchema=JSON.parse(source.value);assertStructure(initialSchema);}catch{initialSchema=originalSchema;initialSchemaError=true;}
    const model = new BuilderModel(initialSchema);
    const editable = form.dataset.editable === '1';
    const canvas = document.getElementById('builder-canvas');
    const properties = document.getElementById('builder-properties');
    const rulesPanel = document.getElementById('builder-rules');
    const feedback = document.getElementById('builder-feedback');
    const typeNames = {text:'Teks singkat',textarea:'Teks panjang',phone:'Nomor telepon',email:'Email',number:'Angka',date:'Tanggal',select:'Dropdown',searchable_select:'Pilihan dengan pencarian',radio:'Pilihan tunggal',checkbox:'Ya / Tidak',multi_select:'Pilihan jamak',yes_no:'Ya / Tidak',address:'Alamat',province_city:'Provinsi / Kota',file:'Upload dokumen',consent:'Persetujuan',hidden:'Nilai tersembunyi'};
    const blockNames = {hero:'Pembuka',rich_text:'Paragraf',image:'Gambar',video:'Video',vacancy_summary:'Ringkasan lowongan',trust:'Kepercayaan',requirements:'Persyaratan',process_steps:'Alur proses',faq:'Pertanyaan umum',testimonial:'Testimoni',form:'Formulir',cta:'Tombol pendaftaran',divider:'Pemisah',notice:'Pemberitahuan'};
    const actionNames = {SHOW_FIELD:'Tampilkan field',HIDE_FIELD:'Sembunyikan field',REQUIRE_FIELD:'Wajibkan field',SKIP_STEP:'Lewati langkah',SHOW_NOTICE:'Tampilkan pesan',BLOCK_SUBMISSION:'Hentikan pendaftaran',SET_VALUE:'Isi nilai otomatis',ROUTE_TO_PAGE:'Lanjut ke halaman',ROUTE_TO_FUNNEL:'Lanjut ke funnel lain'};
    const types = JSON.parse(document.getElementById('field-types').textContent);
    let selected = null, activeStep = model.schema.steps[0]?.id, saving = false, previewTimer, previewController, previewSequence = 0, manualSchema = initialSchemaError, activePage = 'landing', propertyTab = 'content', autoSaveTimer, autoSavePaused = false, savePromise = null, saveConflict = false;
    const signature = () => JSON.stringify([model.schema, form.elements.name.value, form.elements.vacancy_id.value]);
    let savedSignature = signature();
    let canvasTimer, canvasController, canvasSequence=0, rendererCache=null, rendererSignature='', readinessSignature='', readinessResult=null;
    const renderSignature=()=>JSON.stringify([model.schema,form.elements.vacancy_id.value,activePage]);
    const studio = createFunnelStudio({form, createEngine:createFunnelEngine, names:blockNames, getState:()=>({schema:model.schema,selected,page:activePage}),onShortcut:studioShortcut,onError:text=>message(text,true),
        choose:(kind,id)=>{propertyTab='content';showPanels();choose(kind,id);},
        commit:(kind,id,key,value)=>attempt(()=>{model.mutate(schema=>{const item=kind==='thank_you'?schema.thank_you:schema[kind==='field'?'fields':'blocks'].find(item=>(item.key??item.id)===id);if(item)item[key]=value;});syncInlineProperty(kind,key,value);}),
        move:(kind,id,target)=>attempt(()=>{
            if(target.kind===kind&&target.id===id)return;
            if(kind==='field'){
                const field=model.schema.fields.find(item=>item.key===target.id),step=target.kind==='step'?target.id:field?.step;if(!step)return;
                const siblings=model.schema.fields.filter(item=>item.step===step&&item.key!==id),index=siblings.findIndex(item=>item.key===target.id);
                model.moveField(id,step,target.kind==='field'?(target.after?siblings[index+1]?.key:target.id):null);
            }else if(target.kind===kind){const list=model.schema[kind==='block'?'blocks':'steps'].filter(item=>item.id!==id),index=list.findIndex(item=>item.id===target.id);model.move(kind==='block'?'blocks':'steps',id,target.after?list[index+1]?.id:target.id);}
        }),
        add:(kind,id,target)=>kind==='add-block'?addBlock(id,target):addFieldFromPalette(id,target),
    });
    const el = (tag, text = '', className = '') => {const node = document.createElement(tag);node.textContent = text;if(className)node.className = className;return node;};
    const message = (text, error = false) => {feedback.hidden = !text;document.getElementById('builder-feedback-text').textContent = text;feedback.setAttribute('role',error?'alert':'status');feedback.className = error ? 'errors' : 'notice';};
    document.getElementById('dismiss-feedback').onclick=()=>feedback.hidden=true;
    const button = (text, callback, className = 'secondary') => {const node=el('button',text,className);node.type='button';node.disabled=!editable;node.onclick=callback;return node;};
    source.readOnly=!editable;
    source.addEventListener('input',()=>{manualSchema=true;document.getElementById('save-state').textContent='Schema diubah · terapkan ke canvas';});
    function attempt(callback, rerenderProperties = false) {
        if(manualSchema){message('Terapkan schema ke canvas terlebih dahulu sebelum mengedit elemen lain.',true);return;}
        try {callback();if(!saveConflict){autoSavePaused=false;message('');}changed();if(rerenderProperties)renderProperties();} catch(error) {message(error.message,true);}
    }
    function changed() {
        if(!manualSchema)source.value=JSON.stringify(model.schema,null,2);
        document.getElementById('save-state').textContent = saving ? 'Menyimpan…' : saveConflict ? 'Konflik draft · unduh sebelum memuat ulang' : autoSavePaused ? 'Gagal menyimpan' : signature()===savedSignature ? 'Tersimpan' : 'Belum disimpan';
        document.getElementById('builder-undo').disabled=!editable||!model.undoStack.length;
        document.getElementById('builder-redo').disabled=!editable||!model.redoStack.length;
        document.querySelector('.studio-save-indicator').dataset.state=saveConflict||autoSavePaused?'error':signature()===savedSignature?'saved':'pending';
        document.getElementById('builder-counts').textContent=`${model.schema.steps.length} langkah · ${model.schema.fields.length} field · ${model.schema.rules.length} kondisi`;
        document.dispatchEvent(new CustomEvent('jm-funnel-schema-change',{detail:{schema:model.schema,selected}}));
        renderCanvas();schedulePreview();clearTimeout(autoSaveTimer);document.getElementById('retry-save').hidden=!autoSavePaused;
        if(editable && !saving && !autoSavePaused && !saveConflict && !manualSchema && document.getElementById('auto-save').checked && signature()!==savedSignature){autoSaveTimer=setTimeout(autoSaveDraft,1200);}
    }
    document.addEventListener('jm-funnel-tracking-apply',event=>{
        if(!editable||form.dataset.trackingEditable!=='1')return;
        attempt(()=>model.mutate(schema=>{schema.tracking=structuredClone(event.detail);}));
    });
    function autoSaveDraft(){if(studio.isInteracting()){autoSaveTimer=setTimeout(autoSaveDraft,300);return;}saveDraft(false);}
    function inputControl(parent, label, value, onChange, options = {}) {
        const wrapper=el('label',label);const input=el(options.choices?'select':options.multiline?'textarea':'input');
        if(options.choices) options.choices.forEach(choice=>{const [value,label]=Array.isArray(choice)?choice:[choice,choice];const option=el('option',label);option.value=value;input.append(option);});
        if(options.type) input.type=options.type;
        if(options.type==='checkbox') {input.checked=!!value;wrapper.className='checkbox-label';} else input.value=value??'';
        input.disabled=!editable||!!options.disabled;
        if(options.min!==undefined)input.min=options.min;
        if(options.max!==undefined)input.max=options.max;
        input.addEventListener(options.choices||options.type==='checkbox'?'change':options.immediate===false?'change':'input',()=>onChange(options.type==='checkbox'?input.checked:input.value,input));
        wrapper.append(input);parent.append(wrapper);return input;
    }
    function choose(kind,id) {selected={kind,id};renderStyle();if(kind==='step')activeStep=id;else if(kind==='field')activeStep=model.schema.fields.find(field=>field.key===id)?.step??activeStep;studio.renderLayers();studio.selection();renderProperties();}
    function syncInlineProperty(kind,key,value){
        if(kind==='thank_you'){document.getElementById(key==='headline'?'thank-headline':key==='button_text'?'thank-button':'thank-text').value=value;return;}
        const labels={title:'Judul',label:'Label field',text:'Isi',button_text:'Teks tombol'};
        for(const label of properties.querySelectorAll('label')){if(label.firstChild?.textContent===labels[key]){const control=label.querySelector('input,textarea');if(control)control.value=value;}}
    }
    function selectedItem() {return selected?model.schema[selected.kind==='field'?'fields':selected.kind==='step'?'steps':'blocks'].find(item=>item[selected.kind==='field'?'key':'id']===selected.id):null;}
    function deleteElement(kind, id) {
        if (!editable) return;
        const item = model.schema[kind === 'field' ? 'fields' : 'blocks'].find(item => item[kind === 'field' ? 'key' : 'id'] === id);
        if (!item) return;
        if (kind === 'field' && PROTECTED_FIELDS.includes(id)) {message('Nama dan WhatsApp merupakan identitas wajib dan tidak dapat dihapus.', true);return;}
        if (!confirm(kind === 'field' ? `Hapus field “${item.label}” beserta kondisi terkait?` : `Hapus blok “${item.title || blockNames[item.type]}”?`)) return;
        attempt(() => {
            if (kind === 'field') model.removeField(id); else model.removeBlock(id);
            if (selected?.kind === kind && selected.id === id) selected = null;
            renderRules();
        }, true);
    }
    function reorder(kind,id,delta) {
        const list=model.schema[kind];const index=list.findIndex(item=>item.id===id);const destination=index+delta;
        if(destination<0||destination>=list.length)return;
        model.move(kind,id,delta<0?list[destination].id:list[destination+1]?.id);
    }
    function renderCanvas() {
        if(!model.schema.steps.some(step=>step.id===activeStep))activeStep=model.schema.steps[0]?.id;
        studio.renderLayers();
        if(!studio.hasFrame()&&!canvas.firstElementChild){const loading=el('div','Menyiapkan kanvas funnel…','funnel-canvas-loading');loading.setAttribute('role','status');canvas.append(loading);}
        scheduleCanvas();
    }
    function renderProperties() {
        properties.replaceChildren();const item=selectedItem();document.getElementById('funnel-selection-label').textContent=activePage==='thank_you'?'Halaman konfirmasi':item?(selected.kind==='field'?'Field · ':selected.kind==='step'?'Langkah · ':'Blok · ')+(item.label||item.title||blockNames[item.type]):'Pilih elemen untuk mulai mengedit';
        if(!item){selected=null;properties.append(el('p','Pilih field, langkah, atau blok pada susunan formulir.','muted'));return;}
        const kind=selected.kind,id=selected.id,group=key=>`${kind}:${id}:${key}`;
        const update=(patch,key,rebuild=false)=>attempt(()=>{
            if(kind==='field'){model.updateField(selected.id,patch,group(key));if(patch.key){selected.id=patch.key;renderRules();}}
            else if(kind==='step'){model.updateStep(selected.id,patch,group(key));if(patch.id){selected.id=patch.id;activeStep=patch.id;renderRules();}}
            else model.mutate(schema=>Object.assign(schema.blocks.find(block=>block.id===id),patch),group(key));
        },rebuild);
        inputControl(properties,kind==='field'?'Label field':'Judul',kind==='field'?item.label:item.title,value=>update({[kind==='field'?'label':'title']:value},'title'));
        if(kind==='field') {
            const protectedField=PROTECTED_FIELDS.includes(id);
            inputControl(properties,'Tipe field',item.type,value=>{const patch={type:value};if(['select','searchable_select','radio','multi_select','province_city'].includes(value)&&!item.options?.length)patch.options=['Pilihan 1','Pilihan 2'];update(patch,'type',true);},{choices:types.map(type=>[type,typeNames[type]]),disabled:protectedField});
            inputControl(properties,'Langkah',item.step,value=>attempt(()=>model.moveField(selected.id,value),true),{choices:model.schema.steps.map(step=>[step.id,step.title])});
            inputControl(properties,'Wajib diisi',item.required,value=>update({required:value},'required'),{type:'checkbox',disabled:protectedField});
            inputControl(properties,'Placeholder',item.placeholder,value=>update({placeholder:value},'placeholder'));
            inputControl(properties,'Petunjuk pengisian',item.help,value=>update({help:value},'help'),{multiline:true});
            if(['select','searchable_select','radio','multi_select','province_city'].includes(item.type))inputControl(properties,'Pilihan jawaban (satu per baris)',(item.options||[]).join('\n'),value=>update({options:value.split('\n').map(option=>option.trim()).filter(Boolean)},'options'),{multiline:true});
            if(item.type==='hidden')inputControl(properties,'Nilai otomatis',item.value,value=>update({value},'value'));
            if(['text','textarea','address','number'].includes(item.type)) {
                const range=el('div','','grid');properties.append(range);
                ['min','max'].forEach(key=>inputControl(range,`${key==='min'?'Minimum':'Maksimum'} ${item.type==='number'?'nilai':'karakter'}`,item.validation?.[key],value=>update({validation:{...item.validation,[key]:value===''?null:Number(value)}},key),{type:'number',min:0,max:1000000}));
            }
            const advanced=el('details');advanced.append(el('summary','Pengaturan data'));properties.append(advanced);
            inputControl(advanced,'Kunci data',item.key,(value,input)=>{const old=selected.id;update({key:value},'key',true);if(selected.id===old)input.value=old;},{disabled:protectedField,immediate:false});
            inputControl(advanced,'Sensitivitas data',item.sensitivity,value=>update({sensitivity:value},'sensitivity'),{choices:[['GENERAL','Umum'],['CONTACT','Kontak'],['IDENTITY','Identitas'],['DOCUMENT','Dokumen'],['HEALTH','Kesehatan'],['LOCATION','Lokasi'],['FINANCIAL','Keuangan']]});
            const actions=el('div','','actions');properties.append(actions);
            actions.append(button('Duplikasi',()=>attempt(()=>{selected.id=model.duplicateField(selected.id);},true)));
            const siblings=model.schema.fields.filter(field=>field.step===item.step);const index=siblings.findIndex(field=>field.key===id);
            actions.append(button('↑',()=>attempt(()=>model.moveField(id,item.step,siblings[index-1]?.key))),button('↓',()=>attempt(()=>model.moveField(id,item.step,siblings[index+2]?.key))));
            actions.children[1].disabled=!editable||index===0;actions.children[2].disabled=!editable||index===siblings.length-1;
            if(!protectedField)actions.append(button('Hapus',()=>deleteElement('field',id),'builder-danger'));
        } else if(kind==='step') {
            inputControl(properties,'Petunjuk langkah',item.help,value=>update({help:value},'help'),{multiline:true});
            inputControl(properties,'Teks tombol lanjut',item.next_label||'Lanjut',value=>update({next_label:value},'next_label'));
            inputControl(properties,'Teks tombol kembali',item.back_label||'Kembali',value=>update({back_label:value},'back_label'));
            properties.append(button('Duplikasi langkah',()=>attempt(()=>{selected.id=model.duplicateStep(id);activeStep=selected.id;renderRules();},true)));
            inputControl(properties,'ID langkah',item.id,value=>{update({id:value},'id',true);renderRules();},{immediate:false});
            const actions=el('div','','actions');actions.append(button('↑ Langkah',()=>attempt(()=>reorder('steps',id,-1))),button('↓ Langkah',()=>attempt(()=>reorder('steps',id,1))));properties.append(actions);
            if(model.schema.steps.length>1) {
                const destinations=model.schema.steps.filter(step=>step.id!==id);let destination=destinations[0].id;
                inputControl(properties,'Pindahkan field saat langkah dihapus',destination,value=>{destination=value;},{choices:destinations.map(step=>[step.id,step.title])});
                properties.append(button('Hapus langkah',()=>{if(confirm('Hapus langkah ini dan pindahkan seluruh field ke langkah tujuan?'))attempt(()=>{model.removeStep(id,destination);selected=null;renderRules();},true);},'builder-danger'));
            }
        } else {
            if(item.type==='form')inputControl(properties,'Teks tombol',item.button_text||'Kirim pendaftaran',value=>update({button_text:value},'button_text'));
            if(['hero','cta'].includes(item.type)){inputControl(properties,'Teks tombol',item.button_text,value=>update({button_text:value},'button_text'));inputControl(properties,'Tujuan tombol (#application-form atau HTTPS)',item.cta_target||'#application-form',value=>update({cta_target:value},'cta_target'));}
            if(item.type==='hero'){
                const badges=item.badges??(item.badge?[item.badge]:[]);const badgeList=el('div','','builder-badge-list');properties.append(el('h3','Badge'),badgeList);
                badges.forEach((badge,index)=>{const row=el('div','','builder-badge-row');badgeList.append(row);
                    inputControl(row,`Badge ${index+1}`,badge,value=>{const values=[...(item.badges??badges)];values[index]=value;update({badges:values,badge:null},'badge:'+index);});
                    const remove=button('×',()=>{const values=[...(item.badges??badges)];values.splice(index,1);update({badges:values,badge:null},'badges',true);});remove.title=`Hapus badge ${index+1}`;remove.setAttribute('aria-label',remove.title);row.append(remove);
                });
                const addBadge=button('+ Tambah badge',()=>update({badges:[...(item.badges??badges),''],badge:null},'badges',true));addBadge.disabled=!editable||badges.length>=10;properties.append(addBadge,el('small','Maksimal 10 badge, masing-masing 100 karakter.'));
                inputControl(properties,'Kepercayaan (maksimal 4, satu per baris)',(item.trust_items||[]).join('\n'),value=>update({trust_items:value.split('\n').map(line=>line.trim()).filter(Boolean)},'trust_items'),{multiline:true});}
            inputControl(properties,'Isi',item.text,value=>update({text:value},'text'),{multiline:true});
            if(item.type==='video')inputControl(properties,'URL video HTTPS',item.url,value=>update({url:value},'url'),{type:'url'});
            if(['image','hero'].includes(item.type)) {
                const mediaSource=item.media_source||(item.url?.startsWith('/funnel-media/')?'upload':'link');
                inputControl(properties,'Sumber gambar',mediaSource,value=>update({media_source:value},'media_source',true),{choices:[['link','Link gambar'],['upload','Upload gambar']]});
                if(mediaSource==='link')inputControl(properties,'URL gambar HTTPS',item.url,value=>update({url:value},'url'),{type:'url'});
                else {
                    const wrapper=el('label','Pilih gambar'),upload=el('input');upload.type='file';upload.accept='image/jpeg,image/png,image/webp';upload.disabled=!editable;wrapper.append(upload);properties.append(wrapper,el('small','JPG, PNG, WebP · maksimal 5 MB · maksimal 6000 × 6000 px.'));
                    if(item.url)properties.append(el('small','Gambar sudah terpasang. Upload lagi untuk menggantinya.'));
                    upload.onchange=async()=>{
                        const file=upload.files[0];if(!file)return;if(file.size>5*1024*1024){message('Ukuran gambar maksimal 5 MB.',true);upload.value='';return;}
                        const blockId=id;upload.disabled=true;message('Mengunggah gambar…');const body=new FormData();body.set('_token',form.elements._token.value);body.set('image',file);
                        try{const response=await fetch(form.dataset.uploadUrl,{method:'POST',body,headers:{Accept:'application/json'},credentials:'same-origin'});const result=await response.json();if(!response.ok)throw new Error(Object.values(result.errors||{}).flat().join(' ')||result.message||'Upload gagal.');
                            if(!model.schema.blocks.some(block=>block.id===blockId)){message('Gambar diunggah, tetapi bloknya sudah dihapus.',true);return;}
                            attempt(()=>model.mutate(schema=>Object.assign(schema.blocks.find(block=>block.id===blockId),{url:result.url,media_source:'upload'})),true);message('Gambar berhasil diunggah. Publish untuk menampilkan perubahan.');
                        }catch(error){message(error.message||'Upload gagal. Coba lagi.',true);}finally{upload.disabled=!editable;upload.value='';}
                    };
                }
            }
            if(['image','hero'].includes(item.type)){inputControl(properties,'Teks alternatif gambar',item.alt,value=>update({alt:value},'alt'));inputControl(properties,'Gambar dekoratif',item.decorative,value=>update({decorative:value},'decorative'),{type:'checkbox'});}
            inputControl(properties,'Warna latar blok',item.background_color||({white:'#ffffff',muted:'#f1f5f9',navy:'#0b1f3a'}[item.background])||'#ffffff',value=>update({background_color:value},'background_color'),{type:'color'});
            inputControl(properties,'Warna teks blok',item.text_color||model.schema.design?.text_color||(item.background==='navy'?'#ffffff':'#142d35'),value=>update({text_color:value},'text_color'),{type:'color'});
            const actions=el('div','','actions');actions.append(button('↑',()=>attempt(()=>reorder('blocks',id,-1))),button('↓',()=>attempt(()=>reorder('blocks',id,1))));properties.append(actions);
            if(item.type!=='form')actions.append(button('Duplikasi blok',()=>attempt(()=>{const newId=model.unique('block',model.schema.blocks.map(block=>block.id));model.mutate(schema=>{if(schema.blocks.length>=50)throw new Error('Maksimal 50 blok.');const index=schema.blocks.findIndex(block=>block.id===id);schema.blocks.splice(index+1,0,{...JSON.parse(JSON.stringify(schema.blocks[index])),id:newId});});selected={kind:'block',id:newId};},true)));
            actions.append(button('Hapus blok',()=>deleteElement('block',id),'builder-danger'));
        }
    }
    function renderRules() {
        rulesPanel.replaceChildren();
        if(!model.schema.rules.length)rulesPanel.append(el('p','Belum ada kondisi. Tambahkan aturan untuk menyesuaikan pertanyaan dengan jawaban pendaftar.','muted'));
        model.schema.rules.forEach((rule,index)=>{
            const card=el('section','','builder-rule');card.append(el('h3',`Kondisi ${index+1}`));const grid=el('div','','grid');card.append(grid);
            const update=(patch,rebuild=false)=>attempt(()=>model.mutate(schema=>Object.assign(schema.rules[index],patch),`rule:${index}:${Object.keys(patch)[0]}`),false);
            inputControl(grid,'Jika jawaban',rule.field,value=>update({field:value}),{choices:model.schema.fields.map(field=>[field.key,field.label])});
            inputControl(grid,'Operator',rule.operator,value=>update({operator:value}),{choices:[['eq','Sama dengan'],['neq','Tidak sama dengan'],['lt','Kurang dari'],['lte','Kurang atau sama'],['gt','Lebih dari'],['gte','Lebih atau sama'],['contains','Mengandung']]});
            inputControl(grid,'Nilai pembanding',rule.value,value=>update({value}));
            inputControl(grid,'Maka',rule.action,value=>{update({action:value,target:null});renderRules();},{choices:Object.entries(actionNames)});
            if(['SHOW_FIELD','HIDE_FIELD','REQUIRE_FIELD','SET_VALUE'].includes(rule.action)) {
                const fields=model.schema.fields.filter(field=>!PROTECTED_FIELDS.includes(field.key));
                inputControl(grid,'Field tujuan',rule.target||'',value=>update({target:value}),{choices:[['','Pilih field tujuan'],...fields.map(field=>[field.key,field.label])]});
            } else if(rule.action==='SKIP_STEP') {
                const steps=model.schema.steps.filter(step=>!model.schema.fields.some(field=>PROTECTED_FIELDS.includes(field.key)&&field.step===step.id));
                inputControl(grid,'Langkah yang dilewati',rule.target||'',value=>update({target:value}),{choices:[['','Pilih langkah'],...steps.map(step=>[step.id,step.title])]});
            } else if(['ROUTE_TO_PAGE','ROUTE_TO_FUNNEL'].includes(rule.action))inputControl(grid,'Tujuan (/apply/slug)',rule.target,value=>update({target:value}));
            if(rule.action==='SET_VALUE')inputControl(grid,'Nilai yang diisi',rule.set_value,value=>update({set_value:value}));
            if(['SHOW_NOTICE','BLOCK_SUBMISSION'].includes(rule.action))inputControl(card,'Pesan kepada pendaftar',rule.message,value=>update({message:value}),{multiline:true});
            card.append(button('Hapus kondisi',()=>attempt(()=>{model.mutate(schema=>schema.rules.splice(index,1));renderRules();}),'builder-danger'));rulesPanel.append(card);
        });
    }
    function addRule() {activePage='form';showPanels();attempt(()=>{model.mutate(schema=>{if(schema.rules.length>=50)throw new Error('Maksimal 50 kondisi.');schema.rules.push({field:schema.fields[0].key,operator:'eq',value:'',action:'SHOW_NOTICE',message:'Lengkapi data untuk melanjutkan.'});});renderRules();});if(!document.getElementById('funnel-rules').open)document.getElementById('funnel-rules').showModal();}
    const presets=[
        {key:'name',label:'Nama Lengkap',type:'text',sensitivity:'GENERAL'},
        {key:'whatsapp',label:'Nomor WhatsApp',type:'phone',sensitivity:'CONTACT'},
        {key:'birth_date',label:'Tanggal Lahir',type:'date',sensitivity:'IDENTITY'},
        {key:'age',label:'Umur',type:'number',validation:{min:18,max:65},sensitivity:'GENERAL'},
        {key:'gender',label:'Jenis Kelamin',type:'select',options:['Perempuan','Laki-laki'],sensitivity:'GENERAL'},
        {key:'height',label:'Tinggi Badan (cm)',type:'number',validation:{min:100,max:230},sensitivity:'GENERAL'},
        {key:'marital_status',label:'Status Pernikahan',type:'select',options:['Belum menikah','Menikah','Cerai'],sensitivity:'GENERAL'},
        {key:'domicile',label:'Domisili',type:'address',sensitivity:'LOCATION'},
        {key:'destination',label:'Negara Tujuan',type:'text',sensitivity:'GENERAL'},
        {key:'education',label:'Pendidikan',type:'select',options:['SD','SMP','SMA / SMK','Diploma','Sarjana'],sensitivity:'GENERAL'},
        {key:'experience',label:'Pengalaman Kerja',type:'textarea',sensitivity:'GENERAL'},
        {key:'initial_document',label:'Dokumen Awal',type:'file',sensitivity:'DOCUMENT'},
        {key:'health_screen',label:'Pemeriksaan Kesehatan Awal',type:'yes_no',sensitivity:'HEALTH'},
        {key:'document',label:'Upload Dokumen',type:'file',sensitivity:'DOCUMENT'},
        {key:'consent',label:'Consent / Persetujuan',type:'consent',sensitivity:'GENERAL'}
    ];
    function library(search='') {
        const target=document.getElementById('field-library');target.replaceChildren();
        for(const preset of presets.filter(preset=>preset.label.toLowerCase().includes(search.toLowerCase()))){const node=button(preset.label,()=>addFieldFromPalette(preset.key));node.classList.add('field-preset');node.title='Tambah atau pilih '+preset.label;studio.bindPalette(node,'add-field',preset.key);target.append(node);}
        target.append(el('small','Field kustom','library-category'));
        for(const type of types.filter(type=>`${typeNames[type]} ${type}`.toLowerCase().includes(search.toLowerCase()))){const node=button(typeNames[type],()=>addFieldFromPalette(type));studio.bindPalette(node,'add-field',type);target.append(node);}
    }
    function applySharedCanvas(doc) {const key=rendererSignature;studio.attach(doc,key,()=>key===renderSignature());}
    function scheduleCanvas() {
        const key=renderSignature();clearTimeout(canvasTimer);
        if(studio.isInteracting()){canvasTimer=setTimeout(scheduleCanvas,150);return;}
        if(rendererSignature===key&&rendererCache){if(studio.currentKey()===key)form.dataset.previewPending='false';else if(!studio.isLoading(key))applySharedCanvas(rendererCache);return;}
        form.dataset.previewPending='true';document.getElementById('canvas-retry').hidden=true;
        const sequence=++canvasSequence;canvasController?.abort();
        document.getElementById('canvas-render-status').textContent='Memperbarui canvas…';
        canvasTimer=setTimeout(async()=>{
            canvasController=new AbortController();const body=new FormData();body.set('_token',form.elements._token.value);body.set('schema',JSON.stringify(model.schema));body.set('vacancy_id',form.elements.vacancy_id.value);body.set('page',activePage);
            try{const response=await fetch(form.dataset.previewUrl,{method:'POST',body,headers:{Accept:'application/json'},credentials:'same-origin',signal:canvasController.signal});
                if(!response.ok){const result=await response.json();throw new Error(Object.values(result.errors||{}).flat().join(' ')||'Canvas belum dapat ditampilkan.');}
                const html=await response.text();if(sequence!==canvasSequence||renderSignature()!==key)return;
                if(studio.isInteracting()){canvasTimer=setTimeout(scheduleCanvas,150);return;}
                rendererCache=new DOMParser().parseFromString(html,'text/html');rendererSignature=key;applySharedCanvas(rendererCache);document.getElementById('canvas-render-status').textContent='Pratinjau draft · Terbitkan untuk memperbarui halaman publik.';
            }catch(error){if(error.name!=='AbortError'&&sequence===canvasSequence){document.getElementById('canvas-render-status').textContent=error.message;document.getElementById('canvas-retry').hidden=false;}}
        },450);
    }
    function schedulePreview() {
        clearTimeout(previewTimer);previewSequence++;previewController?.abort();
        if(!document.getElementById('live-preview').open)return;
        document.getElementById('preview-status').textContent='Menyiapkan pratinjau…';
        const sequence=previewSequence;previewTimer=setTimeout(()=>loadPreview(sequence),650);
    }
    async function loadPreview(sequence) {
        previewController=new AbortController();const body=new FormData();body.set('_token',form.elements._token.value);body.set('schema',JSON.stringify(model.schema));body.set('vacancy_id',form.elements.vacancy_id.value);body.set('page',activePage);
        try {const response=await fetch(form.dataset.previewUrl,{method:'POST',body,headers:{Accept:'application/json'},credentials:'same-origin',signal:previewController.signal});
            if(!response.ok){const error=await response.json();throw new Error(Object.values(error.errors||{}).flat().join(' ')||error.message||'Pratinjau belum tersedia.');}
            const html=await response.text();if(sequence!==previewSequence)return;
            document.getElementById('builder-preview').srcdoc=html;document.getElementById('preview-status').textContent='Pratinjau sesuai draft saat ini.';
        }catch(error){if(error.name!=='AbortError'&&sequence===previewSequence)document.getElementById('preview-status').textContent=error.message;}
    }
    document.getElementById('canvas-retry').onclick=()=>{rendererSignature='';scheduleCanvas();};
    document.getElementById('live-preview').addEventListener('toggle',schedulePreview);
    document.getElementById('block-search').oninput=event=>document.querySelectorAll('[data-add-block]').forEach(node=>node.hidden=!node.textContent.toLowerCase().includes(event.target.value.toLowerCase()));
    document.getElementById('field-search').oninput=event=>library(event.target.value);
    function addBlock(type,target=null){attempt(()=>{
        let block=model.schema.blocks.find(block=>block.type==='form');
        if(type!=='form'||!block){const id=model.unique('block',model.schema.blocks.map(block=>block.id));model.mutate(schema=>{if(schema.blocks.length>=50)throw new Error('Maksimal 50 blok.');const index=target?schema.blocks.findIndex(item=>item.id===target.id):-1;schema.blocks.splice(index<0?schema.blocks.length:index+Number(target.after),0,{id,type,title:blockNames[type],text:''});});block=model.schema.blocks.find(block=>block.id===id);}
        activePage='landing';propertyTab='content';showPanels();selected={kind:'block',id:block.id};studio.openInspector();
    },true);}
    function addFieldFromPalette(value,target=null){attempt(()=>{
        const preset=presets.find(item=>item.key===value),type=preset?.type??value;
        const existing=preset&&model.schema.fields.find(field=>field.key===preset.key);
        activePage='form';propertyTab='content';showPanels();studio.openInspector();if(existing){selected={kind:'field',id:existing.key};activeStep=existing.step;return;}
        const targetField=target?.kind==='field'?model.schema.fields.find(item=>item.key===target.id):null;
        const step=target?.kind==='step'?target.id:targetField?.step??(model.schema.steps.some(item=>item.id===activeStep)?activeStep:model.schema.steps[0].id);
        const key=preset?.key??model.unique('field',model.schema.fields.map(item=>item.key));
        model.mutate(schema=>{if(schema.fields.length>=80)throw new Error('Maksimal 80 field.');const field={key,type,step,label:preset?.label??typeNames[type],required:false,sensitivity:['email','phone'].includes(type)?'CONTACT':'GENERAL',...preset};if(['select','searchable_select','radio','multi_select','province_city'].includes(type)&&!field.options)field.options=['Pilihan 1','Pilihan 2'];const index=targetField?schema.fields.findIndex(item=>item.key===targetField.key):-1;schema.fields.splice(index<0?schema.fields.length:index+Number(target.after),0,field);});
        selected={kind:'field',id:key};activeStep=step;renderRules();
    },true);}
    document.querySelectorAll('[data-add-block]').forEach(node=>{node.disabled=!editable;node.textContent=`${blockNames[node.dataset.addBlock]} +`;node.onclick=()=>addBlock(node.dataset.addBlock);studio.bindPalette(node,'add-block',node.dataset.addBlock);});
    document.getElementById('add-step').onclick=()=>attempt(()=>{activePage='form';propertyTab='content';showPanels();studio.openInspector();activeStep=model.addStep();selected={kind:'step',id:activeStep};renderRules();},true);
    document.getElementById('add-step').disabled=!editable;
    ['add-rule','add-rule-inline'].forEach(id=>{document.getElementById(id).onclick=addRule;document.getElementById(id).disabled=!editable;});
    document.getElementById('device').onchange=event=>{const width=event.target.value;document.getElementById('builder-preview').style.width=width;canvas.dataset.device=width;studio.resize();document.querySelectorAll('button[data-device]').forEach(node=>node.setAttribute('aria-pressed',String(node.dataset.device===width)));};
    document.querySelectorAll('button[data-device]').forEach(node=>node.onclick=()=>{const device=document.getElementById('device');device.value=node.dataset.device;device.dispatchEvent(new Event('change'));});
    document.getElementById('retry-save').onclick=()=>{if(saveConflict){message('Draft di server sudah berubah. Unduh draft ini melalui menu file sebelum memuat ulang.',true);return;}autoSavePaused=false;saveDraft();};
    document.getElementById('load-schema').disabled=!editable;
    document.getElementById('load-schema').onclick=()=>attempt(()=>{model.replace(JSON.parse(source.value));selected=null;renderRules();},true);
    [['thank-headline','headline'],['thank-text','text'],['thank-button','button_text']].forEach(([id,key])=>{const input=document.getElementById(id);input.disabled=!editable;input.oninput=()=>attempt(()=>model.mutate(schema=>schema.thank_you[key]=input.value,`thank_you:${key}`));});
    [['post-submit','post_submit'],['external-url','external_redirect'],['wa-template','whatsapp_template_id']].forEach(([id,key])=>{const input=document.getElementById(id);input.disabled=!editable;input.onchange=()=>attempt(()=>model.mutate(schema=>schema[key]=key==='whatsapp_template_id'?(input.value?Number(input.value):null):input.value));});
    for(const name of ['name','vacancy_id']){form.elements[name].disabled=!editable;for(const event of ['input','change'])form.elements[name].addEventListener(event,()=>{document.getElementById('funnel-file-title').textContent=form.elements.name.value;if(!saveConflict)autoSavePaused=false;changed();});}
    if(!editable){document.querySelectorAll('[data-funnel-vacancy-picker] input').forEach(input=>input.disabled=true);}
    function updateSettings() {document.getElementById('thank-button').value=model.schema.thank_you.button_text||'';document.getElementById('thank-headline').value=model.schema.thank_you.headline;document.getElementById('thank-text').value=model.schema.thank_you.text;document.getElementById('post-submit').value=model.schema.post_submit;document.getElementById('external-url').value=model.schema.external_redirect||'';document.getElementById('wa-template').value=model.schema.whatsapp_template_id||'';document.getElementById('consent-text').value=model.schema.consent_text;renderStyle();}
    const oldChanged=changed;
    // Settings must also follow schema restored through undo or JSON import.
    document.getElementById('builder-undo').onclick=()=>{if(manualSchema){message('Terapkan schema terlebih dahulu.',true);return;}model.undo();updateSettings();oldChanged();renderRules();renderProperties();renderStyle();};document.getElementById('builder-redo').onclick=()=>{if(manualSchema){message('Terapkan schema terlebih dahulu.',true);return;}model.redo();updateSettings();oldChanged();renderRules();renderProperties();renderStyle();};
    document.getElementById('load-schema').onclick=()=>{try{const imported=JSON.parse(source.value);model.replace(imported);manualSchema=false;updateSettings();selected=null;renderRules();renderProperties();changed();message('Schema diterapkan ke canvas.');}catch(error){message(error.message,true);}};
    async function saveDraft(latest=true) {
        studio.finishInline();
        if(savePromise){const success=await savePromise;if(!success||!latest||signature()===savedSignature)return success;return saveDraft(true);}
        if(!editable||saveConflict)return false;
        if(manualSchema){message('Terapkan schema ke canvas sebelum menyimpan.',true);return false;}
        if(!form.checkValidity()){
            const invalid=[...form.elements].find(input=>input.willValidate&&!input.checkValidity());
            if(invalid){invalid.closest('details')?.setAttribute('open','');if(invalid.closest('#builder-style-panel'))propertyTab='style';else propertyTab='content';showPanels();studio.openInspector();message('Periksa '+(invalid.closest('label')?.firstChild?.textContent||invalid.name||'isian funnel')+': '+invalid.validationMessage,true);}
            autoSavePaused=true;document.getElementById('save-state').textContent='Periksa isian';return false;
        }
        source.value=JSON.stringify(model.schema);const body=new FormData(form),sentSignature=signature();saving=true;document.getElementById('save-draft').disabled=true;changed();message('');
        savePromise=(async()=>{
            try {const response=await fetch(form.action,{method:'POST',body,headers:{Accept:'application/json'},credentials:'same-origin'});const result=await response.json();
                if(!response.ok){saveConflict=response.status===409;throw new Error(Object.values(result.errors||{}).flat().join(' ')||result.message||'Draft gagal disimpan.');}
                document.getElementById('draft-token').value=result.draft_token;document.querySelectorAll('#publish-form [name="draft_token"],#funnel-tracking [name="draft_token"]').forEach(input=>input.value=result.draft_token);
                if(signature()===sentSignature){const normalized=JSON.stringify(model.schema)!==JSON.stringify(result.schema);model.schema=result.schema;savedSignature=signature();if(normalized){updateSettings();renderRules();renderProperties();renderStyle();}}else savedSignature=sentSignature;
                message(signature()===savedSignature?'':'Versi yang dikirim tersimpan. Perubahan terbaru masih akan disimpan.');return true;
            }catch(error){autoSavePaused=true;message(error.message||'Koneksi terputus. Draft tetap ada di halaman ini; coba simpan kembali.',true);return false;}
            finally{saving=false;savePromise=null;document.getElementById('save-draft').disabled=false;changed();}
        })();
        return savePromise;
    }
    form.addEventListener('submit',event=>{event.preventDefault();clearTimeout(autoSaveTimer);saveDraft();});
    document.getElementById('auto-save').disabled=!editable;
    document.getElementById('auto-save').onchange=()=>{autoSavePaused=false;changed();};
    document.getElementById('open-preview').onclick=()=>{document.getElementById('preview-dialog').showModal();document.getElementById('live-preview').open=true;schedulePreview();};
    document.getElementById('close-preview').onclick=()=>document.getElementById('preview-dialog').close();
    const publishDialog=document.getElementById('publish-dialog'),confirmPublish=document.getElementById('confirm-publish'),acknowledge=document.getElementById('publish-ack');
    document.getElementById('publish-draft')?.addEventListener('click',async event=>{
        studio.finishInline();if(manualSchema||saveConflict){message(saveConflict?'Selesaikan konflik draft sebelum publikasi.':'Terapkan schema ke canvas sebelum publikasi.',true);return;}
        const trigger=event.currentTarget;trigger.disabled=true;readinessSignature=signature();readinessResult=null;acknowledge.checked=false;confirmPublish.disabled=true;
        const panel=document.getElementById('publish-readiness');panel.replaceChildren(el('p','Memeriksa kesiapan…'));publishDialog.showModal();
        const body=new FormData();body.set('_token',form.elements._token.value);body.set('schema',JSON.stringify(model.schema));body.set('vacancy_id',form.elements.vacancy_id.value);
        try{const response=await fetch(form.dataset.readinessUrl,{method:'POST',body,headers:{Accept:'application/json'},credentials:'same-origin'});const result=await response.json();
            if(!response.ok)throw new Error(Object.values(result.errors||{}).flat().join(' ')||result.message||'Pemeriksaan gagal.');
            readinessResult=result;panel.replaceChildren();result.issues.forEach(issue=>panel.append(el('p',`${{success:'✓',warning:'!',blocker:'✕',info:'ⓘ'}[issue.severity]} ${issue.message}`,'readiness-'+issue.severity)));
            if(result.tracking){const summary=el('section','','tracking-summary');summary.append(el('h3','Tracking Summary'),el('p',result.tracking.destinations.map(item=>`${item.display_name} (${item.provider}) · ${item.health}`).join(', ')||'Tidak ada destination aktif'),el('p',`Event: ${result.tracking.events.join(', ')||'—'}`),el('p',`${result.tracking.rules.filter(rule=>rule.enabled).length} section/action · ${result.tracking.consent}`));panel.append(summary);}
            const warnings=result.issues.some(issue=>issue.severity==='warning');document.getElementById('publish-warning-label').hidden=!warnings;confirmPublish.disabled=!result.ready||warnings;
        }catch(error){panel.replaceChildren(el('p',error.message,'errors'));}finally{trigger.disabled=false;}
    });
    acknowledge.onchange=()=>{confirmPublish.disabled=!readinessResult?.ready||(!acknowledge.checked&&readinessResult.issues.some(issue=>issue.severity==='warning'));};
    document.getElementById('cancel-publish').onclick=()=>publishDialog.close();
    confirmPublish.onclick=async()=>{
        if(!readinessResult?.ready||signature()!==readinessSignature){publishDialog.close();message('Draft berubah. Periksa kesiapan publikasi kembali.',true);return;}
        confirmPublish.disabled=true;clearTimeout(autoSaveTimer);
        const success=signature()===savedSignature||await saveDraft();
        if(success&&signature()===savedSignature){const publish=document.getElementById('publish-form');publish.querySelector('[name="draft_token"]').value=document.getElementById('draft-token').value;publish.dataset.ready='1';document.getElementById('funnel-history').close();publish.requestSubmit();}
        else{publishDialog.close();message('Publikasi belum dilakukan. Simpan draft terbaru dan coba lagi.',true);}
    };
    document.getElementById('publish-form')?.addEventListener('submit',event=>{if(event.currentTarget.dataset.ready==='1')return;event.preventDefault();document.getElementById('publish-draft').click();});
    function showPanels() {
        document.querySelectorAll('[data-builder-page]').forEach(button=>button.setAttribute('aria-selected',String(button.dataset.builderPage===activePage)));
        document.querySelectorAll('[data-property-tab]').forEach(button=>button.setAttribute('aria-selected',String(button.dataset.propertyTab===propertyTab)));
        document.getElementById('builder-content-panel').hidden=propertyTab!=='content';
        document.getElementById('builder-style-panel').hidden=propertyTab!=='style';
        document.getElementById('builder-logic-panel').hidden=propertyTab!=='logic';
        document.getElementById('thank-you-properties').hidden=activePage!=='thank_you';
        properties.hidden=activePage==='thank_you';
        rulesPanel.closest('.builder-rules').hidden=false;
        document.querySelector('.builder-hint').textContent=activePage==='landing'?'Seret blok untuk mengubah susunan landing page. Pilih blok untuk mengedit konten dan tampilannya.':activePage==='form'?'Seret field untuk mengubah urutan atau memindahkannya ke langkah lain.':'Atur pesan dan tindakan setelah pendaftaran melalui panel properti.';
    }
    function renderStyle() {
        const panel=document.getElementById('builder-style-panel');panel.replaceChildren();const design=model.schema.design||{};
        panel.append(el('h3','Tampilan funnel'));
        const update=(patch,key)=>attempt(()=>model.mutate(schema=>{schema.design={...schema.design,...patch};},'design:'+key));
        const palette=[['#087f73','Teal Juragan Migran'],['#2563eb','Biru JM'],['#0b1f3a','Navy JM']];if(design.primary_color&&!palette.some(([color])=>color===design.primary_color))palette.push([design.primary_color,'Warna draft sebelumnya']);
        inputControl(panel,'Warna brand',design.primary_color||'#087f73',value=>update({primary_color:value},'primary_color'),{choices:palette});
        inputControl(panel,'Latar halaman',design.background_color||'#f5f7fb',value=>update({background_color:value},'background_color'),{type:'color'});
        inputControl(panel,'Warna teks halaman',design.text_color||'#142d35',value=>update({text_color:value},'text_color'),{type:'color'});
        inputControl(panel,'Sudut kartu',design.radius??16,value=>update({radius:Number(value)},'radius'),{choices:[['0','Siku'],['8','Lembut'],['16','Bulat'],['24','Sangat bulat']]});
        inputControl(panel,'Layout landing page',design.layout||'split',value=>update({layout:value},'layout'),{choices:[['stacked','Blok tersusun dari atas ke bawah'],['split','Konten dan form berdampingan']]});
        inputControl(panel,'Kolom field form',design.form_columns??1,value=>update({form_columns:Number(value)},'form_columns'),{choices:[['1','Satu kolom'],['2','Dua kolom']]});
        const block=selected?.kind==='block'?selectedItem():null;
        if(block){panel.append(el('h3','Tampilan blok terpilih'));
            if(['hero','image'].includes(block.type)){
                for(const [key,label,min,max] of [['image_width','Lebar gambar (%)',25,100],['image_height','Tinggi gambar (px, kosong = otomatis)',80,1000],['image_radius','Sudut gambar (px)',0,64]]){inputControl(panel,label,block[key]??'',value=>attempt(()=>model.mutate(schema=>{const item=schema.blocks.find(item=>item.id===block.id);if(value==='')delete item[key];else item[key]=Number(value);},'image:'+key)),{type:'number',min,max});}
                inputControl(panel,'Cara gambar mengisi ruang',block.image_fit||'cover',value=>attempt(()=>model.mutate(schema=>schema.blocks.find(item=>item.id===block.id).image_fit=value)),{choices:[['cover','Isi ruang (potong tepi)'],['contain','Tampilkan utuh']]});
            }
            inputControl(panel,'Warna latar blok',block.background_color||({white:'#ffffff',muted:'#f1f5f9',navy:'#0b1f3a'}[block.background])||'#ffffff',value=>attempt(()=>model.mutate(schema=>schema.blocks.find(item=>item.id===block.id).background_color=value)),{type:'color'});
            inputControl(panel,'Warna teks blok',block.text_color||design.text_color||(block.background==='navy'?'#ffffff':'#142d35'),value=>attempt(()=>model.mutate(schema=>schema.blocks.find(item=>item.id===block.id).text_color=value)),{type:'color'});
            inputControl(panel,'Perataan teks',block.align||'left',value=>attempt(()=>model.mutate(schema=>schema.blocks.find(item=>item.id===block.id).align=value)),{choices:[['left','Kiri'],['center','Tengah'],['right','Kanan']]});
            inputControl(panel,'Jarak dalam blok',block.padding??24,value=>attempt(()=>model.mutate(schema=>schema.blocks.find(item=>item.id===block.id).padding=Number(value))),{choices:[['0','Tanpa jarak'],['16','Rapat'],['24','Normal'],['40','Lapang'],['64','Lebar']]});
            inputControl(panel,'Sembunyikan dari halaman publik',block.hidden||false,value=>attempt(()=>model.mutate(schema=>schema.blocks.find(item=>item.id===block.id).hidden=value)),{type:'checkbox',disabled:block.type==='form'});
        }
    }
    document.querySelectorAll('[data-builder-page]').forEach(button=>button.onclick=()=>{studio.finishInline();if(activePage!==button.dataset.builderPage)studio.resetView();activePage=button.dataset.builderPage;selected=null;showPanels();renderCanvas();renderProperties();renderStyle();schedulePreview();});
    document.querySelectorAll('[data-property-tab]').forEach(button=>button.onclick=()=>{propertyTab=button.dataset.propertyTab;showPanels();renderStyle();});
    document.getElementById('open-logic').onclick=()=>{activePage='form';showPanels();renderCanvas();document.getElementById('funnel-rules').showModal();};
    document.getElementById('consent-text').disabled=!editable;
    document.getElementById('consent-text').oninput=event=>attempt(()=>model.mutate(schema=>schema.consent_text=event.target.value,'consent_text'));
    window.addEventListener('beforeunload',event=>{if(editable&&(manualSchema||studio.isDirty()||signature()!==savedSignature)){event.preventDefault();event.returnValue='';}});
    function studioShortcut(event){
        if(!editable)return;
        const typing=event.target.isContentEditable||event.target.closest('input,textarea,select'),key=event.key.toLowerCase(),modifier=event.ctrlKey||event.metaKey;
        if(modifier&&key==='s'){event.preventDefault();form.requestSubmit();return;}
        if(typing||document.querySelector('dialog[open]'))return;
        if(modifier&&key==='z'){event.preventDefault();studio.finishInline();document.getElementById(event.shiftKey?'builder-redo':'builder-undo').click();}
        else if(modifier&&key==='d'){event.preventDefault();[...properties.querySelectorAll('button')].find(button=>button.textContent.startsWith('Duplikasi'))?.click();}
        else if((key==='delete'||key==='backspace')&&selected){event.preventDefault();if(selected.kind==='step')[...properties.querySelectorAll('button')].find(button=>button.textContent==='Hapus langkah')?.click();else deleteElement(selected.kind,selected.id);}
    }
    document.addEventListener('keydown',studioShortcut);
    document.getElementById('reset-schema').disabled=!editable;document.getElementById('reset-schema').onclick=()=>{if(confirm('Pulihkan schema dari draft saat halaman dimuat?')){manualSchema=false;attempt(()=>{model.replace(originalSchema);updateSettings();selected=null;renderRules();},true);}};
    document.getElementById('funnel-export').onclick=()=>{studio.finishInline();const file=new Blob([manualSchema?source.value:JSON.stringify(model.schema,null,2)],{type:'application/json'}),url=URL.createObjectURL(file),link=document.createElement('a');link.href=url;link.download='funnel-draft.json';link.click();setTimeout(()=>URL.revokeObjectURL(url),1000);};
    library();renderRules();renderStyle();showPanels();changed();if(initialSchemaError)message('Schema yang terakhir dimasukkan tidak valid. Perbaiki di pengaturan lanjutan atau pulihkan draft tersimpan.',true);
}
