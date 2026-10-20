(() => {
    const root=document.querySelector('[data-tracking-settings]');if(!root)return;
    const drawer=document.getElementById('tracking-destination-drawer'),form=drawer.querySelector('form'),help=document.getElementById('tracking-help'),feedback=root.querySelector('[data-tracking-feedback]'),records=JSON.parse(document.getElementById('tracking-destination-data').textContent),csrf=form.elements._token.value;
    let editing=null;
    const guides={META:'Meta: Events Manager → Data Sources → Pixel/Dataset ID (angka).',TIKTOK:'TikTok: Tools → Events → Web Events → Pixel ID.',GOOGLE:'Google Ads: Goals → Conversions → Tag setup. Tag ID dan conversion label adalah dua nilai berbeda.'};
    function updatePlatform(){const provider=form.elements.provider.value;root.querySelector('[data-platform-hint]').textContent=guides[provider];root.querySelector('[data-pixel-label]').textContent={META:'Pixel / Dataset ID',TIKTOK:'TikTok Pixel ID',GOOGLE:'Google tag ID / Ads destination'}[provider];form.elements.pixel_reference.placeholder={META:'123456789012345',TIKTOK:'C7U9F8D2A1B3C',GOOGLE:'AW-123456789 / G-ABC123 / GT-ABC123'}[provider];form.elements.account_reference.closest('label').hidden=provider==='META';if(form.elements.developer_token)form.elements.developer_token.closest('label').hidden=provider!=='GOOGLE';}
    function open(record=null,provider='META',readonly=false){
        form.reset();form.querySelector('[type=submit]').textContent='Simpan';editing=record?.id??null;form.querySelectorAll('input,select,textarea').forEach(input=>input.disabled=readonly);form.querySelector('[type=submit]').hidden=readonly;form.elements.provider.disabled=readonly||!!record;
        root.querySelector('[data-destination-title]').textContent=readonly?'Lihat Tracking Destination':record?'Ubah Tracking Destination':'Tambah Tracking Destination';root.querySelector('[data-destination-message]').textContent='';
        form.elements.provider.value=record?.provider??provider;
        if(record){for(const key of ['display_name','environment','pixel_reference','account_reference','notes'])form.elements[key].value=record[key]??'';for(const key of ['active','browser_enabled','server_enabled'])form.elements[key].checked=!!record[key];form.elements.domains.value=(JSON.parse(record.allowed_domains||'[]')||[]).join('\n');}
        updatePlatform();drawer.showModal();
    }
    root.querySelectorAll('[data-destination-add]').forEach(button=>button.onclick=()=>open(null,button.dataset.destinationAdd||'META'));
    root.querySelectorAll('[data-destination-edit]').forEach(button=>button.onclick=()=>open(records.find(record=>record.id===Number(button.dataset.destinationEdit))));
    root.querySelectorAll('[data-destination-view]').forEach(button=>button.onclick=()=>open(records.find(record=>record.id===Number(button.dataset.destinationView)),'META',true));
    form.elements.provider.onchange=updatePlatform;
    root.querySelectorAll('[data-tracking-close]').forEach(button=>button.onclick=()=>button.closest('dialog').close());
    function showGuide(provider='META'){root.querySelectorAll('[data-help-pane]').forEach(pane=>pane.hidden=pane.dataset.helpPane!==provider);root.querySelectorAll('[data-help-platform]').forEach(button=>button.setAttribute('aria-pressed',String(button.dataset.helpPlatform===provider)));}
    root.querySelectorAll('[data-tracking-help]').forEach(button=>button.onclick=()=>{showGuide(form.elements.provider.value);if(!help.open)help.showModal();if(button.dataset.trackingHelp==='privacy')help.querySelector('#tracking-privacy').scrollIntoView();});
    root.querySelectorAll('[data-help-platform]').forEach(button=>button.onclick=()=>showGuide(button.dataset.helpPlatform));
    const coach=root.querySelector('[data-tracking-coach]'),preference=`jm-tracking-tutorial-v813-${document.body.dataset.uiUser}`;
    try{coach.hidden=localStorage.getItem(preference)==='done';}catch{coach.hidden=true;}
    root.querySelector('[data-tracking-tutorial]').onclick=()=>coach.hidden=false;
    root.querySelector('[data-tracking-coach-close]').onclick=()=>{coach.hidden=true;try{localStorage.setItem(preference,'done');}catch{}};
    form.onsubmit=async event=>{
        event.preventDefault();const button=form.querySelector('[type=submit]'),message=root.querySelector('[data-destination-message]');button.disabled=true;message.textContent='';
        const data=Object.fromEntries(new FormData(form));delete data._token;delete data.domains;data.provider=form.elements.provider.value;data.allowed_domains=form.elements.domains.value.split(/[\s,]+/).filter(Boolean);for(const key of ['active','browser_enabled','server_enabled'])data[key]=form.elements[key].checked;
        try{const response=await fetch(editing?`${form.action}/${editing}`:form.action,{method:editing?'PATCH':'POST',credentials:'same-origin',headers:{Accept:'application/json','Content-Type':'application/json','X-CSRF-TOKEN':csrf},body:JSON.stringify(data)});const result=await response.json();if(!response.ok)throw Error(Object.values(result.errors||{}).flat().join(' ')||result.message||'Penyimpanan gagal.');if(result.duplicate){message.textContent='Tersimpan. ID ini juga digunakan destination lain; periksa agar tidak memasang tag ganda.';button.textContent='Tersimpan';}else location.reload();}catch(error){message.textContent=error.message;}finally{button.disabled=false;}
    };
    root.querySelectorAll('[data-destination-test]').forEach(button=>button.onclick=async()=>{button.disabled=true;feedback.textContent=button.dataset.testMode==='connection'?'Memeriksa koneksi vendor…':'Memvalidasi konfigurasi lokal…';try{const response=await fetch(button.dataset.destinationTest,{method:'POST',headers:{Accept:'application/json','Content-Type':'application/json','X-CSRF-TOKEN':csrf},body:JSON.stringify({mode:button.dataset.testMode||'event'})});const result=await response.json();if(!response.ok)throw Error(result.message||'Test gagal.');feedback.textContent=[result.status,result.message,...(result.warnings||[])].join(' ');}catch(error){feedback.textContent=error.message;}finally{button.disabled=false;}});
    const filterDialog=root.querySelector('[data-tracking-filter-dialog]'),filterForm=root.querySelector('[data-tracking-filter-form]'),filterHome=root.querySelector('[data-tracking-filter-home]');
    root.querySelector('[data-tracking-filter-open]').onclick=()=>{filterDialog.append(filterForm);filterDialog.showModal();};
    root.querySelector('[data-tracking-filter-close]').onclick=()=>filterDialog.close();
    filterDialog.addEventListener('close',()=>filterHome.append(filterForm));
    matchMedia('(max-width:767px)').addEventListener('change',event=>{if(!event.matches&&filterDialog.open)filterDialog.close();});
    if(new URL(location.href).searchParams.get('tab')==='server')document.getElementById('server-events').scrollIntoView();
})();
