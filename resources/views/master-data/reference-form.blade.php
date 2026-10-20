<form method="post" action="{{ route('master-reference.save',$reference) }}" class="grid">@csrf
@if($entry)<input name="id" type="hidden" value="{{ $entry->id }}">@endif
<x-candidate-field name="code" label="Kode" :value="$entry?->code" required maxlength="100" />
<x-candidate-field name="name" label="Nama" :value="$entry?->name" required maxlength="255" />
<x-candidate-field name="category" label="Kategori / Sektor" :value="$entry?->category" maxlength="255" />
<div><label>Prioritas / Severity default</label><select name="default_priority">@foreach(['low'=>'Rendah','normal'=>'Normal','high'=>'Tinggi','urgent'=>'Mendesak'] as $value=>$label)<option value="{{ $value }}" @selected(($entry?->default_priority??'normal')===$value)>{{ $label }}</option>@endforeach</select></div>
<x-candidate-field name="sla_hours" label="SLA default (jam)" :value="$entry?->sla_hours" type="number" min="0" />
<x-candidate-field name="default_role" label="Role PIC / eskalasi default" :value="$entry?->default_role" maxlength="100" />
<div><label>Status</label><select name="is_active"><option value="1" @selected($entry?->is_active??true)>Aktif</option><option value="0" @selected($entry && !$entry->is_active)>Nonaktif</option></select></div>
<div class="full"><label>Deskripsi</label><textarea name="description" maxlength="2000">{{ $entry?->description }}</textarea></div><p class="muted full">Nonaktif mencegah pilihan baru dan mempertahankan histori. Kode sistem atau data yang sudah digunakan dilindungi.</p><div><button>Simpan</button></div></form>
