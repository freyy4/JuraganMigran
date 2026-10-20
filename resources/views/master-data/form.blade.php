@extends('layouts.candidates')
@section('title',($record->exists ? 'Edit ' : 'Tambah ').$definition['label'])
@section('content')
<x-master-workspace title="Data Master" description="Kelola data master sebagai acuan operasional. Periksa penggunaan dan cakupan sebelum mengubah konfigurasi.">
<div class="heading"><h1>{{ $record->exists ? 'Edit' : 'Tambah' }} {{ $definition['label'] }}</h1></div>
<form method="post" action="{{ $record->exists ? route('master-data.update',[$master,$record->id]) : route('master-data.store',$master) }}">@csrf @if($record->exists) @method('put') @endif<section class="card"><div class="grid">
@if(isset($definition['parent_field']))<div><label for="parent">{{ $definition['parent_label'] }} *</label><select name="{{ $definition['parent_field'] }}" id="parent" required><option value="">Pilih {{ strtolower($definition['parent_label']) }}</option>@foreach($parents as $parent)<option value="{{ $parent->id }}" @selected((string)old($definition['parent_field'],$record->{$definition['parent_field']})===(string)$parent->id)>{{ $parent->name }} · {{ $parent->code ?: $parent->id }}</option>@endforeach</select></div>@endif
@foreach($definition['fields'] as $field=>$label)
@if($field==='allowed_file_types')<div><label>Format file yang diizinkan</label>@foreach(['pdf','jpg','jpeg','png'] as $format)<label class="check"><input type="checkbox" name="allowed_file_types[]" value="{{ $format }}" @checked(in_array($format,old('allowed_file_types',$record->allowed_file_types ?: ['pdf','jpg','jpeg','png'])))>{{ strtoupper($format) }}</label>@endforeach</div>
@elseif(in_array($field,['is_active','has_expiry','is_terminal']))<div><label for="{{ $field }}">{{ $label }} *</label><select id="{{ $field }}" name="{{ $field }}" required><option value="1" @selected((string)old($field,(int)$record->{$field})==='1')>{{ $field==='is_active' ? 'Aktif' : 'Ya' }}</option><option value="0" @selected((string)old($field,(int)$record->{$field})==='0')>{{ $field==='is_active' ? 'Nonaktif' : 'Tidak' }}</option></select></div>
@elseif($field==='data_type')<div><label for="data_type">Tipe Data *</label><select id="data_type" name="data_type" required>@foreach(\App\Models\RequirementDefinition::DATA_TYPES as $value=>$text)<option value="{{ $value }}" @selected(old($field,$record->{$field})===$value)>{{ $text }}</option>@endforeach</select></div>
@else<x-candidate-field :name="$field" :label="$label" :value="$record->{$field}" :type="in_array($field,['sequence','sla_hours','max_file_size_kb']) ? 'number' : 'text'" :required="$field==='name' || $field==='sequence' || ($field==='code' && ($definition['unique_code'] ?? false)) || ($field==='category' && $master==='jenis-persyaratan')" :maxlength="$field==='postal_code' ? 10 : 255" :min="in_array($field,['sequence','sla_hours','max_file_size_kb']) ? 0 : null" />
@endif
@endforeach
</div></section><div class="actions footer-actions"><button>Simpan</button><a class="button secondary" href="{{ route('master-data.index',$master) }}">Batal</a></div></form>
</x-master-workspace>
@endsection
