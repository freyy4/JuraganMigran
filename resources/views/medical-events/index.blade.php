@extends('layouts.candidates')
@section('title', 'MCU')
@section('content')
<div class="heading"><div><h1>MCU</h1><p class="muted">Kelola jadwal dan catat hasil pemeriksaan dari klinik. Waktu menggunakan WIB.</p></div><a class="button" href="{{ route('medical-events.create') }}">+ Jadwalkan MCU</a></div>
<form method="get" action="{{ route('medical-events.index') }}" class="card filters"><div class="search"><label for="search">Cari MCU</label><input id="search" name="search" value="{{ request('search') }}" maxlength="255" placeholder="Kode lamaran atau nama CPMI"></div><div><label for="status">Status</label><select id="status" name="status"><option value="">Semua</option>@foreach(\App\Models\MedicalEvent::STATUSES as $key=>$label)<option value="{{ $key }}" @selected(request('status') === $key)>{{ $label }}</option>@endforeach</select></div>
<div class="filter-actions"><button>Terapkan</button><a class="button secondary" href="{{ route('medical-events.index') }}">Reset</a></div>
</form>
<div class="card table-card"><div class="table-scroll"><table><thead><tr><th>Jadwal (WIB)</th><th>Lamaran / CPMI</th><th>Klinik</th><th>Status</th><th>Hasil Klinik</th><th>Berlaku Sampai</th><th>Aksi</th></tr></thead><tbody>
@forelse($events as $event)<tr><td>{{ $event->scheduled_at?->copy()->setTimezone('Asia/Jakarta')->format('d/m/Y H:i') ?? '—' }}</td><td>{{ $event->application?->application_code }}<br>{{ $event->application?->candidate?->person?->full_name }}</td><td>{{ $event->clinic?->name ?? '—' }}</td><td>{{ \App\Models\MedicalEvent::STATUSES[$event->status] ?? $event->status }}</td><td>{{ \App\Models\MedicalEvent::CLEARANCES[$event->clearance_status] ?? 'Belum ada hasil' }}</td><td>{{ $event->expires_at?->format('d/m/Y') ?? '—' }}@if($event->expires_at && $event->expires_at->format('Y-m-d') < now('Asia/Jakarta')->format('Y-m-d'))<br><span class="badge">Kedaluwarsa</span>@endif</td><td><a href="{{ route('medical-events.edit', $event) }}">Edit / Catat Hasil</a></td></tr>
@empty<tr><td colspan="7" class="empty">Belum ada jadwal MCU yang ditemukan.</td></tr>@endforelse
</tbody></table></div><x-crm-pagination :rows="$events" label="MCU" /></div>
@endsection
