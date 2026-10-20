@extends('layouts.candidates')
@section('title', 'Pemeriksaan Awal Kesehatan')
@section('content')
<div class="heading"><div><h1>Pemeriksaan Awal Kesehatan</h1><p class="muted">Kelola jawaban pemeriksaan awal untuk setiap lamaran.</p></div></div>
<div class="grid">@foreach(['IN_PROGRESS','COMPLETED_NO_IMMEDIATE_FOLLOWUP','NEEDS_CLARIFICATION','REFER_TO_MCU','MEDICAL_REVIEW_REQUIRED'] as $outcome)<section class="card"><h3>{{ \App\Services\HealthScreeningEngine::OUTCOMES[$outcome] }}</h3><strong>{{ $metrics[$outcome]??0 }}</strong></section>@endforeach</div>
@if(!$aggregateOnly)<form method="get" class="card filters" action="{{ route('health-screenings.index') }}"><div class="search"><label for="search">Cari lamaran</label><input id="search" name="search" maxlength="255" value="{{ request('search') }}" placeholder="Kode lamaran atau nama CPMI"></div>
<div class="filter-actions"><button>Cari</button><a class="button secondary" href="{{ route('health-screenings.index') }}">Reset</a></div>
</form>
<div class="card table-card"><div class="table-scroll"><table><thead><tr><th>Lamaran</th><th>CPMI</th><th>Lowongan</th><th>Status</th><th>Progres Jawaban</th><th>Aksi</th></tr></thead><tbody>
@forelse($applications as $application)
<tr><td>{{ $application->application_code }}</td><td>{{ $application->candidate?->person?->full_name ?? '—' }}</td><td>{{ $application->vacancy?->title ?? '—' }}</td><td>{{ $application->latestHealthInterview?->status === 'completed' ? 'Selesai' : 'Belum Selesai' }}</td><td>{{ $application->latestHealthInterview?->completeness_percentage ?? 0 }}%</td><td><a class="button secondary" href="{{ route('health-screenings.show', $application) }}">Periksa</a></td></tr>
@empty<tr><td colspan="6" class="empty">Tidak ada lamaran yang ditemukan.</td></tr>@endforelse
</tbody></table></div><x-crm-pagination :rows="$applications" label="lamaran" /></div>
@else<section class="card"><p>Tampilan agregat. Jawaban dan daftar kesehatan individu tidak ditampilkan.</p></section>@endif
@endsection
