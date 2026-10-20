@extends('layouts.candidates')
@section('title',$country->exists ? 'Edit Negara' : 'Tambah Negara')
@section('content')
<x-master-workspace title="Negara" description="Kelola negara sebagai acuan operasional. Periksa penggunaan dan cakupan sebelum mengubah konfigurasi.">
<div class="heading"><h1>{{ $country->exists ? 'Edit' : 'Tambah' }} Negara</h1></div>
<form method="post" action="{{ $country->exists ? route('countries.update',$country) : route('countries.store') }}">@csrf @if($country->exists) @method('put') @endif<section class="card"><div class="grid"><x-candidate-field name="code" label="Kode negara (2–3 huruf)" :value="$country->code" required maxlength="3" /><x-candidate-field name="name" label="Nama negara" :value="$country->name" required maxlength="255" /><x-candidate-field name="region" label="Kawasan" :value="$country->region" maxlength="100" /><x-candidate-field name="flag" label="Bendera / ikon" :value="$country->flag" maxlength="16" /><div><label for="is_active">Status *</label><select id="is_active" name="is_active" required><option value="1" @selected((string)old('is_active',(int)$country->is_active)==='1')>Aktif</option><option value="0" @selected((string)old('is_active',(int)$country->is_active)==='0')>Nonaktif</option></select></div></div></section><div class="actions footer-actions"><button>Simpan Negara</button><a class="button secondary" href="{{ route('countries.index') }}">Batal</a></div></form>
</x-master-workspace>
@endsection
