@extends('layouts.candidates')
@section('title', $company->exists ? 'Edit Mitra / Sponsor' : 'Tambah Mitra / Sponsor')
@section('content')
<x-master-workspace title="Mitra / Sponsor" description="Kelola mitra / sponsor sebagai acuan operasional. Periksa penggunaan dan cakupan sebelum mengubah konfigurasi.">
<a class="back" href="{{ route('companies.index') }}">← Daftar Mitra / Sponsor</a>
<div class="heading"><div><h1>{{ $company->exists ? 'Edit' : 'Tambah' }} Mitra / Sponsor</h1><p class="muted">Kolom bertanda * wajib diisi.</p></div></div>
<form method="post" action="{{ $company->exists ? route('companies.update', $company) : route('companies.store') }}">
@csrf @if($company->exists) @method('PUT') @endif
<section class="card"><h2>Informasi perusahaan</h2><div class="grid">
<x-candidate-field name="code" label="Kode perusahaan" :value="$company->code" required maxlength="255" />
<x-candidate-field name="name" label="Nama perusahaan" :value="$company->name" required maxlength="255" />
<div><label for="type">Jenis perusahaan <span class="required">*</span></label><select name="type" id="type" required><option value="">Pilih jenis perusahaan</option>@foreach(\App\Models\Company::typeLabels() as $key=>$label)<option value="{{ $key }}" @selected(old('type', $company->type) === $key)>{{ $label }}</option>@endforeach</select></div>
<fieldset class="full"><legend>Negara perusahaan</legend><p class="muted">Pilih satu atau lebih negara dengan mencentang pilihan berikut.</p>
<input type="hidden" name="countries_present" value="1">
@php($selectedCountries=array_map('strval', (array) old('country_ids', $company->selectedCountryIds())))
<div class="grid">
@forelse($countries as $country)
<label class="check"><input type="checkbox" name="country_ids[]" value="{{ $country->id }}" @checked(in_array((string)$country->id,$selectedCountries,true))>{{ $country->name }}{{ !$country->is_active ? ' (nonaktif)' : '' }}</label>
@empty
<p class="muted">Belum ada negara aktif. Tambahkan melalui <a href="{{ route('countries.create') }}">menu Negara</a>.</p>
@endforelse
</div>
@error('country_ids')
<div class="field-error">{{ $message }}</div>
@enderror
@error('country_ids.*')
<div class="field-error">{{ $message }}</div>
@enderror
</fieldset>
<x-candidate-field name="phone" label="Telepon" :value="$company->phone" type="tel" maxlength="30" />
<x-candidate-field name="email" label="Email" :value="$company->email" type="email" maxlength="255" />
<div><label for="status">Status <span class="required">*</span></label><select name="status" id="status" required>@foreach($statuses as $status)<option value="{{ $status }}" @selected(old('status', $company->status) === $status)>{{ \App\Models\Company::statusLabel($status) }}</option>@endforeach</select></div>
<div class="full"><label for="address">Alamat</label><textarea id="address" name="address" maxlength="5000">{{ old('address', $company->address) }}</textarea></div>
</div></section>
<section class="card"><h2>Daftar PIC</h2><p class="muted">Tambahkan kontak perusahaan dan pilih paling banyak satu PIC utama.</p><div id="contacts">@foreach(old('contacts', $company->exists ? $company->contacts->toArray() : []) as $index=>$contact)@include('companies.contact-fields')@endforeach</div><button type="button" class="secondary" id="add-contact">+ Tambah PIC</button></section>
<div class="actions footer-actions"><button type="submit">Simpan {{ $company->exists ? 'Perubahan' : 'Perusahaan' }}</button><a class="button secondary" href="{{ $company->exists ? route('companies.show', $company) : route('companies.index') }}">Batal</a></div>
</form>
<template id="contact-template">@include('companies.contact-fields', ['index'=>'__INDEX__','contact'=>[]])</template>
</x-master-workspace>
@endsection
@push('scripts')
<script>
const contacts = document.getElementById('contacts');
let nextIndex = Math.max(-1, ...Array.from(contacts.querySelectorAll('input[name]')).map(input => Number(input.name.match(/^contacts\[(\d+)\]/)?.[1] ?? -1))) + 1;
document.getElementById('add-contact').addEventListener('click', () => { contacts.insertAdjacentHTML('beforeend', document.getElementById('contact-template').innerHTML.replaceAll('__INDEX__', String(nextIndex++))); });
contacts.addEventListener('click', event => { const button = event.target.closest('[data-remove-contact]'); if (button) button.closest('[data-contact]').remove(); });
contacts.addEventListener('change', event => { if (event.target.matches('input[type="checkbox"]') && event.target.checked) contacts.querySelectorAll('input[type="checkbox"]').forEach(input => { if (input !== event.target) input.checked = false; }); });
</script>
@endpush
