@extends('layouts.candidates')
@section('title', $candidate->exists ? 'Edit Calon Pekerja' : 'Tambah Calon Pekerja')
@section('content')
<a class="back" href="{{ route('candidates.index') }}">← Daftar calon pekerja</a>
<div class="heading"><div><h1>{{ $candidate->exists ? 'Edit' : 'Tambah' }} Calon Pekerja</h1><p class="muted">Lengkapi profil calon pekerja. Kolom bertanda * wajib diisi.</p></div></div>
<form method="post" action="{{ $candidate->exists ? route('candidates.update', $candidate) : route('candidates.store') }}">
@csrf @if($candidate->exists) @method('PUT') @endif
<section class="card"><h2>Data pendaftaran</h2><div class="grid">
<x-candidate-field name="candidate_code" label="Kode CPMI" :value="$candidate->candidate_code" required maxlength="255" />
<x-candidate-field name="status" label="Status" :value="$candidate->status" required maxlength="255" list="statuses" />
<datalist id="statuses">@foreach($statuses as $status)<option value="{{ $status }}">{{ \App\Models\Candidate::statusLabel($status) }}</option>@endforeach</datalist>
<x-candidate-field name="registered_at" label="Tanggal daftar" type="datetime-local" :value="$candidate->registered_at?->format('Y-m-d\TH:i')" required />
</div></section>
<section class="card"><h2>Profil calon pekerja</h2><div class="grid">
<div><label for="marital_status">Status perkawinan</label><select id="marital_status" name="person[marital_status]"><option value="">Belum diisi</option>@foreach(['single'=>'Belum menikah','married'=>'Menikah','divorced'=>'Cerai hidup','widowed'=>'Cerai mati'] as $value=>$label)<option value="{{ $value }}" @selected(old('person.marital_status',$candidate->person?->marital_status)===$value)>{{ $label }}</option>@endforeach</select></div>
@foreach(['full_name'=>'Nama lengkap','nik'=>'NIK','birth_place'=>'Tempat lahir','birth_date'=>'Tanggal lahir','phone'=>'Telepon','whatsapp'=>'WhatsApp','email'=>'Email','education_level'=>'Pendidikan'] as $field=>$label)
<x-candidate-field :name="'person['.$field.']'" :label="$label" :value="$field === 'birth_date' ? $candidate->person?->birth_date?->format('Y-m-d') : $candidate->person?->{$field}" :type="match($field) {'birth_date'=>'date', 'email'=>'email', 'phone','whatsapp'=>'tel', default=>'text'}" :required="$field === 'full_name'" :maxlength="match($field) {'nik'=>16,'phone','whatsapp'=>30,default=>255}" />
@endforeach
<div><label for="gender">Jenis kelamin</label><select id="gender" name="person[gender]"><option value="">Pilih jenis kelamin</option><option value="male" @selected(old('person.gender', $candidate->person?->gender) === 'male')>Laki-laki</option><option value="female" @selected(old('person.gender', $candidate->person?->gender) === 'female')>Perempuan</option></select></div>
<div class="full"><label for="address">Alamat</label><textarea id="address" name="person[address]" maxlength="5000">{{ old('person.address', $candidate->person?->address) }}</textarea></div>
</div></section>
<section class="card"><h2>Kontak keluarga / darurat</h2><p class="muted">Tambahkan kontak yang dapat dihubungi bila diperlukan.</p>
<div id="contacts">@foreach(old('contacts', $candidate->exists ? $candidate->contacts->toArray() : []) as $index=>$contact)@include('candidates.contact-fields')@endforeach</div>
<button type="button" class="secondary" id="add-contact">+ Tambah kontak</button>
</section>
<div class="actions footer-actions"><button type="submit">Simpan {{ $candidate->exists ? 'Perubahan' : 'Calon Pekerja' }}</button><a class="button secondary" href="{{ $candidate->exists ? route('candidates.show', $candidate) : route('candidates.index') }}">Batal</a></div>
</form>
<template id="contact-template">@include('candidates.contact-fields', ['index'=>'__INDEX__', 'contact'=>[]])</template>
@endsection
@push('scripts')
<script>
const contacts = document.getElementById('contacts');
let nextIndex = Math.max(-1, ...Array.from(contacts.querySelectorAll('input[name]')).map(input => Number(input.name.match(/^contacts\[(\d+)\]/)?.[1] ?? -1))) + 1;
document.getElementById('add-contact').addEventListener('click', () => {
    contacts.insertAdjacentHTML('beforeend', document.getElementById('contact-template').innerHTML.replaceAll('__INDEX__', String(nextIndex++)));
});
contacts.addEventListener('click', event => { const button = event.target.closest('[data-remove-contact]'); if (button) button.closest('[data-contact]').remove(); });
</script>
@endpush
