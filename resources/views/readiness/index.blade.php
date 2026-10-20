@extends('layouts.candidates')
@section('title','Kesiapan Keberangkatan')
@section('content')
<div class="heading"><div><h1>Kesiapan Keberangkatan</h1><p class="muted">Seluruh item wajib harus selesai sebelum lamaran dapat ditandai siap berangkat.</p></div></div>
<form method="get" class="card filters" action="{{ route('readiness.index') }}"><div class="search"><label for="search">Cari CPMI / kode lamaran</label><input id="search" name="search" value="{{ request('search') }}" maxlength="255"></div><div><label for="readiness">Kesiapan</label><select id="readiness" name="readiness"><option value="">Semua</option>@foreach(['ready'=>'Siap Berangkat','pending'=>'Belum Siap','empty'=>'Belum Ada Checklist'] as $value=>$label)<option value="{{ $value }}" @selected(request('readiness')===$value)>{{ $label }}</option>@endforeach</select></div>
<div class="filter-actions"><button>Terapkan</button><a class="button secondary" href="{{ route('readiness.index') }}">Reset</a></div>
</form>
<div class="card table-card"><div class="table-scroll"><table><thead><tr><th>CPMI</th><th>Kode Lamaran</th><th>Lowongan</th><th>Mitra</th><th>Progress</th><th>Kesiapan</th><th>Aksi</th></tr></thead><tbody>@forelse($applications as $application)
@php($items=$application->readinessChecklists->flatMap->items)
@php($progress=$items->isEmpty() ? 0 : (int)floor($items->where('status','completed')->count()*100/$items->count()))
@php($ready=$application->readinessChecklists->isNotEmpty() && $application->readinessChecklists->every(fn($checklist)=>$checklist->isReady()))
<tr><td>{{ $application->candidate?->person?->full_name ?? 'Profil tidak tersedia' }}</td><td>{{ $application->application_code }}</td><td>{{ $application->vacancy?->title ?? '—' }}</td><td>{{ $application->vacancy?->jobOrder?->company?->name ?? '—' }}</td><td><progress max="100" value="{{ $progress }}" aria-label="Progress kesiapan"></progress> {{ $progress }}%</td><td>{{ $ready ? 'Siap Berangkat' : ($application->readinessChecklists->isEmpty() ? 'Belum Ada Checklist' : 'Belum Siap') }}</td><td><a href="{{ route('readiness.show',$application) }}">Detail Checklist</a></td></tr>@empty<tr><td colspan="7" class="empty">Tidak ada lamaran yang ditemukan.</td></tr>@endforelse</tbody></table></div><x-crm-pagination :rows="$applications" label="lamaran" /></div>
@endsection
