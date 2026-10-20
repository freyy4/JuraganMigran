@extends('layouts.candidates')
@section('title', $label)
@section('content')
<div class="heading"><div><h1>{{ $label }}</h1><p class="muted">Kelola jadwal dan waktu aktual. Semua waktu menggunakan WIB.</p></div><a class="button" href="{{ route($prefix.'.create') }}">+ Tambah {{ $label }}</a></div>
<form method="get" action="{{ route($prefix.'.index') }}" class="card filters"><div class="search"><label for="search">Cari peserta</label><input id="search" name="search" maxlength="255" value="{{ request('search') }}" placeholder="Nama, kode CPMI, atau kode lamaran"></div><div><label for="status">Status</label><select id="status" name="status"><option value="">Semua</option>@foreach(['scheduled'=>'Terjadwal','completed'=>'Selesai','cancelled'=>'Dibatalkan'] as $key=>$text)<option value="{{ $key }}" @selected(request('status') === $key)>{{ $text }}</option>@endforeach</select></div>
<div class="filter-actions"><button>Terapkan</button><a class="button secondary" href="{{ route($prefix.'.index') }}">Reset</a></div>
</form>
<div class="card table-card"><div class="table-scroll"><table><thead><tr><th>Peserta / Lamaran</th><th>Jadwal (WIB)</th><th>Waktu Aktual (WIB)</th><th>Status</th><th>Catatan</th><th>Aksi</th></tr></thead><tbody>
@forelse($events as $event)<tr><td>{{ $event->full_name }}<br>{{ $event->application_code }}</td><td>{{ $event->scheduled_at ? \Illuminate\Support\Carbon::parse($event->scheduled_at, config('app.timezone'))->setTimezone('Asia/Jakarta')->format('d/m/Y H:i') : '—' }}</td><td>{{ $event->completed_at ? \Illuminate\Support\Carbon::parse($event->completed_at, config('app.timezone'))->setTimezone('Asia/Jakarta')->format('d/m/Y H:i') : '—' }}</td><td>{{ ['scheduled'=>'Terjadwal','completed'=>'Selesai','cancelled'=>'Dibatalkan'][$event->status] ?? $event->status }}</td><td style="white-space:pre-line;max-width:300px">{{ $event->notes }}</td><td><div class="actions"><a href="{{ route($prefix.'.edit', $event->id) }}">Edit / Catat Aktual</a><a href="{{ route('readiness.show', $event->application_id) }}">Checklist</a></div></td></tr>
@empty<tr><td colspan="6" class="empty">Belum ada catatan yang sesuai.</td></tr>@endforelse
</tbody></table></div><x-crm-pagination :rows="$events" label="catatan" /></div>
@endsection
