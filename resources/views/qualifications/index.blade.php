@extends('layouts.candidates')
@section('title', 'Kualifikasi')
@section('content')
<div class="heading"><div><h1>Kualifikasi</h1><p class="muted">Pilih lamaran untuk memeriksa persyaratan lowongannya.</p></div></div>
<form method="get" action="{{ route('qualifications.index') }}" class="card filters"><div class="search"><label for="search">Cari lamaran</label><input id="search" name="search" value="{{ request('search') }}" maxlength="255" placeholder="Kode lamaran, kode CPMI, atau nama kandidat"></div>
<div class="filter-actions"><button type="submit">Cari</button><a class="button secondary" href="{{ route('qualifications.index') }}">Reset</a></div>
</form>
<div class="card table-card"><div class="table-scroll"><table><thead><tr><th>Kode Lamaran</th><th>CPMI</th><th>Lowongan</th><th>Progres Pemeriksaan</th><th>Aksi</th></tr></thead><tbody>@forelse($applications as $application)@php
    $requirements = $application->vacancy?->currentRequirements ?? collect();
    $currentResults = $application->qualificationResults->whereIn('vacancy_requirement_id', $requirements->pluck('id'));
    $checked = $currentResults->filter(fn ($result) => isset(\App\Models\ApplicationQualificationResult::RESULTS[$result->result]) && $result->result !== 'not_checked')->count();
    $progress = $requirements->count() ? (int) round($checked / $requirements->count() * 100) : 0;
@endphp
<tr><td>{{ $application->application_code }}</td><td>{{ $application->candidate?->person?->full_name ?? 'Profil tidak tersedia' }}<br><span class="muted">{{ $application->candidate?->candidate_code }}</span></td><td>{{ $application->vacancy?->title ?? '—' }}</td><td>@if($requirements->isEmpty())<span class="muted">Belum ada persyaratan</span>@else<strong>{{ $progress }}%</strong><br><span class="muted">{{ $checked }} / {{ $requirements->count() }} diperiksa</span>@if($currentResults->contains('result', 'review'))<br><span class="badge">Perlu Tinjauan</span>@endif @if($currentResults->contains('result', 'rejected'))<br><span class="badge">Tidak Dilanjutkan</span>@endif @endif</td><td><a class="button secondary" href="{{ route('qualifications.show',$application) }}">Periksa Kualifikasi</a></td></tr>@empty<tr><td colspan="5" class="empty">Tidak ada lamaran yang ditemukan.</td></tr>@endforelse</tbody></table></div><x-crm-pagination :rows="$applications" label="lamaran" /></div>
@endsection
