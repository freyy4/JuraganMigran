@extends('layouts.candidates')
@section('title','Template WhatsApp')
@section('content')
<h1>Template WhatsApp</h1><p class="muted">Pesan membuka draft WhatsApp. Status terkirim harus dikonfirmasi petugas.</p>
@foreach($templates->prepend((object)['id'=>null,'name'=>'','vacancy_id'=>null,'body'=>'Halo {{name}}, terima kasih telah mendaftar {{vacancy}}.','status'=>'draft']) as $template)
<section class="card"><h2>{{ $template->id?$template->name:'Buat template' }}</h2><form method="POST" action="{{ route('acquisition.saveTemplate') }}" class="grid wa-template-form">@csrf<input type="hidden" name="id" value="{{ $template->id }}"><x-candidate-field name="name" label="Nama template" :value="$template->name" required /><div><label>Scope<select name="vacancy_id"><option value="">Global</option>@foreach($vacancies as $vacancy)<option value="{{ $vacancy->id }}" @selected($template->vacancy_id===$vacancy->id)>{{ $vacancy->title }}</option>
@endforeach</select></label></div><div><label>Status<select name="status">@foreach(['draft'=>'Draft','active'=>'Aktif','inactive'=>'Nonaktif'] as $status=>$label)<option value="{{ $status }}" @selected($template->status===$status)>{{ $label }}</option>
@endforeach</select></label></div><div class="full"><label>Pesan<textarea name="body" rows="5" required>{{ $template->body }}</textarea></label><p class="muted">@verbatim
Variabel: {{name}}, {{phone}}, {{vacancy}}, {{country}}, {{sponsor}}, {{stage}}, {{cs_name}}, {{candidate_id}}, {{lead_id}}, {{application_id}}, {{funnel_name}}.
@endverbatim</p></div><div class="wa-bubble full" aria-label="Pratinjau pesan"></div><button>Simpan template</button></form></section>
@endforeach
@endsection
@push('scripts')<script>document.querySelectorAll('.wa-template-form').forEach(form=>{const input=form.querySelector('textarea'),preview=form.querySelector('.wa-bubble');const update=()=>preview.textContent=input.value.replace(/\{\{([^}]+)\}\}/g,(all,key)=>({name:'Contoh CPMI',phone:'+628123456789',vacancy:'Operator Produksi',country:'Taiwan',sponsor:'Mitra',stage:'Verifikasi Awal',cs_name:'CS Juragan Migran',candidate_id:'CPMI-CONTOH',lead_id:'LEAD-CONTOH',application_id:'APP-CONTOH',funnel_name:'Funnel Contoh'}[key]??all));input.addEventListener('input',update);update();});</script>@endpush
