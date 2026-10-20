@extends('layouts.candidates')
@section('title', 'Dokumen')
@section('content')
<div class="heading"><div><h1>Dokumen</h1><p class="muted">Pilih lamaran untuk melihat checklist dan mengelola dokumen kandidat.</p></div></div>
@include('documents.action-queue')
<details class="card"><summary>Ringkasan Tambahan</summary><div class="grid">@foreach($summary as $label=>$count)<div class="card"><h2>{{ $label }}</h2>@php($summaryUrl = match($label) {
    'Menunggu Review' => route('document-verifications.index'),
    'Dokumen Kurang' => route('missing-documents.index'),
    'Ditolak' => route('document-verifications.index', ['status' => 'rejected']),
    'Akan Kedaluwarsa' => route('expired-documents.index', ['period' => 'expiring']),
})
<a href="{{ $summaryUrl }}" aria-label="{{ $label }}: {{ $count }}. Buka daftar."><strong style="font-size:28px">{{ $count }}</strong></a>@if($label === 'Akan Kedaluwarsa')<p class="muted">Dalam 30 hari ke depan.</p>@elseif($label === 'Dokumen Kurang')<p class="muted">Kebutuhan wajib per lamaran yang belum tersedia, ditolak, atau kedaluwarsa.</p>@endif</div>@endforeach</div></details>
<form class="card filters" method="get" action="{{ route('documents.index') }}"><div class="search"><label for="search">Cari lamaran</label><input id="search" name="search" value="{{ request('search') }}" maxlength="255" placeholder="Kode lamaran, kode CPMI, atau nama kandidat"></div>
<div class="filter-actions"><button type="submit">Cari</button><a class="button secondary" href="{{ route('documents.index') }}">Reset</a></div>
</form>
<div class="card table-card"><div class="table-scroll"><table><thead><tr><th>Kode Lamaran</th><th>CPMI</th><th>Lowongan</th><th>Aksi</th></tr></thead><tbody>@forelse($applications as $application)<tr><td>{{ $application->application_code }}</td><td>{{ $application->candidate?->person?->full_name ?? 'Profil tidak tersedia' }}</td><td>{{ $application->vacancy?->title ?? '—' }}</td><td><a class="button secondary" href="{{ route('documents.show',$application) }}">Checklist Dokumen</a></td></tr>@empty<tr><td colspan="4" class="empty">Tidak ada lamaran yang ditemukan.</td></tr>@endforelse</tbody></table></div><x-crm-pagination :rows="$applications" label="lamaran" /></div>
@endsection
