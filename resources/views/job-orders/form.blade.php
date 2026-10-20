@extends('layouts.candidates')
@section('title', $order->exists ? 'Edit Permintaan Tenaga Kerja' : 'Tambah Permintaan Tenaga Kerja')
@section('content')
<a class="back" href="{{ route('job-orders.index') }}">← Daftar permintaan tenaga kerja</a>
<div class="heading"><div><h1>{{ $order->exists ? 'Edit' : 'Tambah' }} Permintaan Tenaga Kerja</h1><p class="muted">{{ $order->exists ? 'Kode: '.$order->code : 'Kode dibuat otomatis setelah disimpan.' }} Kolom bertanda * wajib diisi.</p></div></div>
<form method="post" action="{{ $order->exists ? route('job-orders.update', $order) : route('job-orders.store') }}">@csrf @if($order->exists) @method('PUT') @endif
<section class="card"><h2>Informasi permintaan</h2><div class="grid">
<div><label for="company_id">Mitra <span class="required">*</span></label><select id="company_id" name="company_id" required><option value="">Pilih mitra</option>@foreach($companies as $company)<option value="{{ $company->id }}" @selected((string)old('company_id', $order->company_id) === (string)$company->id)>{{ $company->name }}{{ $company->trashed() ? ' (dihapus)' : '' }}</option>@endforeach</select>@if($companies->isEmpty())<p class="muted">Tambahkan perusahaan melalui menu <a href="{{ route('companies.create') }}">Mitra / Sponsor</a> terlebih dahulu.</p>@endif</div>
<div><label for="country_id">Negara <span class="required">*</span></label><select id="country_id" name="country_id" required><option value="">Pilih negara</option>@foreach($countries as $country)<option value="{{ $country->id }}" @selected((string)old('country_id', $order->country_id) === (string)$country->id)>{{ $country->name }}{{ !$country->is_active ? ' (nonaktif)' : '' }}</option>@endforeach</select>@if($countries->isEmpty())<p class="muted">Data negara belum tersedia.</p>@endif</div>
<x-candidate-field name="reference_number" label="Nomor referensi" :value="$order->reference_number" maxlength="255" />
<x-candidate-field name="quota" label="Kuota" type="number" :value="$order->quota" required min="0" max="4294967295" step="1" />
<x-candidate-field name="valid_from" label="Berlaku dari" type="date" :value="$order->valid_from?->format('Y-m-d')" />
<x-candidate-field name="valid_until" label="Berlaku sampai" type="date" :value="$order->valid_until?->format('Y-m-d')" />
<div><label for="status">Status <span class="required">*</span></label><select id="status" name="status" required>@foreach($statuses as $status)<option value="{{ $status }}" @selected(old('status', $order->status) === $status)>{{ \App\Models\Company::statusLabel($status) }}</option>@endforeach</select></div>
</div></section>
<div class="actions footer-actions"><button type="submit">Simpan {{ $order->exists ? 'Perubahan' : 'Permintaan' }}</button><a class="button secondary" href="{{ $order->exists ? route('job-orders.show', $order) : route('job-orders.index') }}">Batal</a></div>
</form>
@endsection
