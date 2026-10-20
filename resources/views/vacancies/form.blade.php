@extends('layouts.candidates')
@section('title', $vacancy->exists ? 'Edit Lowongan' : 'Tambah Lowongan')
@section('content')
<a class="back" href="{{ route('vacancies.index') }}">← Daftar lowongan</a>
<div class="heading"><div><h1>{{ $vacancy->exists ? 'Edit' : 'Tambah' }} Lowongan</h1><p class="muted">Kolom bertanda * wajib diisi.</p></div></div>
<form method="post" action="{{ $vacancy->exists ? route('vacancies.update',$vacancy) : route('vacancies.store') }}">@csrf @if($vacancy->exists) @method('PUT') @endif
<section class="card"><h2>Informasi lowongan</h2><div class="grid">
<x-candidate-field name="code" label="Kode lowongan" :value="$vacancy->code" required maxlength="255" />
@php($selectedOrder=$orders->firstWhere('id',old('job_order_id',$vacancy->job_order_id)))
<div><label for="destination_country">Negara tujuan</label><select id="destination_country"><option value="">Semua negara tujuan</option>@foreach($countries as $country)<option value="{{ $country->id }}" @selected((string)$selectedOrder?->country_id === (string)$country->id)>{{ $country->name }}{{ !$country->is_active ? ' (nonaktif)' : '' }}</option>@endforeach</select><p class="muted">Negara lowongan mengikuti permintaan tenaga kerja yang dipilih.</p></div>
<div><label for="job_order_id">Permintaan tenaga kerja <span class="required">*</span></label><select id="job_order_id" name="job_order_id" required><option value="">Pilih permintaan tenaga kerja</option>@foreach($orders as $order)<option data-country-id="{{ $order->country_id }}" value="{{ $order->id }}" @selected((string)old('job_order_id',$vacancy->job_order_id) === (string)$order->id)>{{ $order->code }} · {{ $order->company?->name }} · {{ $order->country?->name }}</option>@endforeach</select>@if($orders->isEmpty())<p class="muted">Tambahkan <a href="{{ route('job-orders.create') }}">permintaan tenaga kerja</a> terlebih dahulu.</p>@endif</div>
<x-candidate-field name="title" label="Nama lowongan" :value="$vacancy->title" required maxlength="255" />
<x-candidate-field name="position_name" label="Posisi" :value="$vacancy->position_name" required maxlength="255" />
<x-candidate-field name="quota" label="Kuota" type="number" :value="$vacancy->quota" required min="0" max="4294967295" step="1" />
<x-candidate-field name="salary" label="Gaji" type="number" :value="$vacancy->salary" min="0" step="0.01" />
<x-candidate-field name="currency" label="Mata uang (kode tiga huruf)" :value="$vacancy->currency" maxlength="3" placeholder="Contoh: IDR" />
<x-candidate-field name="contract_duration" label="Durasi kontrak" :value="$vacancy->contract_duration" maxlength="255" />
<div><label for="status">Status <span class="required">*</span></label><select id="status" name="status" required>@foreach($statuses as $status)<option value="{{ $status }}" @selected(old('status',$vacancy->status) === $status)>{{ \App\Models\Company::statusLabel($status) }}</option>@endforeach</select></div>
<div class="full"><label for="description">Deskripsi</label><textarea id="description" name="description" maxlength="50000">{{ old('description',$vacancy->description) }}</textarea></div>
</div></section>
<section class="card"><h2>Persyaratan</h2><p class="muted">Pilih persyaratan yang digunakan, lalu isi nilai dan ketentuannya.</p>
@forelse($definitions as $definition)
@php($saved = $requirements->get($definition->id))
@php($prefix = 'requirements.'.$definition->id)
<div class="contact" data-requirement>
<input type="hidden" name="requirements[{{ $definition->id }}][definition_id]" value="{{ $definition->id }}">
<input type="hidden" name="requirements[{{ $definition->id }}][selected]" value="0">
<label class="check"><input type="checkbox" name="requirements[{{ $definition->id }}][selected]" value="1" data-selected @checked(old($prefix.'.selected',(bool)$saved))>{{ $definition->name }}{{ $definition->unit ? ' ('.$definition->unit.')' : '' }}{{ !$definition->is_active ? ' · Definisi tidak aktif' : '' }}</label>
<p class="muted">{{ $definition->category }}</p>
<div class="grid" data-fields>
<div><label for="operator-{{ $definition->id }}">Ketentuan</label><select id="operator-{{ $definition->id }}" name="requirements[{{ $definition->id }}][operator]" data-operator>@foreach($definition->operators() as $key=>$label)<option value="{{ $key }}" @selected(old($prefix.'.operator',$saved?->operator ?? 'eq') === $key)>{{ $label }}</option>@endforeach</select></div>
<div data-value><label for="value-{{ $definition->id }}">Nilai{{ $definition->unit ? ' ('.$definition->unit.')' : '' }}</label>
@if($definition->inputType() === 'boolean')<select id="value-{{ $definition->id }}" name="requirements[{{ $definition->id }}][value]"><option value="">Pilih nilai</option><option value="1" @selected((string)old($prefix.'.value',$saved?->value) === '1')>Ya</option><option value="0" @selected((string)old($prefix.'.value',$saved?->value) === '0')>Tidak</option></select>
@else<input id="value-{{ $definition->id }}" name="requirements[{{ $definition->id }}][value]" type="{{ $definition->inputType() }}" value="{{ old($prefix.'.value',$saved?->value) }}" @if($definition->inputType() === 'number') step="{{ in_array($definition->data_type,['integer','int']) ? '1' : 'any' }}" @else maxlength="5000" @endif>@endif
</div>
@if($definition->inputType() === 'number')
<div data-range><label for="min-{{ $definition->id }}">Batas minimum{{ $definition->unit ? ' ('.$definition->unit.')' : '' }}</label><input id="min-{{ $definition->id }}" name="requirements[{{ $definition->id }}][value_min]" type="number" step="{{ in_array($definition->data_type,['integer','int']) ? '1' : '0.0001' }}" value="{{ old($prefix.'.value_min',in_array($definition->data_type,['integer','int']) && $saved?->value_min !== null ? (int)$saved->value_min : $saved?->value_min) }}"></div>
<div data-range><label for="max-{{ $definition->id }}">Batas maksimum{{ $definition->unit ? ' ('.$definition->unit.')' : '' }}</label><input id="max-{{ $definition->id }}" name="requirements[{{ $definition->id }}][value_max]" type="number" step="{{ in_array($definition->data_type,['integer','int']) ? '1' : '0.0001' }}" value="{{ old($prefix.'.value_max',in_array($definition->data_type,['integer','int']) && $saved?->value_max !== null ? (int)$saved->value_max : $saved?->value_max) }}"></div>
@endif
<div><input type="hidden" name="requirements[{{ $definition->id }}][is_required]" value="0"><label class="check"><input type="checkbox" name="requirements[{{ $definition->id }}][is_required]" value="1" @checked(old($prefix.'.is_required',$saved?->is_required ?? true))>Syarat wajib</label></div>
<div class="full"><label for="source-{{ $definition->id }}">Referensi sumber</label><textarea id="source-{{ $definition->id }}" name="requirements[{{ $definition->id }}][source_reference]" maxlength="5000">{{ old($prefix.'.source_reference',$saved?->source_reference) }}</textarea></div>
</div></div>
@empty<p class="muted">Belum ada definisi persyaratan aktif. Persyaratan akan tersedia setelah data definisi ditambahkan.</p>@endforelse
</section><div class="actions footer-actions"><button type="submit">Simpan {{ $vacancy->exists ? 'Perubahan' : 'Lowongan' }}</button><a class="button secondary" href="{{ $vacancy->exists ? route('vacancies.show',$vacancy) : route('vacancies.index') }}">Batal</a></div>
</form>
@endsection
@push('scripts')
<script>
const destinationCountry=document.getElementById('destination_country');
const jobOrder=document.getElementById('job_order_id');
function filterJobOrders() {
    const country=destinationCountry.value;
    Array.from(jobOrder.options).forEach(option=> { if(!option.value)return; const matches=!country || option.dataset.countryId===country; option.hidden=!matches; option.disabled=!matches; });
    if(jobOrder.selectedOptions[0]?.disabled)jobOrder.value='';
}
destinationCountry.addEventListener('change',filterJobOrders);
jobOrder.addEventListener('change',()=>{if(jobOrder.value){destinationCountry.value=jobOrder.selectedOptions[0].dataset.countryId;filterJobOrders();}});
filterJobOrders();

function updateRequirement(row) {
    const selected = row.querySelector('[data-selected]').checked;
    const fields = row.querySelector('[data-fields]');
    fields.hidden = !selected;
    fields.querySelectorAll('input,select,textarea').forEach(input => input.disabled = !selected);
    const between = row.querySelector('[data-operator]').value === 'between';
    row.querySelectorAll('[data-range]').forEach(group => { group.hidden = !between; group.querySelectorAll('input').forEach(input => input.disabled = !selected || !between); });
    const value = row.querySelector('[data-value]');
    value.hidden = between;
    value.querySelectorAll('input,select').forEach(input => input.disabled = !selected || between);
}
document.querySelectorAll('[data-requirement]').forEach(row => { updateRequirement(row); row.addEventListener('change',() => updateRequirement(row)); });
</script>
@endpush
