@extends('layouts.candidates')
@section('title', $event->exists ? 'Edit MCU' : 'Jadwalkan MCU')
@section('content')
<a class="back" href="{{ route('medical-events.index') }}">← Daftar MCU</a><div class="heading"><div><h1>{{ $event->exists ? 'Edit MCU' : 'Jadwalkan MCU' }}</h1><p class="muted">Catat hasil sesuai laporan klinik. Waktu menggunakan WIB.</p></div></div>
@if($clinics->isEmpty())<div class="errors" role="alert">Belum ada klinik aktif. <a href="{{ route('companies.create') }}">Tambahkan mitra dengan jenis Klinik</a> terlebih dahulu.</div>@endif
<form method="post" action="{{ $event->exists ? route('medical-events.update', $event) : route('medical-events.store') }}">@csrf @if($event->exists) @method('PUT') @endif
<section class="card"><div class="grid">
<div class="full"><label for="application_id">Lamaran *</label>@if($event->exists)<input type="hidden" name="application_id" value="{{ $event->application_id }}"><p>{{ $event->application?->application_code }} · {{ $event->application?->candidate?->person?->full_name }}</p>@else<select id="application_id" name="application_id" required><option value="">Pilih lamaran</option>@foreach($applications as $application)<option value="{{ $application->id }}" @selected((string)old('application_id') === (string)$application->id)>{{ $application->application_code }} · {{ $application->candidate?->person?->full_name }}</option>@endforeach</select>@endif</div>
<div><label for="clinic_id">Klinik *</label><select id="clinic_id" name="clinic_id" required><option value="">Pilih klinik</option>@foreach($clinics as $clinic)<option value="{{ $clinic->id }}" @selected((string)old('clinic_id', $event->clinic_id) === (string)$clinic->id)>{{ $clinic->name }}{{ $clinic->trashed() || $clinic->status !== 'active' ? ' (tidak aktif)' : '' }}</option>@endforeach</select></div>
<x-candidate-field name="scheduled_at" label="Jadwal MCU (WIB)" type="datetime-local" :value="$event->scheduled_at?->copy()->setTimezone('Asia/Jakarta')->format('Y-m-d\TH:i')" required />
<div><label for="status">Status *</label><select id="status" name="status" required>@foreach(\App\Models\MedicalEvent::STATUSES as $key=>$label)<option value="{{ $key }}" @selected(old('status', $event->status) === $key)>{{ $label }}</option>@endforeach</select></div>
<x-candidate-field name="completed_at" label="Waktu Selesai (WIB)" type="datetime-local" :value="$event->completed_at?->copy()->setTimezone('Asia/Jakarta')->format('Y-m-d\TH:i')" />
<div><label for="clearance_status">Hasil Klinik</label><select id="clearance_status" name="clearance_status"><option value="">Belum ada hasil</option>@foreach(\App\Models\MedicalEvent::CLEARANCES as $key=>$label)<option value="{{ $key }}" @selected(old('clearance_status', $event->clearance_status) === $key)>{{ $label }}</option>@endforeach</select></div>
<x-candidate-field name="expires_at" label="Berlaku Sampai" type="date" :value="$event->expires_at?->format('Y-m-d')" />
</div><p class="muted">Untuk status Selesai, isi waktu selesai dan hasil klinik.</p></section>
<div class="footer-actions actions"><button @disabled($clinics->isEmpty() || $applications->isEmpty())>Simpan MCU</button><a class="button secondary" href="{{ route('medical-events.index') }}">Batal</a></div></form>
@endsection
