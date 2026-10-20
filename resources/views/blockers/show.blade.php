@extends('layouts.candidates')
@section('title', 'Detail Kendala')
@section('content')
<a class="back" href="{{ route('blockers.index') }}">← Daftar kendala</a><div class="heading"><div><h1>Detail Kendala</h1><p class="muted">{{ $blocker->code }}</p></div><div class="actions"><a class="button" href="{{ route('blockers.edit',$blocker) }}">Edit Kendala</a>@include('blockers.resolve')</div></div>
@if($blocker->isOverdue())<div class="errors" role="status">Kendala ini terlambat dan belum selesai.</div>@endif
<section class="card"><h2>Informasi kendala</h2><dl class="grid">
@foreach(['Kode'=>$blocker->code,'Tahap'=>$blocker->stage?->name,'Kategori'=>$blocker->category,'PIC'=>$blocker->owner?->name,'Prioritas'=>\App\Models\ApplicationBlocker::label($blocker->priority),'Status'=>\App\Models\ApplicationBlocker::label($blocker->status),'Deadline'=>$blocker->due_at ? $blocker->due_at->copy()->setTimezone(\App\Models\ApplicationBlocker::TIMEZONE)->format('d/m/Y H:i').' WIB' : 'Tanpa deadline','Waktu selesai'=>$blocker->resolved_at?->copy()->setTimezone(\App\Models\ApplicationBlocker::TIMEZONE)->format('d/m/Y H:i'),'Diselesaikan oleh'=>$blocker->resolver?->name] as $label=>$value)<div><dt>{{ $label }}</dt><dd>{{ $value ?: '—' }}</dd></div>@endforeach
<div class="full"><dt>Kendala</dt><dd>{{ $blocker->description }}</dd></div><div class="full"><dt>Tindakan berikutnya</dt><dd>{{ $blocker->next_action ?: '—' }}</dd></div></dl></section>
<section class="card"><h2>CPMI dan lamaran terkait</h2><dl class="grid">
@foreach(['Kode lamaran'=>$blocker->application?->application_code,'Kode CPMI'=>$blocker->application?->candidate?->candidate_code,'Nama CPMI'=>$blocker->application?->candidate?->person?->full_name,'Lowongan'=>$blocker->application?->vacancy?->title] as $label=>$value)<div><dt>{{ $label }}</dt><dd>{{ $value ?: '—' }}</dd></div>@endforeach
</dl><div class="actions footer-actions">@if($blocker->application && !$blocker->application->trashed())<a class="button secondary" href="{{ route('applications.show',$blocker->application) }}">Detail Lamaran</a>@endif @if($blocker->application?->candidate && !$blocker->application->candidate->trashed())<a class="button secondary" href="{{ route('candidates.show',$blocker->application->candidate) }}">Profil CPMI</a>@endif</div></section>
@endsection
