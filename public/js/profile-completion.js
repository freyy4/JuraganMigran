(() => {
 const form = document.getElementById('completion-form'); if (!form) return;
 const labels = ['Data Dasar','Screening Awal','Dokumen','Preferensi Kerja','Keluarga & Izin','Kesiapan Berangkat'];
 const stateKey = `cpmi-step-${form.dataset.key}`;
 let step = Number(sessionStorage.getItem(stateKey) || 0), timer, dirty = false, saving = false, queued = false, revision = 0;
 const status = document.getElementById('autosave-status');
 const value = name => form.elements.namedItem(name)?.value || '';
 const text = (parent, message, cls = '') => { const p = document.createElement('p'); p.className = cls; p.textContent = message; parent.append(p); };
 function navigate(next) { step = Math.max(0, Math.min(6, next)); sessionStorage.setItem(stateKey, step); form.querySelectorAll('[data-step-panel]').forEach(p => p.hidden = Number(p.dataset.stepPanel) !== step); form.querySelectorAll('[data-step]').forEach(b => { if (Number(b.dataset.step) === step) b.setAttribute('aria-current','step'); else b.removeAttribute('aria-current'); }); document.getElementById('previous-step').disabled = step === 0; form.querySelectorAll('[data-advance]').forEach(b => b.textContent = step === 6 ? 'Simpan & Selesai' : 'Simpan & Lanjutkan →'); }
 function insights() {
  const birth = value('person[birth_date]'); let age = null;
  if (birth) { const [y,m,d] = birth.split('-').map(Number); const today = new Date(); age = today.getFullYear()-y - (today.getMonth()+1<m || (today.getMonth()+1===m && today.getDate()<d) ? 1 : 0); }
  form.elements.namedItem('age_display').value = age === null ? 'Diisi otomatis dari tanggal lahir' : `${age} tahun`;
  const fields = [['person[full_name]','person[birth_date]','person[whatsapp]','person[address]'],['person[height_cm]','person[weight_kg]'],[],['preferences[target_country_id]','preferences[work_sector]','preferences[experience_summary]'],[],['preferences[passport_status]','preferences[departure_readiness]']];
  const counts = fields.map(names => names.filter(n => value(n).trim()).length); const family = form.querySelectorAll('#contacts [data-contact]').length; counts[4] = family ? 1 : 0;
  const totals = [4,2,1,3,1,2], done = counts.reduce((a,b)=>a+b,0), total = totals.reduce((a,b)=>a+b,0); const score = Math.round(done/total*100);
  document.getElementById('completion-score').textContent=score; document.querySelector('.completion-ring').style.setProperty('--score', `${score}%`);
  const scores=document.getElementById('section-scores'), tasks=document.getElementById('next-tasks'), review=document.getElementById('profile-review'); scores.replaceChildren();tasks.replaceChildren();review.replaceChildren();
  labels.forEach((label,i)=>{text(scores,`${label}   ${counts[i]}/${totals[i]}`); if(counts[i]<totals[i]) { const b=document.createElement('button');b.type='button';b.className='secondary task-row';b.textContent=`○ Lengkapi ${label.toLowerCase()}`;b.onclick=()=>navigate(i);tasks.append(b); } text(review,`${label}: ${counts[i]}/${totals[i]} data tersedia${i===2 ? ' — verifikasi melalui Document Engine' : ''}`); });
  const panel=document.getElementById('data-insights'); panel.replaceChildren();text(panel,age === null ? 'Isi tanggal lahir untuk menghitung usia otomatis.' : `Usia otomatis terhitung: ${age} tahun.`, 'insight-row');
  const marital=value('person[marital_status]'); const documents={married:'Buku Nikah diperlukan karena status perkawinan Menikah.',divorced:'Akta Cerai diperlukan karena status Cerai Hidup.',widowed:'Akta Kematian pasangan perlu diperiksa sesuai program.',single:'Dokumen perkawinan tidak diminta kecuali aturan program mewajibkan.'};
  const message=documents[marital] || 'Lengkapi status perkawinan untuk mengevaluasi dokumen keluarga.'; text(panel,message,'insight-row');document.getElementById('document-insight').textContent=message;
  text(panel,'Persyaratan akhir dokumen dan izin mengikuti konfigurasi program pada lamaran.','insight-row');
 }
 function clearErrors(){form.querySelectorAll('.autosave-error').forEach(e=>e.remove());}
 async function save() {
  if(saving){queued=true;return false;} const invalid = Array.from(form.elements).find(el => el.willValidate && !el.validity.valid); if(invalid){const panel=invalid.closest('[data-step-panel]');if(panel)navigate(Number(panel.dataset.stepPanel));} if(!form.reportValidity()){status.textContent='Lengkapi kolom wajib sebelum menyimpan.';return false;}
  const savedRevision = revision; saving=true; status.textContent='Menyimpan…'; const snapshot=new FormData(form); snapshot.delete('age_display'); clearErrors();
  try { const response=await fetch(form.action,{method:'POST',headers:{Accept:'application/json','X-Requested-With':'XMLHttpRequest'},body:snapshot});const data=await response.json();if(!response.ok){for(const [key,messages] of Object.entries(data.errors||{})){const name=key.replace(/\.([^.]*)/g,'[$1]'); const input=form.elements.namedItem(name);const error=document.createElement('small');error.className='autosave-error field-error';error.textContent=messages[0];(input?.parentElement||status.parentElement).append(error);} throw new Error(response.status===409?'Profil diubah staf lain. Muat ulang sebelum menyimpan.':data.message||'Gagal menyimpan.');} if(data.update_url){form.action=data.update_url;form.dataset.existing='1';let method=form.elements.namedItem('_method');if(!method){method=document.createElement('input');method.type='hidden';method.name='_method';form.append(method);}method.value='PUT';} form.elements.namedItem('profile_version').value=data.version; dirty=queued || revision !== savedRevision;status.textContent='✓ Tersimpan'; return true;
  }catch(error){status.textContent=`Gagal menyimpan: ${error.message} Klik Simpan Draft untuk mencoba lagi.`;return false;}finally{saving=false;if(queued){queued=false;save();}}
 }
 form.addEventListener('input',()=>{dirty=true;revision++;insights();clearTimeout(timer);if(value('person[full_name]').trim())timer=setTimeout(save,1100);});
 form.addEventListener('change',()=>{dirty=true;revision++;insights();clearTimeout(timer);if(value('person[full_name]').trim())timer=setTimeout(save,1100);});
 document.querySelectorAll('[data-save-draft]').forEach(b=>b.onclick=()=>{clearTimeout(timer);save();});
 document.querySelectorAll('[data-advance]').forEach(b=>b.onclick=async()=>{clearTimeout(timer);if(await save()){if(step===6)location.href=form.action;else navigate(step+1);}});
 form.querySelectorAll('[data-step]').forEach(b=>b.onclick=()=>navigate(Number(b.dataset.step)));document.getElementById('previous-step').onclick=()=>navigate(step-1);
 form.addEventListener('keydown',e=>{if(e.key==='Enter' && e.target.tagName==='INPUT')e.preventDefault();if((e.ctrlKey||e.metaKey)&&e.key==='s'){e.preventDefault();save();}});
 const contacts=document.getElementById('contacts');let nextIndex=Math.max(-1,...Array.from(contacts.querySelectorAll('input[name]')).map(i=>Number(i.name.match(/^contacts\[(\d+)\]/)?.[1]??-1)))+1;
 document.getElementById('add-contact').onclick=()=>{contacts.insertAdjacentHTML('beforeend',document.getElementById('contact-template').innerHTML.replaceAll('__INDEX__',String(nextIndex++)));dirty=true;revision++;insights();};
 contacts.onclick=e=>{const b=e.target.closest('[data-remove-contact]');if(b){b.closest('[data-contact]').remove();dirty=true;revision++;insights();clearTimeout(timer);timer=setTimeout(save,1100);}};
 window.addEventListener('beforeunload',e=>{if(dirty){e.preventDefault();e.returnValue='';}});window.addEventListener('offline',()=>status.textContent='Offline — perubahan belum tersimpan.');window.addEventListener('online',()=>{if(dirty)save();});form.addEventListener('submit',()=>dirty=false);
 navigate(step);insights();
})();
